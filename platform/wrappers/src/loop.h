/*
 * loop.{c,h} — WRAPPER-SPEC §2.3, the select loop, waitpid and teardown.
 *
 * This is the part every front-end shares unchanged. qemu_wrapper_telnet passes
 * no hooks at all; iol_wrapper passes hooks so its TAP, UDP and AF_UNIX
 * descriptors join the same select set and its frame forwarding runs in the same
 * pass as the console.
 */
#ifndef WRAPPER_LOOP_H
#define WRAPPER_LOOP_H

#include <sys/select.h>

#include "child.h"
#include "console.h"

/*
 * Extra descriptors owned by a front-end.
 *
 *   prepare  add your fds to rfds/wfds; return the highest one, or -1.
 *   service  called after select with the ready sets; dispatch your fds.
 *
 * Both may be NULL. Neither should block: everything in the set is
 * non-blocking, and a front-end that blocks here stalls the console.
 */
typedef struct {
	int  (*prepare)(void *ud, fd_set *rfds, fd_set *wfds);
	void (*service)(void *ud, const fd_set *rfds, const fd_set *wfds);
	void *ud;
} loop_hooks_t;

/*
 * Install the signal disposition of §2.2 step 3 and run until the child exits or
 * we are signalled. Returns the process exit code — always 0; §2.4 says a
 * wrapper that got as far as starting its child exits 0 however it ends, and the
 * caller ignores the status anyway.
 *
 * On return the listener is closed and the child is gone (R4).
 */
int loop_run(console_t *con, child_t *ch, const loop_hooks_t *hooks);

/*
 * R4, exposed for front-ends that need to give up outside the loop (a failed
 * NETMAP write in iol_wrapper, say) after the child is already running.
 * Signals the process group, waits briefly, escalates to SIGKILL, closes the
 * console.
 */
void loop_teardown(console_t *con, child_t *ch, const char *why);

#endif /* WRAPPER_LOOP_H */
