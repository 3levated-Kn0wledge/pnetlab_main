/*
 * console.{c,h} — WRAPPER-SPEC §2.1, the "terminal server".
 *
 * One listening TCP socket, up to 1023 simultaneous viewers, telnet option
 * negotiation on accept, an xterm window title, IAC filtering on input and a
 * broadcast of everything the node emits to every viewer.
 *
 * R1 lives here. getNodeStatus() in includes/functions.php decides a node is
 * running by running
 *
 *     netstat -a -t -n | grep LISTEN | grep ':<console port>'
 *
 * and nothing else — no pid file, no health check, no connect(). If this socket
 * is not LISTENing the node reads as stopped no matter how healthy the emulator
 * is, and device_qemu::start()'s four one-second retries give up. Opening the
 * listener is therefore the single most important thing a wrapper does, and it
 * is done before the child is forked so the window in which the node looks dead
 * is as short as possible.
 */
#ifndef WRAPPER_CONSOLE_H
#define WRAPPER_CONSOLE_H

#include <stddef.h>
#include <sys/select.h>

/* §2.1. The original's capacity; kept, though the practical limit is lower —
 * see the FD_SETSIZE note in console.c. */
#define CONSOLE_MAX_CLIENTS 1023

#define CONSOLE_TITLE_MAX   256

/* Per-client output backlog before we give up on that viewer (see §10.3 #1 and
 * the comment on console_broadcast). */
#define CONSOLE_QUEUE_MAX   (256 * 1024)

/*
 * Telnet input filter — the pure part of §2.1's input path, extracted so it can
 * be unit-tested without a socket.
 *
 * The original recv()s one byte and, if it is IAC (0xFF), recv()s two more and
 * discards all three. That is the whole of its telnet protocol handling. Two
 * consequences we deliberately do not reproduce (§10.3 #8):
 *
 *   - a literal 0xFF in the data stream is destroyed, along with the two bytes
 *     behind it. We unescape IAC IAC to a single 0xFF, which is what RFC 854
 *     requires and what every client sends once BINARY is negotiated;
 *   - a subnegotiation (IAC SB ... IAC SE) is not three bytes, so the original
 *     leaks the body of it into the node's console as keystrokes. We consume to
 *     IAC SE. No client should send one, because we never offer an option that
 *     invites it, but "should" is doing a lot of work in a protocol this old.
 *
 * The filter is a byte-at-a-time state machine because a command can be split
 * across two TCP segments.
 */
typedef struct {
	unsigned char state;
} telnet_filter_t;

void telnet_filter_init(telnet_filter_t *f);

/*
 * Copy the data bytes of in[0..inlen) to out, dropping telnet commands.
 * out must have room for inlen bytes. Returns the number of bytes written.
 */
size_t telnet_filter(telnet_filter_t *f, const unsigned char *in, size_t inlen,
                     unsigned char *out);

typedef struct {
	int             fd;
	telnet_filter_t filter;
	unsigned char  *queue;      /* pending output, allocated on first stall */
	size_t          qlen;
	size_t          qoff;
	size_t          qcap;
} console_client_t;

typedef struct {
	int              listen_fd;   /* -1 when running without a console (§3.5) */
	int              nclients;
	console_client_t clients[CONSOLE_MAX_CLIENTS];
	char             title[CONSOLE_TITLE_MAX];
} console_t;

/* Set up an empty console. Does not touch the network. */
void console_init(console_t *c, const char *title);

/*
 * Open the listening socket on `port`. R1. Returns 0, or -1 after logging the
 * exact call that failed — the caller must treat that as fatal and exit(1),
 * because a wrapper with no listener can never make its node read as running.
 */
int console_open(console_t *c, int port);

/* Close the listener and every client. Idempotent. */
void console_close(console_t *c);

/* §2.1, SIGHUP: drop every viewer, keep the listener and keep running. */
void console_drop_all_clients(console_t *c);

/*
 * Add the listener and all clients to the select sets. Clients only appear in
 * wfds while they have a backlog. Returns the highest fd added, or -1 if none.
 * Front-ends that own extra descriptors (iol_wrapper's TAP, UDP and AF_UNIX
 * fds) fold this result into their own max.
 */
int console_prepare(const console_t *c, fd_set *rfds, fd_set *wfds);

/*
 * Service everything console-related that select reported ready: flush stalled
 * clients, accept a new viewer, and read from viewers that typed something.
 *
 * Keystrokes are telnet-filtered and appended to buf; the return value is how
 * many bytes were placed there, to be written to the child's stdin. If buf
 * fills, the remaining clients are simply left for the next pass — select will
 * report them again.
 */
size_t console_service(console_t *c, const fd_set *rfds, const fd_set *wfds,
                       unsigned char *buf, size_t buflen);

/*
 * Send node output to every viewer.
 *
 * §10.3 #1: the original reads and sends ONE BYTE AT A TIME, one send() per
 * client per byte. We take a block. The bytes on the wire are identical and the
 * syscall count during a boot log drops by three orders of magnitude.
 *
 * Viewer sockets are non-blocking here, which the original's were not. That
 * matters more than it sounds: with blocking sockets one viewer whose TCP window
 * has closed — a laptop that slept with a console open — stops the whole select
 * loop, so the node's console freezes for everybody and the emulator eventually
 * blocks on its own stdout. We queue up to CONSOLE_QUEUE_MAX per viewer and drop
 * the viewer beyond that. Losing one stalled spectator beats losing the node.
 */
void console_broadcast(console_t *c, const void *buf, size_t len);

#endif /* WRAPPER_CONSOLE_H */
