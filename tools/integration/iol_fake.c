/*
 * iol_fake — a stand-in for a Cisco IOL image, so iol_wrapper's data plane can
 * be exercised without one.
 *
 * IOL images are licensed Cisco binaries. This project deliberately ships none
 * and the reference appliance has none, so `iol_wrapper` is the one wrapper that
 * cannot be verified against the real thing here. This program is what closes as
 * much of that gap as can be closed: it plays the part of the IOL process on the
 * AF_UNIX bus, so every byte of iol_wrapper's forwarding — bus to TAP, TAP to
 * bus, bus to UDP, UDP to bus — is a real syscall against a real socket rather
 * than a mock.
 *
 * It is deliberately NOT built from platform/wrappers/src/iol.h. It encodes the
 * frame layout from the specification independently, so that a wrong constant in
 * iol.c cannot cancel itself out against the same wrong constant in the test.
 *
 * What it does, in the same shape a real IOL instance would:
 *
 *   - takes the argument list iol_wrapper builds — `-e <n> -s <m> ...` with the
 *     instance id as the trailing positional word;
 *   - binds /tmp/netio<uid>/<instance> as an AF_UNIX datagram socket and
 *     connects to /tmp/netio<uid>/<instance+512>, which is the wrapper;
 *   - reads NETMAP out of the cwd and reports its first line, which proves the
 *     wrapper wrote it where a real IOL would look;
 *   - uses stdin and stdout as its console, which is what makes it drivable from
 *     the telnet port the wrapper serves.
 *
 * Console commands, one per line:
 *
 *   inject <interface> <text>   emit a broadcast Ethernet frame from that
 *                               interface, carrying <text> after the 14-byte
 *                               Ethernet header. Where it comes out — a TAP or a
 *                               UDP tunnel — is the wrapper's decision, and the
 *                               thing under test.
 *   banner                      reprint the startup lines. A console has no
 *                               scrollback, so a client that connects after the
 *                               node booted has missed them — same as a real
 *                               node, and the reason this command exists rather
 *                               than the harness racing the fork.
 *   stat                        print the counters.
 *   quit                        exit, so the "wrapper follows its child out"
 *                               path can be tested.
 *
 * Everything it receives on the bus is printed to the console as one RX line, so
 * the test harness can assert on frames the wrapper delivered inwards without
 * needing any privilege of its own.
 */
#define _GNU_SOURCE

#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/select.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <sys/un.h>
#include <unistd.h>

#define HDR       8
#define ETH_HDR   14
#define FRAME_MAX 10000
#define PSEUDO    512

static int         g_dev  = -1;
static int         g_bus  = -1;
static int         g_peer = -1;
static char        g_local[128];
static char        g_peer_path[128];
static char        g_netmap[128];
static int         g_eth = -1, g_ser = -1;
static unsigned long g_rx, g_rx_tagged, g_tx;

/*
 * Ethertype 0x88b5 is IEEE's local-experimental type. Every frame this program
 * injects carries it, and the harness's inbound frames do too, so counting
 * frames that carry it separates "traffic the test generated" from "traffic the
 * host generated" — a Linux bridge floods its own IPv6 multicast to every port,
 * and those frames arrive on the node's TAP exactly like a lab frame would.
 * Without the distinction, a count assertion here measures the host's mood.
 */
#define TEST_ETHERTYPE_HI 0x88
#define TEST_ETHERTYPE_LO 0xb5

static void put16(unsigned char *p, int v)
{
	p[0] = (unsigned char) ((v >> 8) & 0xff);
	p[1] = (unsigned char) (v & 0xff);
}

static int get16(const unsigned char *p)
{
	return (p[0] << 8) | p[1];
}

static int unix_addr(struct sockaddr_un *sa, const char *path)
{
	memset(sa, 0, sizeof(*sa));
	sa->sun_family = AF_UNIX;
	if (strlen(path) >= sizeof(sa->sun_path))
		return -1;
	memcpy(sa->sun_path, path, strlen(path));
	return 0;
}

static int peer_connect(void)
{
	struct sockaddr_un sa;

	if (g_peer >= 0)
		return 0;
	if (unix_addr(&sa, g_peer_path) != 0)
		return -1;
	g_peer = socket(AF_UNIX, SOCK_DGRAM, 0);
	if (g_peer < 0)
		return -1;
	if (connect(g_peer, (struct sockaddr *) &sa, sizeof(sa)) != 0) {
		close(g_peer);
		g_peer = -1;
		return -1;
	}
	return 0;
}

/* One printable rendering of the payload past the Ethernet header, so the shell
 * harness can grep for a marker it chose. */
static void printable(const unsigned char *p, size_t len, char *out, size_t cap)
{
	size_t i, n = 0;

	for (i = 0; i < len && n + 2 < cap; i++)
		out[n++] = (p[i] >= 0x20 && p[i] < 0x7f) ? (char) p[i] : '.';
	out[n] = '\0';
}

static void do_inject(int ifnum, const char *text)
{
	unsigned char frame[FRAME_MAX];
	size_t        tlen = strlen(text);
	size_t        len;

	if (tlen > sizeof(frame) - HDR - ETH_HDR)
		tlen = sizeof(frame) - HDR - ETH_HDR;

	/*
	 * The bus header, from the specification: destination instance, source
	 * instance, destination interface, source interface. We are the real
	 * instance, so this frame is addressed to the pseudo-instance the wrapper
	 * impersonates, and NETMAP wires interface i to interface i.
	 */
	put16(frame + 0, g_dev + PSEUDO);
	put16(frame + 2, g_dev);
	frame[4] = (unsigned char) ifnum;
	frame[5] = (unsigned char) ifnum;
	frame[6] = 0;
	frame[7] = 0;

	/* A broadcast Ethernet frame, so a Linux bridge floods it to every other
	 * port and the harness's probe interface sees it. Ethertype 0x88b5 is the
	 * IEEE local-experimental type, which nothing else on the host uses. */
	memset(frame + HDR, 0xff, 6);
	frame[HDR + 6]  = 0x02;
	frame[HDR + 7]  = 0x00;
	frame[HDR + 8]  = 0x00;
	frame[HDR + 9]  = 0x00;
	frame[HDR + 10] = 0x00;
	frame[HDR + 11] = (unsigned char) ifnum;
	frame[HDR + 12] = 0x88;
	frame[HDR + 13] = 0xb5;
	memcpy(frame + HDR + ETH_HDR, text, tlen);

	len = HDR + ETH_HDR + tlen;

	if (peer_connect() != 0) {
		printf("TXFAIL if=%d: no connection to %s\n", ifnum, g_peer_path);
		return;
	}
	if (send(g_peer, frame, len, 0) < 0) {
		printf("TXFAIL if=%d: %s\n", ifnum, strerror(errno));
		close(g_peer);
		g_peer = -1;
		return;
	}
	g_tx++;
	printf("TX if=%d len=%zu\n", ifnum, len);
}

/*
 * Printed at startup and again on demand. The console has no scrollback: a
 * telnet client that connects after the node has booted sees nothing that was
 * said before it arrived, exactly as with a real node. The harness therefore
 * asks for this rather than racing the startup.
 */
static void do_banner(void)
{
	printf("NETMAP first line: %s\n", g_netmap);
	printf("FAKEIOL ready dev=%d eth=%d ser=%d bus=%s peer=%s\n",
	       g_dev, g_eth, g_ser, g_local, g_peer_path);
}

static void do_console_line(char *line)
{
	char *cmd = strtok(line, " \t\r\n");

	if (cmd == NULL)
		return;

	if (strcmp(cmd, "inject") == 0) {
		char *a = strtok(NULL, " \t\r\n");
		char *b = strtok(NULL, "\r\n");

		if (a == NULL || b == NULL) {
			printf("ERR usage: inject <interface> <text>\n");
			return;
		}
		do_inject(atoi(a), b);
		return;
	}
	if (strcmp(cmd, "banner") == 0) {
		do_banner();
		return;
	}
	if (strcmp(cmd, "stat") == 0) {
		/* rx counts everything that arrived on the bus; rxtagged counts only
		 * the frames the test itself generated. Assert on the second. */
		printf("STAT rx=%lu rxtagged=%lu tx=%lu dev=%d\n",
		       g_rx, g_rx_tagged, g_tx, g_dev);
		return;
	}
	if (strcmp(cmd, "quit") == 0) {
		printf("BYE\n");
		exit(0);
	}
	printf("ERR unknown command '%s'\n", cmd);
}

static void do_bus_rx(void)
{
	unsigned char buf[FRAME_MAX];
	char          text[256];
	ssize_t       n;

	n = recv(g_bus, buf, sizeof(buf), 0);
	if (n < 0)
		return;
	if (n < HDR) {
		printf("RXSHORT len=%zd\n", n);
		return;
	}

	g_rx++;
	if ((size_t) n >= HDR + ETH_HDR) {
		printable(buf + HDR + ETH_HDR, (size_t) n - HDR - ETH_HDR,
		          text, sizeof(text));
		if (buf[HDR + 12] == TEST_ETHERTYPE_HI &&
		    buf[HDR + 13] == TEST_ETHERTYPE_LO)
			g_rx_tagged++;
	} else {
		text[0] = '\0';
	}

	/* The ethertype is in the line so that a stray frame identifies itself in
	 * the test output: 86dd is the host's own IPv6 multicast arriving over the
	 * bridge, not anything the wrapper invented. */
	printf("RX dst=%d src=%d dstif=%d srcif=%d type=%02x%02x len=%zd data=%s\n",
	       get16(buf), get16(buf + 2), buf[4], buf[5],
	       ((size_t) n >= HDR + ETH_HDR) ? buf[HDR + 12] : 0,
	       ((size_t) n >= HDR + ETH_HDR) ? buf[HDR + 13] : 0, n, text);
}

int main(int argc, char *argv[])
{
	struct sockaddr_un sa;
	/* "/tmp/netio<uid>" — 21 characters at the very most. Sized well under the
	 * destinations below so the compiler can see the concatenation always fits;
	 * at 128 it could not, and -Werror=format-truncation refused the build. */
	char               dir[64];
	int                i;
	FILE              *nm;
	/* Console input is reassembled into lines here rather than by stdio; see
	 * the read() in the loop below. */
	char               line[512];
	int                linelen = 0;

	setvbuf(stdout, NULL, _IOLBF, 0);

	/* iol_wrapper builds `<image> -e <n> -s <m> <caller's tail> <instance>`,
	 * so the instance id is the last word and everything else is noise to us. */
	for (i = 1; i < argc; i++) {
		if (strcmp(argv[i], "-e") == 0 && i + 1 < argc)
			g_eth = atoi(argv[++i]);
		else if (strcmp(argv[i], "-s") == 0 && i + 1 < argc)
			g_ser = atoi(argv[++i]);
	}
	if (argc > 1)
		g_dev = atoi(argv[argc - 1]);

	if (g_dev <= 0) {
		printf("FATAL no instance id in the argument list\n");
		return 1;
	}

	snprintf(dir, sizeof(dir), "/tmp/netio%u", (unsigned) getuid());
	snprintf(g_local, sizeof(g_local), "%s/%d", dir, g_dev);
	snprintf(g_peer_path, sizeof(g_peer_path), "%s/%d", dir, g_dev + PSEUDO);

	/* Both are bound as AF_UNIX paths, and sun_path is 108 bytes with no
	 * termination guarantee. Fitting the buffer is not the same as fitting the
	 * socket, so check the limit that actually applies. */
	if (strlen(g_local) >= sizeof(((struct sockaddr_un *) 0)->sun_path) ||
	    strlen(g_peer_path) >= sizeof(((struct sockaddr_un *) 0)->sun_path)) {
		printf("FATAL socket path too long for sun_path\n");
		return 1;
	}

	/* The wrapper creates this; a real IOL would find it already there. */
	if (mkdir(dir, 0755) != 0 && errno != EEXIST) {
		printf("FATAL mkdir(%s): %s\n", dir, strerror(errno));
		return 1;
	}
	(void) unlink(g_local);

	if (unix_addr(&sa, g_local) != 0) {
		printf("FATAL socket path too long\n");
		return 1;
	}
	g_bus = socket(AF_UNIX, SOCK_DGRAM, 0);
	if (g_bus < 0 ||
	    bind(g_bus, (struct sockaddr *) &sa, sizeof(sa)) != 0) {
		printf("FATAL bind(%s): %s\n", g_local, strerror(errno));
		return 1;
	}

	/*
	 * NETMAP proves the wrapper wrote its wiring table into the cwd, which is
	 * where a real IOL reads it from — and the cwd is the node's running
	 * directory only because the wrapper never chdir()s (R2).
	 */
	nm = fopen("NETMAP", "r");
	if (nm != NULL) {
		if (fgets(g_netmap, sizeof(g_netmap), nm) == NULL)
			g_netmap[0] = '\0';
		g_netmap[strcspn(g_netmap, "\r\n")] = '\0';
		fclose(nm);
	} else {
		snprintf(g_netmap, sizeof(g_netmap), "MISSING: %s", strerror(errno));
	}

	(void) peer_connect();

	do_banner();

	for (;;) {
		fd_set rfds;
		int    maxfd = (g_bus > STDIN_FILENO) ? g_bus : STDIN_FILENO;

		FD_ZERO(&rfds);
		FD_SET(STDIN_FILENO, &rfds);
		FD_SET(g_bus, &rfds);

		if (select(maxfd + 1, &rfds, NULL, NULL, NULL) < 0) {
			if (errno == EINTR)
				continue;
			break;
		}

		if (FD_ISSET(STDIN_FILENO, &rfds)) {
			/*
			 * read(), not fgets(). stdio would happily pull two commands
			 * into its own buffer, hand back the first, and then select()
			 * would report nothing readable for the second — the console
			 * would appear to hang on every other command.
			 */
			char    chunk[512];
			ssize_t n = read(STDIN_FILENO, chunk, sizeof(chunk));
			ssize_t k;

			if (n <= 0)
				break;   /* the wrapper closed our console */
			for (k = 0; k < n; k++) {
				if (chunk[k] == '\n' || chunk[k] == '\r') {
					line[linelen] = '\0';
					if (linelen > 0)
						do_console_line(line);
					linelen = 0;
				} else if (linelen < (int) sizeof(line) - 1) {
					line[linelen++] = chunk[k];
				}
			}
		}
		if (FD_ISSET(g_bus, &rfds))
			do_bus_rx();
	}

	(void) unlink(g_local);
	return 0;
}
