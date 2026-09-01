/*
 * iol_wrapper — WRAPPER-SPEC §5.
 *
 * A console server (§2, shared with the other wrappers) PLUS the entire data
 * plane for a Cisco IOL/IOU node. IOL only knows how to talk to other IOL
 * instances, over unix datagram sockets in a per-user directory, using a wiring
 * table it reads from a file called NETMAP. It has no idea what a Linux bridge
 * is and no idea what a UDP tunnel is. This program is the adapter.
 *
 * The trick, and it is the whole design: the wrapper writes a NETMAP that wires
 * every interface of the real IOL instance to the same interface number of a
 * SECOND instance numbered <device id> + 512 — and then impersonates that second
 * instance on IOL's own bus. Every frame the node emits therefore lands in this
 * process, which looks at the source interface, and either
 *
 *   - writes it to the TAP device for that interface, if the port group is below
 *     the -e count (an Ethernet port, bridged into the lab by the PHP), or
 *   - rewraps it and sends it over UDP to the wrapper of the node at the other
 *     end of the link, if the port group is at or above -e (a serial port).
 *
 * and the same in reverse. That is §5.4 through §5.7 in three sentences.
 *
 * How it is invoked (devices/iol/device_iol.php::command()):
 *
 *   /opt/unetlab/wrappers/iol_wrapper -D <iol id> -S <session> -P <port>
 *       -t <name> -F <running path>/<image> -d <delay> -e <eth> -s <ser>
 *       [-l <map>]... -- -n <nvram> -q -m <ram> [-c startup-config]
 *       > <running path>/wrapper.txt
 *
 * with `2>&1 &` appended by device::start(), cwd already set to the node's
 * running directory, no environment set at all, and — uniquely among the
 * wrappers — running as the per-node tenant account `unl<session>`
 * (uid 32768 + session), because device_iol::prepare() drops privileges before
 * the exec. That uid is load-bearing: it names the AF_UNIX directory, and IOL
 * inherits it by being our child, which is the only reason the two of them find
 * each other (R10).
 *
 * CLEAN ROOM. Written from the behavioural specification and from this
 * repository's PHP. No upstream wrapper source or binary was read. See
 * README.md before extending it.
 *
 * WHAT IS AND IS NOT PROVEN. Everything below the console is unit-tested
 * (wrapper_test.c) and exercised end to end against a stand-in IOL
 * (tools/integration/iol-dataplane.sh). It has never been run against a real
 * Cisco IOL image, because this project ships none and the reference appliance
 * has none. The frame layouts come from the specification, not from observation.
 * Treat "IOL nodes work" as unproven until someone with a licensed image says
 * otherwise.
 */
#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <getopt.h>
#include <limits.h>
#include <linux/if_tun.h>
#include <net/if.h>
#include <netdb.h>
#include <netinet/in.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/ioctl.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <sys/un.h>
#include <unistd.h>

#include "child.h"
#include "cmdline.h"
#include "console.h"
#include "iol.h"
#include "log.h"
#include "loop.h"
#include "version.h"

#define DEFAULT_TITLE "Terminal Server"

/* §5.2 defaults. -e and -s default to 2 port groups, i.e. 8 interfaces each. */
#define DEFAULT_ETH 2
#define DEFAULT_SER 2

/* §5.2 validation 4: -e + -s must not exceed the 16 port groups an interface
 * byte's low nibble can address. */
#define IOL_MAX_TOTAL_GROUPS 16

/*
 * How many datagrams one readable descriptor may yield before the loop moves
 * on. Without a cap a busy link starves the console — and the console is what
 * the operator is looking at when they decide the node is broken. With one, a
 * descriptor that still has data simply shows up in the next select(), which
 * costs one syscall.
 */
#define IOL_RX_BURST 64

/* ------------------------------------------------------------------ usage */

void iol_usage(const char *progname)
{
	printf("Usage: %s -D <id> -P <port> -F <image> [options] -- <iol args...>\n"
	       "       %s -v\n"
	       "\n"
	       "  -D <id>       IOL instance id, 1-%d. Mandatory. Also names the\n"
	       "                AF_UNIX sockets and the NETMAP entries.\n"
	       "  -P <port>     TCP port for the console AND UDP port for the\n"
	       "                serial data plane. Mandatory.\n"
	       "  -F <image>    IOL image to run. Must exist. Mandatory.\n"
	       "  -S <session>  Node session id; names the TAP interfaces\n"
	       "                (vunl<session>_<interface>). Without it no TAP is\n"
	       "                opened and Ethernet ports are inert.\n"
	       "  -T <tenant>   Tenant id, 0-255. Default 0, which is what the\n"
	       "                fork always runs with.\n"
	       "  -t <title>    Console window title. Default \"%s\".\n"
	       "  -d <seconds>  Wait this long before starting IOL, printing one\n"
	       "                dot per second to the console. Default 0.\n"
	       "  -e <groups>   Ethernet port groups. Default %d.\n"
	       "  -s <groups>   Serial port groups. Default %d. -e + -s <= %d.\n"
	       "  -l <map>      Serial link, repeatable, as\n"
	       "                <local if>:<host>:<remote id>:<remote if>:<port>.\n"
	       "                Order does not matter: maps are parsed after all\n"
	       "                other options.\n"
	       "  -v            Print the version and exit.\n"
	       "\n"
	       "Everything after -- is passed to IOL, between its -e/-s flags and\n"
	       "the trailing instance number. NETMAP is written into the current\n"
	       "directory, which must be the node's running directory.\n",
	       progname, progname, IOL_PSEUDO_OFFSET, DEFAULT_TITLE,
	       DEFAULT_ETH, DEFAULT_SER, IOL_MAX_TOTAL_GROUPS);
}

/* ------------------------------------------------------------ option parsing */

/* strtol with the checks that matter: fully numeric, non-negative, in range. */
static int parse_nonneg(const char *s, int *out)
{
	char *end = NULL;
	long  v;

	if (s == NULL || *s == '\0')
		return -1;

	errno = 0;
	v = strtol(s, &end, 10);
	if (errno != 0 || end == s || *end != '\0' || v < 0 || v > INT_MAX)
		return -1;

	*out = (int) v;
	return 0;
}

/* As above but for one colon-delimited field of a -l map, which is not
 * NUL-terminated. */
static int parse_field(const char *s, size_t len, int *out)
{
	char tmp[32];

	if (len == 0 || len >= sizeof(tmp))
		return -1;
	memcpy(tmp, s, len);
	tmp[len] = '\0';
	return parse_nonneg(tmp, out);
}

int iol_link_parse(const char *spec, int eth, int ser, iol_link_t *out)
{
	const char *f[5];
	size_t      flen[5];
	const char *p = spec;
	int         i, groups;

	memset(out, 0, sizeof(*out));
	out->fd = -1;

	if (spec == NULL || *spec == '\0') {
		log_err("-l: empty link map");
		return -1;
	}

	/*
	 * Exactly five colon-separated fields. A sixth (or a colon inside what
	 * should be a host name) is rejected rather than ignored: an IPv6 literal
	 * would parse as several fields here, and silently mis-wiring a link is
	 * worse than refusing to start. The PHP only ever emits "localhost".
	 */
	for (i = 0; i < 5; i++) {
		const char *c = strchr(p, ':');

		f[i] = p;
		if (i < 4) {
			if (c == NULL) {
				log_err("-l '%s': expected 5 colon-separated fields, "
				        "found %d", spec, i + 1);
				return -1;
			}
			flen[i] = (size_t) (c - p);
			p = c + 1;
		} else {
			if (c != NULL) {
				log_err("-l '%s': more than 5 colon-separated fields "
				        "(an IPv6 literal host is not supported)", spec);
				return -1;
			}
			flen[i] = strlen(p);
		}
	}

	/*
	 * Field 1, the local interface. THIS IS THE INDEX. links[] is
	 * IOL_MAX_IFACES long and is indexed by this value at every frame that
	 * comes off the bus, so it is bounded here, once, at parse time.
	 */
	if (parse_field(f[0], flen[0], &out->local_if) != 0 ||
	    out->local_if >= IOL_MAX_IFACES) {
		log_err("-l '%s': local interface must be 0..%d", spec,
		        IOL_MAX_IFACES - 1);
		return -1;
	}

	/* Field 2, the host. "localhost" in every map the fork emits. */
	if (flen[1] == 0 || flen[1] >= sizeof(out->host)) {
		log_err("-l '%s': host name is empty or too long", spec);
		return -1;
	}
	memcpy(out->host, f[1], flen[1]);
	out->host[flen[1]] = '\0';

	/* Field 3, the remote IOL instance id. Goes into a 16-bit header field. */
	if (parse_field(f[2], flen[2], &out->remote_dev) != 0 ||
	    out->remote_dev < 1 || out->remote_dev > 65535) {
		log_err("-l '%s': remote device id must be 1..65535 (a null id here "
		        "usually means the far node is not an IOL node)", spec);
		return -1;
	}

	/* Field 4, the remote interface. Written into a header byte, so it has to
	 * fit in one — and in the far end's interface space. */
	if (parse_field(f[3], flen[3], &out->remote_if) != 0 ||
	    out->remote_if >= IOL_MAX_IFACES) {
		log_err("-l '%s': remote interface must be 0..%d", spec,
		        IOL_MAX_IFACES - 1);
		return -1;
	}

	/* Field 5, the remote node's console port — which is also its data-plane
	 * UDP port. See the comment above iol_udp_open(). */
	if (parse_field(f[4], flen[4], &out->remote_port) != 0 ||
	    out->remote_port < 1 || out->remote_port > 65535) {
		log_err("-l '%s': remote port must be 1..65535", spec);
		return -1;
	}

	/*
	 * Cross-check against the port-group counts. The fork emits one -l per
	 * connected SERIAL interface, and a map naming an Ethernet port would
	 * never be consulted — iol_if_kind() sends those frames to a TAP. Warn
	 * rather than fail: the link is inert, not dangerous, and refusing to
	 * start the node over it would be a worse trade.
	 */
	groups = eth + ser;
	if (groups > 0) {
		int g = out->local_if % IOL_MAX_GROUPS;

		if (g < eth)
			log_wrn("-l '%s': local interface %d is in Ethernet group %d "
			        "(-e %d), so this map will never be used",
			        spec, out->local_if, g, eth);
		else if (g >= groups)
			log_wrn("-l '%s': local interface %d is in group %d, beyond "
			        "-e %d + -s %d", spec, out->local_if, g, eth, ser);
	}

	out->used = 1;
	return 0;
}

iol_parse_t iol_parse(int argc, char *const argv[], iol_opts_t *opts)
{
	/* §10.3 #5: the maps are collected here and parsed after the loop, so the
	 * argument order stops mattering and -e/-s are known when they are
	 * checked. The strings live in argv, which outlives us. */
	const char *raw_links[IOL_MAX_IFACES];
	int         nraw = 0;
	int         sep, opt_argc, c, i;

	memset(opts, 0, sizeof(*opts));
	opts->tenant   = 0;             /* §5.2: the fork relies on this default */
	opts->device   = -1;
	opts->session  = -1;
	opts->port     = -1;
	opts->delay    = 0;
	opts->eth      = DEFAULT_ETH;
	opts->ser      = DEFAULT_SER;
	opts->cmd_from = -1;
	snprintf(opts->title, sizeof(opts->title), "%s", DEFAULT_TITLE);
	for (i = 0; i < IOL_MAX_IFACES; i++)
		opts->links[i].fd = -1;

	/*
	 * Find "--" before getopt and hide the tail from it. This matters more
	 * here than anywhere else: IOL's own arguments are `-n <nvram> -q -m
	 * <ram> -c startup-config`, every one of which looks like an option we
	 * implement or nearly do. GNU getopt would happily eat them, and -c or -m
	 * would then be reported as unknown options rather than passed to IOL.
	 */
	sep = cmdline_find_separator(argc, argv);
	opt_argc = (sep < 0) ? argc : sep;
	if (sep >= 0 && sep + 1 < argc)
		opts->cmd_from = sep + 1;

	optind = 0;   /* glibc: zero means reinitialise, including the permutation
	               * bookkeeping, which 1 leaves behind */
	opterr = 0;   /* leading colon: we report errors, not getopt */

	while ((c = getopt(opt_argc, argv, ":vT:D:S:P:d:t:F:e:s:l:")) != -1) {
		switch (c) {
		case 'T':
			if (parse_nonneg(optarg, &opts->tenant) != 0 ||
			    opts->tenant > 255) {
				log_err("-T: '%s' is not a tenant id in 0..255 (it is one "
				        "byte of the UDP header)", optarg);
				return IOL_USAGE;
			}
			break;
		case 'D':
			if (parse_nonneg(optarg, &opts->device) != 0) {
				log_err("-D: '%s' is not a valid device id", optarg);
				return IOL_USAGE;
			}
			break;
		case 'S':
			if (parse_nonneg(optarg, &opts->session) != 0) {
				log_err("-S: '%s' is not a valid session id", optarg);
				return IOL_USAGE;
			}
			break;
		case 'P':
			if (parse_nonneg(optarg, &opts->port) != 0 ||
			    opts->port > 65535) {
				log_err("-P: '%s' is not a valid port number", optarg);
				return IOL_USAGE;
			}
			break;
		case 'd':
			if (parse_nonneg(optarg, &opts->delay) != 0) {
				log_err("-d: '%s' is not a valid number of seconds", optarg);
				return IOL_USAGE;
			}
			break;
		case 't':
			snprintf(opts->title, sizeof(opts->title), "%s", optarg);
			break;
		case 'F':
			if (optarg[0] == '\0' ||
			    (size_t) snprintf(opts->image, sizeof(opts->image), "%s",
			                      optarg) >= sizeof(opts->image)) {
				log_err("-F: image path is empty or too long");
				return IOL_USAGE;
			}
			break;
		case 'e':
			if (parse_nonneg(optarg, &opts->eth) != 0) {
				log_err("-e: '%s' is not a valid port-group count", optarg);
				return IOL_USAGE;
			}
			break;
		case 's':
			if (parse_nonneg(optarg, &opts->ser) != 0) {
				log_err("-s: '%s' is not a valid port-group count", optarg);
				return IOL_USAGE;
			}
			break;
		case 'l':
			if (nraw >= IOL_MAX_IFACES) {
				log_err("-l: more than %d link maps; a node has only %d "
				        "interfaces", IOL_MAX_IFACES, IOL_MAX_IFACES);
				return IOL_USAGE;
			}
			raw_links[nraw++] = optarg;
			break;
		case 'v':
			return IOL_VERSION;
		case ':':
			log_err("-%c requires an argument", optopt);
			return IOL_USAGE;
		default:
			log_err("unknown option -%c", optopt);
			return IOL_USAGE;
		}
	}

	if (optind < opt_argc) {
		log_err("unexpected argument '%s' (IOL's own arguments go after --)",
		        argv[optind]);
		return IOL_USAGE;
	}

	/* §5.2, validation in order. */
	if (opts->tenant < 0) {
		log_err("-T: tenant id must not be negative");
		return IOL_USAGE;
	}
	if (opts->device < 0) {
		log_err("-D is required: the IOL instance id names the bus sockets "
		        "and every NETMAP entry");
		return IOL_USAGE;
	}
	/*
	 * R9. device + 512 is the pseudo-instance this wrapper impersonates, so a
	 * device id above 512 would collide with another node's real id on the
	 * bus. device_iol.php's getIolId() caps a lab at 512 for exactly this
	 * reason; enforce the same bound here rather than trusting the caller.
	 */
	if (opts->device < 1 || opts->device > IOL_PSEUDO_OFFSET) {
		log_err("-D: device id %d is outside 1..%d — the wrapper impersonates "
		        "instance <id>+%d and a larger id would collide with a real "
		        "one", opts->device, IOL_PSEUDO_OFFSET, IOL_PSEUDO_OFFSET);
		return IOL_USAGE;
	}
	if (opts->image[0] == '\0') {
		log_err("-F is required: there is no default IOL image");
		return IOL_USAGE;
	}
	if (access(opts->image, F_OK) != 0) {
		log_err("-F '%s': %s", opts->image, strerror(errno));
		return IOL_USAGE;
	}
	if (access(opts->image, X_OK) != 0)
		log_wrn("-F '%s' is not executable by uid %u; IOL will not start",
		        opts->image, (unsigned) geteuid());
	if (opts->eth + opts->ser > IOL_MAX_TOTAL_GROUPS) {
		log_err("-e %d + -s %d exceeds the %d port groups an interface byte "
		        "can address", opts->eth, opts->ser, IOL_MAX_TOTAL_GROUPS);
		return IOL_USAGE;
	}

	/*
	 * §10.3 #6 in spirit: -P is not on the specification's mandatory list, but
	 * without it the console never LISTENs, and R1 says a node whose console
	 * port is not listening reads as stopped however healthy IOL is. Failing
	 * here names the missing argument; failing later names a bind on port
	 * 65535.
	 */
	if (opts->port < 0) {
		log_err("-P is required: it is both the console port (without which "
		        "the node can never read as running) and the data-plane UDP "
		        "port");
		return IOL_USAGE;
	}

	/* Now the deferred maps, with -e and -s known. */
	for (i = 0; i < nraw; i++) {
		iol_link_t l;

		if (iol_link_parse(raw_links[i], opts->eth, opts->ser, &l) != 0)
			return IOL_USAGE;

		if (opts->links[l.local_if].used)
			log_wrn("-l: interface %d is mapped more than once; the last map "
			        "wins", l.local_if);
		else
			opts->nlinks++;

		opts->links[l.local_if] = l;   /* index bounded in iol_link_parse */
	}

	return IOL_OK;
}

/* ----------------------------------------------------------------- NETMAP */

int iol_netmap_write(const char *path, int device)
{
	FILE *f;
	int   i;

	/*
	 * §5.4: remove any existing file first, and treat a failed removal as
	 * fatal. A stale NETMAP from a previous run of a DIFFERENT node would wire
	 * this node's interfaces to an instance that is not us, and the symptom
	 * would be a node whose links are all silently dead.
	 */
	if (unlink(path) != 0 && errno != ENOENT) {
		log_err("cannot remove the existing %s: %s", path, strerror(errno));
		return -1;
	}

	/* Append, per the specification. The file cannot exist at this point, so
	 * "a" and "w" behave identically; "a" is what is written down. */
	f = fopen(path, "a");
	if (f == NULL) {
		log_err("cannot write %s in the running directory: %s", path,
		        strerror(errno));
		return -1;
	}

	/*
	 * 64 lines: every interface of the real instance wired to the same
	 * interface number of the pseudo-instance this wrapper impersonates. All
	 * 64 are written whatever -e and -s say, because IOL reads this file
	 * before it knows how many interfaces it has been asked for, and an entry
	 * for an interface that does not exist is harmless.
	 */
	for (i = 0; i < IOL_MAX_IFACES; i++) {
		if (fprintf(f, "%d:%d %d:%d\n", device, i,
		            device + IOL_PSEUDO_OFFSET, i) < 0) {
			log_err("writing %s failed: %s", path, strerror(errno));
			fclose(f);
			return -1;
		}
	}

	if (fclose(f) != 0) {
		log_err("closing %s failed: %s", path, strerror(errno));
		return -1;
	}

	log_inf("wrote %s: interfaces 0-%d of instance %d wired to instance %d "
	        "(this wrapper)", path, IOL_MAX_IFACES - 1, device,
	        device + IOL_PSEUDO_OFFSET);
	return 0;
}

/* --------------------------------------------------------- frame handling */

iol_if_kind_t iol_if_kind(int eth, unsigned int ifbyte)
{
	unsigned int group;

	/*
	 * THE bounds check. Everything downstream — links[], tap_fd[] — is
	 * IOL_MAX_IFACES long and is indexed by this byte, and this byte arrives
	 * from a unix socket IOL wrote to, or is derived from one that arrived on
	 * a UDP socket bound to a wildcard address. A unit above 3 cannot exist
	 * (IOL has four interfaces per group), so anything >= 64 is nonsense.
	 */
	if (ifbyte >= IOL_MAX_IFACES)
		return IOL_IF_BAD;

	group = ifbyte % IOL_MAX_GROUPS;

	/* §5.6: below the -e count it is Ethernet, at or above it serial. A group
	 * beyond -e + -s is still "serial" and is dropped a moment later by the
	 * link lookup, which reports the interface number. */
	return (group < (unsigned int) eth) ? IOL_IF_ETH : IOL_IF_SER;
}

void iol_hdr_build(unsigned char *hdr, int dst_dev, int src_dev,
                   int dst_if, int src_if)
{
	hdr[0] = (unsigned char) ((dst_dev >> 8) & 0xff);
	hdr[1] = (unsigned char) (dst_dev & 0xff);
	hdr[2] = (unsigned char) ((src_dev >> 8) & 0xff);
	hdr[3] = (unsigned char) (src_dev & 0xff);
	hdr[4] = (unsigned char) (dst_if & 0xff);
	hdr[5] = (unsigned char) (src_if & 0xff);
	hdr[6] = 0;
	hdr[7] = 0;
}

int iol_hdr_src_if(const unsigned char *frame, size_t len)
{
	if (len < IOL_HDR_LEN)
		return -1;
	return frame[5];
}

int iol_hdr_dst_dev(const unsigned char *frame, size_t len)
{
	if (len < IOL_HDR_LEN)
		return -1;
	return (frame[0] << 8) | frame[1];
}

int iol_to_udp(unsigned char *frame, size_t len, int tenant, int mydev,
               const iol_link_t *link)
{
	int src_if;

	if (len < IOL_HDR_LEN)
		return -1;
	if (link == NULL || !link->used)
		return -1;

	/* Read the source interface out BEFORE overwriting the header: offset 5
	 * holds it on the bus and offset 7 on the wire, and the rewrite is in
	 * place. */
	src_if = frame[5];

	frame[0] = (unsigned char) tenant;
	frame[1] = (unsigned char) tenant;
	frame[2] = (unsigned char) ((link->remote_dev >> 8) & 0xff);
	frame[3] = (unsigned char) (link->remote_dev & 0xff);
	frame[4] = (unsigned char) ((mydev >> 8) & 0xff);
	frame[5] = (unsigned char) (mydev & 0xff);
	frame[6] = (unsigned char) link->remote_if;
	frame[7] = (unsigned char) src_if;
	return 0;
}

int iol_from_udp(const unsigned char *pkt, size_t len, int tenant, int mydev,
                 unsigned char *out, size_t outcap, size_t *outlen)
{
	int dst_dev, dst_if;

	/*
	 * §5.6: "frames that are too long, too short, carry the wrong tenant id at
	 * offset 0, or the wrong destination device id at offsets 2-3 are logged
	 * and dropped".
	 *
	 * This is the only place in the program where bytes from a socket bound to
	 * a wildcard address become an array index, so the order is deliberate:
	 * length first (so the reads below are in bounds), then identity, then the
	 * index itself.
	 */
	if (len < IOL_HDR_LEN)
		return IOL_UDP_ERR_SHORT;
	if (len > outcap || len > IOL_FRAME_MAX)
		return IOL_UDP_ERR_LONG;

	if (pkt[0] != (unsigned char) tenant)
		return IOL_UDP_ERR_TENANT;

	dst_dev = (pkt[2] << 8) | pkt[3];
	if (dst_dev != mydev)
		return IOL_UDP_ERR_DEVICE;

	dst_if = pkt[6];
	if (dst_if >= IOL_MAX_IFACES)
		return IOL_UDP_ERR_IFACE;

	/*
	 * Rebuild as a bus frame addressed to the real instance. Source is the
	 * pseudo-instance we impersonate, on the SAME interface number: NETMAP
	 * wires <device>:<i> to <device+512>:<i>, so from IOL's point of view a
	 * frame for its interface i can only have come from our interface i.
	 */
	memcpy(out + IOL_HDR_LEN, pkt + IOL_HDR_LEN, len - IOL_HDR_LEN);
	iol_hdr_build(out, mydev, mydev + IOL_PSEUDO_OFFSET, dst_if, dst_if);
	*outlen = len;
	return IOL_UDP_OK;
}

const char *iol_udp_strerror(int rc)
{
	switch (rc) {
	case IOL_UDP_OK:         return "ok";
	case IOL_UDP_ERR_SHORT:  return "shorter than a header";
	case IOL_UDP_ERR_LONG:   return "longer than the receive buffer";
	case IOL_UDP_ERR_TENANT: return "wrong tenant id";
	case IOL_UDP_ERR_DEVICE: return "not addressed to this node";
	case IOL_UDP_ERR_IFACE:  return "destination interface out of range";
	}
	return "unknown";
}

/* -------------------------------------------------------- child command */

int iol_sh_quote(const char *in, char *out, size_t outlen)
{
	size_t n = 0;

	if (in == NULL || outlen < 3)
		return -1;

	out[n++] = '\'';
	for (; *in != '\0'; in++) {
		if (*in == '\'') {
			/* close, escaped quote, reopen */
			if (n + 4 >= outlen)
				return -1;
			out[n++] = '\'';
			out[n++] = '\\';
			out[n++] = '\'';
			out[n++] = '\'';
		} else {
			if (n + 2 >= outlen)
				return -1;
			out[n++] = *in;
		}
	}
	out[n++] = '\'';
	out[n] = '\0';
	return 0;
}

int iol_build_command(const iol_opts_t *opts, int argc, char *const argv[],
                      cmdline_t *cmd)
{
	char quoted[PATH_MAX + 16];

	/*
	 * §5.3:
	 *
	 *   <image> -e <eth> -s <ser> <everything after --> <device id>
	 *
	 * The image path is the one word we compose rather than pass through, so
	 * it is the one word the PHP's escapeshellarg() has not already quoted by
	 * the time it reaches the shell — -F arrives unquoted from getopt. Quote
	 * it again on the way back out. In practice a running path never contains
	 * a space; "in practice" is not a guarantee, and the failure mode is a
	 * shell splitting a path into two arguments.
	 */
	if (iol_sh_quote(opts->image, quoted, sizeof(quoted)) != 0) {
		log_err("the image path does not fit after quoting");
		return -1;
	}

	if (cmdline_append(cmd, quoted) != 0)
		return -1;
	if (cmdline_appendf(cmd, " -e %d -s %d", opts->eth, opts->ser) != 0)
		return -1;

	/* The caller's `-n <nvram> -q -m <ram> [-c startup-config]`, still
	 * carrying the PHP's quoting, which /bin/sh undoes. */
	if (opts->cmd_from >= 0 &&
	    cmdline_append_tail(cmd, argc, argv, opts->cmd_from) != 0)
		return -1;

	/* IOL takes its instance number as the trailing positional argument. It
	 * must be last, after the caller's tail. */
	if (cmdline_appendf(cmd, " %d", opts->device) != 0)
		return -1;

	return 0;
}

#ifndef WRAPPER_NO_MAIN

/* ============================ runtime ==================================== */

typedef struct {
	iol_opts_t *o;

	uid_t uid;
	char  dir[128];
	char  local_path[128];   /* /tmp/netio<uid>/<device+512> — ours */
	char  peer_path[128];    /* /tmp/netio<uid>/<device>     — IOL's */

	int bus_fd;    /* AF_UNIX SOCK_DGRAM, bound to local_path */
	int peer_fd;   /* AF_UNIX SOCK_DGRAM, connected to peer_path */
	int peer_up;
	int udp_fd;    /* the data-plane listener, bound to the -P port */

	int tap_fd[IOL_MAX_IFACES];

	/* One warning per interface, not one per frame: an unmapped serial port on
	 * a busy link would otherwise fill wrapper.txt faster than the node boots. */
	unsigned char warned[IOL_MAX_IFACES];

	unsigned long rx_bus, rx_udp, rx_tap, dropped;

	unsigned char buf[IOL_FRAME_MAX];
	unsigned char out[IOL_FRAME_MAX];
} iol_state_t;

static void state_init(iol_state_t *st, iol_opts_t *o)
{
	int i;

	memset(st, 0, sizeof(*st));
	st->o       = o;
	st->bus_fd  = -1;
	st->peer_fd = -1;
	st->udp_fd  = -1;
	for (i = 0; i < IOL_MAX_IFACES; i++)
		st->tap_fd[i] = -1;
}

static int set_nonblock(int fd)
{
	int flags = fcntl(fd, F_GETFL, 0);

	if (flags < 0)
		return -1;
	return fcntl(fd, F_SETFL, flags | O_NONBLOCK);
}

/* ------------------------------------------------------------ AF_UNIX bus */

/*
 * R10. The directory name carries the wrapper's own uid, and IOL — which is our
 * child, and therefore runs as the same uid — builds the same name for itself.
 * device_iol::prepare() drops to `unl<session>` (uid 32768 + session) before
 * exec'ing us, so in production this is a per-node directory: one wrapper, one
 * IOL, two sockets, nothing else. If a future change stops dropping privileges,
 * every node's bus lands in /tmp/netio0 together and the ids stop being unique —
 * getIolId() only guarantees uniqueness within a lab.
 */
static int bus_paths(iol_state_t *st)
{
	st->uid = getuid();

	if ((size_t) snprintf(st->dir, sizeof(st->dir), "/tmp/netio%u",
	                      (unsigned) st->uid) >= sizeof(st->dir))
		return -1;
	if ((size_t) snprintf(st->local_path, sizeof(st->local_path), "%s/%d",
	                      st->dir, st->o->device + IOL_PSEUDO_OFFSET)
	    >= sizeof(st->local_path))
		return -1;
	if ((size_t) snprintf(st->peer_path, sizeof(st->peer_path), "%s/%d",
	                      st->dir, st->o->device) >= sizeof(st->peer_path))
		return -1;

	/* sun_path is 108 bytes and is not NUL-terminated by the kernel when it is
	 * exactly full. Both paths are ~25 bytes in production; check anyway. */
	if (strlen(st->local_path) >= sizeof(((struct sockaddr_un *) 0)->sun_path) ||
	    strlen(st->peer_path) >= sizeof(((struct sockaddr_un *) 0)->sun_path))
		return -1;

	return 0;
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

/* Connect (or reconnect) the sending half. IOL binds its socket when it starts,
 * which is after we do, so this is expected to fail on the first attempt and to
 * be retried from the forwarding paths. */
static int peer_connect(iol_state_t *st)
{
	struct sockaddr_un sa;

	if (st->peer_fd >= 0) {
		close(st->peer_fd);
		st->peer_fd = -1;
	}
	st->peer_up = 0;

	if (unix_addr(&sa, st->peer_path) != 0)
		return -1;

	/* SOCK_CLOEXEC throughout the data plane: IOL is our child and execs
	 * through /bin/sh, and a descriptor it inherits is one it can hold open
	 * after we are gone — including, for the bound socket below, the bus
	 * address the next run of this node needs. */
	st->peer_fd = socket(AF_UNIX, SOCK_DGRAM | SOCK_NONBLOCK | SOCK_CLOEXEC, 0);
	if (st->peer_fd < 0) {
		log_errno("socket(AF_UNIX, SOCK_DGRAM) for the IOL peer");
		return -1;
	}

	if (connect(st->peer_fd, (struct sockaddr *) &sa, sizeof(sa)) != 0) {
		/* ENOENT until IOL has bound its socket. Not worth an ERR: it is the
		 * normal state of affairs for the first second of a node's life. */
		log_dbg("connect(%s): %s", st->peer_path, strerror(errno));
		close(st->peer_fd);
		st->peer_fd = -1;
		return -1;
	}

	st->peer_up = 1;
	log_inf("connected to the IOL instance at %s", st->peer_path);
	return 0;
}

static int bus_open(iol_state_t *st)
{
	struct sockaddr_un sa;

	if (bus_paths(st) != 0) {
		log_err("the AF_UNIX bus paths do not fit in sun_path");
		return -1;
	}

	/* 0755 per §5.5. IOL binds its own socket in here, as the same uid. */
	if (mkdir(st->dir, 0755) != 0 && errno != EEXIST) {
		log_err("mkdir(%s): %s", st->dir, strerror(errno));
		return -1;
	}

	/* A socket left behind by a previous run of this node would make bind()
	 * fail with EADDRINUSE and take the whole data plane with it. */
	if (unlink(st->local_path) != 0 && errno != ENOENT)
		log_wrn("could not remove the stale socket %s: %s",
		        st->local_path, strerror(errno));

	if (unix_addr(&sa, st->local_path) != 0)
		return -1;

	st->bus_fd = socket(AF_UNIX, SOCK_DGRAM | SOCK_NONBLOCK | SOCK_CLOEXEC, 0);
	if (st->bus_fd < 0) {
		log_errno("socket(AF_UNIX, SOCK_DGRAM)");
		return -1;
	}
	if (bind(st->bus_fd, (struct sockaddr *) &sa, sizeof(sa)) != 0) {
		log_err("bind(%s): %s", st->local_path, strerror(errno));
		close(st->bus_fd);
		st->bus_fd = -1;
		return -1;
	}

	log_inf("AF_UNIX bus: listening as instance %d on %s, IOL expected at %s",
	        st->o->device + IOL_PSEUDO_OFFSET, st->local_path, st->peer_path);

	/* Expected to fail here; retried on demand. */
	(void) peer_connect(st);
	return 0;
}

static void bus_close(iol_state_t *st)
{
	if (st->bus_fd >= 0) {
		close(st->bus_fd);
		st->bus_fd = -1;
		/* Leave nothing behind in /tmp: the next run of this node would find
		 * it and have to clean it up, and a stale socket in a shared netio
		 * directory outlives the uid that owned it. */
		if (st->local_path[0] != '\0')
			(void) unlink(st->local_path);
	}
	if (st->peer_fd >= 0) {
		close(st->peer_fd);
		st->peer_fd = -1;
	}
	st->peer_up = 0;
}

/*
 * Hand one frame to IOL. §5.6: a failed write "is logged as 'will try to
 * recreate it later' and the socket pair is rebuilt on a later pass" — which is
 * what makes an IOL restart survivable without restarting the wrapper, and the
 * wrapper is what holds the console port.
 */
static void bus_send(iol_state_t *st, const unsigned char *frame, size_t len)
{
	ssize_t n;

	if (!st->peer_up && peer_connect(st) != 0) {
		st->dropped++;
		return;
	}

	n = send(st->peer_fd, frame, len, MSG_NOSIGNAL);
	if (n >= 0)
		return;

	if (errno == EAGAIN || errno == EWOULDBLOCK || errno == ENOBUFS) {
		/* IOL is not draining its socket. Dropping is what a real wire does
		 * to a congested link, and blocking here would stall the console. */
		log_dbg("IOL's socket is full; dropped a %zu byte frame", len);
		st->dropped++;
		return;
	}

	log_wrn("send to %s failed (%s); will try to recreate it later",
	        st->peer_path, strerror(errno));
	st->dropped++;
	close(st->peer_fd);
	st->peer_fd = -1;
	st->peer_up = 0;
}

/* ---------------------------------------------------------------- UDP */

/*
 * THE PORT QUESTION, settled from the PHP rather than from the specification.
 *
 * §5.6 says the data-plane UDP socket binds "the same port number as -P", the
 * console TCP port, and flags the worry that two IOL nodes on one host would
 * then collide. They do not, for two independent reasons:
 *
 *   1. Console ports are unique per NODE, not per lab. includes/__node.php
 *      allocates node_session_port = 30000 + node_session_id, where
 *      createNodeSession() picks an id not already present in the whole
 *      node_sessions table. Two IOL nodes never share a -P.
 *
 *   2. TCP and UDP are separate port spaces anyway, so even a wrapper listening
 *      on TCP 30001 and UDP 30001 is binding two unrelated things.
 *
 * And it is not merely safe, it is REQUIRED: devices/iol/device_iol.php:124
 * builds each -l map as
 *
 *     <local if>:localhost:<remote iol id>:<remote if>:<remote node's getPort()>
 *
 * — the fifth field is the far node's CONSOLE port. The only way that field can
 * find the far node's data plane is if every wrapper listens for data on its own
 * console port number. Binding anything else here breaks every serial link.
 */
static int udp_open(iol_state_t *st, int port)
{
	struct sockaddr_in6 a6;
	struct sockaddr_in  a4;
	int                 fd, on = 1, off = 0;

	fd = socket(AF_INET6, SOCK_DGRAM | SOCK_CLOEXEC, 0);
	if (fd >= 0) {
		if (setsockopt(fd, IPPROTO_IPV6, IPV6_V6ONLY, &off, sizeof(off)) != 0)
			log_wrn("could not clear IPV6_V6ONLY on the data plane: %s",
			        strerror(errno));
		if (setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &on, sizeof(on)) != 0)
			log_wrn("SO_REUSEADDR on the data plane: %s", strerror(errno));
		memset(&a6, 0, sizeof(a6));
		a6.sin6_family = AF_INET6;
		a6.sin6_addr   = in6addr_any;
		a6.sin6_port   = htons((unsigned short) port);
		if (bind(fd, (struct sockaddr *) &a6, sizeof(a6)) != 0) {
			log_err("bind(UDP [::]:%d): %s", port, strerror(errno));
			close(fd);
			return -1;
		}
	} else {
		log_wrn("socket(AF_INET6, SOCK_DGRAM) failed (%s); falling back to "
		        "IPv4", strerror(errno));
		fd = socket(AF_INET, SOCK_DGRAM | SOCK_CLOEXEC, 0);
		if (fd < 0) {
			log_errno("socket(AF_INET, SOCK_DGRAM)");
			return -1;
		}
		if (setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &on, sizeof(on)) != 0)
			log_wrn("SO_REUSEADDR on the data plane: %s", strerror(errno));
		memset(&a4, 0, sizeof(a4));
		a4.sin_family      = AF_INET;
		a4.sin_addr.s_addr = htonl(INADDR_ANY);
		a4.sin_port        = htons((unsigned short) port);
		if (bind(fd, (struct sockaddr *) &a4, sizeof(a4)) != 0) {
			log_err("bind(UDP 0.0.0.0:%d): %s", port, strerror(errno));
			close(fd);
			return -1;
		}
	}

	if (set_nonblock(fd) != 0) {
		log_errno("O_NONBLOCK on the data-plane socket");
		close(fd);
		return -1;
	}

	st->udp_fd = fd;
	log_inf("serial data plane listening on UDP port %d (fd %d)", port, fd);
	return 0;
}

/* One connected sending socket per link, created on first use — the far node's
 * wrapper may not have started yet, and there is nothing to wait for: a
 * connected UDP socket succeeds regardless and reports the problem on send. */
static int link_connect(iol_link_t *l)
{
	struct addrinfo  hints, *res = NULL, *ai;
	char             port[16];
	int              rc, fd = -1;

	memset(&hints, 0, sizeof(hints));
	hints.ai_family   = AF_UNSPEC;
	hints.ai_socktype = SOCK_DGRAM;
	snprintf(port, sizeof(port), "%d", l->remote_port);

	rc = getaddrinfo(l->host, port, &hints, &res);
	if (rc != 0) {
		log_wrn("cannot resolve %s:%d for interface %d: %s",
		        l->host, l->remote_port, l->local_if, gai_strerror(rc));
		return -1;
	}

	for (ai = res; ai != NULL; ai = ai->ai_next) {
		fd = socket(ai->ai_family,
		            ai->ai_socktype | SOCK_NONBLOCK | SOCK_CLOEXEC,
		            ai->ai_protocol);
		if (fd < 0)
			continue;
		if (connect(fd, ai->ai_addr, ai->ai_addrlen) == 0)
			break;
		close(fd);
		fd = -1;
	}
	freeaddrinfo(res);

	if (fd < 0) {
		log_wrn("cannot open a data-plane socket to %s:%d for interface %d",
		        l->host, l->remote_port, l->local_if);
		return -1;
	}

	l->fd = fd;
	log_inf("interface %d -> %s:%d (instance %d interface %d), fd %d",
	        l->local_if, l->host, l->remote_port, l->remote_dev, l->remote_if,
	        fd);
	return 0;
}

/* ---------------------------------------------------------------- TAP */

/*
 * §5.7. device_iol::prepare() has already created these with
 * `tunctl -u unl<session> -t vunl<session>_<if>` and attached them to the lab's
 * bridges, so they are persistent devices owned by our uid and all we do is
 * attach to one. An interface with no TAP is an interface the lab did not wire,
 * which is the normal case for most of the 64.
 */
static int tap_open(const char *name)
{
	struct ifreq ifr;
	char         probe[64 + IFNAMSIZ];
	int          fd, s;

	snprintf(probe, sizeof(probe), "/sys/class/net/%s/dev_id", name);
	if (access(probe, F_OK) != 0) {
		log_inf("no TAP device %s; interface left unwired", name);
		return -1;
	}

	fd = open("/dev/net/tun", O_RDWR | O_CLOEXEC);
	if (fd < 0) {
		log_wrn("open(/dev/net/tun) for %s: %s", name, strerror(errno));
		return -1;
	}

	memset(&ifr, 0, sizeof(ifr));
	/*
	 * IFF_NO_PI is not optional. Without it the kernel prepends a 4-byte
	 * tun_pi header to everything it hands us and expects one on everything we
	 * write, and the forwarding code here reads and writes bare Ethernet
	 * frames with the destination MAC at offset 0. The symptom of getting it
	 * wrong is a link that passes frames which are all four bytes wrong.
	 */
	ifr.ifr_flags = IFF_TAP | IFF_NO_PI;
	snprintf(ifr.ifr_name, IFNAMSIZ, "%s", name);

	if (ioctl(fd, TUNSETIFF, &ifr) != 0) {
		log_wrn("TUNSETIFF(%s): %s — interface left unwired", name,
		        strerror(errno));
		close(fd);
		return -1;
	}

	if (set_nonblock(fd) != 0) {
		log_wrn("O_NONBLOCK on %s: %s", name, strerror(errno));
		close(fd);
		return -1;
	}

	/* Bring it up. This needs CAP_NET_ADMIN, which the wrapper does not have
	 * once the PHP has dropped it to the tenant uid — so a failure here is
	 * expected and harmless whenever addTap() has already done it. */
	s = socket(AF_INET, SOCK_DGRAM, 0);
	if (s >= 0) {
		struct ifreq up;

		memset(&up, 0, sizeof(up));
		snprintf(up.ifr_name, IFNAMSIZ, "%s", name);
		if (ioctl(s, SIOCGIFFLAGS, &up) == 0) {
			if ((up.ifr_flags & IFF_UP) == 0) {
				up.ifr_flags |= IFF_UP | IFF_RUNNING;
				if (ioctl(s, SIOCSIFFLAGS, &up) != 0)
					log_wrn("could not bring %s up: %s (it may already be "
					        "up, or we may have no CAP_NET_ADMIN)",
					        name, strerror(errno));
			}
		}
		close(s);
	}

	log_inf("attached to TAP %s (fd %d)", name, fd);
	return fd;
}

static void taps_open(iol_state_t *st)
{
	int g, u, opened = 0;

	if (st->o->session < 0) {
		log_wrn("no -S given: TAP names cannot be built, so every Ethernet "
		        "interface is inert");
		return;
	}

	/*
	 * §5.7: port groups 0 .. -e-1, four units each, interface number
	 * 16*unit + group. The bound is structural — -e is at most 16 (validated)
	 * and unit at most 3 — so `ifnum` cannot leave the array. Assert it
	 * anyway: this loop and iol_if_kind() have to agree about the size of the
	 * interface space, and they are 200 lines apart.
	 */
	for (g = 0; g < st->o->eth && g < IOL_MAX_GROUPS; g++) {
		for (u = 0; u < IOL_UNITS_PER_GROUP; u++) {
			int  ifnum = IOL_MAX_GROUPS * u + g;
			char name[IFNAMSIZ];

			if (ifnum < 0 || ifnum >= IOL_MAX_IFACES)
				continue;
			if ((size_t) snprintf(name, sizeof(name), "vunl%d_%d",
			                      st->o->session, ifnum) >= sizeof(name)) {
				log_wrn("TAP name for session %d interface %d does not fit "
				        "in IFNAMSIZ", st->o->session, ifnum);
				continue;
			}

			st->tap_fd[ifnum] = tap_open(name);
			if (st->tap_fd[ifnum] >= 0)
				opened++;
		}
	}

	log_inf("%d TAP interface(s) attached", opened);
}

static void taps_close(iol_state_t *st)
{
	int i;

	for (i = 0; i < IOL_MAX_IFACES; i++) {
		if (st->tap_fd[i] >= 0) {
			close(st->tap_fd[i]);
			st->tap_fd[i] = -1;
		}
	}
}

/* ------------------------------------------------------------- forwarding */

/* IOL -> here. Classify by the source interface and send the payload out of the
 * right side of the wrapper. */
static void rx_bus(iol_state_t *st)
{
	int burst;

	for (burst = 0; burst < IOL_RX_BURST; burst++) {
		ssize_t       n;
		int           src_if;
		iol_if_kind_t kind;

		/* MSG_TRUNC so an oversized datagram reports its real length instead
		 * of arriving silently truncated. A truncated frame is a corrupt
		 * frame, and forwarding one is worse than dropping it. */
		n = recv(st->bus_fd, st->buf, sizeof(st->buf), MSG_TRUNC);
		if (n < 0) {
			if (errno == EAGAIN || errno == EWOULDBLOCK)
				return;
			if (errno == EINTR)
				continue;
			log_wrn("recv on the AF_UNIX bus: %s", strerror(errno));
			return;
		}

		st->rx_bus++;

		if ((size_t) n > sizeof(st->buf)) {
			log_wrn("dropped a %zd byte frame from IOL: longer than the "
			        "%zu byte receive buffer", n, sizeof(st->buf));
			st->dropped++;
			continue;
		}

		src_if = iol_hdr_src_if(st->buf, (size_t) n);
		if (src_if < 0) {
			log_dbg("dropped a %zd byte frame from IOL: no room for a header",
			        n);
			st->dropped++;
			continue;
		}

		kind = iol_if_kind(st->o->eth, (unsigned int) src_if);
		if (kind == IOL_IF_BAD) {
			log_wrn("dropped a frame from IOL: source interface byte %d is "
			        "outside 0..%d", src_if, IOL_MAX_IFACES - 1);
			st->dropped++;
			continue;
		}

		if (kind == IOL_IF_ETH) {
			/* src_if < IOL_MAX_IFACES, guaranteed by iol_if_kind(). */
			int fd = st->tap_fd[src_if];

			if (fd < 0) {
				if (!st->warned[src_if]) {
					st->warned[src_if] = 1;
					log_wrn("interface %d has no TAP; frames from it are "
					        "dropped (this is normal for an unconnected "
					        "port)", src_if);
				}
				st->dropped++;
				continue;
			}
			if (write(fd, st->buf + IOL_HDR_LEN,
			          (size_t) n - IOL_HDR_LEN) < 0) {
				log_dbg("write to the TAP for interface %d: %s", src_if,
				        strerror(errno));
				st->dropped++;
			}
			continue;
		}

		/* Serial: encapsulate and tunnel. */
		{
			iol_link_t *l = &st->o->links[src_if];   /* bounded above */

			if (!l->used) {
				if (!st->warned[src_if]) {
					st->warned[src_if] = 1;
					log_wrn("serial interface %d has no -l map; its frames "
					        "are dropped", src_if);
				}
				st->dropped++;
				continue;
			}
			if (l->fd < 0 && link_connect(l) != 0) {
				st->dropped++;
				continue;
			}
			if (iol_to_udp(st->buf, (size_t) n, st->o->tenant, st->o->device,
			               l) != 0) {
				st->dropped++;
				continue;
			}
			if (send(l->fd, st->buf, (size_t) n, MSG_NOSIGNAL) < 0) {
				/* ECONNREFUSED here is the far node not being started yet:
				 * an ICMP port-unreachable reported on the next send. It
				 * clears by itself when the peer comes up. */
				log_dbg("send to %s:%d for interface %d: %s", l->host,
				        l->remote_port, src_if, strerror(errno));
				st->dropped++;
			}
		}
	}
}

/* A peer wrapper -> here -> IOL. */
static void rx_udp(iol_state_t *st)
{
	int burst;

	for (burst = 0; burst < IOL_RX_BURST; burst++) {
		ssize_t n;
		size_t  outlen = 0;
		int     rc;

		n = recv(st->udp_fd, st->buf, sizeof(st->buf), MSG_TRUNC);
		if (n < 0) {
			if (errno == EAGAIN || errno == EWOULDBLOCK)
				return;
			if (errno == EINTR || errno == ECONNREFUSED)
				continue;   /* ECONNREFUSED: a peer that has gone away */
			log_wrn("recv on the data plane: %s", strerror(errno));
			return;
		}

		st->rx_udp++;

		if ((size_t) n > sizeof(st->buf)) {
			log_wrn("dropped a %zd byte data-plane datagram: longer than the "
			        "%zu byte receive buffer", n, sizeof(st->buf));
			st->dropped++;
			continue;
		}

		rc = iol_from_udp(st->buf, (size_t) n, st->o->tenant, st->o->device,
		                  st->out, sizeof(st->out), &outlen);
		if (rc != IOL_UDP_OK) {
			/* At DBG: on a shared host this is reachable by anything that can
			 * send to the port, and an ERR-level log for it would be a way to
			 * fill a disk. */
			log_dbg("dropped a %zd byte data-plane datagram: %s", n,
			        iol_udp_strerror(rc));
			st->dropped++;
			continue;
		}

		bus_send(st, st->out, outlen);
	}
}

/* The bridge -> here -> IOL. */
static void rx_tap(iol_state_t *st, int ifnum)
{
	int burst;

	if (ifnum < 0 || ifnum >= IOL_MAX_IFACES || st->tap_fd[ifnum] < 0)
		return;

	for (burst = 0; burst < IOL_RX_BURST; burst++) {
		ssize_t n;

		/* Read into the buffer PAST the header, so the header can be built in
		 * front of the payload without a second copy. */
		n = read(st->tap_fd[ifnum], st->buf + IOL_HDR_LEN,
		         sizeof(st->buf) - IOL_HDR_LEN);
		if (n < 0) {
			if (errno == EAGAIN || errno == EWOULDBLOCK)
				return;
			if (errno == EINTR)
				continue;
			log_wrn("read from the TAP for interface %d: %s", ifnum,
			        strerror(errno));
			return;
		}
		if (n == 0)
			return;

		st->rx_tap++;

		if ((size_t) n == sizeof(st->buf) - IOL_HDR_LEN)
			log_wrn("a frame on interface %d filled the receive buffer; it "
			        "may have been truncated", ifnum);

		/*
		 * NETMAP wires <device>:<i> to <device+512>:<i>, so a frame arriving
		 * on our interface i is, from IOL's point of view, a frame from the
		 * pseudo-instance's interface i destined for its own interface i.
		 */
		iol_hdr_build(st->buf, st->o->device,
		              st->o->device + IOL_PSEUDO_OFFSET, ifnum, ifnum);
		bus_send(st, st->buf, (size_t) n + IOL_HDR_LEN);
	}
}

/* ------------------------------------------------------------- loop hooks */

static int add_fd(int fd, fd_set *set, int max)
{
	if (fd < 0 || fd >= FD_SETSIZE)
		return max;
	FD_SET(fd, set);
	return (fd > max) ? fd : max;
}

static int iol_hook_prepare(void *ud, fd_set *rfds, fd_set *wfds)
{
	iol_state_t *st = ud;
	int          max = -1, i;

	(void) wfds;   /* every descriptor here is drained on read; nothing queues */

	max = add_fd(st->bus_fd, rfds, max);
	max = add_fd(st->udp_fd, rfds, max);
	for (i = 0; i < IOL_MAX_IFACES; i++)
		max = add_fd(st->tap_fd[i], rfds, max);

	return max;
}

static void iol_hook_service(void *ud, const fd_set *rfds, const fd_set *wfds)
{
	iol_state_t *st = ud;
	int          i;

	(void) wfds;

	if (st->bus_fd >= 0 && st->bus_fd < FD_SETSIZE && FD_ISSET(st->bus_fd, rfds))
		rx_bus(st);
	if (st->udp_fd >= 0 && st->udp_fd < FD_SETSIZE && FD_ISSET(st->udp_fd, rfds))
		rx_udp(st);
	for (i = 0; i < IOL_MAX_IFACES; i++) {
		int fd = st->tap_fd[i];

		if (fd >= 0 && fd < FD_SETSIZE && FD_ISSET(fd, rfds))
			rx_tap(st, i);
	}
}

static void dataplane_close(iol_state_t *st)
{
	int i;

	for (i = 0; i < IOL_MAX_IFACES; i++) {
		if (st->o->links[i].fd >= 0) {
			close(st->o->links[i].fd);
			st->o->links[i].fd = -1;
		}
	}
	taps_close(st);
	if (st->udp_fd >= 0) {
		close(st->udp_fd);
		st->udp_fd = -1;
	}
	bus_close(st);

	log_inf("data plane closed: %lu frame(s) from IOL, %lu from peers, %lu "
	        "from TAPs, %lu dropped",
	        st->rx_bus, st->rx_udp, st->rx_tap, st->dropped);
}

/* ------------------------------------------------------------------ main */

int main(int argc, char *argv[])
{
	static iol_opts_t  opts;    /* static: ~20 KB of link table */
	static iol_state_t st;      /* static: two 10 KB frame buffers */
	const char        *env[] = { "LD_LIBRARY_PATH=" IOL_LIB_PATH, NULL };
	cmdline_t          cmd;
	console_t          con;
	child_t            ch;
	child_spec_t       spec;
	loop_hooks_t       hooks;
	int                rc;

	/* R3: the basename must stay `iol_wrapper`. unl_wrapper's `stopall` runs
	 * `pkill -TERM iol_wrapper` and includes/api_status.php counts running IOL
	 * nodes with `pgrep -f -c -P 1 iol_wrapper`. Renaming this target breaks
	 * both, silently. */
	log_init("iol_wrapper");

	/* R4, before anything is forked. */
	child_become_group_leader();

	/*
	 * R2: no chdir(), ever. The cwd is the node's running directory, set by
	 * device::start() before the exec, and it is where NETMAP has to be
	 * written; it is also how `sudo fuser -k -TERM <running path>` finds us at
	 * teardown, which is the only way the UI can stop a node.
	 *
	 * R5: no daemonising and no double fork. The shell that backgrounded us
	 * has already exited, so we are parented to PID 1, which is what
	 * `pgrep -f -c -P 1 iol_wrapper` counts.
	 */

	switch (iol_parse(argc, argv, &opts)) {
	case IOL_VERSION:
		printf("%s\n", WRAPPER_VERSION_BLURB);
		return 0;
	case IOL_USAGE:
		iol_usage("iol_wrapper");
		return 1;
	case IOL_OK:
		break;
	}

	log_inf("IOL instance %d, session %d, tenant %d: %d ethernet and %d serial "
	        "port group(s), %d serial link(s)", opts.device, opts.session,
	        opts.tenant, opts.eth, opts.ser, opts.nlinks);

	/*
	 * §5.4, and it has to happen before IOL starts: NETMAP is read by IOL at
	 * startup and never again. "NETMAP" relative to the cwd, which R2
	 * guarantees is the running directory.
	 */
	if (iol_netmap_write("NETMAP", opts.device) != 0)
		return 1;

	console_init(&con, opts.title);

	/*
	 * R1, before the fork. getNodeStatus() decides this node is running by
	 * looking for a LISTEN on this port and nothing else, and
	 * device_qemu-style start loops give up after four seconds.
	 */
	if (console_open(&con, opts.port) != 0)
		return 1;

	/*
	 * The data plane also comes up before the fork, and the ordering is not
	 * arbitrary: IOL binds its own socket inside /tmp/netio<uid>, so that
	 * directory must exist before it starts, and a frame IOL emits in its
	 * first milliseconds has nowhere to go unless our socket is already bound.
	 *
	 * A failure here is deliberately NOT fatal. A node with a broken data
	 * plane but a working console is debuggable; a node that never starts,
	 * because R1 was never satisfied, presents to the operator as "the UI is
	 * broken".
	 */
	state_init(&st, &opts);
	if (bus_open(&st) != 0)
		log_err("the AF_UNIX bus is unavailable; this node's interfaces will "
		        "not pass traffic");
	if (udp_open(&st, opts.port) != 0)
		log_err("the UDP data plane is unavailable; this node's serial links "
		        "will not pass traffic");
	taps_open(&st);

	if (cmdline_init(&cmd) != 0)
		return 1;
	if (iol_build_command(&opts, argc, argv, &cmd) != 0) {
		log_err("the child command line exceeded ARG_MAX");
		cmdline_free(&cmd);
		console_close(&con);
		dataplane_close(&st);
		return 1;
	}

	memset(&spec, 0, sizeof(spec));
	spec.mode    = CHILD_PIPES;   /* IOL's console is its stdout; no pty needed */
	spec.command = cmdline_str(&cmd);
	spec.delay   = (unsigned int) opts.delay;
	/*
	 * §5.3 writes LD_LIBRARY_PATH as a prefix on the command string. Setting
	 * it as environment instead is equivalent for the child and immune to
	 * however the shell feels about the quoting around it. It points at
	 * /opt/unetlab/addons/iol/lib, which carries the libcrypto.so.4 that IOL
	 * images need and no modern distribution ships.
	 */
	spec.env_extra = env;

	if (child_spawn(&ch, &spec) != 0) {
		console_close(&con);
		dataplane_close(&st);
		cmdline_free(&cmd);
		return 1;
	}

	memset(&hooks, 0, sizeof(hooks));
	hooks.prepare = iol_hook_prepare;
	hooks.service = iol_hook_service;
	hooks.ud      = &st;

	rc = loop_run(&con, &ch, &hooks);

	dataplane_close(&st);
	cmdline_free(&cmd);
	return rc;
}

#endif /* WRAPPER_NO_MAIN */
