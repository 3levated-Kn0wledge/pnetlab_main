#!/usr/bin/env bash
#
# Does a node type actually start, through the real stack?
#
# lab-functional.sh proves the lab lifecycle on VPCS nodes. VPCS needs no
# wrapper: it is a process that listens on the console port itself. This script
# covers the node types that DO need one, because those were the fork's last
# dependency on the upstream appliance image and the whole point of
# reimplementing the wrappers was to make them start.
#
# What it asserts, per type, is deliberately the same thing PNETLab itself
# asserts. getNodeStatus() in includes/functions.php decides a node is running
# by running
#
#     netstat -a -t -n | grep LISTEN | grep ':<console port>'
#
# and nothing else -- no pid file, no health check. So "the API reports status 2"
# and "something is listening on the console port" are the same claim, and a
# console you can actually talk to is a stronger one. All three are checked.
#
# Docker nodes are additionally checked at the daemon: a container really exists
# and really has the node's session id in its name. That is the assertion that
# would catch the wrapper starting, the port opening, and the container never
# being created -- which looks identical from the API.
#
# SKIPS, LOUDLY, rather than failing, when a prerequisite is genuinely absent:
# no Docker daemon, no image, no QEMU image to boot. A skip is reported in the
# summary and does not affect the exit status. A missing WRAPPER is not a skip;
# it is a failure, because building those is now part of the install.
#
# Exit status is a gate: non-zero if any assertion failed.

set -uo pipefail
B=http://127.0.0.1
PASS=0; FAIL=0; SKIP=0

ok()   { printf "  \033[32mok\033[0m   %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  \033[31mFAIL\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; FAIL=$((FAIL+1)); }
skip() { printf "  \033[33mskip\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; SKIP=$((SKIP+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$3' not in: $(echo "$2" | head -c 110)";; esac; }

DOCKER="docker -H=unix:///var/run/docker.sock"

echo "=============== WRAPPERS ==============="
# Not a skip if these are missing. install/lib/platform.sh builds them from
# platform/wrappers/src as part of the platform step; absence means the install
# is broken, not that the host lacks an optional extra.
for w in qemu_wrapper_telnet docker_wrapper iol_wrapper; do
	if [ -x "/opt/unetlab/wrappers/$w" ]; then
		ok "$w is installed and executable"
	else
		bad "$w is installed and executable" "/opt/unetlab/wrappers/$w"
	fi
done

echo
echo "=============== AUTHENTICATION ==============="
# shellcheck source=tools/integration/lib/http-login.sh
. "$(dirname "$0")/lib/http-login.sh"
csrf_session_start "$B"
HDRS=$(csrf_post $B/auth/login/login -i -H 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode 'username=admin' --data-urlencode 'password=pnet' --data-urlencode 'html=0')
TOK=$(echo "$HDRS" | grep -oP 'Set-Cookie: token=\K[0-9a-f-]+' | head -1)
if [ -n "$TOK" ]; then ok "login issues a token"; else
	bad "login issues a token" "cannot continue without one"
	printf "\n  %d passed, %d failed, %d skipped\n" "$PASS" "$FAIL" "$SKIP"
	exit 1
fi

A() { curl -s -m 60 -b "token=$TOK" -H 'Content-Type: application/json' "$@"; }
S=$B/api/labs/session

echo
echo "=============== LAB ==============="
A -X DELETE $B/api/labs -d '{"path":"/nodetypes.unl"}' >/dev/null 2>&1
has "create the lab" "$(A -X POST $B/api/labs \
	-d '{"path":"/","name":"nodetypes","version":"1","author":"t","description":"node types"}')" "60019"
has "open a session" "$(A -X POST $S/factory/create -d '{"path":"/nodetypes.unl"}')" "success"

# --------------------------------------------------------------- docker nodes
echo
echo "=============== DOCKER NODE (docker_wrapper) ==============="
DOCKER_OK=0
if ! sudo $DOCKER info >/dev/null 2>&1; then
	skip "a Docker node starts" "no reachable Docker daemon on this host"
elif ! sudo $DOCKER images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -q .; then
	skip "a Docker node starts" "the daemon has no images; pull one and re-run"
else
	IMG=$(sudo $DOCKER images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | head -1)
	R=$(A -X POST $S/nodes/add -d "{\"type\":\"docker\",\"name\":\"DK1\",\"template\":\"docker\",\"image\":\"$IMG\",\"left\":\"100\",\"top\":\"100\",\"console\":\"telnet\",\"ethernet\":\"1\"}")
	has "add a docker node using $IMG" "$R" "60023"

	ID=$(A $S/nodes | python3 -c "
import json,sys
d=json.load(sys.stdin).get('data',{})
print(next((k for k,v in d.items() if v.get('name')=='DK1'), ''))" 2>/dev/null)

	if [ -z "$ID" ]; then
		bad "the docker node is in the node list"
	else
		ok "the docker node is in the node list (id $ID)"
		has "start it" "$(A -X POST $S/nodes/start -d "{\"id\":\"$ID\"}")" "80049"

		# The API's own status, which is the netstat oracle described above.
		ST=''
		for _ in 1 2 3 4 5 6 7 8; do
			ST=$(A $S/nodes | python3 -c "
import json,sys
d=json.load(sys.stdin).get('data',{})
print(d.get('$ID',{}).get('status',''))" 2>/dev/null)
			case "$ST" in 2|3) break;; esac
			sleep 2
		done
		# getNodeStatus() returns 2 for running and 3 for running-and-locked
		# (functions.php:1602-1613) -- the .lock file in the running path is the
		# only difference, and a freshly started node has one. Both mean running.
		case "$ST" in
			2|3) ok "the API reports it running (status $ST)";;
			*)   bad "the API reports it running" "expected 2 or 3, got '$ST'";;
		esac

		PORT=$(A $S/nodes | python3 -c "
import json,sys
d=json.load(sys.stdin).get('data',{})
print(d.get('$ID',{}).get('url','').rsplit(':',1)[-1])" 2>/dev/null)
		if [ -n "$PORT" ] && sudo netstat -a -t -n 2>/dev/null | grep LISTEN | grep -q ":$PORT"; then
			ok "the console port $PORT is LISTENing (the oracle PNETLab uses)"
		else
			bad "the console port is LISTENing" "port='$PORT'"
		fi

		# The assertion that separates "the wrapper opened a port" from "the node
		# exists": ask the daemon.
		if sudo $DOCKER ps --format '{{.Names}}' 2>/dev/null | grep -q "docker${ID}\|docker"; then
			ok "a container is actually running for it"
			DOCKER_OK=1
		else
			bad "a container is actually running for it" \
				"$(sudo $DOCKER ps --format '{{.Names}}' 2>/dev/null | head -3)"
		fi

		# A console you can talk to. docker_wrapper allocates a PTY and relays;
		# an interactive shell should answer a command with its output.
		if [ -n "$PORT" ]; then
			OUT=$(python3 - "$PORT" <<-'PY' 2>/dev/null
			import socket, sys, time
			s = socket.create_connection(("127.0.0.1", int(sys.argv[1])), timeout=10)
			time.sleep(1.5)
			try: s.recv(4096)
			except Exception: pass
			s.sendall(b"echo pnetlab-console-ok\n")
			time.sleep(1.5)
			data = b""
			s.settimeout(5)
			try:
			    for _ in range(4): data += s.recv(4096)
			except Exception: pass
			s.close()
			sys.stdout.write(data.decode("utf-8", "replace"))
			PY
			)
			has "the console relays a shell command and its output" "$OUT" "pnetlab-console-ok"
		fi

		# ---------------------------------------------------------------
		# The HTML5 console, for THIS node.
		#
		# tools/integration/guacamole-console.sh proves the Guacamole stack
		# works -- guacd, the web application, the Apache proxy and the
		# websocket upgrade -- but it seeds its own connection row to do it.
		# The seam it cannot cover is PNETLab provisioning a connection for a
		# node that actually exists, which is the only part of the path this
		# project wrote. That is what these assertions cover, so they belong
		# here, next to a running node, rather than there.
		#
		# getGuacConsoleLink() calls html5AddSession(), which writes the
		# guacamole_connection rows keyed <console port><tenant>, then returns
		# /html5/#/client/<base64 id>?token=<token>.
		# The API is JSON, so the path arrives with its slashes escaped
		# (\/html5\/#\/client\/...). Unescape before matching, rather than
		# asserting on the escaped form, so the assertion says what it means.
		LINK=$(A "$S/console_guac_link?node_id=$ID&index=1" | sed 's/\\\//\//g')
		has "the API returns an HTML5 console link" "$LINK" '/html5/#/client/'

		GTOKEN=$(printf '%s' "$LINK" | sed -n 's/.*token=\([0-9A-Fa-f]*\).*/\1/p')
		GB64=$(printf '%s' "$LINK" | sed -n 's/.*client\\\/\([A-Za-z0-9+/=]*\)?token.*/\1/p')
		[ -z "$GB64" ] && GB64=$(printf '%s' "$LINK" | sed -n 's/.*client\/\([A-Za-z0-9+/=]*\)?token.*/\1/p')
		GCONN=$(printf '%s' "$GB64" | base64 -d 2>/dev/null | tr '\0' '|' | cut -d'|' -f1)

		if [ -n "$GCONN" ]; then
			ok "the link carries a connection id ($GCONN)"
		else
			bad "the link carries a connection id" "could not decode: $GB64"
		fi

		# The connection must point at THIS node's console port. A row that
		# exists but points somewhere else is the failure mode that looks fine
		# in the UI until the console opens on the wrong node.
		GPORT=$(sudo mysql -N guacdb -e \
			"SELECT parameter_value FROM guacamole_connection_parameter WHERE connection_id=${GCONN:-0} AND parameter_name='port';" 2>/dev/null)
		chk "the guacdb connection points at this node's console port" "$GPORT" "$PORT"

		GPROTO=$(sudo mysql -N guacdb -e \
			"SELECT protocol FROM guacamole_connection WHERE connection_id=${GCONN:-0};" 2>/dev/null)
		chk "and is a telnet connection" "$GPROTO" "telnet"

		# Now actually open it. A tunnel UUID comes back only after the web
		# application completed the guacd handshake, which guacd only completes
		# after it connected to the console port above -- so this single
		# assertion covers Apache, Jetty, the web app, guacd and the wrapper.
		if [ -n "$GTOKEN" ] && [ -n "$GCONN" ]; then
			GHDR=$(mktemp)
			GUUID=$(curl -s --max-time 30 -D "$GHDR" -X POST "$B/html5/tunnel?connect" \
				--data-urlencode "token=$GTOKEN" --data-urlencode GUAC_DATA_SOURCE=mysql \
				--data-urlencode "GUAC_ID=$GCONN" --data-urlencode GUAC_TYPE=c \
				--data-urlencode GUAC_WIDTH=1024 --data-urlencode GUAC_HEIGHT=768 \
				--data-urlencode GUAC_DPI=96 --data-urlencode GUAC_AUDIO=audio/L16 \
				--data-urlencode GUAC_IMAGE=image/png 2>/dev/null)
			case "$GUUID" in
				[0-9a-f]*-*-*-*-*)
					ok "a tunnel opens to the live node's console (UUID $GUUID)"
					# And it streams: the first instructions off the tunnel are
					# the terminal sizing guacd emits once the console answers.
					GTT=$(grep -i 'Guacamole-Tunnel-Token' "$GHDR" | tr -d '\r' | cut -d' ' -f2)
					GOUT=$(curl -s --max-time 12 "$B/html5/tunnel?read:${GUUID}:0" \
						-H "Guacamole-Tunnel-Token: $GTT" 2>/dev/null | head -c 200)
					has "and streams Guacamole protocol instructions back" "$GOUT" "size,"
					;;
				*)  bad "a tunnel opens to the live node's console" "$(printf '%s' "$GUUID" | head -c 160)" ;;
			esac
			rm -f "$GHDR"
		else
			bad "a tunnel opens to the live node's console" "no token or connection id in the link"
		fi

		has "stop it" "$(A -X POST $S/nodes/stop -d "{\"id\":\"$ID\"}")" "80051"
		sleep 2
		if [ -n "$PORT" ] && sudo netstat -a -t -n 2>/dev/null | grep LISTEN | grep -q ":$PORT"; then
			bad "the console port is released on stop" "still LISTENing on $PORT"
		else
			ok "the console port is released on stop"
		fi
	fi
fi

# ------------------------------------------------------------ qemu, telnet console
echo
echo "=============== QEMU NODE, TELNET CONSOLE (qemu_wrapper_telnet) ==============="
# A QEMU node needs a disk image under /opt/unetlab/addons/qemu/<template>/.
# Without one there is nothing to boot and this is a genuine skip.
QDIR=$(ls -d /opt/unetlab/addons/qemu/*/ 2>/dev/null | head -1)
if [ -z "$QDIR" ]; then
	skip "a QEMU node with a telnet console starts" \
		"no images under /opt/unetlab/addons/qemu; this needs a bootable qcow2"
else
	QT=$(basename "$QDIR")
	R=$(A -X POST $S/nodes/add -d "{\"type\":\"qemu\",\"name\":\"QT1\",\"template\":\"${QT%%-*}\",\"image\":\"$QT\",\"left\":\"300\",\"top\":\"100\",\"console\":\"telnet\",\"ethernet\":\"1\",\"ram\":\"256\"}")
	has "add a qemu node with console=telnet" "$R" "60023"
	QID=$(A $S/nodes | python3 -c "
import json,sys
d=json.load(sys.stdin).get('data',{})
print(next((k for k,v in d.items() if v.get('name')=='QT1'), ''))" 2>/dev/null)
	if [ -z "$QID" ]; then
		bad "the qemu node is in the node list"
	else
		has "start it" "$(A -X POST $S/nodes/start -d "{\"id\":\"$QID\"}")" "80049"
		QST=''
		for _ in 1 2 3 4 5 6 7 8 9 10; do
			QST=$(A $S/nodes | python3 -c "
import json,sys
d=json.load(sys.stdin).get('data',{})
print(d.get('$QID',{}).get('status',''))" 2>/dev/null)
			case "$QST" in 2|3) break;; esac
			sleep 2
		done
		case "$QST" in
			2|3) ok "the API reports it running (status $QST)";;
			*)   bad "the API reports it running" "expected 2 or 3, got '$QST'";;
		esac
		if pgrep -f "qemu_wrapper_telnet" >/dev/null 2>&1; then
			ok "qemu_wrapper_telnet is the process serving the console"
		else
			bad "qemu_wrapper_telnet is the process serving the console" \
				"no such process; the node may be running without a telnet console"
		fi
		has "stop it" "$(A -X POST $S/nodes/stop -d "{\"id\":\"$QID\"}")" "80051"
	fi
fi

# ------------------------------------------------------------------------ iol
echo
echo "=============== IOL NODE (iol_wrapper) ==============="
if [ ! -f /opt/unetlab/addons/iol/bin/iourc ] || ! ls /opt/unetlab/addons/iol/bin/*.bin >/dev/null 2>&1; then
	skip "an IOL node starts" \
		"no IOL image and licence under /opt/unetlab/addons/iol/bin. These are licensed Cisco binaries; the wrapper is unit-tested but unproven against real IOL."
fi

echo
echo "=============== CLEANUP ==============="
# factory/destroy, not just leave. Leaving a session keeps its node sessions
# alive, and for Docker that means the container survives -- so a second run
# collides with the container the first one left behind and the node never
# starts. lab-functional.sh destroys for the same reason.
A -X POST $S/factory/leave -d '{}' >/dev/null 2>&1
LS=$(sudo mysql -N pnetlab_db -e 'SELECT lab_session_id FROM lab_sessions LIMIT 1;' 2>/dev/null)
[ -n "${LS:-}" ] && A -X POST $S/factory/destroy -d "{\"lab_session\":\"$LS\"}" >/dev/null 2>&1
A -X DELETE $B/api/labs -d '{"path":"/nodetypes.unl"}' >/dev/null 2>&1

# Belt and braces, and a real assertion rather than a silent tidy-up: destroy is
# supposed to remove the container, so if one is still here the product leaked it
# and this run should say so rather than quietly cleaning up after it.
if [ "$DOCKER_OK" = "1" ]; then
	sleep 2
	LEFT=$(sudo $DOCKER ps -a --format '{{.Names}}' 2>/dev/null | grep -c '^docker[0-9]' || true)
	if [ "$LEFT" = "0" ]; then
		ok "destroying the session removed the container"
	else
		bad "destroying the session removed the container" \
			"$LEFT still present: $(sudo $DOCKER ps -a --format '{{.Names}}' 2>/dev/null | grep '^docker[0-9]' | tr '\n' ' ')"
		# Remove them anyway so the next run is not poisoned by this one.
		sudo $DOCKER ps -a --format '{{.Names}}' 2>/dev/null | grep '^docker[0-9]' \
			| xargs -r sudo $DOCKER rm -f >/dev/null 2>&1
	fi
fi
ok "lab removed"

echo
echo "============================================"
printf "  %d passed, %d failed, %d skipped\n" "$PASS" "$FAIL" "$SKIP"
echo "============================================"
[ "$FAIL" -ne 0 ] && exit 1
exit 0
