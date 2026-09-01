#include <errno.h>
#include <signal.h>
#include <string.h>
#include <sys/select.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#include "console.h"
#include "child.h"
#include "log.h"
#include "loop.h"

static volatile sig_atomic_t g_hup;
static volatile sig_atomic_t g_term;

static void on_signal(int sig)
{
	if (sig == SIGHUP)
		g_hup = 1;
	else
		g_term = 1;
}

static void install_signals(void)
{
	struct sigaction sa;

	/*
	 * A viewer that disconnects mid-write must not kill the wrapper. Every
	 * send() also passes MSG_NOSIGNAL, but the child's pipe has no such flag,
	 * so the disposition is what protects us.
	 */
	signal(SIGPIPE, SIG_IGN);

	memset(&sa, 0, sizeof(sa));
	sa.sa_handler = on_signal;
	sigfillset(&sa.sa_mask);   /* §2.2 step 3: all signals blocked in-handler */
	sa.sa_flags = 0;           /* deliberately NOT SA_RESTART — see below */

	/*
	 * The handler only sets a flag. That is what §2.2 describes ("logs and
	 * returns"): termination is a consequence of select() being interrupted,
	 * not something the handler does. Without SA_RESTART select() returns
	 * EINTR, the loop notices the flag, and teardown happens on the main stack
	 * where it is allowed to log, wait and close things — none of which is
	 * async-signal-safe inside a handler.
	 */
	sigaction(SIGHUP,  &sa, NULL);
	sigaction(SIGINT,  &sa, NULL);
	sigaction(SIGTERM, &sa, NULL);
}

void loop_teardown(console_t *con, child_t *ch, const char *why)
{
	struct timespec nap = { 0, 50 * 1000 * 1000 };   /* 50 ms */
	int             i, status = 0;

	log_inf("shutting down: %s", why);

	if (ch != NULL && ch->pid > 0) {
		/*
		 * R4. Three signals, because the child may have left our group:
		 *
		 *   kill(0, ...)         our own process group, which child.c put us
		 *                        at the head of. Catches the shell, the
		 *                        emulator, and anything they forked.
		 *   kill(-pid, ...)      the child's own group, which exists only in
		 *                        PTY mode where the child had to setsid() to
		 *                        get a controlling terminal.
		 *   kill(pid, ...)       belt and braces.
		 *
		 * We are in our own group, so the first of these hits us too. That is
		 * harmless: the handler above only sets a flag, and we are already on
		 * the way out.
		 */
		kill(0, SIGTERM);
		kill(-ch->pid, SIGTERM);
		kill(ch->pid, SIGTERM);

		/* Give it 2 s to leave. An orphaned QEMU keeps the console port
		 * bound, and a bound port means getNodeStatus() reports the node as
		 * running forever — the worst possible failure, because the UI then
		 * refuses to start it again. */
		for (i = 0; i < 40; i++) {
			pid_t r = waitpid(ch->pid, &status, WNOHANG);

			if (r == ch->pid || (r < 0 && errno == ECHILD))
				break;
			nanosleep(&nap, NULL);
		}
		if (i == 40) {
			log_wrn("child %d did not exit on SIGTERM; sending SIGKILL",
			        (int) ch->pid);
			kill(-ch->pid, SIGKILL);
			kill(ch->pid, SIGKILL);
			waitpid(ch->pid, &status, 0);
		}
		ch->pid = -1;
	}

	if (ch != NULL)
		child_close(ch);

	/* Closing the listener is what releases the port, which is what makes
	 * getNodeStatus() report the node as stopped. */
	if (con != NULL)
		console_close(con);
}

static void log_child_status(int status)
{
	if (WIFEXITED(status))
		log_inf("child exited with status %d", WEXITSTATUS(status));
	else if (WIFSIGNALED(status))
		log_inf("child killed by signal %d", WTERMSIG(status));
	else
		log_inf("child gone (raw status 0x%x)", (unsigned) status);
}

int loop_run(console_t *con, child_t *ch, const loop_hooks_t *hooks)
{
	install_signals();

	for (;;) {
		fd_set rfds, wfds;
		int    maxfd = -1, n, hookmax;
		int    status = 0;

		/* §2.3 step 1. Reaped before select so a child that died while we
		 * were blocked is noticed on the very next pass. */
		if (child_reap(ch, &status) == 1) {
			log_child_status(status);
			ch->pid = -1;
			/* R6: nc exits when QEMU drops the unix socket, so this is the
			 * normal path by which a stopped node stops LISTENing. */
			loop_teardown(con, ch, "child process ended");
			return 0;
		}

		FD_ZERO(&rfds);
		FD_ZERO(&wfds);

		if (ch->out_fd >= 0 && ch->out_fd < FD_SETSIZE) {
			FD_SET(ch->out_fd, &rfds);
			maxfd = ch->out_fd;
		}

		n = console_prepare(con, &rfds, &wfds);
		if (n > maxfd)
			maxfd = n;

		if (hooks != NULL && hooks->prepare != NULL) {
			hookmax = hooks->prepare(hooks->ud, &rfds, &wfds);
			if (hookmax > maxfd)
				maxfd = hookmax;
		}

		if (maxfd < 0) {
			loop_teardown(con, ch, "nothing left to poll");
			return 0;
		}

		/* No timeout: there is nothing to do on a tick. */
		n = select(maxfd + 1, &rfds, &wfds, NULL, NULL);

		if (n < 0) {
			if (errno == EINTR) {
				if (g_term) {
					/* This is the device::stop() path: `sudo fuser -k
					 * -TERM <running path>` SIGTERMs everything whose
					 * cwd is the node's running directory, which is us
					 * (R2) and the child. */
					loop_teardown(con, ch, "signalled");
					return 0;
				}
				if (g_hup) {
					g_hup = 0;
					console_drop_all_clients(con);
				}
				continue;
			}
			log_errno("select");
			loop_teardown(con, ch, "select failed");
			return 0;
		}

		/* §2.3 step 4: node output. Read in blocks, not one byte at a time
		 * (§10.3 #1). */
		if (ch->out_fd >= 0 && FD_ISSET(ch->out_fd, &rfds)) {
			unsigned char buf[4096];
			ssize_t       r = read(ch->out_fd, buf, sizeof(buf));

			if (r > 0) {
				console_broadcast(con, buf, (size_t) r);
			} else if (r == 0 ||
			           (r < 0 && errno == EIO)) {
				/* EOF on a pipe, or EIO on a PTY master once the slave
				 * side is closed — the same event wearing two hats. */
				loop_teardown(con, ch, "child closed its output");
				return 0;
			} else if (r < 0 && errno != EINTR && errno != EAGAIN) {
				log_errno("read(child output)");
				loop_teardown(con, ch, "child output error");
				return 0;
			}
		}

		/* §2.3 steps 5 and 6: accept, drain, and collect keystrokes. */
		{
			unsigned char in[4096];
			size_t        got = console_service(con, &rfds, &wfds,
			                                    in, sizeof(in));

			if (got > 0 && ch->in_fd >= 0) {
				size_t off = 0;

				while (off < got) {
					ssize_t w = write(ch->in_fd, in + off, got - off);

					if (w > 0) {
						off += (size_t) w;
						continue;
					}
					if (w < 0 && errno == EINTR)
						continue;
					if (w < 0 && (errno == EAGAIN ||
					              errno == EWOULDBLOCK)) {
						/* See child.c: the emulator is not
						 * reading. Drop the keystrokes rather
						 * than stall every viewer. */
						log_wrn("child input is full; dropped %zu "
						        "byte(s) of console input",
						        got - off);
						break;
					}
					log_errno("write(child input)");
					loop_teardown(con, ch, "child input closed");
					return 0;
				}
			}
		}

		if (hooks != NULL && hooks->service != NULL)
			hooks->service(hooks->ud, &rfds, &wfds);
	}
}
