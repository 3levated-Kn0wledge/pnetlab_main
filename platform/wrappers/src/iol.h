/*
 * iol.h — the testable half of iol_wrapper (WRAPPER-SPEC §5).
 *
 * iol_wrapper is the only wrapper with a data plane. Everything in it that is
 * pure logic — option parsing, the link map, the NETMAP file, and the frame
 * encode/decode/classify functions that the forwarding paths are built out of —
 * lives behind this header so wrapper_test.c can assert on it directly. What is
 * left in iol.c is descriptors: sockets, TAPs, and the select hooks that wire
 * them into the shared loop.
 *
 * That split is not cosmetic. iol_wrapper cannot be verified end to end without
 * a licensed Cisco IOL image, and this repository deliberately carries none. The
 * parts that CAN be proved without one are the parts that index arrays with
 * bytes that arrived off the wire, and those are all here.
 */
#ifndef WRAPPER_IOL_H
#define WRAPPER_IOL_H

#include <limits.h>
#include <stddef.h>
#include <sys/socket.h>

#include "cmdline.h"
#include "console.h"

/*
 * IOL numbers an interface x/y as `16 * y + x`: the low nibble is the port
 * GROUP (0-15, `x`) and the high nibble is the UNIT within that group (0-3,
 * `y`). devices/iol/device_iol.php builds its interface ids the same way —
 * createEthernets() uses `$i = $x + $y * 16` and createSerials() offsets `$x` by
 * the ethernet group count, which is why the serial groups follow the ethernet
 * ones in one flat 0..63 space.
 *
 * 64 is therefore the hard ceiling on an interface number, and it is the size of
 * every per-interface array here. Interface bytes arrive from the AF_UNIX bus
 * and from UDP peers — i.e. from outside — so every one of them is checked
 * against this before it indexes anything.
 */
#define IOL_MAX_IFACES      64
#define IOL_MAX_GROUPS      16
#define IOL_UNITS_PER_GROUP 4

/* §5.6. Eight bytes of header in front of a bare Ethernet frame, on both the
 * AF_UNIX bus and the UDP tunnel — with different layouts, see below. */
#define IOL_HDR_LEN 8

/* §5.6: "The receive buffer is 10000 bytes", so jumbo frames up to ~9990 bytes
 * of payload survive. Anything longer is dropped rather than truncated: a
 * truncated Ethernet frame is a corrupt one, and silently corrupting frames is
 * far worse for the person debugging the lab than losing them. */
#define IOL_FRAME_MAX 10000

/*
 * R9. The wrapper impersonates a second IOL instance, numbered `device id +
 * 512`, and NETMAP wires every interface of the real instance to the same
 * interface number on that pseudo-instance. This offset is why
 * device_iol.php's getIolId() caps a lab at 512 IOL nodes: at 513 the real and
 * pseudo id spaces would overlap and two nodes would answer to the same bus
 * address.
 */
#define IOL_PSEUDO_OFFSET 512

#define IOL_HOST_MAX 256

/* The child's LD_LIBRARY_PATH (§5.3). /opt/unetlab/addons/iol/lib carries
 * libcrypto.so.4, which IOL images link against and no modern distribution
 * ships. Set as environment rather than as a "VAR=value " prefix on the command
 * string — see child_spec_t::env_extra. */
#define IOL_LIB_PATH "/opt/unetlab/addons/iol/lib"

/*
 * One `-l` map: the fork emits one per connected SERIAL interface, as
 *
 *     <local if>:<host>:<remote iol id>:<remote if>:<remote console port>
 *
 * (devices/iol/device_iol.php:124). The fifth field is the remote node's
 * console port, and that is not a mistake — see the block comment above
 * iol_udp_open() in iol.c.
 */
typedef struct {
	int  used;
	int  local_if;      /* 0 .. IOL_MAX_IFACES-1 */
	int  remote_dev;    /* 1 .. 65535, the peer's IOL instance id */
	int  remote_if;     /* 0 .. IOL_MAX_IFACES-1 */
	int  remote_port;   /* 1 .. 65535 */
	int  fd;            /* connected UDP socket, -1 until opened */
	char host[IOL_HOST_MAX];
} iol_link_t;

typedef struct {
	int  tenant;        /* -T, 0..255. Never passed by the fork; the default
	                     * 0 is what every lab actually runs with. */
	int  remote;        /* -R: serial links to other hosts. Binds the data
	                     * plane on every interface and lets a -l host be
	                     * something other than loopback. Never passed by
	                     * the fork; see iol_udp_open(). */
	int  device;        /* -D, 1..512. Mandatory. */
	int  session;       /* -S, node session id; names the TAP interfaces. */
	int  port;          /* -P, console TCP port AND data-plane UDP port. */
	int  delay;         /* -d */
	int  eth;           /* -e, ethernet port groups */
	int  ser;           /* -s, serial port groups */
	int  cmd_from;      /* argv index of the first child-command word, or -1 */
	int  nlinks;
	char title[CONSOLE_TITLE_MAX];
	char image[PATH_MAX];
	iol_link_t links[IOL_MAX_IFACES];   /* indexed BY LOCAL INTERFACE */
} iol_opts_t;

typedef enum {
	IOL_OK      = 0,
	IOL_USAGE   = 1,   /* caller prints usage and exits 1 */
	IOL_VERSION = 2    /* caller prints the version and exits 0 */
} iol_parse_t;

/*
 * Parse argv. §10.3 #5: the original's -l handler runs inside the option loop
 * and depends on -T, -D and -P having been seen first, so the argument order is
 * load-bearing and the usage text has to say "use the above parameter order".
 * Here the maps are collected as raw strings during the loop and parsed after
 * it, once -e and -s are known — so order stops mattering and a map can be
 * validated against the interface counts it has to be consistent with.
 */
iol_parse_t iol_parse(int argc, char *const argv[], iol_opts_t *opts);

void iol_usage(const char *progname);

/*
 * Parse one -l value. `eth`/`ser` are the port-group counts the map must be
 * consistent with; pass 0 for both to skip that cross-check.
 * Returns 0, or -1 after logging exactly which field was wrong.
 */
int iol_link_parse(const char *spec, int eth, int ser, iol_link_t *out);

/*
 * §5.4. Write the NETMAP file: 64 lines of
 *
 *     <device>:<i> <device+512>:<i>
 *
 * An existing file is removed first. Returns 0, or -1 after logging.
 *
 * In production `path` is the literal string "NETMAP", written into the cwd —
 * which R2 guarantees is the node's running directory. The parameter exists so
 * the unit tests can assert on the exact bytes without writing into the source
 * tree.
 */
int iol_netmap_write(const char *path, int device);

/*
 * §5.6 classification. Take the port group out of an interface byte and decide
 * which side of the data plane the frame belongs on.
 *
 * IOL_IF_BAD covers the two ways a byte off the wire can be nonsense: a unit
 * above 3, i.e. any value >= 64. Everything else is ETH below the -e count and
 * SER at or above it, exactly as the specification has it — a serial group
 * beyond -e + -s is still classified SER and is then dropped by the link
 * lookup, which logs the interface number.
 */
typedef enum {
	IOL_IF_BAD = 0,
	IOL_IF_ETH = 1,
	IOL_IF_SER = 2
} iol_if_kind_t;

iol_if_kind_t iol_if_kind(int eth, unsigned int ifbyte);

/*
 * The AF_UNIX (IOL bus) header. Eight bytes:
 *
 *   0-1  destination instance id, 16-bit big-endian
 *   2-3  source instance id, 16-bit big-endian
 *   4    destination interface
 *   5    source interface
 *   6-7  unused by us, zeroed on the frames we generate
 */
void iol_hdr_build(unsigned char *hdr, int dst_dev, int src_dev,
                   int dst_if, int src_if);

/* -1 if the frame is too short to have a header. */
int iol_hdr_src_if(const unsigned char *frame, size_t len);
int iol_hdr_dst_dev(const unsigned char *frame, size_t len);

/*
 * The UDP (wrapper-to-wrapper) header. Also eight bytes, but NOT the same
 * layout — it carries the tenant and shifts everything along:
 *
 *   0    tenant id (destination)
 *   1    tenant id (source) — the same value; a lab is one tenant
 *   2-3  destination device id, 16-bit big-endian
 *   4-5  source device id (ours), 16-bit big-endian
 *   6    destination interface
 *   7    source interface
 *
 * iol_to_udp() rewrites an IOL-bus header into a UDP one IN PLACE, which is why
 * the source interface has to be read out of offset 5 before anything is
 * written. Returns 0, or -1 if the frame is too short or the link is unusable.
 */
int iol_to_udp(unsigned char *frame, size_t len, int tenant, int mydev,
               const iol_link_t *link);

/*
 * The mirror image, with the validation §5.6 requires. Copies `pkt` into `out`
 * with an IOL-bus header addressed to the real instance, ready to be written to
 * the AF_UNIX peer.
 *
 * Every rejection has its own code so a test can assert on WHICH check fired
 * rather than merely that something was dropped — these are the checks that
 * stand between a UDP socket bound to a wildcard address and a byte that
 * indexes an array.
 */
typedef enum {
	IOL_UDP_OK         =  0,
	IOL_UDP_ERR_SHORT  = -1,   /* no room for a header */
	IOL_UDP_ERR_LONG   = -2,   /* would not fit the receive buffer */
	IOL_UDP_ERR_TENANT = -3,   /* not our tenant */
	IOL_UDP_ERR_DEVICE = -4,   /* not addressed to this node */
	IOL_UDP_ERR_IFACE  = -5    /* destination interface >= 64 */
} iol_udp_rc_t;

int iol_from_udp(const unsigned char *pkt, size_t len, int tenant, int mydev,
                 unsigned char *out, size_t outcap, size_t *outlen);

/* Human-readable form of the codes above, for the drop log. */
const char *iol_udp_strerror(int rc);

/*
 * Single-quote a word for /bin/sh. The image path from -F is the only part of
 * the child command line we assemble ourselves rather than pass through from
 * the PHP (which has already escapeshellarg()d everything it sends). Returns
 * 0, or -1 if the result would not fit.
 */
int iol_sh_quote(const char *in, char *out, size_t outlen);

/*
 * Assemble the child command (§5.3):
 *
 *     <image> -e <eth> -s <ser> <everything after --> <device id>
 *
 * The device id is the trailing positional argument — that is how IOL takes its
 * instance number — and the PHP's `-- -n <nvram> -q -m <ram> [-c startup-config]`
 * lands between the flags and it.
 *
 * `cmd` must already be initialised; the ARG_MAX cap it carries is the point of
 * routing this through cmdline_t rather than a local buffer. Returns 0, or -1
 * if anything overflowed.
 */
int iol_build_command(const iol_opts_t *opts, int argc, char *const argv[],
                      cmdline_t *cmd);

/*
 * The serial data-plane listener.
 *
 * Bound to 127.0.0.1 unless `remote` is set, in which case to every interface
 * (dual-stack [::], or 0.0.0.0 without IPv6). What arrives on it is validated
 * by iol_from_udp() -- tenant byte, device id, interface -- and nothing else:
 * there is no authentication, the tenant is always 0, and the device and
 * interface ranges are small. On a wildcard bind that made any host that could
 * reach the appliance able to inject frames into a node's serial interface
 * without a PNETLab account. The fork only ever links nodes on one host
 * (device_iol.php writes `localhost` into every -l map), so the loopback bind
 * costs nothing and closes the port to the network.
 *
 * Port 0 binds an ephemeral port, which is how the unit test exercises this.
 * Returns the fd, or -1 after logging.
 */
int iol_udp_open(int port, int remote);

/* True for 127.0.0.0/8, ::1, and the v4-mapped form of the former. */
int iol_sockaddr_is_loopback(const struct sockaddr *sa, socklen_t len);

#endif /* WRAPPER_IOL_H */
