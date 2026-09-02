#!/usr/bin/env bash
#
# Functional test for the console wrappers in platform/wrappers/src. Run it from
# anywhere, on any Linux host with a compiler — it needs no PNETLab install:
#
#     bash tools/integration/wrapper-console.sh
#
# It builds qemu_wrapper_telnet, starts it the way devices/qemu/device_qemu.php
# starts it, and checks the five things in §1 of the wrapper specification that
# decide whether a node works at all:
#
#   R1  the console port LISTENs. This is the ONLY thing getNodeStatus() looks
#       at, so it is the liveness oracle the whole UI is built on.
#   R2  the wrapper's cwd stays the node's running directory, so that
#       device::stop()'s `sudo fuser -k -TERM <running path>` can reach it.
#   R3  the executable's basename is the name the PHP invokes and pgreps.
#   R4  SIGTERM takes the child with it — no orphaned emulator, no held port.
#   R5  the wrapper is a child of PID 1, so `pgrep -f -c -P 1` counts it.
#
# and then the thing R1 exists to serve: that bytes actually relay, in both
# directions, to several viewers at once.
#
# Notes for anyone extending it, learned by getting these wrong:
#
#   - Do not background the wrapper with a plain `&` from this script. bash makes
#     it a child of the script, so PPID is the script and the R5 check passes for
#     the wrong reason. The PHP runs `sh -c "... &"`, whose shell exits
#     immediately and leaves the wrapper parented to init. Reproduce that.
#   - Never cd into the running directory. The teardown check is `fuser -k`
#     against that directory, and it would kill the test.
#   - `cat` stands in for `nc -U console.sock` in the first half because it needs
#     nothing else running. The second half uses the real thing, with socat
#     standing in for QEMU's serial socket, because the pipeline is where the
#     interesting failures are.
#   - A wrapper that dies still leaves wrapper.txt, and it is the first place to
#     look when a check here fails.
#
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SRC="$ROOT/platform/wrappers/src"
WRAP="$SRC/qemu_wrapper_telnet"

PASS=0; FAIL=0
ok()   { printf "  \033[32mok\033[0m   %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  \033[31mFAIL\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; FAIL=$((FAIL+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$3' not in: $(echo "$2" | head -c 160)";; esac; }

listening() { ss -ltnH "sport = :$1" 2>/dev/null | grep -q LISTEN; }

wait_listen()  { for _ in $(seq 1 50); do listening "$1" && return 0; sleep 0.1; done; return 1; }
wait_gone()    { for _ in $(seq 1 50); do listening "$1" || return 0; sleep 0.1; done; return 1; }
wait_dead()    { for _ in $(seq 1 50); do kill -0 "$1" 2>/dev/null || return 0; sleep 0.1; done; return 1; }

# The PHP's launch path: chdir to the running directory, then exec a command
# string ending in "&" through a shell that exits at once. Everything about R2
# and R5 depends on this shape, so the test must not shortcut it.
launch() {
	local dir=$1; shift
	( cd "$dir" && sh -c "$* > wrapper.txt 2>&1 &" )
}

echo "=============== BUILD ==============="
BUILD=$(make -C "$SRC" 2>&1)
if [ $? -eq 0 ]; then ok "wrappers build with -Wall -Wextra -Werror"; else bad "wrappers build" "$BUILD"; fi
[ -x "$WRAP" ] && ok "qemu_wrapper_telnet was produced" || bad "qemu_wrapper_telnet was produced"
# R3: the PHP invokes this exact path and includes/api_status.php pgreps these
# names. A renamed target is a silently broken teardown.
chk "the binary is named qemu_wrapper_telnet (R3)" "$(basename "$WRAP")" "qemu_wrapper_telnet"

echo
echo "=============== ARGUMENT CONTRACT ==============="
"$WRAP" -v >/dev/null 2>&1; chk "-v exits 0" "$?" "0"
has "-v prints a version" "$("$WRAP" -v 2>&1)" "1.0"
"$WRAP" -t x -- cat >/dev/null 2>&1; chk "a missing -P exits 1" "$?" "1"
has "a missing -P says why" "$("$WRAP" -t x -- cat 2>&1)" "-P is required"
"$WRAP" -P 99 >/dev/null 2>&1; chk "a missing child command exits 1" "$?" "1"
"$WRAP" -P abc -- cat >/dev/null 2>&1; chk "a non-numeric port exits 1" "$?" "1"
"$WRAP" -Q -P 99 -- cat >/dev/null 2>&1; chk "an unknown option exits 1" "$?" "1"
has "usage does not advertise the unimplemented -T/-D/-F" \
    "$(if "$WRAP" -Q 2>&1 | grep -qE '^ +-[TDF] '; then echo STALE; else echo CLEAN; fi)" "CLEAN"

# A port nothing else is on. 3xxxx is where PNETLab puts consoles, but stay off
# the range a real install would be using.
PORT=$(( 39000 + (RANDOM % 500) ))
while listening "$PORT"; do PORT=$((PORT+1)); done
DIR=$(mktemp -d /tmp/wrapper-console.XXXXXX)
trap 'sudo fuser -k -TERM "$DIR" >/dev/null 2>&1; rm -rf "$DIR"' EXIT

echo
echo "=============== LISTENER AND PROCESS SHAPE (R1-R5) ==============="
launch "$DIR" "$WRAP" -P "$PORT" -t "'TestNode'" -- cat
if wait_listen "$PORT"; then ok "the console port LISTENs (R1)"; else bad "the console port LISTENs (R1)" "$(cat "$DIR/wrapper.txt" 2>/dev/null)"; fi

# This is literally what includes/functions.php runs to decide a node is up.
netstat -a -t -n 2>/dev/null | grep LISTEN | grep -q ":$PORT" \
	&& ok "getNodeStatus()'s own netstat|grep LISTEN matches" \
	|| bad "getNodeStatus()'s own netstat|grep LISTEN matches"

WPID=$(pgrep -f "qemu_wrapper_telnet -P $PORT" | head -1)
[ -n "$WPID" ] && ok "the wrapper is running" || bad "the wrapper is running"
chk "the wrapper's cwd is the running directory (R2)" "$(readlink -f "/proc/$WPID/cwd" 2>/dev/null)" "$(readlink -f "$DIR")"
chk "the wrapper is parented to PID 1 (R5)" "$(awk '{print $4}' "/proc/$WPID/stat" 2>/dev/null)" "1"
chk "pgrep -f -c -P 1 counts it (R5)" "$(pgrep -f -c -P 1 "qemu_wrapper_telnet -P $PORT" 2>/dev/null || echo 0)" "1"
CPID=$(pgrep -P "$WPID" | head -1)
[ -n "$CPID" ] && ok "the child was forked" || bad "the child was forked"
chk "the child inherits the running directory too (R2)" "$(readlink -f "/proc/$CPID/cwd" 2>/dev/null)" "$(readlink -f "$DIR")"
chk "the wrapper heads its own process group (R4)" "$(ps -o pgid= -p "$WPID" | tr -d ' ')" "$WPID"
chk "the child is in that group (R4)" "$(ps -o pgid= -p "$CPID" | tr -d ' ')" "$WPID"
has "wrapper.txt records the listener at INF" "$(cat "$DIR/wrapper.txt")" "console listening on port $PORT"
has "wrapper.txt records the assembled child command" "$(cat "$DIR/wrapper.txt")" "/bin/sh -c \" cat\""

echo
echo "=============== CONSOLE PROTOCOL AND RELAY ==============="
PYOUT=$(PORT="$PORT" python3 - <<'PY'
import os, socket, time

port = int(os.environ["PORT"])
ok = fail = 0
def chk(label, cond, detail=""):
    global ok, fail
    if cond: print("  \033[32mok\033[0m   %s" % label); ok += 1
    else:    print("  \033[31mFAIL\033[0m %s\n       %s" % (label, str(detail)[:200])); fail += 1

def connect():
    s = socket.create_connection(("127.0.0.1", port), timeout=5)
    s.settimeout(5)
    return s

def drain(s, want, timeout=3.0):
    """Read until `want` bytes have arrived or time runs out."""
    buf = b""
    end = time.time() + timeout
    while len(buf) < want and time.time() < end:
        try:
            b = s.recv(4096)
        except socket.timeout:
            break
        if not b:
            break
        buf += b
    return buf

GREET = (b"\xff\xfb\x01"          # IAC WILL ECHO
         b"\xff\xfb\x03"          # IAC WILL SUPPRESS-GO-AHEAD
         b"\xff\xfb\x00"          # IAC WILL BINARY
         b"\xff\xfd\x00"          # IAC DO BINARY
         b"\x1b]0;TestNode\x07")  # xterm window title from -t

a = connect()
g = drain(a, len(GREET))
chk("an IPv4 client connects to the IPv6 listener", True)
chk("the telnet negotiation arrives first, as 12 bytes", g[:12] == GREET[:12], g[:12].hex())
chk("the xterm title from -t follows it", g[12:] == GREET[12:], g[12:])

a.sendall(b"hello\n")
chk("client -> child -> client relays", b"hello" in drain(a, 6), "nothing came back")

# Two viewers on one console is the whole reason this program exists rather than
# a socat one-liner: socat's `fork` gives each viewer its own connection to a
# single-writer unix socket, and they do not see each other.
b = connect()
drain(b, len(GREET))
a.sendall(b"broadcast\n")
ga = drain(a, 9)
gb = drain(b, 9)
chk("the first viewer sees output", b"broadcast" in ga, ga)
chk("a second, simultaneous viewer sees the same output", b"broadcast" in gb, gb)
b.sendall(b"from-b\n")
chk("either viewer can type", b"from-b" in drain(a, 6), "not echoed to the first viewer")

# §2.1: any three-byte IAC command from a client is swallowed, not passed on.
a.sendall(b"x\xff\xfd\x01y\n")
r = drain(a, 3)
chk("an IAC command from a client never reaches the child", b"xy" in r, r)
# §10.3 #8: the original loses the 0xFF and the two bytes behind it.
a.sendall(b"p\xff\xffq\n")
r = drain(a, 4)
chk("IAC IAC reaches the child as one literal 0xFF", b"p\xffq" in r, r)

# A viewer leaving must not disturb the others, and must not take the node down.
b.close()
time.sleep(0.3)
a.sendall(b"still-here\n")
chk("a viewer disconnecting leaves the console working",
    b"still-here" in drain(a, 10), "console died with the second viewer")
a.close()
print("PYRESULT %d %d" % (ok, fail))
PY
)
# Print the assertions, then fold their tally into the shell one, so the single
# number at the bottom means what it says.
echo "$PYOUT" | grep -v '^PYRESULT'
read -r PYOK PYFAIL < <(echo "$PYOUT" | awk '/^PYRESULT/ {print $2, $3}')
PASS=$((PASS + ${PYOK:-0}))
FAIL=$((FAIL + ${PYFAIL:-0}))

echo
echo "=============== TEARDOWN (R4) ==============="
# device::stop()'s first move, exactly: SIGTERM everything holding the running
# directory open. That is the wrapper (R2) and, because it inherits the cwd, the
# child as well.
sudo fuser -k -TERM "$DIR" >/dev/null 2>&1
if wait_dead "$WPID"; then ok "the wrapper exits on SIGTERM (R4)"; else bad "the wrapper exits on SIGTERM (R4)"; fi
if wait_dead "$CPID"; then ok "the child dies with it — no orphan (R4)"; else bad "the child dies with it — no orphan (R4)"; fi
if wait_gone "$PORT"; then ok "the port is released, so the node reads as stopped"; else bad "the port is released"; fi

echo
echo "=============== THE REAL PIPELINE (nc -U over a unix socket) ==============="
# What device_qemu.php actually builds. socat stands in for QEMU's
# -chardev socket,...,server,nowait serial port.
SOCK="$DIR/console.sock"
PORT2=$((PORT + 1))
while listening "$PORT2"; do PORT2=$((PORT2+1)); done
setsid socat "UNIX-LISTEN:$SOCK,fork" EXEC:/bin/cat >/dev/null 2>&1 &
SOCAT=$!
for _ in $(seq 1 50); do [ -S "$SOCK" ] && break; sleep 0.1; done
[ -S "$SOCK" ] && ok "a QEMU-style unix console socket exists" || bad "a QEMU-style unix console socket exists"

launch "$DIR" "$WRAP" -P "$PORT2" -t "'R1'" -- nc -U "$SOCK"
if wait_listen "$PORT2"; then ok "the wrapper serves TCP for a unix-socket console (R1)"; else bad "the wrapper serves TCP for a unix-socket console (R1)" "$(cat "$DIR/wrapper.txt")"; fi
WPID2=$(pgrep -f "qemu_wrapper_telnet -P $PORT2" | head -1)

RELAY=$(PORT="$PORT2" python3 -c '
import os, socket, time
s = socket.create_connection(("127.0.0.1", int(os.environ["PORT"])), timeout=5)
s.settimeout(4)
time.sleep(0.4)
try: s.recv(4096)
except Exception: pass
s.sendall(b"through-the-socket\n")
out = b""
end = time.time() + 3
while time.time() < end and b"through-the-socket" not in out:
    try: out += s.recv(4096)
    except Exception: break
print(out.decode(errors="replace").strip())
')
has "bytes traverse telnet -> wrapper -> nc -> unix socket and back" "$RELAY" "through-the-socket"

# R6: when QEMU goes away, nc exits, the wrapper sees the child gone and exits,
# and the port is released — which is how a crashed node comes to read as
# stopped rather than staying up forever.
kill -- "-$SOCAT" 2>/dev/null
kill "$SOCAT" 2>/dev/null
pkill -f "UNIX-LISTEN:$SOCK" 2>/dev/null
rm -f "$SOCK"
if wait_dead "$WPID2"; then ok "the wrapper follows its child out (R6)"; else bad "the wrapper follows its child out (R6)"; fi
if wait_gone "$PORT2"; then ok "the port is released when the node dies (R6)"; else bad "the port is released when the node dies (R6)"; fi

echo
echo "=============== START DELAY (-d) ==============="
PORT3=$((PORT2 + 1))
while listening "$PORT3"; do PORT3=$((PORT3+1)); done
launch "$DIR" "$WRAP" -P "$PORT3" -d 4 -t "'Slow'" -- cat
wait_listen "$PORT3" && ok "the listener is up before the delay elapses (R1)" || bad "the listener is up before the delay elapses (R1)"
DOTS=$(PORT="$PORT3" python3 -c '
import os, socket, time
s = socket.create_connection(("127.0.0.1", int(os.environ["PORT"])), timeout=5)
s.settimeout(6)
out = b""
end = time.time() + 5
while time.time() < end and b"\n" not in out:
    try: out += s.recv(4096)
    except Exception: break
print(out.decode(errors="replace").count("."))
')
# Not an exact count: the child writes its first dot the instant it is forked,
# which is before any viewer can have connected, so what a client sees depends on
# how fast it got there. What matters is that the delay is visible, and that the
# console is live during it rather than after it.
if [ "${DOTS:-0}" -ge 2 ]; then
	ok "the delay is shown to a connected viewer as dots ($DOTS of 4)"
else
	bad "the delay is shown to a connected viewer as dots" "saw ${DOTS:-0} dots"
fi
sudo fuser -k -TERM "$DIR" >/dev/null 2>&1
wait_gone "$PORT3" && ok "the delayed node tears down cleanly" || bad "the delayed node tears down cleanly"

echo
echo "============================================"
printf "  assertions: %d passed, %d failed\n" "$PASS" "$FAIL"
echo "============================================"
[ "$FAIL" -eq 0 ] || exit 1
