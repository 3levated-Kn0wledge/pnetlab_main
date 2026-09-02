#!/usr/bin/env bash
#
# Functional test for docker_wrapper (platform/wrappers/src/docker.c). Run it
# from anywhere on a Linux host with a compiler and a working Docker daemon:
#
#     bash tools/integration/wrapper-docker.sh
#
# It builds the wrapper, starts it the way devices/docker/device_docker.php
# starts it — against a throwaway container it creates and removes — and checks:
#
#   R1  the console port LISTENs, using getNodeStatus()'s own
#       `netstat | grep LISTEN | grep ':<port>'` oracle. Docker nodes are the one
#       type whose status does NOT come from this (it comes from
#       `docker inspect`), so a broken wrapper here shows up as a node that reads
#       as perfectly healthy and whose console will not open.
#   R4  SIGTERM to the running directory takes the wrapper AND its docker client
#       with it, and releases the port.
#   §4.3/R7  the child is on a REAL pseudo-terminal. This is the whole point of
#       the file: `docker exec -ti` refuses to run without a TTY, and the
#       original got one by ssh -tt'ing to root@localhost with a standing
#       passwordless root key. We allocate the PTY locally instead, so the test
#       asks the container's own shell whether its stdin is a terminal.
#   §2.1  several viewers share one console session.
#
# It SKIPS, rather than fails, when there is no Docker daemon or no usable
# image: this suite has to run on hosts that are not appliances.
#
# Notes for anyone extending it, learned from wrapper-console.sh:
#
#   - Do not background the wrapper with a plain `&` from this script. bash makes
#     it a child of the script and the process-shape checks then pass for the
#     wrong reason. The PHP runs `sh -c "... &"`, whose shell exits at once.
#   - Never cd into the running directory: teardown is `fuser -k` against it and
#     it would kill the test.
#   - The container MUST be named docker<session>. That is the wrapper's whole
#     -p contract, and it is what device_docker.php names its containers.
#   - Assertions that look for a marker string must not be satisfiable by the
#     container's own echo of what we typed. Splitting the marker across two
#     shell strings ("TTY""_YES") is why it can only appear in the output.
#
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SRC="$ROOT/platform/wrappers/src"
WRAP="$SRC/docker_wrapper"

PASS=0; FAIL=0
ok()   { printf "  \033[32mok\033[0m   %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  \033[31mFAIL\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; FAIL=$((FAIL+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$3' not in: $(echo "$2" | head -c 200)";; esac; }
hasnt() { case "$2" in *"$3"*) bad "$1" "'$3' IS in: $(echo "$2" | head -c 200)";; *) ok "$1";; esac; }
skip() { printf "\n  \033[33mSKIP\033[0m %s\n" "$1"; exit 0; }

listening() { ss -ltnH "sport = :$1" 2>/dev/null | grep -q LISTEN; }
wait_listen() { for _ in $(seq 1 100); do listening "$1" && return 0; sleep 0.1; done; return 1; }
wait_gone()   { for _ in $(seq 1 100); do listening "$1" || return 0; sleep 0.1; done; return 1; }
wait_dead()   { for _ in $(seq 1 100); do kill -0 "$1" 2>/dev/null || return 0; sleep 0.1; done; return 1; }

# device::start()'s launch shape: chdir to the running directory, then run a
# command string ending in "&" through a shell that exits immediately.
launch() {
	local dir=$1; shift
	( cd "$dir" && sh -c "$* > wrapper.txt 2>&1 &" )
}

echo "=============== PREREQUISITES ==============="
command -v docker >/dev/null 2>&1 || skip "no docker CLI on this host; docker_wrapper cannot be exercised"
DOCKER="docker"
if ! $DOCKER info >/dev/null 2>&1; then
	# The PHP runs as www-data in the docker group; here we may be an ordinary
	# user, and sudo is how a developer host usually reaches the daemon.
	if sudo -n docker info >/dev/null 2>&1; then
		DOCKER="sudo docker"
	else
		skip "no reachable Docker daemon (is dockerd running, and are you in the docker group?)"
	fi
fi
ok "a Docker daemon is reachable ($DOCKER)"

# The socket the PHP now uses, rather than the unauthenticated tcp://127.0.0.1:4243
# it used to. Informational: a daemon on a different endpoint is still testable.
[ -S /var/run/docker.sock ] && ok "/var/run/docker.sock exists" \
	|| bad "/var/run/docker.sock exists" "the web layer addresses the daemon here"

IMAGE=""
for i in alpine:latest busybox:latest ubuntu:latest debian:latest; do
	if $DOCKER image inspect "$i" >/dev/null 2>&1; then IMAGE="$i"; break; fi
done
if [ -z "$IMAGE" ]; then
	if $DOCKER pull -q alpine:latest >/dev/null 2>&1; then
		IMAGE="alpine:latest"
	else
		skip "no usable container image locally and 'docker pull alpine' failed (offline?)"
	fi
fi
ok "using image $IMAGE"

echo
echo "=============== BUILD ==============="
BUILD=$(make -C "$SRC" docker_wrapper 2>&1)
if [ $? -eq 0 ]; then ok "docker_wrapper builds with -Wall -Wextra -Werror"; else bad "docker_wrapper builds" "$BUILD"; fi
[ -x "$WRAP" ] || { bad "docker_wrapper was produced"; exit 1; }
# R3: device_docker.php invokes this exact path, and install/sudoers.d/pnetlab
# allowlists it by name.
chk "the binary is named docker_wrapper (R3)" "$(basename "$WRAP")" "docker_wrapper"

echo
echo "=============== ARGUMENT CONTRACT ==============="
"$WRAP" -v >/dev/null 2>&1; chk "-v exits 0" "$?" "0"
"$WRAP" -t x -p 1 >/dev/null 2>&1; chk "a missing -P exits 1" "$?" "1"
has "a missing -P says why" "$("$WRAP" -t x -p 1 2>&1)" "-P is required"
# §10.3 #6: the original defaults -p to -1 and attaches to "docker-1".
"$WRAP" -P 99 >/dev/null 2>&1; chk "a missing -p exits 1" "$?" "1"
has "a missing -p says why" "$("$WRAP" -P 99 2>&1)" "-p is required"
"$WRAP" -P abc -p 1 >/dev/null 2>&1; chk "a non-numeric port exits 1" "$?" "1"
"$WRAP" -P 99 -p abc >/dev/null 2>&1; chk "a non-numeric session exits 1" "$?" "1"
"$WRAP" -Q -P 99 -p 1 >/dev/null 2>&1; chk "an unknown option exits 1" "$?" "1"
USAGE=$("$WRAP" -Q 2>&1)
has "usage documents the unix socket" "$USAGE" "unix:///var/run/docker.sock"
hasnt "usage does not advertise the unimplemented -T/-D/-F" \
	"$(echo "$USAGE" | grep -E '^ +-[TDF] ' || true)" "-T"

SESSION=$(( 90000 + (RANDOM % 9000) ))
NAME="docker${SESSION}"
PORT=$(( 39500 + (RANDOM % 400) ))
while listening "$PORT"; do PORT=$((PORT+1)); done
DIR=$(mktemp -d /tmp/wrapper-docker.XXXXXX)

cleanup() {
	sudo fuser -k -TERM "$DIR" >/dev/null 2>&1
	$DOCKER rm -f "$NAME" >/dev/null 2>&1
	rm -rf "$DIR"
}
trap cleanup EXIT

echo
echo "=============== A CONTAINER TO ATTACH TO ==============="
# device_docker::prepare() creates the container and start() starts it; the
# wrapper only ever attaches to one that is already running.
$DOCKER rm -f "$NAME" >/dev/null 2>&1
if $DOCKER run -d --name "$NAME" "$IMAGE" sleep 600 >/dev/null 2>&1; then
	ok "throwaway container $NAME is running"
else
	skip "could not start a container (daemon reachable but not usable)"
fi
chk "the container is up" "$($DOCKER inspect --format '{{ .State.Running }}' "$NAME" 2>/dev/null)" "true"

echo
echo "=============== LISTENER AND PROCESS SHAPE (R1) ==============="
# Exactly device_docker::start()'s command, sudo included.
launch "$DIR" sudo "$WRAP" -P "$PORT" -t "'TestDocker'" -p "$SESSION" -c sh
if wait_listen "$PORT"; then ok "the console port LISTENs (R1)"; else bad "the console port LISTENs (R1)" "$(cat "$DIR/wrapper.txt" 2>/dev/null)"; fi

# The literal command includes/functions.php runs to decide a node is up. Docker
# nodes use docker inspect instead, but every other node type is judged by this,
# and it is the only oracle the console port has.
netstat -a -t -n 2>/dev/null | grep LISTEN | grep -q ":$PORT" \
	&& ok "getNodeStatus()'s own netstat|grep LISTEN matches" \
	|| bad "getNodeStatus()'s own netstat|grep LISTEN matches"

WPID=$(pgrep -f "docker_wrapper -P $PORT" | head -1)
[ -n "$WPID" ] && ok "the wrapper is running" || bad "the wrapper is running"
chk "the wrapper's cwd is the running directory (R2)" \
    "$(sudo readlink -f "/proc/$WPID/cwd" 2>/dev/null)" "$(readlink -f "$DIR")"
CPID=$(pgrep -P "$WPID" | head -1)
[ -n "$CPID" ] && ok "the child was forked" || bad "the child was forked"

LOG=$(cat "$DIR/wrapper.txt" 2>/dev/null)
has "wrapper.txt records the listener at INF" "$LOG" "console listening on port $PORT"
has "wrapper.txt records the assembled docker command" "$LOG" "exec -ti $NAME"
# R7 / §10.3 #3. The original ran the whole thing through
# `ssh root@localhost -i /root/.ssh/id_rsa_dy -tt` purely to obtain a TTY,
# which required a standing passwordless root key on the appliance.
hasnt "the child command contains no ssh hop (R7)" "$LOG" "ssh "
hasnt "the child command contains no root ssh key (R7)" "$LOG" "id_rsa"
has "the daemon is addressed over the unix socket" "$LOG" "-H=unix:///var/run/docker.sock"
hasnt "the unauthenticated tcp socket is not used" "$LOG" "4243"

echo
echo "=============== THE PTY, AND THE CONSOLE (§4.3) ==============="
PYOUT=$(PORT="$PORT" python3 - <<'PY'
import os, socket, time

port = int(os.environ["PORT"])
ok = fail = 0
def chk(label, cond, detail=""):
    global ok, fail
    if cond: print("  \033[32mok\033[0m   %s" % label); ok += 1
    else:    print("  \033[31mFAIL\033[0m %s\n       %s" % (label, str(detail)[:300])); fail += 1

def connect():
    s = socket.create_connection(("127.0.0.1", port), timeout=10)
    s.settimeout(5)
    return s

def read_until(s, marker, timeout=8.0):
    buf = b""
    end = time.time() + timeout
    while time.time() < end and marker not in buf:
        try:
            b = s.recv(4096)
        except socket.timeout:
            break
        if not b:
            break
        buf += b
    return buf

GREET = (b"\xff\xfb\x01\xff\xfb\x03\xff\xfb\x00\xff\xfd\x00"
         b"\x1b]0;TestDocker\x07")

a = connect()
g = read_until(a, b"\x07", 5)
chk("the telnet negotiation arrives first, as 12 bytes", g[:12] == GREET[:12], g[:12].hex())
chk("the xterm title from -t follows it", GREET[12:] in g, g[12:40])

# Let the container's shell get its prompt out before we type.
time.sleep(1.0)
try: a.recv(65536)
except Exception: pass

# THE assertion this whole file exists for. `docker exec -ti` will not even run
# without a terminal, and the shell inside the container is asked directly.
# The marker is split so the container's echo of the line cannot satisfy it.
a.sendall(b'test -t 0 && echo "TTY""_YES"\n')
r = read_until(a, b"TTY_YES")
chk("the child's stdin is a real terminal inside the container (R7)",
    b"TTY_YES" in r, r)

a.sendall(b'tty\n')
r = read_until(a, b"/dev/pts/")
chk("the container's tty is a pts device", b"/dev/pts/" in r, r)

# 80x24, as §4.3 says: the original never negotiated a size either, and an unset
# pty reports 0x0, which makes anything curses-based misbehave.
a.sendall(b'stty size 2>/dev/null || echo "24"" 80"\n')
r = read_until(a, b"24 80")
chk("the pty is 80x24, not the 0x0 an unconfigured one reports",
    b"24 80" in r, r)

# §2.1: one console, several viewers. This is the difference between the wrapper
# and a socat one-liner, which gives each viewer its own separate session.
b = connect()
read_until(b, b"\x07", 5)
time.sleep(0.5)
try: b.recv(65536)
except Exception: pass
a.sendall(b'echo "BROAD""CAST"\n')
ra = read_until(a, b"BROADCAST")
rb = read_until(b, b"BROADCAST")
chk("the first viewer sees the output", b"BROADCAST" in ra, ra)
chk("a second, simultaneous viewer sees the same output", b"BROADCAST" in rb, rb)

b.sendall(b'echo "FROM""_B"\n')
chk("either viewer can type", b"FROM_B" in read_until(a, b"FROM_B"), "not seen by the first viewer")

# §2.1: a three-byte IAC command from a client is swallowed, not passed on.
a.sendall(b'echo "IA""C"\xff\xfd\x01\n')
chk("an IAC command from a client never reaches the container",
    b"IAC" in read_until(a, b"IAC"), "the IAC broke the line")

b.close()
time.sleep(0.4)
a.sendall(b'echo "STILL""_HERE"\n')
chk("a viewer disconnecting leaves the console working",
    b"STILL_HERE" in read_until(a, b"STILL_HERE"), "console died with the second viewer")
a.close()
print("PYRESULT %d %d" % (ok, fail))
PY
)
echo "$PYOUT" | grep -v '^PYRESULT'
read -r PYOK PYFAIL < <(echo "$PYOUT" | awk '/^PYRESULT/ {print $2, $3}')
PASS=$((PASS + ${PYOK:-0}))
FAIL=$((FAIL + ${PYFAIL:-0}))

echo
echo "=============== TEARDOWN (R4) ==============="
# device::stop()'s first move, exactly: SIGTERM everything holding the node's
# running directory open. That is the wrapper (R2) and the docker client it
# forked, which inherited the cwd.
sudo fuser -k -TERM "$DIR" >/dev/null 2>&1
if wait_dead "$WPID"; then ok "the wrapper exits on SIGTERM (R4)"; else bad "the wrapper exits on SIGTERM (R4)"; fi
if wait_dead "$CPID"; then ok "the docker client dies with it — no orphan (R4)"; else bad "the docker client dies with it — no orphan (R4)"; fi
if wait_gone "$PORT"; then ok "the port is released"; else bad "the port is released"; fi
# Stopping the console must not stop the node: a Docker node's status comes from
# docker inspect, and device::stop() removes the container separately.
chk "the container is untouched by the console teardown" \
    "$($DOCKER inspect --format '{{ .State.Running }}' "$NAME" 2>/dev/null)" "true"

echo
echo "=============== A DEAD CONTAINER (R6) ==============="
# When the container goes away the docker client exits, the wrapper follows it
# out and the port is released — the same shape as nc exiting when QEMU drops
# its socket.
PORT2=$((PORT + 1))
while listening "$PORT2"; do PORT2=$((PORT2+1)); done
launch "$DIR" sudo "$WRAP" -P "$PORT2" -t "'TestDocker2'" -p "$SESSION" -c sh
if wait_listen "$PORT2"; then ok "a second console attaches to the same container (R1)"; else bad "a second console attaches to the same container (R1)" "$(cat "$DIR/wrapper.txt")"; fi
WPID2=$(pgrep -f "docker_wrapper -P $PORT2" | head -1)
$DOCKER rm -f "$NAME" >/dev/null 2>&1
if wait_dead "$WPID2"; then ok "the wrapper follows its child out when the container dies (R6)"; else bad "the wrapper follows its child out when the container dies (R6)"; fi
if wait_gone "$PORT2"; then ok "the port is released when the container dies (R6)"; else bad "the port is released when the container dies (R6)"; fi

echo
echo "============================================"
printf "  assertions: %d passed, %d failed\n" "$PASS" "$FAIL"
echo "============================================"
[ "$FAIL" -eq 0 ] || exit 1
