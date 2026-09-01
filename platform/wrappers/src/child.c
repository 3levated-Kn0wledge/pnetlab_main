/* ptsname_r and cfmakeraw are GNU/BSD extensions; both are in glibc. */
#define _GNU_SOURCE

#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <signal.h>
#include <stdlib.h>
#include <string.h>
#include <sys/ioctl.h>
#include <sys/wait.h>
#include <termios.h>
#include <time.h>
#include <unistd.h>

#include "child.h"
#include "log.h"

void child_become_group_leader(void)
{
	/* R4: SIGTERM must take the child with us. setpgrp() puts us in a new
	 * process group of our own; the child inherits it, so at teardown one
	 * kill(0, SIGTERM) reaches the whole tree — the shell, the emulator, and
	 * anything either of them forked. Leaving an orphaned QEMU or IOL behind
	 * is the failure this prevents, and it is an expensive one: the port stays
	 * bound, so the node reads as running forever. */
	if (setpgid(0, 0) != 0)
		log_wrn("setpgid failed: %s — teardown will fall back to signalling "
		        "the child directly", strerror(errno));
}

static void set_cloexec_off(int fd)
{
	int flags = fcntl(fd, F_GETFD, 0);

	if (flags >= 0)
		(void) fcntl(fd, F_SETFD, flags & ~FD_CLOEXEC);
}

/* One '.' per second, then a newline. §2.2 step 1. Runs in the child, after its
 * stdio is already on the console, so the dots reach every connected viewer. */
static void child_delay_ticker(unsigned int delay)
{
	struct timespec one = { 1, 0 };
	unsigned int    i;

	if (delay == 0)
		return;

	for (i = 0; i < delay; i++) {
		if (write(STDOUT_FILENO, ".", 1) < 0)
			break;
		while (nanosleep(&one, &one) != 0 && errno == EINTR)
			;
		one.tv_sec  = 1;
		one.tv_nsec = 0;
	}
	if (write(STDOUT_FILENO, "\n", 1) < 0)
		return;
}

static void child_apply_env(const char *const *env_extra)
{
	size_t i;

	if (env_extra == NULL)
		return;

	for (i = 0; env_extra[i] != NULL; i++) {
		const char *eq = strchr(env_extra[i], '=');
		char        name[256];
		size_t      n;

		if (eq == NULL)
			continue;
		n = (size_t) (eq - env_extra[i]);
		if (n == 0 || n >= sizeof(name))
			continue;
		memcpy(name, env_extra[i], n);
		name[n] = '\0';
		(void) setenv(name, eq + 1, 1);
	}
}

static int child_open_pty(int *master, int *slave)
{
	struct winsize ws;
	struct termios tio;
	char           name[PATH_MAX];

	*master = posix_openpt(O_RDWR | O_NOCTTY);
	if (*master < 0) {
		log_errno("posix_openpt");
		return -1;
	}
	if (grantpt(*master) != 0) {
		log_errno("grantpt");
		close(*master);
		return -1;
	}
	if (unlockpt(*master) != 0) {
		log_errno("unlockpt");
		close(*master);
		return -1;
	}
	if (ptsname_r(*master, name, sizeof(name)) != 0) {
		log_errno("ptsname_r");
		close(*master);
		return -1;
	}
	*slave = open(name, O_RDWR | O_NOCTTY);
	if (*slave < 0) {
		log_errno("open(pty slave)");
		close(*master);
		return -1;
	}

	/*
	 * Raw mode on the slave. The wrapper is already a terminal server: it
	 * echoes nothing itself and the far end (the container's shell, IOS) does
	 * its own line editing. Leaving the pty in canonical mode with ECHO on
	 * gives every keystroke back twice and swallows input until Enter.
	 */
	if (tcgetattr(*slave, &tio) == 0) {
		cfmakeraw(&tio);
		if (tcsetattr(*slave, TCSANOW, &tio) != 0)
			log_wrn("tcsetattr on the pty slave: %s", strerror(errno));
	}

	/* §4.3: the original never negotiated a window size either — whatever
	 * `ssh -tt` defaulted to is what the container saw. 80x24 is that, and it
	 * is better than the 0x0 an unset pty reports, which makes `less` and
	 * anything using curses misbehave. */
	memset(&ws, 0, sizeof(ws));
	ws.ws_col = 80;
	ws.ws_row = 24;
	(void) ioctl(*slave, TIOCSWINSZ, &ws);

	return 0;
}

int child_spawn(child_t *ch, const child_spec_t *spec)
{
	int in_pipe[2]  = { -1, -1 };
	int out_pipe[2] = { -1, -1 };
	int master = -1, slave = -1;

	memset(ch, 0, sizeof(*ch));
	ch->pid    = -1;
	ch->in_fd  = -1;
	ch->out_fd = -1;

	if (spec->command == NULL || *spec->command == '\0') {
		log_err("no child command was assembled");
		return -1;
	}

	if (spec->mode == CHILD_PTY) {
		if (child_open_pty(&master, &slave) != 0)
			return -1;
		ch->is_pty = 1;
	} else {
		if (pipe(in_pipe) != 0) {
			log_errno("pipe(child stdin)");
			return -1;
		}
		if (pipe(out_pipe) != 0) {
			log_errno("pipe(child stdout)");
			close(in_pipe[0]);
			close(in_pipe[1]);
			return -1;
		}
	}

	log_inf("starting child: /bin/sh -c \"%s\"", spec->command);

	ch->pid = fork();
	if (ch->pid < 0) {
		log_errno("fork");
		if (master >= 0) { close(master); close(slave); }
		if (in_pipe[0] >= 0) { close(in_pipe[0]); close(in_pipe[1]); }
		if (out_pipe[0] >= 0) { close(out_pipe[0]); close(out_pipe[1]); }
		return -1;
	}

	if (ch->pid == 0) {
		/* ---- child ---- */
		/* R2: no chdir here either. The child inherits the node's running
		 * directory, which is what makes `sudo fuser -k -TERM <running path>`
		 * in device::stop() reach the emulator as well as the wrapper. */
		if (spec->mode == CHILD_PTY) {
			/* A controlling terminal needs a new session, which also moves
			 * the child out of our process group — teardown compensates by
			 * signalling the child's own group as well (see loop.c). */
			if (setsid() < 0)
				_exit(127);
			if (ioctl(slave, TIOCSCTTY, 0) != 0)
				_exit(127);
			close(master);
			if (dup2(slave, STDIN_FILENO) < 0 ||
			    dup2(slave, STDOUT_FILENO) < 0 ||
			    dup2(slave, STDERR_FILENO) < 0)
				_exit(127);
			if (slave > STDERR_FILENO)
				close(slave);
		} else {
			close(in_pipe[1]);
			close(out_pipe[0]);
			if (dup2(in_pipe[0], STDIN_FILENO) < 0 ||
			    dup2(out_pipe[1], STDOUT_FILENO) < 0 ||
			    dup2(out_pipe[1], STDERR_FILENO) < 0)
				_exit(127);
			if (in_pipe[0] > STDERR_FILENO)
				close(in_pipe[0]);
			if (out_pipe[1] > STDERR_FILENO)
				close(out_pipe[1]);
		}

		set_cloexec_off(STDIN_FILENO);
		set_cloexec_off(STDOUT_FILENO);
		set_cloexec_off(STDERR_FILENO);

		/* The parent ignores SIGPIPE; the child must not inherit that, or a
		 * pipeline inside the command string behaves oddly. */
		signal(SIGPIPE, SIG_DFL);

		child_delay_ticker(spec->delay);
		child_apply_env(spec->env_extra);

		/*
		 * The original calls system(3), which forks *another* shell and waits
		 * for it, then logs and exits 0 (§2.2 step 4). We exec the shell
		 * directly instead. Two reasons, both practical:
		 *
		 *   - one less process in the group, and the child pid we hold is the
		 *     shell that actually runs the command, so R6 (nc exits when QEMU
		 *     goes away -> the wrapper exits -> the port is released) reports
		 *     promptly rather than one hop late;
		 *   - the child's exit status is the command's, so the parent can log
		 *     something true about why the node stopped.
		 *
		 * The shell itself is still required: the command carries
		 * escapeshellarg() quoting from the PHP.
		 */
		execl("/bin/sh", "sh", "-c", spec->command, (char *) NULL);
		_exit(127);
	}

	/* ---- parent ---- §2.2, close the ends we do not own. */
	if (spec->mode == CHILD_PTY) {
		close(slave);
		ch->in_fd = ch->out_fd = master;
	} else {
		close(in_pipe[0]);
		close(out_pipe[1]);
		ch->in_fd  = in_pipe[1];
		ch->out_fd = out_pipe[0];
	}

	/*
	 * Non-blocking on the write side. Console input is a human typing, so
	 * dropping a keystroke when the emulator is not reading is survivable;
	 * blocking the select loop on it is not — the console would freeze for
	 * every viewer and node output would stop being relayed.
	 */
	{
		int flags = fcntl(ch->in_fd, F_GETFL, 0);

		if (flags >= 0)
			(void) fcntl(ch->in_fd, F_SETFL, flags | O_NONBLOCK);
	}

	log_inf("child started, pid %d", (int) ch->pid);
	return 0;
}

int child_reap(child_t *ch, int *status)
{
	pid_t r;

	if (ch->pid <= 0)
		return -1;

	r = waitpid(ch->pid, status, WNOHANG);
	if (r == ch->pid)
		return 1;
	if (r < 0 && errno != EINTR)
		return -1;
	return 0;
}

int child_wait_blocking(child_t *ch)
{
	int status = 0;

	if (ch->pid <= 0)
		return -1;

	while (waitpid(ch->pid, &status, 0) < 0) {
		if (errno != EINTR)
			return -1;
	}
	return status;
}

void child_close(child_t *ch)
{
	if (ch->in_fd >= 0)
		close(ch->in_fd);
	if (ch->out_fd >= 0 && ch->out_fd != ch->in_fd)
		close(ch->out_fd);
	ch->in_fd = ch->out_fd = -1;
}
