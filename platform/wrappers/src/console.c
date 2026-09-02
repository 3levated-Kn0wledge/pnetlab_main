#define _GNU_SOURCE   /* accept4 */

#include <errno.h>
#include <fcntl.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <unistd.h>

#include "console.h"
#include "log.h"

/* RFC 854 */
#define TN_IAC   255
#define TN_DONT  254
#define TN_DO    253
#define TN_WONT  252
#define TN_WILL  251
#define TN_SB    250
#define TN_SE    240

#define TN_OPT_BINARY 0
#define TN_OPT_ECHO   1
#define TN_OPT_SGA    3

enum {
	TF_DATA = 0,
	TF_IAC,
	TF_OPT,      /* consuming the option byte of WILL/WONT/DO/DONT */
	TF_SUB,      /* inside IAC SB ... */
	TF_SUB_IAC   /* inside a subnegotiation, just saw IAC */
};

void telnet_filter_init(telnet_filter_t *f)
{
	f->state = TF_DATA;
}

size_t telnet_filter(telnet_filter_t *f, const unsigned char *in, size_t inlen,
                     unsigned char *out)
{
	size_t i, n = 0;

	for (i = 0; i < inlen; i++) {
		unsigned char b = in[i];

		switch (f->state) {
		case TF_DATA:
			if (b == TN_IAC)
				f->state = TF_IAC;
			else
				out[n++] = b;
			break;

		case TF_IAC:
			if (b == TN_IAC) {
				/* IAC IAC is a literal 0xFF. §10.3 #8: the original
				 * loses it. */
				out[n++] = TN_IAC;
				f->state = TF_DATA;
			} else if (b == TN_SB) {
				f->state = TF_SUB;
			} else if (b >= TN_WILL && b <= TN_DONT) {
				f->state = TF_OPT;
			} else {
				/* Two-byte command (NOP, AYT, Interrupt Process, ...).
				 * We answer none of them: the client is negotiating
				 * against the unconditional WILLs we sent at accept
				 * time and never waits for a reply. */
				f->state = TF_DATA;
			}
			break;

		case TF_OPT:
			f->state = TF_DATA;
			break;

		case TF_SUB:
			if (b == TN_IAC)
				f->state = TF_SUB_IAC;
			break;

		case TF_SUB_IAC:
			if (b == TN_SE)
				f->state = TF_DATA;
			else if (b == TN_IAC)
				f->state = TF_SUB;   /* escaped 0xFF in the body */
			else
				f->state = TF_SUB;
			break;

		default:
			f->state = TF_DATA;
			break;
		}
	}

	return n;
}

void console_init(console_t *c, const char *title)
{
	memset(c, 0, sizeof(*c));
	c->listen_fd = -1;
	snprintf(c->title, sizeof(c->title), "%s",
	         (title != NULL) ? title : "Terminal Server");
}

static int set_nonblock(int fd)
{
	int flags = fcntl(fd, F_GETFL, 0);

	if (flags < 0)
		return -1;
	return fcntl(fd, F_SETFL, flags | O_NONBLOCK);
}

int console_open(console_t *c, int port)
{
	struct sockaddr_in6 a6;
	struct sockaddr_in  a4;
	int                 fd, on = 1, off = 0;

	/* §2.1: AF_INET6 bound to the wildcard. With net.ipv6.bindv6only=0 that
	 * also accepts IPv4 clients as v4-mapped, which is what the Guacamole
	 * telnet client and a plain `telnet 127.0.0.1 <port>` both are. We set
	 * IPV6_V6ONLY off explicitly rather than trusting the sysctl: a host that
	 * has been hardened with bindv6only=1 would otherwise present a listener
	 * that satisfies netstat, and so satisfies R1 and makes the node read as
	 * running, while refusing every actual console connection. */
	/* SOCK_CLOEXEC on the listener, and on every accepted client below.
	 * child.c forks and execs the emulator through /bin/sh, and an inherited
	 * copy of this descriptor in that process tree keeps the port bound
	 * after the wrapper has gone. getNodeStatus() reads a bound console
	 * port as "running", so an orphaned child -- a `sleep` in a template's
	 * shell string, a helper the emulator spawned and did not wait for --
	 * left a stopped node that the UI refused to start again, forever. The
	 * pty slave and the pipes are handed over on purpose in child.c; nothing
	 * else here should survive the exec. */
	fd = socket(AF_INET6, SOCK_STREAM | SOCK_CLOEXEC, 0);
	if (fd >= 0) {
		if (setsockopt(fd, IPPROTO_IPV6, IPV6_V6ONLY, &off, sizeof(off)) != 0)
			log_wrn("could not clear IPV6_V6ONLY: %s", strerror(errno));
		if (setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &on, sizeof(on)) != 0) {
			log_errno("setsockopt(SO_REUSEADDR)");
			close(fd);
			return -1;
		}
		memset(&a6, 0, sizeof(a6));
		a6.sin6_family = AF_INET6;
		a6.sin6_addr   = in6addr_any;
		a6.sin6_port   = htons((unsigned short) port);
		if (bind(fd, (struct sockaddr *) &a6, sizeof(a6)) != 0) {
			log_err("bind([::]:%d) failed: %s", port, strerror(errno));
			close(fd);
			return -1;
		}
	} else {
		/* A kernel booted with ipv6.disable=1 fails at socket(), not at
		 * bind. The console port is R1, so it is worth one fallback rather
		 * than a dead node. */
		log_wrn("socket(AF_INET6) failed (%s); falling back to IPv4 only",
		        strerror(errno));
		fd = socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, 0);
		if (fd < 0) {
			log_errno("socket(AF_INET)");
			return -1;
		}
		if (setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &on, sizeof(on)) != 0) {
			log_errno("setsockopt(SO_REUSEADDR)");
			close(fd);
			return -1;
		}
		memset(&a4, 0, sizeof(a4));
		a4.sin_family      = AF_INET;
		a4.sin_addr.s_addr = htonl(INADDR_ANY);
		a4.sin_port        = htons((unsigned short) port);
		if (bind(fd, (struct sockaddr *) &a4, sizeof(a4)) != 0) {
			log_err("bind(0.0.0.0:%d) failed: %s", port, strerror(errno));
			close(fd);
			return -1;
		}
	}

	if (set_nonblock(fd) != 0) {
		log_errno("fcntl(O_NONBLOCK) on the listening socket");
		close(fd);
		return -1;
	}

	if (listen(fd, 5) != 0) {
		log_err("listen(:%d) failed: %s", port, strerror(errno));
		close(fd);
		return -1;
	}

	c->listen_fd = fd;
	/* R1: from this line on, `netstat -a -t -n | grep LISTEN | grep ':<port>'`
	 * matches and getNodeStatus() reports the node as running. */
	log_inf("console listening on port %d (fd %d)", port, fd);
	return 0;
}

static void client_free_queue(console_client_t *cl)
{
	free(cl->queue);
	cl->queue = NULL;
	cl->qlen = cl->qoff = cl->qcap = 0;
}

/*
 * Remove one viewer. The registry is compacted by moving the last entry into the
 * freed slot, so callers iterating over it must NOT advance their index after a
 * drop — every loop below re-examines the same slot.
 */
static void client_drop(console_t *c, int slot, const char *why)
{
	console_client_t *cl = &c->clients[slot];

	log_inf("console client on fd %d closed (%s)", cl->fd, why);
	close(cl->fd);
	client_free_queue(cl);

	c->nclients--;
	if (slot != c->nclients)
		c->clients[slot] = c->clients[c->nclients];
	memset(&c->clients[c->nclients], 0, sizeof(c->clients[c->nclients]));
	c->clients[c->nclients].fd = -1;
}

void console_drop_all_clients(console_t *c)
{
	int i;

	/* §10.3 #2: the original's SIGHUP loop increments where it should
	 * decrement, walks off the end of the array and closes whatever
	 * descriptors happen to be there — including, eventually, the listening
	 * socket and the child's pipe. Close each registered client exactly once
	 * and zero the count. */
	for (i = 0; i < c->nclients; i++) {
		close(c->clients[i].fd);
		client_free_queue(&c->clients[i]);
	}
	if (c->nclients > 0)
		log_inf("dropped %d console client(s)", c->nclients);
	c->nclients = 0;
}

void console_close(console_t *c)
{
	console_drop_all_clients(c);
	if (c->listen_fd >= 0) {
		close(c->listen_fd);
		c->listen_fd = -1;
	}
}

/* §2.1: the negotiation and the xterm title, as one write, before the client is
 * registered. The four WILL/DO pairs are unconditional and unanswered; a client
 * that dislikes them simply refuses locally. */
static void console_greet(console_t *c, int fd)
{
	unsigned char msg[16 + CONSOLE_TITLE_MAX + 8];
	size_t        n = 0;
	size_t        tlen = strlen(c->title);

	msg[n++] = TN_IAC; msg[n++] = TN_WILL; msg[n++] = TN_OPT_ECHO;
	msg[n++] = TN_IAC; msg[n++] = TN_WILL; msg[n++] = TN_OPT_SGA;
	msg[n++] = TN_IAC; msg[n++] = TN_WILL; msg[n++] = TN_OPT_BINARY;
	msg[n++] = TN_IAC; msg[n++] = TN_DO;   msg[n++] = TN_OPT_BINARY;

	/* OSC 0: set the window title. No NUL, no length prefix, BEL terminated. */
	msg[n++] = 0x1b; msg[n++] = ']'; msg[n++] = '0'; msg[n++] = ';';
	memcpy(msg + n, c->title, tlen);
	n += tlen;
	msg[n++] = 0x07;

	/* Best effort: a client that vanishes between accept() and here is dealt
	 * with by the first read instead. */
	if (send(fd, msg, n, MSG_NOSIGNAL) < 0)
		log_dbg("greeting fd %d failed: %s", fd, strerror(errno));
}

static void console_accept(console_t *c)
{
	int fd, on = 1;

	fd = accept4(c->listen_fd, NULL, NULL, SOCK_CLOEXEC);
	if (fd < 0) {
		if (errno != EAGAIN && errno != EWOULDBLOCK && errno != EINTR &&
		    errno != ECONNABORTED)
			log_wrn("accept failed: %s", strerror(errno));
		return;
	}

	if (c->nclients >= CONSOLE_MAX_CLIENTS) {
		log_wrn("refusing console client on fd %d: %d clients already connected",
		        fd, c->nclients);
		close(fd);
		return;
	}

	/*
	 * select() indexes an fd_set by descriptor number, so a descriptor at or
	 * above FD_SETSIZE cannot be watched — FD_SET on one corrupts memory past
	 * the end of the set. The 1023-client capacity in §2.1 is therefore not
	 * reachable in practice: the listener, the child pipes and stdio all
	 * consume numbers too. Refuse rather than smash the stack.
	 */
	if (fd >= FD_SETSIZE) {
		log_wrn("refusing console client: fd %d is beyond FD_SETSIZE (%d)",
		        fd, FD_SETSIZE);
		close(fd);
		return;
	}

	if (set_nonblock(fd) != 0) {
		log_wrn("could not set O_NONBLOCK on fd %d: %s", fd, strerror(errno));
		close(fd);
		return;
	}
	/* A console is interactive and its packets are tiny; Nagle just adds
	 * 40 ms to every keystroke echo. */
	if (setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &on, sizeof(on)) != 0)
		log_dbg("TCP_NODELAY on fd %d: %s", fd, strerror(errno));

	console_greet(c, fd);

	memset(&c->clients[c->nclients], 0, sizeof(c->clients[c->nclients]));
	c->clients[c->nclients].fd = fd;
	telnet_filter_init(&c->clients[c->nclients].filter);
	c->nclients++;

	log_inf("console client connected on fd %d (%d connected)", fd, c->nclients);
}

/* Returns 0 if the client is still usable, -1 if it must be dropped. */
static int client_queue(console_client_t *cl, const unsigned char *buf, size_t len)
{
	size_t pending = cl->qlen - cl->qoff;

	if (pending + len > CONSOLE_QUEUE_MAX)
		return -1;

	/* Reclaim the consumed prefix before growing. */
	if (cl->qoff > 0) {
		memmove(cl->queue, cl->queue + cl->qoff, pending);
		cl->qlen = pending;
		cl->qoff = 0;
	}

	if (cl->qlen + len > cl->qcap) {
		size_t         ncap = (cl->qcap == 0) ? 4096 : cl->qcap;
		unsigned char *nq;

		while (ncap < cl->qlen + len)
			ncap *= 2;
		nq = realloc(cl->queue, ncap);
		if (nq == NULL)
			return -1;
		cl->queue = nq;
		cl->qcap  = ncap;
	}

	memcpy(cl->queue + cl->qlen, buf, len);
	cl->qlen += len;
	return 0;
}

void console_broadcast(console_t *c, const void *buf, size_t len)
{
	int i = 0;

	if (len == 0)
		return;

	while (i < c->nclients) {
		console_client_t *cl = &c->clients[i];
		const unsigned char *p = buf;
		size_t remaining = len;
		int    drop = 0;

		/* Anything already queued must go first or the stream reorders. */
		if (cl->qoff < cl->qlen) {
			if (client_queue(cl, p, remaining) != 0)
				drop = 1;
			remaining = 0;
		}

		while (!drop && remaining > 0) {
			ssize_t w = send(cl->fd, p, remaining, MSG_NOSIGNAL);

			if (w > 0) {
				p += w;
				remaining -= (size_t) w;
				continue;
			}
			if (w < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) {
				if (client_queue(cl, p, remaining) != 0)
					drop = 1;
				remaining = 0;
				break;
			}
			if (w < 0 && errno == EINTR)
				continue;
			/* §2.1: a negative send closes that client. */
			drop = 1;
		}

		if (drop)
			client_drop(c, i, "send failed or backlog exceeded");
		else
			i++;   /* only advance when the slot was not reused */
	}
}

/* Push whatever is queued for one client. Returns -1 if it must be dropped. */
static int client_drain(console_client_t *cl)
{
	while (cl->qoff < cl->qlen) {
		ssize_t w = send(cl->fd, cl->queue + cl->qoff, cl->qlen - cl->qoff,
		                 MSG_NOSIGNAL);

		if (w > 0) {
			cl->qoff += (size_t) w;
			continue;
		}
		if (w < 0 && errno == EINTR)
			continue;
		if (w < 0 && (errno == EAGAIN || errno == EWOULDBLOCK))
			return 0;
		return -1;
	}

	cl->qoff = cl->qlen = 0;
	return 0;
}

int console_prepare(const console_t *c, fd_set *rfds, fd_set *wfds)
{
	int max = -1, i;

	if (c->listen_fd >= 0) {
		FD_SET(c->listen_fd, rfds);
		max = c->listen_fd;
	}

	for (i = 0; i < c->nclients; i++) {
		int fd = c->clients[i].fd;

		FD_SET(fd, rfds);
		if (c->clients[i].qoff < c->clients[i].qlen)
			FD_SET(fd, wfds);
		if (fd > max)
			max = fd;
	}

	return max;
}

size_t console_service(console_t *c, const fd_set *rfds, const fd_set *wfds,
                       unsigned char *buf, size_t buflen)
{
	size_t used = 0;
	int    i = 0;

	/* Stalled viewers first: draining them is what lets the queue shrink. */
	while (i < c->nclients) {
		if (FD_ISSET(c->clients[i].fd, wfds) && client_drain(&c->clients[i]) != 0)
			client_drop(c, i, "write failed while draining backlog");
		else
			i++;
	}

	if (c->listen_fd >= 0 && FD_ISSET(c->listen_fd, rfds))
		console_accept(c);

	i = 0;
	while (i < c->nclients) {
		console_client_t *cl = &c->clients[i];
		unsigned char     raw[4096];
		size_t            want;
		ssize_t           n;

		if (!FD_ISSET(cl->fd, rfds) || used >= buflen) {
			i++;
			continue;
		}

		/* The filter never expands its input, so reading at most the room
		 * left in buf guarantees the write below fits. Reading more and
		 * then truncating would silently eat keystrokes. */
		want = buflen - used;
		if (want > sizeof(raw))
			want = sizeof(raw);

		n = recv(cl->fd, raw, want, 0);
		if (n == 0) {
			client_drop(c, i, "peer closed");
			continue;
		}
		if (n < 0) {
			if (errno == EAGAIN || errno == EWOULDBLOCK || errno == EINTR) {
				i++;
				continue;
			}
			client_drop(c, i, strerror(errno));
			continue;
		}

		used += telnet_filter(&cl->filter, raw, (size_t) n, buf + used);
		i++;
	}

	return used;
}
