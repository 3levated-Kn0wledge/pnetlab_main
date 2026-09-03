#!/usr/bin/env bash
#
# Functional test for iol_wrapper — WRAPPER-SPEC §5. Run it from anywhere:
#
#     bash tools/integration/iol-dataplane.sh
#
# READ THIS FIRST. iol_wrapper drives a licensed Cisco IOL image. This project
# ships none, the reference appliance has none, and none was used to write the
# code. So this script does NOT prove that IOL nodes work. What it proves is that
# the wrapper does the things the specification says it does, against a stand-in
# IOL (tools/integration/iol_fake.c) that binds the same AF_UNIX socket a real
# instance would and speaks the same 8-byte bus header. Everything except IOL's
# own behaviour is real: real TAP devices on a real bridge, real UDP datagrams,
# real unix datagram sockets, the real select loop.
#
# What is checked:
#
#   R1  the console port LISTENs — getNodeStatus()'s only liveness oracle.
#   R2  the wrapper's cwd stays the node's running directory, which is also
#       where NETMAP has to land.
#   R3  the basename is iol_wrapper, because `pkill -TERM iol_wrapper` and
#       `pgrep -f -c -P 1 iol_wrapper` are written against it.
#   R4  SIGTERM takes the child with it.
#   R5  the wrapper is a child of PID 1.
#   §5.4  NETMAP: 64 lines, <id>:<i> <id+512>:<i>, in the cwd.
#   §5.5  the AF_UNIX bus in /tmp/netio<uid>.
#   §5.6  frame forwarding, both directions, both paths, with the drop rules.
#   §5.7  TAP attach with IFF_TAP|IFF_NO_PI.
#
# Needs: a compiler, python3, iproute2, and passwordless sudo for `ip` (to make
# the TAPs and the bridge, which device_iol::prepare() does with tunctl) and for
# `fuser` (which is how device::stop() kills a node). It creates
# vunl<session>_* TAPs, one bridge and one probe TAP, and removes all of them,
# plus its sockets under /tmp/netio<uid>, on the way out.
#
# Notes for anyone extending it:
#
#   - Do not background the wrapper with a plain `&`. bash would make it a child
#     of this script and the R5 check would pass for the wrong reason. The PHP
#     runs `sh -c "... &"`, whose shell exits at once and leaves the wrapper
#     parented to init.
#   - Never cd into the running directory: the teardown check is `fuser -k`
#     against it, and it would kill the test.
#   - Frames are injected from the stand-in IOL over the CONSOLE, not by this
#     script directly. That is deliberate: it means the data plane is being
#     driven through the same pipe a real IOL's stdout and stdin use, so a
#     console that stalls under traffic shows up here.
#
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SRC="$ROOT/platform/wrappers/src"
WRAP="$SRC/iol_wrapper"

PASS=0; FAIL=0
ok()   { printf "  \033[32mok\033[0m   %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  \033[31mFAIL\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; FAIL=$((FAIL+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$3' not in: $(echo "$2" | tr '\n' '|' | head -c 300)";; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1" "'$3' unexpectedly in: $(echo "$2" | tr '\n' '|' | head -c 300)";; *) ok "$1";; esac; }

listening() { ss -ltnH "sport = :$1" 2>/dev/null | grep -q LISTEN; }
udpbound()  { ss -lunH "sport = :$1" 2>/dev/null | grep -q .; }

wait_listen() { for _ in $(seq 1 50); do listening "$1" && return 0; sleep 0.1; done; return 1; }
wait_gone()   { for _ in $(seq 1 50); do listening "$1" || return 0; sleep 0.1; done; return 1; }
wait_dead()   { for _ in $(seq 1 50); do kill -0 "$1" 2>/dev/null || return 0; sleep 0.1; done; return 1; }
wait_file()   { for _ in $(seq 1 50); do [ -e "$1" ] && return 0; sleep 0.1; done; return 1; }

# The PHP's launch path: chdir to the running directory, then run a command
# string ending in "&" through a shell that exits at once. R2 and R5 both depend
# on this shape.
launch() {
	local dir=$1; shift
	( cd "$dir" && sh -c "$* > wrapper.txt 2>&1 &" )
}

UID_NUM=$(id -u)
SESSION=$((900 + (RANDOM % 90)))
BR="briol$SESSION"
PROBE="iolp$SESSION"
TAP0="vunl${SESSION}_0"
DIR=$(mktemp -d /tmp/iol-dataplane.XXXXXX)
DIR2=$(mktemp -d /tmp/iol-dataplane.XXXXXX)
FAKE="$DIR/fake_iol"

cleanup() {
	sudo fuser -k -TERM "$DIR" "$DIR2" >/dev/null 2>&1
	sleep 0.3
	sudo ip link del "$BR" 2>/dev/null
	sudo ip link del "$TAP0" 2>/dev/null
	sudo ip link del "$PROBE" 2>/dev/null
	rm -f "/tmp/netio$UID_NUM/"{11,523,12,524,13,525} 2>/dev/null
	rmdir "/tmp/netio$UID_NUM" 2>/dev/null
	rm -rf "$DIR" "$DIR2"
}
trap cleanup EXIT

echo "=============== BUILD ==============="
BUILD=$(make -C "$SRC" 2>&1)
if [ $? -eq 0 ]; then ok "the wrappers build with -Wall -Wextra -Werror"; else bad "the wrappers build" "$BUILD"; fi
[ -x "$WRAP" ] && ok "iol_wrapper was produced" || bad "iol_wrapper was produced"
# R3: unl_wrapper's `stopall` runs `pkill -TERM iol_wrapper`, and
# includes/api_status.php counts nodes with `pgrep -f -c -P 1 iol_wrapper`.
chk "the binary is named iol_wrapper (R3)" "$(basename "$WRAP")" "iol_wrapper"

FBUILD=$(cc -std=gnu11 -O2 -Wall -Wextra -Werror -o "$FAKE" "$ROOT/tools/integration/iol_fake.c" 2>&1)
if [ $? -eq 0 ]; then ok "the stand-in IOL builds"; else bad "the stand-in IOL builds" "$FBUILD"; fi

echo
echo "=============== ARGUMENT CONTRACT ==============="
"$WRAP" -v >/dev/null 2>&1; chk "-v exits 0" "$?" "0"
has "-v prints a version" "$("$WRAP" -v 2>&1)" "1.0"
"$WRAP" -P 9 -F "$FAKE" >/dev/null 2>&1; chk "a missing -D exits 1" "$?" "1"
"$WRAP" -D 1 -P 9 >/dev/null 2>&1;       chk "a missing -F exits 1" "$?" "1"
"$WRAP" -D 1 -F "$FAKE" >/dev/null 2>&1; chk "a missing -P exits 1" "$?" "1"
"$WRAP" -D 1 -P 9 -F /no/such/image >/dev/null 2>&1; chk "an -F that does not exist exits 1" "$?" "1"
"$WRAP" -D 513 -P 9 -F "$FAKE" >/dev/null 2>&1; chk "a device id above 512 exits 1 (R9)" "$?" "1"
"$WRAP" -D 1 -P 9 -F "$FAKE" -e 9 -s 8 >/dev/null 2>&1; chk "-e + -s above 16 exits 1" "$?" "1"
"$WRAP" -D 1 -P 9 -F "$FAKE" -l "64:localhost:7:2:30013" >/dev/null 2>&1
chk "an out-of-range interface in -l exits 1" "$?" "1"
has "an out-of-range interface in -l says which field" \
    "$("$WRAP" -D 1 -P 9 -F "$FAKE" -l "64:localhost:7:2:30013" 2>&1)" "local interface must be 0..63"
# §10.3 #5: the original needs -T/-D/-P before -l and says so in its usage text.
"$WRAP" -l "2:localhost:7:2:30013" -s 2 -e 2 -P 9 -F "$FAKE" -D 1 -x >/dev/null 2>&1
chk "-l before the options it depends on is not a usage error (§10.3 #5)" "$?" "1"
has "...and the failure is the unknown -x, not the ordering" \
    "$("$WRAP" -l "2:localhost:7:2:30013" -s 2 -e 2 -P 9 -F "$FAKE" -D 1 -x 2>&1)" "unknown option"

echo
echo "=============== NETWORK FIXTURE ==============="
# device_iol::prepare() creates these with `tunctl -u unl<session> -t
# vunl<session>_<if>` and attaches them to the lab's bridges. Same shape.
sudo ip link add "$BR" type bridge forward_delay 0 stp_state 0 2>/dev/null
# A bridge with IPv6 enabled emits router solicitations and MLD reports of its
# own, and floods them to every port — so they arrive on the node's TAP looking
# exactly like lab traffic. addTap() in includes/cli.php disables IPv6 on every
# TAP it makes for the same reason; do it for the bridge too, BEFORE it comes up.
# The assertions below still do not depend on the host being quiet.
sudo sysctl -qw "net.ipv6.conf.$BR.disable_ipv6=1" >/dev/null 2>&1
sudo ip link set "$BR" up
sudo ip tuntap add dev "$TAP0" mode tap user "$UID_NUM" 2>/dev/null
sudo ip tuntap add dev "$PROBE" mode tap user "$UID_NUM" 2>/dev/null
sudo sysctl -qw "net.ipv6.conf.$TAP0.disable_ipv6=1" >/dev/null 2>&1
sudo sysctl -qw "net.ipv6.conf.$PROBE.disable_ipv6=1" >/dev/null 2>&1
sudo ip link set "$TAP0" master "$BR"
sudo ip link set "$PROBE" master "$BR"
sudo ip link set "$TAP0" up
sudo ip link set "$PROBE" up
[ -e "/sys/class/net/$TAP0/dev_id" ] && ok "the node's TAP $TAP0 exists" || bad "the node's TAP $TAP0 exists"
[ -e "/sys/class/net/$PROBE/dev_id" ] && ok "a probe TAP on the same bridge exists" || bad "the probe TAP exists"

PORT=$(( 39200 + (RANDOM % 300) ))
while listening "$PORT" || udpbound "$PORT"; do PORT=$((PORT+1)); done
PORT2=$((PORT + 1))
while listening "$PORT2" || udpbound "$PORT2"; do PORT2=$((PORT2+1)); done

echo
echo "=============== LISTENER, NETMAP AND PROCESS SHAPE (R1-R5, §5.4, §5.5) ==============="
# Device 11 with two ethernet and two serial port groups, and one serial link on
# interface 2 pointing at a peer we will play by hand.
launch "$DIR" "$WRAP" -D 11 -S "$SESSION" -P "$PORT" -t "'R1'" -F "$FAKE" \
	-d 0 -e 2 -s 2 -l "2:localhost:7:34:$PORT2" -- -n 1024 -q -m 512
if wait_listen "$PORT"; then ok "the console port LISTENs (R1)"; else bad "the console port LISTENs (R1)" "$(cat "$DIR/wrapper.txt" 2>/dev/null)"; fi

# Exactly what includes/functions.php runs to decide the node is up.
netstat -a -t -n 2>/dev/null | grep LISTEN | grep -q ":$PORT" \
	&& ok "getNodeStatus()'s own netstat|grep LISTEN matches" \
	|| bad "getNodeStatus()'s own netstat|grep LISTEN matches"

# §5.6: the data plane binds the SAME number on UDP. See the port-collision note
# in iol.c — console ports are unique per node, and the -l map's fifth field is
# the far node's console port, so this is required, not incidental.
udpbound "$PORT" && ok "the data plane is bound to UDP $PORT, the console port number" \
	|| bad "the data plane is bound to UDP $PORT"

# And to LOOPBACK. What arrives on this port is checked by iol_from_udp() alone
# -- tenant 0, a device id, an interface -- so a wildcard bind let any host that
# could reach the appliance inject frames into a node's serial interface. The
# fork writes localhost into every -l map; -R is the opt-in for the old bind.
BOUND=$(ss -lunH "sport = :$PORT" 2>/dev/null | awk '{print $4}' | head -1)
case "$BOUND" in
	127.0.0.1:"$PORT") ok "the data plane is bound to 127.0.0.1, not to every interface" ;;
	*) bad "the data plane is bound to 127.0.0.1, not to every interface" "bound to: $BOUND" ;;
esac

WPID=$(pgrep -f "iol_wrapper -D 11 -S $SESSION" | head -1)
[ -n "$WPID" ] && ok "the wrapper is running" || bad "the wrapper is running" "$(cat "$DIR/wrapper.txt" 2>/dev/null)"
chk "the wrapper's cwd is the running directory (R2)" "$(readlink -f "/proc/$WPID/cwd" 2>/dev/null)" "$(readlink -f "$DIR")"
chk "the wrapper is parented to PID 1 (R5)" "$(awk '{print $4}' "/proc/$WPID/stat" 2>/dev/null)" "1"
chk "pgrep -f -c -P 1 iol_wrapper counts it (R3, R5)" "$(pgrep -f -c -P 1 "iol_wrapper -D 11 -S $SESSION" 2>/dev/null || echo 0)" "1"
CPID=$(pgrep -P "$WPID" | head -1)
[ -n "$CPID" ] && ok "IOL was forked" || bad "IOL was forked"
chk "IOL inherits the running directory too (R2)" "$(readlink -f "/proc/$CPID/cwd" 2>/dev/null)" "$(readlink -f "$DIR")"
chk "the wrapper heads its own process group (R4)" "$(ps -o pgid= -p "$WPID" | tr -d ' ')" "$WPID"
chk "IOL is in that group (R4)" "$(ps -o pgid= -p "$CPID" | tr -d ' ')" "$WPID"

# §5.4. The whole data plane hangs off this file: it is what tells IOL to send
# every frame to instance <id>+512, which is the wrapper.
[ -f "$DIR/NETMAP" ] && ok "NETMAP was written into the running directory (§5.4, R2)" || bad "NETMAP was written into the running directory"
chk "NETMAP has 64 lines, one per interface" "$(wc -l < "$DIR/NETMAP" | tr -d ' ')" "64"
chk "the first line wires interface 0 to the pseudo-instance" "$(head -1 "$DIR/NETMAP")" "11:0 523:0"
chk "the last line wires interface 63" "$(tail -1 "$DIR/NETMAP")" "11:63 523:63"

# §5.5, and R10: the directory name is the wrapper's own uid, which in production
# is the per-node tenant account device_iol::prepare() dropped to.
[ -S "/tmp/netio$UID_NUM/523" ] && ok "the wrapper's bus socket is /tmp/netio<uid>/<id+512> (§5.5)" \
	|| bad "the wrapper's bus socket exists" "$(ls -la "/tmp/netio$UID_NUM/" 2>&1)"
wait_file "/tmp/netio$UID_NUM/11" && ok "IOL's own socket is /tmp/netio<uid>/<id>" \
	|| bad "IOL's own socket exists"

has "wrapper.txt records the listener at INF" "$(cat "$DIR/wrapper.txt")" "console listening on port $PORT"
has "wrapper.txt records the assembled child command" "$(cat "$DIR/wrapper.txt")" "-e 2 -s 2 -n 1024 -q -m 512 11"
has "wrapper.txt records the TAP attach" "$(cat "$DIR/wrapper.txt")" "attached to TAP $TAP0"
has "the trailing instance number reached IOL" "$(cat "$DIR/wrapper.txt")" "-m 512 11"

echo
echo "=============== THE DATA PLANE ==============="
DPOUT=$(PORT="$PORT" PORT2="$PORT2" PROBE="$PROBE" python3 - <<'PY'
import fcntl, os, select, socket, struct, sys, time

port   = int(os.environ["PORT"])
port2  = int(os.environ["PORT2"])
probe  = os.environ["PROBE"]

ok = fail = 0
def chk(label, cond, detail=""):
    global ok, fail
    if cond: print("  \033[32mok\033[0m   %s" % label); ok += 1
    else:    print("  \033[31mFAIL\033[0m %s\n       %s" % (label, str(detail)[:300])); fail += 1

# ---- the console, which is also how we drive the stand-in IOL ----------------
con = socket.create_connection(("127.0.0.1", port), timeout=5)
con.settimeout(0.4)

def clean(data):
    """Strip what the wrapper says as a terminal server from what the node says.

    A viewer's stream opens with IAC WILL ECHO / SGA / BINARY, IAC DO BINARY and
    an xterm OSC title (§2.1) — twelve bytes of telnet plus an escape sequence,
    including a literal NUL for option BINARY. Asserting on the raw stream means
    asserting on those too, and printing it on failure feeds a NUL to bash's
    command substitution, which drops it and warns. Take the telnet out and
    assert on what the node actually wrote."""
    out = bytearray()
    i = 0
    while i < len(data):
        b = data[i]
        if b == 0xff:                                  # IAC
            if i + 1 < len(data) and data[i + 1] == 0xfa:   # SB ... IAC SE
                j = data.find(b"\xff\xf0", i)
                i = len(data) if j < 0 else j + 2
            elif i + 1 < len(data) and data[i + 1] == 0xff:  # escaped 0xFF
                out.append(0xff); i += 2
            else:
                i += 3
            continue
        if b == 0x1b:                                  # ESC ] 0 ; title BEL
            j = data.find(b"\x07", i)
            i = len(data) if j < 0 else j + 1
            continue
        out.append(b)
        i += 1
    return out.decode("ascii", errors="replace").replace("\x00", "")

def console_read(want, timeout=4.0):
    buf = b""
    end = time.time() + timeout
    while time.time() < end:
        try:
            b = con.recv(65536)
        except socket.timeout:
            if want is None: break
            continue
        if not b: break
        buf += b
        if want is not None and want.encode() in buf:
            break
    return clean(buf)

def console_send(line):
    con.sendall((line + "\n").encode())

# A console has no scrollback. The wrapper opens its listener before it forks
# (R1), but the node's startup lines are broadcast to whoever is connected AT
# THE TIME, and this client cannot connect before the port exists. So ask for
# them rather than racing the fork — and in doing so, prove the console works in
# both directions before anything else is asserted.
console_send("banner")
banner = console_read("FAKEIOL ready")
chk("the stand-in IOL's console reaches a telnet client, in both directions",
    "FAKEIOL ready dev=11" in banner, banner)
# It read NETMAP out of the cwd, which is where a real IOL looks for it — and the
# cwd is the node's running directory only because the wrapper never chdir()s.
chk("IOL found NETMAP in its working directory (R2, §5.4)",
    "NETMAP first line: 11:0 523:0" in banner, banner)
chk("IOL connected to the wrapper's bus socket (§5.5)",
    "peer=/tmp/netio" in banner and "/523" in banner, banner)

# ---- probe TAP: the other port on the bridge --------------------------------
TUNSETIFF = 0x400454ca
IFF_TAP   = 0x0002
IFF_NO_PI = 0x1000

tap = os.open("/dev/net/tun", os.O_RDWR)
fcntl.ioctl(tap, TUNSETIFF, struct.pack("16sH22s", probe.encode(), IFF_TAP | IFF_NO_PI, b""))
os.set_blocking(tap, False)

def tap_read(marker, timeout=4.0):
    end = time.time() + timeout
    while time.time() < end:
        r, _, _ = select.select([tap], [], [], 0.2)
        if not r:
            continue
        try:
            frame = os.read(tap, 10000)
        except BlockingIOError:
            continue
        # Only our own experimental ethertype, so the kernel's own chatter on a
        # freshly-upped interface cannot be mistaken for a pass.
        if len(frame) >= 14 and frame[12:14] == b"\x88\xb5" and marker in frame:
            return frame
    return None

def tap_drain():
    while True:
        r, _, _ = select.select([tap], [], [], 0)
        if not r: return
        try: os.read(tap, 10000)
        except BlockingIOError: return

# ---- IOL -> wrapper -> TAP (§5.6 ethernet path, §5.7) -----------------------
tap_drain()
console_send("inject 0 ETH-OUTBOUND")
chk("IOL reports the frame left on interface 0", "TX if=0" in console_read("TX if=0"), "")
frame = tap_read(b"ETH-OUTBOUND")
chk("a frame from an ethernet port reaches the TAP for that interface", frame is not None,
    "nothing with ethertype 88b5 arrived on the probe")
if frame:
    # IFF_NO_PI: no 4-byte tun_pi header, so the destination MAC is at offset 0.
    chk("the 8-byte bus header was stripped: the frame starts with the MAC",
        frame[0:6] == b"\xff" * 6, frame[:16].hex())
    chk("the source MAC names the interface it came from", frame[6:12] == b"\x02\x00\x00\x00\x00\x00",
        frame[6:12].hex())

# The unit-1 interface of group 0 is number 16, and the fixture gave it no TAP:
# the frame must be dropped, not sent somewhere else and not fatal.
tap_drain()
console_send("inject 16 NO-SUCH-TAP")
chk("IOL reports the frame left on interface 16", "TX if=16" in console_read("TX if=16"), "")
chk("an ethernet port with no TAP drops the frame rather than putting it on "
    "another interface", tap_read(b"NO-SUCH-TAP", 1.0) is None,
    "it reached the probe anyway")

# ---- TAP -> wrapper -> IOL --------------------------------------------------
inbound = (b"\xff\xff\xff\xff\xff\xff" + b"\x02\x00\x00\x00\x00\x99" + b"\x88\xb5"
           + b"ETH-INBOUND")
os.write(tap, inbound)
out = console_read("ETH-INBOUND")
chk("a frame on the bridge reaches IOL", "ETH-INBOUND" in out, out[-300:])
chk("...addressed to the real instance on the interface it arrived on",
    "RX dst=11 src=523 dstif=0 srcif=0" in out, out[-300:])

# ---- IOL -> wrapper -> UDP (§5.6 serial path) -------------------------------
peer = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
peer.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
peer.bind(("::", port2))
peer.settimeout(4)

console_send("inject 2 SER-OUTBOUND")
try:
    data, src = peer.recvfrom(10000)
except socket.timeout:
    data = None
chk("a frame from a serial port is tunnelled over UDP to the -l peer",
    data is not None and b"SER-OUTBOUND" in data, "nothing arrived on UDP %d" % port2)
if data:
    # §5.6: tenant, tenant, destination device BE, source device BE,
    # destination interface, source interface. The -l map said 7 and 34.
    chk("the tunnel header is exactly <tenant><tenant><dst BE><src BE><dstif><srcif>",
        data[0:8] == b"\x00\x00\x00\x07\x00\x0b\x22\x02", data[0:8].hex())
    chk("the payload is the Ethernet frame, unmodified",
        data[8:14] == b"\xff" * 6 and data[22:] == b"SER-OUTBOUND", data[8:].hex())

# A serial port with no -l map: dropped, node survives.
console_send("inject 3 NO-SUCH-MAP")
console_read("TX if=3")
peer.settimeout(0.6)
try:
    stray = peer.recvfrom(10000)
except socket.timeout:
    stray = None
chk("a serial port with no -l map drops its frames rather than guessing",
    stray is None, stray)

# ---- UDP -> wrapper -> IOL, and the drop rules ------------------------------
def udp_send(hdr, payload=b"SER-INBOUND"):
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.sendto(hdr + payload, ("127.0.0.1", port))
    s.close()

eth = b"\xff\xff\xff\xff\xff\xff\x02\x00\x00\x00\x00\x02\x88\xb5"
# tenant 0, to device 11, from device 7, to our interface 2, from theirs (34).
udp_send(b"\x00\x00\x00\x0b\x00\x07\x02\x22", eth + b"SER-INBOUND")
out = console_read("SER-INBOUND")
chk("a well-formed tunnel datagram reaches IOL", "SER-INBOUND" in out, out[-300:])
chk("...as a bus frame from the pseudo-instance on interface 2",
    "RX dst=11 src=523 dstif=2 srcif=2" in out, out[-300:])

# Every one of these is a byte from a socket bound to a wildcard address.
before = console_read(None, 0.3)
udp_send(b"\x01\x01\x00\x0b\x00\x07\x02\x22", eth + b"WRONG-TENANT")
udp_send(b"\x00\x00\x00\x0c\x00\x07\x02\x22", eth + b"WRONG-DEVICE")
udp_send(b"\x00\x00\x00\x0b\x00\x07\x40\x22", eth + b"IFACE-64")
udp_send(b"\x00\x00\x00\x0b\x00\x07\xff\x22", eth + b"IFACE-255")
udp_send(b"\x00\x00\x00", b"")
after = console_read(None, 1.0)
chk("a datagram for another tenant is dropped", "WRONG-TENANT" not in after, after[-200:])
chk("a datagram for another node is dropped", "WRONG-DEVICE" not in after, after[-200:])
chk("a destination interface of 64 is dropped, not indexed", "IFACE-64" not in after, after[-200:])
chk("a destination interface of 255 is dropped, not indexed", "IFACE-255" not in after, after[-200:])

# ...and the node is still alive after all of that.
console_send("stat")
out = console_read("STAT")
chk("the node is still serving its console after the malformed traffic",
    "STAT" in out, out[-200:])
# rxtagged counts only frames carrying the test's own ethertype, so this is
# "exactly the two the test sent inwards got through, and none of the four
# malformed ones did" — not "the host's bridge was quiet". The plain rx counter
# is reported alongside it, and the difference is the host's own multicast
# arriving on the TAP, which the RX lines above identify by ethertype.
chk("IOL received exactly the two frames that should have got through, and "
    "none of the four that should not", "rxtagged=2" in out, out[-200:])

os.close(tap)
con.close()
peer.close()
print("PYRESULT %d %d" % (ok, fail))
PY
)
echo "$DPOUT" | grep -v '^PYRESULT'
read -r PYOK PYFAIL < <(echo "$DPOUT" | awk '/^PYRESULT/ {print $2, $3}')
PASS=$((PASS + ${PYOK:-0}))
FAIL=$((FAIL + ${PYFAIL:-0}))

echo
echo "=============== TWO NODES ON ONE HOST, WIRED BY SERIAL ==============="
# The question the specification could not settle: §5.6 ties the data-plane UDP
# port to the console port, which looks like it would make two IOL nodes on one
# host collide. It does not — includes/__node.php gives every node a unique
# node_session_port, and the -l map's fifth field is the far node's console port,
# so this arrangement is what makes peer discovery work at all. Here are two
# nodes proving it, each listening on TCP and UDP for its own port number.
PORT3=$((PORT2 + 1))
while listening "$PORT3" || udpbound "$PORT3"; do PORT3=$((PORT3+1)); done
PORT4=$((PORT3 + 1))
while listening "$PORT4" || udpbound "$PORT4"; do PORT4=$((PORT4+1)); done

launch "$DIR2" "$WRAP" -D 12 -S "$((SESSION+1))" -P "$PORT3" -t "'A'" -F "$FAKE" \
	-e 1 -s 1 -l "1:localhost:13:1:$PORT4" -- -q
launch "$DIR2" "$WRAP" -D 13 -S "$((SESSION+2))" -P "$PORT4" -t "'B'" -F "$FAKE" \
	-e 1 -s 1 -l "1:localhost:12:1:$PORT3" -- -q
wait_listen "$PORT3" && ok "node A's console LISTENs" || bad "node A's console LISTENs" "$(cat "$DIR2/wrapper.txt")"
wait_listen "$PORT4" && ok "node B's console LISTENs" || bad "node B's console LISTENs"
udpbound "$PORT3" && ok "node A's data plane is on UDP $PORT3" || bad "node A's data plane is on UDP $PORT3"
udpbound "$PORT4" && ok "node B's data plane is on UDP $PORT4" || bad "node B's data plane is on UDP $PORT4"
chk "two IOL nodes on one host do not collide on a port" \
    "$( { listening "$PORT3" && listening "$PORT4" && udpbound "$PORT3" && udpbound "$PORT4"; } && echo yes)" "yes"

LINKOUT=$(A="$PORT3" B="$PORT4" python3 - <<'PY'
import os, socket, time
ok = fail = 0
def chk(label, cond, detail=""):
    global ok, fail
    if cond: print("  \033[32mok\033[0m   %s" % label); ok += 1
    else:    print("  \033[31mFAIL\033[0m %s\n       %s" % (label, str(detail)[:300])); fail += 1

def con(port):
    s = socket.create_connection(("127.0.0.1", port), timeout=5)
    s.settimeout(0.4)
    return s

def read(s, want, timeout=4.0):
    buf = b""; end = time.time() + timeout
    while time.time() < end:
        try: b = s.recv(65536)
        except socket.timeout:
            if want is None: break
            continue
        if not b: break
        buf += b
        if want is not None and want.encode() in buf: break
    return buf.decode(errors="replace")

a = con(int(os.environ["A"])); read(a, "FAKEIOL ready")
b = con(int(os.environ["B"])); read(b, "FAKEIOL ready")

a.sendall(b"inject 1 A-TO-B\n")
out = read(b, "A-TO-B")
chk("a serial frame crosses from node A to node B", "A-TO-B" in out, out[-300:])
chk("...arriving on B's interface 1, from B's own pseudo-instance",
    "RX dst=13 src=525 dstif=1 srcif=1" in out, out[-300:])

b.sendall(b"inject 1 B-TO-A\n")
out = read(a, "B-TO-A")
chk("and back from B to A", "B-TO-A" in out, out[-300:])
a.close(); b.close()
print("PYRESULT %d %d" % (ok, fail))
PY
)
echo "$LINKOUT" | grep -v '^PYRESULT'
read -r LOK LFAIL < <(echo "$LINKOUT" | awk '/^PYRESULT/ {print $2, $3}')
PASS=$((PASS + ${LOK:-0}))
FAIL=$((FAIL + ${LFAIL:-0}))

echo
echo "=============== TEARDOWN (R4) ==============="
# device::stop()'s first move, exactly: SIGTERM everything holding the running
# directory open, which is the wrapper (R2) and, by inheritance, IOL.
sudo fuser -k -TERM "$DIR" >/dev/null 2>&1
if wait_dead "$WPID"; then ok "the wrapper exits on SIGTERM (R4)"; else bad "the wrapper exits on SIGTERM (R4)"; fi
if wait_dead "$CPID"; then ok "IOL dies with it — no orphan (R4)"; else bad "IOL dies with it — no orphan (R4)"; fi
if wait_gone "$PORT"; then ok "the console port is released, so the node reads as stopped"; else bad "the console port is released"; fi
[ ! -e "/tmp/netio$UID_NUM/523" ] && ok "the wrapper removed its bus socket" || bad "the wrapper removed its bus socket"

sudo fuser -k -TERM "$DIR2" >/dev/null 2>&1
wait_gone "$PORT3" && ok "the second node tears down too" || bad "the second node tears down too"

echo
echo "============================================"
printf "  assertions: %d passed, %d failed\n" "$PASS" "$FAIL"
echo "============================================"
[ "$FAIL" -eq 0 ] || exit 1
