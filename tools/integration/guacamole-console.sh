#!/usr/bin/env bash
#
# Functional test for HTML5 consoles. Run as a user with sudo, ON the host,
# against an install that has had the `guacamole` step applied:
#
#     bash tools/integration/guacamole-console.sh
#
# It proves the thing that is actually hard about this integration and that no
# amount of "is the port open" checking establishes: that noble's guacd 1.3.0
# and a Guacamole 1.5.5 web application negotiate a working connection. It does
# that twice, from both ends —
#
#   * directly against guacd on 127.0.0.1:4822, speaking the Guacamole wire
#     protocol by hand, and asserting the literal `ready` opcode comes back;
#   * through the web application's own tunnel endpoint, with a real token
#     minted by a real POST /api/tokens, asserting a tunnel UUID and then real
#     protocol instructions on the read stream.
#
# Both are needed, because `ready` is NOT visible from the browser side: the web
# application's ConfiguredGuacamoleSocket consumes it during the handshake to
# extract the connection UUID, so a client never sees it. A tunnel UUID coming
# back IS proof that guacd sent `ready` — the socket constructor throws
# otherwise — but it is proof by inference, and this is the risk the whole
# version-pairing decision rests on, so it gets a direct measurement too.
#
# The test invents nothing. It reads the database credentials out of the
# guacamole.properties the installer wrote, and it writes exactly the rows the
# application writes — store/app/Http/Controllers/Auth/LoginController.php for
# the user, includes/functions.php html5AddSession() for the connection — then
# removes them again.
#
# Notes for anyone extending it, learned by getting these wrong:
#
#   - The HTTP tunnel lives at <ctx>/tunnel, NOT <ctx>/api/tunnel, and the
#     servlet compares the query string with `query.equals("connect")`, so
#     ?connect&token=... is a 400. The token goes in the POST body.
#   - From 1.4.0 the connect response carries a Guacamole-Tunnel-Token header
#     and every later request on that tunnel must echo it, or the servlet
#     answers 400 "The HTTP tunnel session token is required".
#   - A telnet console only needs something accepting TCP at the other end for
#     guacd to reach `ready`. socat with /bin/cat is that, and it means this
#     test does not need a running lab.
#   - connection_id is the string CONCATENATION of port and tenant id, not a
#     sum. 32769 + tenant 0 is "327690".
#
set -uo pipefail

PASS=0; FAIL=0
ok()   { printf "  \033[32mok\033[0m   %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  \033[31mFAIL\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; FAIL=$((FAIL+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$3' not in: $(echo "$2" | head -c 160)";; esac; }
skip() { printf "  \033[33mskip\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; }

PROPS=/etc/guacamole/guacamole.properties
WAR=/var/lib/jetty9/webapps/html5.war
EXTDIR=/etc/guacamole/extensions
JETTY_BASE=${GUAC_BASE:-http://127.0.0.1:8080/html5}
APACHE_BASE=${GUAC_APACHE_BASE:-http://127.0.0.1/html5}

# Far away from anything real. Tenant ids are pod+1000 and connection ids are
# <console port><tenant>, so nothing the application creates collides here.
T_ENTITY=990001
T_CONN=9909990
T_USER=guac-selftest
T_PASS=guac-selftest-secret
T_PORT=$(( 39500 + (RANDOM % 400) ))

echo "=============== PREREQUISITES ==============="
if [ ! -f "$WAR" ]; then
	echo "  HTML5 consoles are not installed ($WAR is absent)."
	echo "  Stage the artefacts with tools/vendor-guacamole.sh and run"
	echo "  sudo install/install.sh --only guacamole first."
	exit 2
fi
for b in curl python3 socat ss; do
	command -v "$b" >/dev/null || { echo "  missing required tool: $b"; exit 2; }
done
sudo -n true 2>/dev/null || { echo "  this test needs passwordless sudo (to read $PROPS)"; exit 2; }

GUACD_V=$(dpkg-query -W -f='${Version}' guacd 2>/dev/null)
WAR_V=$(unzip -p "$WAR" 'META-INF/maven/org.apache.guacamole/guacamole/pom.properties' 2>/dev/null |
        sed -n 's/^version=//p' | tr -d '\r')
EXT=$(find "$EXTDIR" -maxdepth 1 -name 'guacamole-auth-jdbc-mysql-*.jar' -printf '%f\n' 2>/dev/null)
EXT_N=$(printf '%s\n' "$EXT" | grep -c . )
EXT_V=${EXT#guacamole-auth-jdbc-mysql-}; EXT_V=${EXT_V%.jar}
echo "  guacd ${GUACD_V:-?} / war ${WAR_V:-?} / extension ${EXT_V:-none}"

# --- the version pairing. This is the plan's top risk, asserted rather than
# --- assumed: an extension that does not match its war is a startup failure,
# --- and a guacd that does not match the war is the pairing under test.
chk "exactly one JDBC auth extension is installed" "$EXT_N" "1"
chk "the auth extension and the .war are the same version" "$EXT_V" "$WAR_V"
case "$GUACD_V" in
	"${WAR_V}"*) ok "guacd and the web application are the same version" ;;
	*) printf "  \033[33mnote\033[0m guacd %s against web app %s — the mismatch under test.\n" \
	          "$GUACD_V" "$WAR_V"
	   printf "       This is the SAFE direction (1.5.0 added a version handshake so a\n"
	   printf "       newer client negotiates down) but it is exactly what the tunnel\n"
	   printf "       assertions below exist to prove. If they fail, re-run the install\n"
	   printf "       with --guacamole-version %s and nothing else changes.\n" "${GUACD_V%%-*}" ;;
esac

for p in vnc rdp ssh telnet; do
	if dpkg-query -W -f='${Status}' "libguac-client-${p}0t64" 2>/dev/null | grep -q '^install ok installed'; then
		ok "protocol client library for ${p} is installed"
	else
		# telnet is PNETLab's DEFAULT console protocol and is only a Suggests: of
		# guacd, so its absence looks like "consoles are broken", not "a package
		# is missing". That is why it is a failure here and not a note.
		bad "protocol client library for ${p} is installed" "libguac-client-${p}0t64 is missing"
	fi
done

echo
echo "=============== CONFIGURATION ==============="
chk "guacamole.properties is 0640 root:jetty" "$(stat -c '%a %U %G' "$PROPS" 2>/dev/null)" "640 root jetty"
PROPTXT=$(sudo cat "$PROPS")
has "the JDBC driver is selected explicitly"      "$PROPTXT" "mysql-driver: mariadb"
# Without this, every login is a 500 whose message never mentions TLS. See the
# comment in install/guacamole/guacamole.properties.in.
has "TLS to the local database is disabled"       "$PROPTXT" "mysql-ssl-mode: disabled"
has "duplicate connections are allowed"           "$PROPTXT" "mysql-disallow-duplicate-connections: false"
# includes/init.php sets SESSION = 3600. These two must move together.
has "the API session timeout is pinned to 60m"    "$PROPTXT" "api-session-timeout: 60"
[ -e /etc/guacamole/lib/mariadb-java-client.jar ] \
	&& ok "the JDBC driver is present in /etc/guacamole/lib" \
	|| bad "the JDBC driver is present in /etc/guacamole/lib"

if ss -ltnH 2>/dev/null | grep -qE '(0\.0\.0\.0|\*|\[::\]):8080'; then
	bad "Jetty is bound to the loopback only" "something is listening on *:8080"
else
	ok "Jetty is bound to the loopback only"
fi

GDB=$(printf '%s\n' "$PROPTXT"  | sed -n 's/^mysql-database:[[:space:]]*//p' | tr -d '\r')
GUSER=$(printf '%s\n' "$PROPTXT"| sed -n 's/^mysql-username:[[:space:]]*//p' | tr -d '\r')
GPASS=$(printf '%s\n' "$PROPTXT"| sed -n 's/^mysql-password:[[:space:]]*//p' | tr -d '\r')
[ -n "$GDB" ] && ok "the properties name a database ($GDB)" || bad "the properties name a database"

# MYSQL_PWD, not -p: a password on the command line is visible in ps to every
# account on the box.
db() { MYSQL_PWD="$GPASS" mariadb -h 127.0.0.1 -u "$GUSER" -N -B "$GDB" "$@"; }
db -e 'SELECT 1;' >/dev/null 2>&1 \
	&& ok "the credentials in guacamole.properties actually work" \
	|| bad "the credentials in guacamole.properties actually work" \
	       "cannot connect to $GDB as $GUSER over 127.0.0.1"

echo
echo "=============== FIXTURE ==============="
cleanup() {
	db -e "DELETE FROM guacamole_connection_parameter  WHERE connection_id = $T_CONN;
	       DELETE FROM guacamole_connection_permission WHERE connection_id = $T_CONN;
	       DELETE FROM guacamole_connection            WHERE connection_id = $T_CONN;
	       DELETE FROM guacamole_user_permission       WHERE entity_id = $T_ENTITY;
	       DELETE FROM guacamole_user                  WHERE user_id  = $T_ENTITY;
	       DELETE FROM guacamole_entity                WHERE entity_id = $T_ENTITY;" >/dev/null 2>&1
	[ -n "${SOCAT_PID:-}" ] && kill "$SOCAT_PID" 2>/dev/null
	pkill -f "TCP-LISTEN:${T_PORT}," >/dev/null 2>&1
	return 0
}
trap cleanup EXIT

# Exactly the rows LoginController::apiLogin() writes. password_salt stays NULL:
# Guacamole's PasswordEncryptionService treats a NULL salt as empty, so an
# unsalted SHA2(x,256) verifies. True from 1.0.0 to 1.6.0.
db -e "REPLACE INTO guacamole_entity (entity_id, name, type) VALUES ($T_ENTITY, '$T_USER', 'USER');
       REPLACE INTO guacamole_user (user_id, entity_id, password_hash, password_date)
              VALUES ($T_ENTITY, $T_ENTITY, UNHEX(SHA2('$T_PASS',256)), NOW());
       REPLACE INTO guacamole_user_permission (entity_id, affected_user_id, permission)
              VALUES ($T_ENTITY,$T_ENTITY,'READ'),($T_ENTITY,$T_ENTITY,'UPDATE');" >/dev/null \
	&& ok "seeded a user the way LoginController does" \
	|| bad "seeded a user the way LoginController does"

# And exactly the rows html5AddSession() writes, including the parameters it
# sends to every protocol whether or not they mean anything to that protocol.
db -e "DELETE FROM guacamole_connection WHERE connection_id = $T_CONN;
       REPLACE INTO guacamole_connection (connection_id, connection_name, protocol)
              VALUES ($T_CONN, '$T_CONN', 'telnet');
       REPLACE INTO guacamole_connection_permission (entity_id, connection_id, permission)
              VALUES ($T_ENTITY, $T_CONN, 'READ');
       INSERT INTO guacamole_connection_parameter (connection_id, parameter_name, parameter_value)
              VALUES ($T_CONN,'hostname','127.0.0.1'),($T_CONN,'port','$T_PORT'),
                     ($T_CONN,'ignore-cert','true'),($T_CONN,'enable-drive','true'),
                     ($T_CONN,'create-drive-path','true'),($T_CONN,'enable-printing','true'),
                     ($T_CONN,'drive-path','/tmp/$T_CONN');" >/dev/null \
	&& ok "seeded a telnet connection the way html5AddSession() does" \
	|| bad "seeded a telnet connection the way html5AddSession() does"

# Stands in for a node's console port. guacd only needs something that completes
# a TCP accept for a telnet connection to reach `ready`.
nohup setsid socat "TCP-LISTEN:${T_PORT},bind=127.0.0.1,reuseaddr,fork" EXEC:/bin/cat \
	</dev/null >/dev/null 2>&1 &
SOCAT_PID=$!
for _ in $(seq 1 30); do ss -ltnH "sport = :$T_PORT" 2>/dev/null | grep -q LISTEN && break; sleep 0.2; done
ss -ltnH "sport = :$T_PORT" 2>/dev/null | grep -q LISTEN \
	&& ok "a stand-in console is listening on 127.0.0.1:$T_PORT" \
	|| bad "a stand-in console is listening on 127.0.0.1:$T_PORT"

echo
echo "=============== GUACD HANDSHAKE (the ready opcode) ==============="
# The Guacamole wire protocol, by hand. Every element is LENGTH.VALUE, elements
# are comma separated, instructions end with ';'. The handshake is:
#     -> select,<protocol>       <- args,VERSION_x_y_z,<param names...>
#     -> size / audio / video / image
#     -> connect,<one value per name that came back, in that order>
#     <- ready,$<uuid>
# guacd sends `ready` only once it has connected to the far end, so this single
# assertion covers guacd, the protocol client library, and the parameters.
GUACD_OUT=$(T_PORT="$T_PORT" python3 - <<'PY'
import os, socket, sys

def enc(*a):
    return ",".join("%d.%s" % (len(x.encode()), x) for x in a) + ";"

def read_inst(s):
    buf = b""
    while True:
        c = s.recv(1)
        if not c:
            raise EOFError("guacd closed the connection mid-instruction")
        if c == b";":
            break
        buf += c
    out, i, t = [], 0, buf.decode(errors="replace")
    while i < len(t):
        d = t.index(".", i)
        n = int(t[i:d])
        out.append(t[d + 1:d + 1 + n])
        i = d + 1 + n + 1
    return out

port = os.environ["T_PORT"]
ok = fail = 0
def chk(label, cond, detail=""):
    global ok, fail
    if cond: print("  \033[32mok\033[0m   %s" % label); ok += 1
    else:    print("  \033[31mFAIL\033[0m %s\n       %s" % (label, str(detail)[:200])); fail += 1

try:
    s = socket.create_connection(("127.0.0.1", 4822), timeout=10)
    s.settimeout(15)
    chk("guacd accepts a connection on 127.0.0.1:4822", True)

    s.sendall(enc("select", "telnet").encode())
    args = read_inst(s)
    chk("guacd answers `select telnet` with `args`", args[0] == "args", args[:2])
    names = args[1:]
    version = names[0] if names and names[0].startswith("VERSION_") else "(none)"
    chk("guacd states its protocol version in the handshake",
        version.startswith("VERSION_"), names[:1])
    print("       guacd offers %d parameters, protocol %s" % (len(names), version))
    chk("the telnet client library is loaded (hostname/port are offered)",
        "hostname" in names and "port" in names, names[:8])

    s.sendall(enc("size", "1024", "768", "96").encode())
    for opcode in ("audio", "video", "image"):
        s.sendall(enc(opcode).encode())

    vals = []
    for n in names:
        if n.startswith("VERSION_"): vals.append(n)
        elif n == "hostname":        vals.append("127.0.0.1")
        elif n == "port":            vals.append(port)
        else:                        vals.append("")
    s.sendall(enc("connect", *vals).encode())

    inst = read_inst(s)
    # THE assertion. Anything else here — usually `error` — means the pairing
    # or the parameters are wrong, and the opcode says which.
    chk("guacd replies `ready` and hands back a connection id (THE assertion)",
        inst[0] == "ready" and len(inst) > 1 and inst[1].startswith("$"), inst)
    s.close()
except Exception as e:
    chk("the guacd handshake completes", False, "%s: %s" % (type(e).__name__, e))

print("PYRESULT %d %d" % (ok, fail))
PY
)
echo "$GUACD_OUT" | grep -v '^PYRESULT'
read -r PYOK PYFAIL < <(echo "$GUACD_OUT" | awk '/^PYRESULT/ {print $2, $3}')
PASS=$((PASS + ${PYOK:-0})); FAIL=$((FAIL + ${PYFAIL:-0}))

echo
echo "=============== TOKEN MINT (what updateUserToken() does) ==============="
# includes/functions.php posts exactly this, form-encoded, and looks for
# "authToken" in the JSON.
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST "$JETTY_BASE/api/tokens" \
       --data-urlencode "username=$T_USER" --data-urlencode "password=definitely-wrong")
if [ "$CODE" = "200" ]; then bad "a wrong password is refused" "got 200"; else ok "a wrong password is refused (HTTP $CODE)"; fi

MINT=$(curl -s --max-time 20 -X POST "$JETTY_BASE/api/tokens" \
       --data-urlencode "username=$T_USER" --data-urlencode "password=$T_PASS")
has "the token endpoint returns an authToken" "$MINT" '"authToken"'
# If this is not "mysql", every console URL the application builds is a 404:
# it is the third \0-separated field of the base64 client identifier.
has "the data source identifier is mysql"     "$MINT" '"dataSource":"mysql"'
TOK=$(printf '%s' "$MINT" | python3 -c 'import json,sys
try: print(json.load(sys.stdin)["authToken"])
except Exception: print("")')
[ -n "$TOK" ] && ok "the token parses out of the JSON" || bad "the token parses out of the JSON" "$MINT"

# The identifier devices/device.php base64s into the client URL.
B64=$(python3 -c "import base64,sys; print(base64.b64encode(b'${T_CONN}\x00c\x00mysql').decode())")
CONNJSON=$(curl -s --max-time 20 "$JETTY_BASE/api/session/data/mysql/connections/$T_CONN?token=$TOK")
has "the seeded connection is visible to that user" "$CONNJSON" "\"identifier\":\"$T_CONN\""
has "and it is a telnet connection"                 "$CONNJSON" '"protocol":"telnet"'
printf "       client identifier the UI would use: %s\n" "$B64"

echo
echo "=============== TUNNEL (guacd $GUACD_V through web app $WAR_V) ==============="
if [ -z "$TOK" ]; then
	bad "the tunnel connects" "no token; skipping"
else
	HDR=$(mktemp)
	# The query string must be exactly `connect`; the servlet compares it with
	# equals(). The token therefore goes in the body, not the URL.
	UUID=$(curl -s --max-time 30 -D "$HDR" -X POST "$JETTY_BASE/tunnel?connect" \
	       --data-urlencode "token=$TOK" \
	       --data-urlencode GUAC_DATA_SOURCE=mysql --data-urlencode "GUAC_ID=$T_CONN" \
	       --data-urlencode GUAC_TYPE=c --data-urlencode GUAC_WIDTH=1024 \
	       --data-urlencode GUAC_HEIGHT=768 --data-urlencode GUAC_DPI=96 \
	       --data-urlencode GUAC_AUDIO=audio/L16 --data-urlencode GUAC_IMAGE=image/png)
	case "$UUID" in
		[0-9a-f]*-*-*-*-*)
			# A UUID here means ConfiguredGuacamoleSocket completed the handshake
			# with guacd, which it can only do after guacd sent `ready`. This is
			# the cross-version half of the pairing proof.
			ok "the web application opened a tunnel to guacd (UUID $UUID)" ;;
		*)  bad "the web application opened a tunnel to guacd" "$(echo "$UUID" | head -c 200)" ;;
	esac

	# 1.4.0+ hands back a per-tunnel token that every later request must echo.
	# 1.3.0 does not, and then this is empty and the header is harmless.
	TT=$(grep -i '^Guacamole-Tunnel-Token:' "$HDR" | tr -d '\r' | sed 's/^[^:]*:[[:space:]]*//')
	rm -f "$HDR"

	STREAM=$(curl -s --max-time 15 -H "Guacamole-Tunnel-Token: $TT" \
	         "$JETTY_BASE/tunnel?read:$UUID:0" | head -c 2000)
	# `ready` is NOT here: the web app consumed it during the handshake to get
	# the connection UUID. What proves the console is live is real drawing
	# instructions arriving from guacd.
	case "$STREAM" in
		*";"*) ok "guacd streams Guacamole protocol instructions back through the tunnel" ;;
		*)     bad "guacd streams instructions back through the tunnel" "$(echo "$STREAM" | head -c 200)" ;;
	esac
	has "the stream carries a terminal sizing instruction" "$STREAM" "size,"
	printf "       first bytes: %s\n" "$(printf '%s' "$STREAM" | head -c 90)"
fi

echo
echo "=============== THROUGH APACHE (the path the browser and the PHP use) ==============="
# The whole application talks to /html5 on port 80, never to :8080.
# includes/functions.php updateUserToken() posts to http://127.0.0.1/html5/api/tokens.
APROBE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$APACHE_BASE/" 2>/dev/null)
if [ "$APROBE" = "200" ]; then
	ok "Apache proxies GET /html5/ to Jetty"
	AMINT=$(curl -s --max-time 20 -X POST "$APACHE_BASE/api/tokens" \
	        --data-urlencode "username=$T_USER" --data-urlencode "password=$T_PASS")
	has "POST /html5/api/tokens through Apache returns a token" "$AMINT" '"authToken"'
	UP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
	     -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
	     -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
	     "$APACHE_BASE/websocket-tunnel")
	# 101 is a completed upgrade; 403/400 means it reached the servlet and was
	# refused for want of a token, which still proves mod_proxy_wstunnel handled
	# the Upgrade. 500/502/404 means it did not.
	case "$UP" in
		101|400|403) ok "mod_proxy_wstunnel forwards the WebSocket upgrade (HTTP $UP)" ;;
		*)           bad "mod_proxy_wstunnel forwards the WebSocket upgrade" "got HTTP $UP" ;;
	esac
else
	skip "the Apache leg" "GET $APACHE_BASE/ returned ${APROBE:-nothing}.
       Either the vhost has not been re-rendered since install/apache/pnetlab.conf.in
       gained its <Location /html5/> blocks, or proxy/proxy_http/proxy_wstunnel
       are not enabled. Fix with: sudo install/install.sh --only apache
       Everything above this line ran against Jetty directly and still holds."
fi

echo
echo "============================================"
printf "  assertions: %d passed, %d failed\n" "$PASS" "$FAIL"
echo "============================================"
if [ "$FAIL" -ne 0 ]; then
	printf "  \033[31m%d assertion(s) failed\033[0m\n" "$FAIL"
	exit 1
fi
exit 0
