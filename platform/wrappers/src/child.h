/*
 * child.{c,h} — WRAPPER-SPEC §2.2, starting and owning the emulator process.
 *
 * Two stdio shapes:
 *
 *   CHILD_PIPES  a pipe each way. What qemu_wrapper_telnet and iol_wrapper need:
 *                their children (`nc -U`, the IOL binary) are happy on pipes.
 *
 *   CHILD_PTY    a pseudo-terminal, child on the slave, wrapper on the master.
 *                What docker_wrapper needs (§4.3): `docker exec -ti` refuses to
 *                run without a TTY, and R7 says we allocate one ourselves rather
 *                than reproducing the original's ssh -tt hop, which required a
 *                standing passwordless root SSH key on the appliance.
 *
 * The front-end does not care which: child_t::in_fd and child_t::out_fd are what
 * the poll loop reads and writes either way (for a PTY they are the same fd).
 */
#ifndef WRAPPER_CHILD_H
#define WRAPPER_CHILD_H

#include <sys/types.h>

typedef enum {
	CHILD_PIPES = 0,
	CHILD_PTY   = 1
} child_mode_t;

typedef struct {
	pid_t pid;
	int   in_fd;    /* we write here; the child sees it as stdin */
	int   out_fd;   /* we read here; the child's stdout and stderr */
	int   is_pty;
} child_t;

typedef struct {
	child_mode_t mode;

	/* The assembled shell command (§2.6), run as `/bin/sh -c <command>`. */
	const char *command;

	/* -d: seconds to wait before exec'ing, one '.' per second written to the
	 * console so a connected viewer can see that something is happening. */
	unsigned int delay;

	/* NULL-terminated list of "NAME=value" strings to put in the child's
	 * environment. Front-ends should prefer this to a "NAME=value " prefix on
	 * the command string: it survives however the shell feels about quoting,
	 * and it is visible in the log as configuration rather than as text.
	 * §1.1: the wrapper inherits no environment worth speaking of, so this is
	 * the only way anything gets set. */
	const char *const *env_extra;
} child_spec_t;

/*
 * R4. Become a process-group leader before forking anything, so the child (and
 * whatever the child forks) lands in a group we can signal as a unit at
 * teardown. Call this first thing in main(), before the listener is opened.
 */
void child_become_group_leader(void);

/* Returns 0, or -1 after logging. A failure is fatal to the wrapper (§2.4). */
int child_spawn(child_t *ch, const child_spec_t *spec);

/* §3.5, the -x babysitter path: no console, just wait for the child. Returns the
 * child's exit status as a shell would report it, or -1. */
int child_wait_blocking(child_t *ch);

/*
 * Non-blocking reap. Returns 1 if the child has exited (status filled in),
 * 0 if it is still running, -1 if there is no child to wait for.
 *
 * §2.3 step 1 uses WNOHANG|WUNTRACED. We drop WUNTRACED deliberately: with it, a
 * child merely stopped by SIGSTOP (a `kill -STOP` from an operator poking at a
 * hung node, or a job-control accident) reports as "gone" and the wrapper tears
 * down a node that was only paused.
 */
int child_reap(child_t *ch, int *status);

void child_close(child_t *ch);

#endif /* WRAPPER_CHILD_H */
