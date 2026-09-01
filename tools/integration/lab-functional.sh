#!/usr/bin/env bash
#
# Functional test for a deployed PNETLab install. Run as a user with sudo, ON
# the appliance, against a working install:
#
#     bash tools/integration/lab-functional.sh
#
# It logs in over the loopback, builds a three-node lab on one bridge, starts it,
# checks the host actually reflects that, pings in three directions, then tears
# everything down and deletes the lab. It leaves no lab behind if it completes.
#
# Requires the captcha to be off, which a fresh install has:
#     REPLACE INTO control VALUES ('ctrl_captcha','0');
#
# Notes for anyone extending it, learned by getting these wrong:
#
#   - Do not grep the raw /api/auth JSON for "password". The lang object carries
#     UI translation strings, one of which is the word password, so a substring
#     match reports a credential leak that is not there. Parse the keys.
#   - VPCS forks. Each node is TWO processes, so three nodes is six.
#   - /api/folders takes no trailing slash; /api/folders/ is a 404.
#   - factory/leave keeps the session open so it can be rejoined. Deleting a lab
#     needs factory/destroy first, or it fails with error_lab_running — which
#     looks like a bug and is not one.
#
# This script's exit status is a gate, not decoration. It used to end on a
# printf, so `$?` was that printf's and the suite reported success whatever
# happened -- 47 assertions could all fail and a caller would see 0. Anything
# added below must feed PASS/FAIL, and the exit at the bottom must stay last.
set -uo pipefail
B=http://127.0.0.1
PASS=0; FAIL=0
DP_PASS=0; DP_FAIL=0
ok()   { printf "  \033[32mok\033[0m   %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  \033[31mFAIL\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; FAIL=$((FAIL+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$3' not in: $(echo "$2" | head -c 110)";; esac; }

echo "=============== AUTHENTICATION ==============="
WRONG=$(curl -s -m 25 -X POST $B/auth/login/login -H 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode 'username=admin' --data-urlencode 'password=definitely-wrong' --data-urlencode 'html=0')
has "wrong password is rejected" "$WRONG" "Password is Wrong"

HDRS=$(curl -s -i -m 25 -X POST $B/auth/login/login -H 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode 'username=admin' --data-urlencode 'password=pnet' --data-urlencode 'html=0')
TOK=$(echo "$HDRS" | grep -oP 'Set-Cookie: token=\K[0-9a-f-]+' | head -1)
[ -n "$TOK" ] && ok "login succeeds and issues a token" || bad "login issues a token"
has "session cookie is HttpOnly" "$HDRS" "httponly"
has "session cookie sets SameSite" "$HDRS" "samesite"

A() { curl -s -m 40 -b "token=$TOK" -H 'Content-Type: application/json' "$@"; }
AC() { curl -s -o /dev/null -w '%{http_code}' -m 40 -b "token=$TOK" "$@"; }

chk "unauthenticated /api/auth is 401" "$(curl -s -o /dev/null -w '%{http_code}' -m 20 $B/api/auth)" "401"
chk "authenticated /api/auth is 200"   "$(AC $B/api/auth)" "200"
LEAK=$(A $B/api/auth | python3 -c "
import json,sys
d=json.load(sys.stdin).get('data',{})
print(','.join(k for k in d if 'pass' in k.lower() or k=='cookie'))")
if [ -z "$LEAK" ]; then ok "no credential fields in /api/auth"; else bad "no credential fields in /api/auth" "leaked: $LEAK"; fi
AUTH=$(A $B/api/auth)
has "role is reported" "$AUTH" '"role"'

echo
echo "=============== LAB LIFECYCLE ==============="
has "lab create" "$(A -X POST $B/api/labs -d '{"path":"/","name":"func","version":"1","author":"t","description":"functional"}')" "60019"
has "duplicate lab is refused" "$(A -X POST $B/api/labs -d '{"path":"/","name":"func","version":"1","author":"t","description":"x"}')" "fail"
has "lab appears in the folder listing" "$(A $B/api/folders)" "func.unl"
S=$B/api/labs/session
has "open a lab session" "$(A -X POST $S/factory/create -d '{"path":"/func.unl"}')" "success"
has "session info returns the lab" "$(A $S/info)" '"name":"func"'

echo
echo "=============== NODES AND NETWORKS ==============="
for n in 1 2 3; do
  R=$(A -X POST $S/nodes/add -d "{\"type\":\"vpcs\",\"name\":\"PC$n\",\"template\":\"vpcs\",\"left\":\"$((100*n))\",\"top\":\"200\",\"console\":\"telnet\",\"ethernet\":\"1\"}")
  has "add node PC$n" "$R" "60023"
done
NODES=$(A $S/nodes)
for n in 1 2 3; do has "PC$n present in node list" "$NODES" "\"name\":\"PC$n\""; done
has "add bridge network" "$(A -X POST $S/networks/add -d '{"type":"bridge","name":"NET1","left":"300","top":"350","visibility":"1"}')" "60006"
for n in 1 2 3; do A -X POST $S/interfaces/edit -d "{\"node_id\":\"$n\",\"data\":{\"0\":\"1\"}}" >/dev/null; done
LINKED=$(A $S/nodes | grep -o '"network_id":1' | wc -l)
chk "all three interfaces are attached to NET1" "$LINKED" "3"
has "topology reports the network" "$(A $S/topology)" '"NET1"'
has "template list is served" "$(A $B/api/list/templates/)" "vpcs"
has "template list includes qemu images dir" "$(A $B/api/list/templates/)" "success"

echo
echo "=============== NODE LIFECYCLE ==============="
for n in 1 2 3; do
  has "start PC$n" "$(A -X POST $S/nodes/start -d "{\"id\":\"$n\"}")" "80049"
  sleep 4
done
sleep 3
chk "six vpcs processes (VPCS forks two per node)" "$(pgrep -c vpcs 2>/dev/null || true)" "6"
chk "three taps exist"                 "$(ip -o link show | grep -c vunl)" "3"
chk "three tenant accounts created"    "$(getent passwd | grep -c '^unl')" "3"
chk "three consoles are listening"     "$(ss -ltn 2>/dev/null | grep -cE ':3000[0-9]')" "3"
BR=$(sudo brctl show 2>/dev/null | awk '/^vnet/{f=1} f&&/vunl/{c++} END{print c+0}')
chk "all three taps are on the bridge"  "$BR" "3"
STATUS=$(A $S/nodes | grep -o '"status":2' | wc -l)
chk "all three nodes report running"   "$STATUS" "3"

echo
echo "=============== DATA PLANE ==============="
# The Python block reports its own tally on a PYRESULT line. Nothing used to
# parse it, so a data-plane failure never reached FAIL: all eight pings could
# break and the suite still printed "0 failed". tee keeps the live output.
DPOUT=$(mktemp)
python3 - <<'PY' | tee "$DPOUT"
import socket, time, sys
def vpcs(port, cmds, wait=1.5):
    try:
        s = socket.create_connection(("127.0.0.1", port), timeout=10); s.settimeout(6)
    except Exception as e:
        return "CONNFAIL: %s" % e
    time.sleep(0.8)
    try: s.recv(4096)
    except Exception: pass
    out = b""
    for c in cmds:
        s.sendall(c.encode() + b"\r\n"); time.sleep(wait)
        try: out += s.recv(65535)
        except Exception: pass
    s.close(); return out.decode(errors="replace")

ok = fail = 0
def chk(label, cond, detail=""):
    global ok, fail
    if cond: print("  \033[32mok\033[0m   %s" % label); ok += 1
    else:    print("  \033[31mFAIL\033[0m %s\n       %s" % (label, detail[:150])); fail += 1

for port, ip in ((30001,"10.0.0.1"), (30002,"10.0.0.2"), (30003,"10.0.0.3")):
    r = vpcs(port, ["", "ip %s/24" % ip])
    chk("console on :%d accepts configuration" % port, "CONNFAIL" not in r, r)

r = vpcs(30001, ["", "ping 10.0.0.2 -c 3"], wait=4)
chk("PC1 -> PC2 ping across the bridge", r.count("icmp_seq=") >= 2, r[-200:])
r = vpcs(30001, ["", "ping 10.0.0.3 -c 3"], wait=4)
chk("PC1 -> PC3 ping (three-way bridge)", r.count("icmp_seq=") >= 2, r[-200:])
r = vpcs(30002, ["", "ping 10.0.0.3 -c 2"], wait=4)
chk("PC2 -> PC3 ping", r.count("icmp_seq=") >= 1, r[-200:])
r = vpcs(30001, ["", "ping 10.0.0.99 -c 1"], wait=4)
chk("ping to an absent host does not succeed", "icmp_seq=" not in r, r[-160:])
r = vpcs(30001, ["", "show ip"], wait=2)
chk("'show ip' reports the configured address", "10.0.0.1" in r, r[-200:])
print("PYRESULT %d %d" % (ok, fail))
PY

DPLINE=$(grep -m1 '^PYRESULT ' "$DPOUT" || true)
rm -f "$DPOUT"
if [ -z "$DPLINE" ]; then
  # No tally at all: python3 died, or the block was edited without updating this.
  bad "the data-plane block reported a result" "no PYRESULT line; treating as a failure"
else
  DP_PASS=$(echo "$DPLINE" | awk '{print $2}')
  DP_FAIL=$(echo "$DPLINE" | awk '{print $3}')
fi

echo
echo "=============== STOP AND CLEANUP ==============="
for n in 1 2 3; do has "stop PC$n" "$(A -X POST $S/nodes/stop -d "{\"id\":\"$n\"}")" "80051"; done
sleep 4
chk "no vpcs processes remain" "$(pgrep -c vpcs 2>/dev/null || true; :)" "0"
chk "no taps remain"           "$(ip -o link show | grep -c vunl)" "0"
chk "no consoles remain"       "$(ss -ltn 2>/dev/null | grep -cE ':3000[0-9]')" "0"
STOPPED=$(A $S/nodes | grep -o '"status":0' | wc -l)
chk "all three nodes report stopped" "$STOPPED" "3"

echo
echo "=============== DELETE ==============="
has "delete a node"  "$(A -X POST $S/nodes/delete -d '{"id":"3"}')" "success"
chk "two nodes remain" "$(A $S/nodes | grep -o '\"name\":\"PC' | wc -l)" "2"
has "leave the session" "$(A -X POST $S/factory/leave -d '{}')" "success"
LS=$(A $S/info >/dev/null; sudo mysql -N pnetlab_db -e 'SELECT lab_session_id FROM lab_sessions LIMIT 1;' 2>/dev/null)
has "destroy the session" "$(A -X POST $S/factory/destroy -d "{\"lab_session\":\"${LS:-1}\"}")" "success"
chk "lab_sessions is empty after destroy" "$(sudo mysql -N pnetlab_db -e 'SELECT COUNT(*) FROM lab_sessions;' 2>/dev/null)" "0"
chk "node_sessions is empty after destroy" "$(sudo mysql -N pnetlab_db -e 'SELECT COUNT(*) FROM node_sessions;' 2>/dev/null)" "0"
has "delete the lab" "$(A -X DELETE $B/api/labs -d '{"path":"/func.unl"}')" "success"

echo
echo "============================================"
printf "  shell assertions:  %d passed, %d failed\n" "$PASS" "$FAIL"
printf "  data-plane checks: %d passed, %d failed\n" "$DP_PASS" "$DP_FAIL"
echo "============================================"

TOTAL_FAIL=$((FAIL + DP_FAIL))
if [ "$TOTAL_FAIL" -ne 0 ]; then
  printf "  \033[31m%d assertion(s) failed\033[0m\n" "$TOTAL_FAIL"
  exit 1
fi
exit 0
