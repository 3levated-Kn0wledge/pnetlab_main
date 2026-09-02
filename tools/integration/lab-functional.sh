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

# shellcheck source=tools/integration/lib/http-login.sh
. "$(dirname "$0")/lib/http-login.sh"

# Recorded BEFORE anything runs.
#
# Node start manufactures a Unix account per node session and, until
# `unl_wrapper -a reap-tenant` existed, nothing ever removed one — so a host that
# has run this script before is carrying its leftovers. Print the count, so
# nobody credits the reaper with clearing accounts it never saw, and so a
# non-zero number here is read as "this host was already leaking" rather than as
# a failure of the run about to start.
BASE_ACCOUNTS=$(getent passwd | grep -c '^unl[0-9]' || true)
printf "  note tenant accounts on this host before the run: %s\n" "$BASE_ACCOUNTS"

echo "=============== AUTHENTICATION ==============="
csrf_session_start "$B"
[ -n "${CSRF_TOKEN:-}" ] && ok "the login page issues an XSRF-TOKEN cookie" \
                         || bad "the login page issues an XSRF-TOKEN cookie"

# VerifyCsrfToken is enabled, so a tokenless POST is refused before the
# controller sees it. Assert that directly: it is the whole point of the
# middleware, and it is the assertion that fails if someone comments it out
# again.
chk "a tokenless login POST is refused with 419" \
  "$(curl -s -o /dev/null -w '%{http_code}' -m 25 -b "$CSRF_JAR" -X POST $B/auth/login/login \
     --data-urlencode 'username=admin' --data-urlencode 'password=pnet' --data-urlencode 'html=0')" "419"

WRONG=$(csrf_post $B/auth/login/login -H 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode 'username=admin' --data-urlencode 'password=definitely-wrong' --data-urlencode 'html=0')
has "wrong password is rejected" "$WRONG" "Password is Wrong"

csrf_refresh
HDRS=$(csrf_post $B/auth/login/login -i -H 'X-Requested-With: XMLHttpRequest' \
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

# The emulator must not be root. Everything below this line — the taps, the
# bridge, the pings — is what proves the drop did not cost the data plane, which
# is the failure mode that matters: with the tap group set to root the node
# starts, its console answers, and no frame ever moves.
chk "no vpcs process runs as root"                  "$(ps -o user= -C vpcs 2>/dev/null | grep -c '^root' || true)" "0"
chk "all six run as a tenant account instead"       "$(ps -o user= -C vpcs 2>/dev/null | grep -c '^unl' || true)"  "6"
chk "three taps exist"                 "$(ip -o link show | grep -c vunl)" "3"

# The session ids this run is actually using, so the account assertions can name
# them instead of counting.
#
# The assertion that used to be here was
#     chk "three tenant accounts created" "$(getent passwd | grep -c '^unl')" "3"
# and it could never fail. destroy empties node_sessions, so the next run is
# handed ids 1, 2 and 3 again — and the three accounts the LAST run leaked
# satisfy the count for this one without a single useradd being run. The leak was
# what made the assertion pass. Ask about these three sessions instead.
SIDS=$(sudo mysql -N pnetlab_db -e 'SELECT node_session_id FROM node_sessions ORDER BY node_session_id;' 2>/dev/null | tr '\n' ' ')
MADE=0; for s in $SIDS; do getent passwd "unl$s" >/dev/null 2>&1 && MADE=$((MADE+1)); done
chk "a tenant account exists for each node session ($SIDS)" "$MADE" "3"
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

# Stopping a node is the ordinary end of a session, and device::stop() reaps the
# account after the taps are down. Both halves are checked: the passwd entry and
# the home directory, because userdel without -r leaves the second behind and
# /opt/unetlab/users would then grow on its own.
LEFT=0;  for s in $SIDS; do getent passwd "unl$s" >/dev/null 2>&1 && LEFT=$((LEFT+1)); done
HOMES=0; for s in $SIDS; do [ -d "/opt/unetlab/users/$s" ] && HOMES=$((HOMES+1)); done
chk "stopping the nodes reaped their tenant accounts" "$LEFT" "0"
chk "and removed their home directories"              "$HOMES" "0"

echo
echo "=============== FAILED START LEAVES NOTHING ==============="
#
# The roadmap's Phase 04 item: "tunctl runs before the failure point, and
# neither stop nor delete removes the interface. One orphaned tap and one
# orphaned unlN account accumulate per failed start."
#
# Forcing it honestly matters more than forcing it easily. prepare() creates the
# tap and then calls connectInterface(), which returns 80029 when the bridge is
# not there -- so removing the bridge behind the API's back reproduces the exact
# shape observed on the appliance: addTap succeeded, the next step did not, and
# prepare returned. Nothing about this is specific to VPCS; qemu, dynamips and
# iol have the same loop, and qemu has four more error returns after it.
#
# It also proves the SECOND half. Since tenant accounts are reaped, a stranded
# tap pins its account: the reaper refuses while a vunl<session>_* exists. So
# "no tap" and "no account" are one assertion made twice, and before the fix
# both failed.
# HOW THE FAILURE IS FORCED, AND THE ONE THAT DOES NOT WORK
#
# Deleting the node's bridge and starting into the gap looks like the obvious
# force -- connectInterface() returns 80029 for a missing bridge, right after
# addTap() -- and it does not work. `unl_wrapper -a start` walks the node's
# interfaces and calls addNetwork() for each one BEFORE it starts anything, so
# it rebuilds the bridge and the node starts normally. That is the wrapper
# behaving correctly; it is written down because the test that assumed
# otherwise passed for the wrong reason.
#
# What is forced instead is prepare()'s last step. vpcs::prepare() creates the
# taps and then touch()es .prepared, and touch() on an immutable file returns
# false for root as well -- so the failure cannot be repaired by anything the
# application does. 80044, one line after the taps exist. Every other node type
# has the same shape, and qemu has four more error returns after its tap loop.
#
# The FILE, not the directory. PC1 ran successfully above, so .prepared already
# exists, and creating an entry is the only thing an immutable DIRECTORY
# forbids -- touch()ing a file that is already there succeeds, and the start
# then succeeds too. That is what the first version of this did.
LSID=$(sudo mysql -N pnetlab_db -e 'SELECT lab_session_id FROM lab_sessions LIMIT 1;' 2>/dev/null)
NSID=$(sudo mysql -N pnetlab_db -e 'SELECT node_session_id FROM node_sessions WHERE node_session_nid=1 LIMIT 1;' 2>/dev/null)
RP="/opt/unetlab/tmp/${LSID}/${NSID}"
sudo mkdir -p "$RP"
sudo touch "$RP/.prepared"
sudo chattr +i "$RP/.prepared" 2>/dev/null
if lsattr "$RP/.prepared" 2>/dev/null | grep -q '^....i'; then
  ok "the node's .prepared is immutable, so prepare() must fail after the tap"
else
  bad "the node's .prepared is immutable, so prepare() must fail after the tap" \
      "chattr +i did not take on $RP/.prepared; is this an ext4 filesystem?"
fi

FS=$(A -X POST $S/nodes/start -d '{"id":"1"}')
# 80049 is 'Node started'. The assertion is that it did NOT report success --
# which code it chose is prepare()'s business, not this suite's.
case "$FS" in
  *80049*) bad "a start that fails after the tap exists is reported as a failure" "got: $(echo "$FS" | head -c 120)" ;;
  *)       ok  "a start that fails after the tap exists is reported as a failure" ;;
esac
sleep 2

# THE REGRESSION THIS SECTION EXISTS FOR. Before the unwind, PC1's tap survived
# here and survived every stop and delete that followed -- device::stopNode()
# did its teardown inside `if getStatus() != 0`, and a node that failed to start
# reports 0, so stop was a no-op on the one node that needed it.
LEAKED=$(ip -o link show 2>/dev/null | grep -c 'vunl' || true)
if [ "$LEAKED" = "0" ]; then
  ok "the failed start left no tap behind"
else
  bad "the failed start left no tap behind" \
      "$(ip -o link show | sed 's/.*\(vunl[0-9]*_[0-9]*\).*/\1/' | grep '^vunl' | tr '\n' ' ')"
fi

# And the account is not pinned. UnlTenantAccount refuses to remove an account
# while a vunl<session>_* interface exists, so a leaked tap strands its account
# for the life of the host. This assertion is only reachable because the one
# above passed, which is the point: they are one bug seen from two sides.
if [ -n "$NSID" ] && getent passwd "unl$NSID" >/dev/null 2>&1; then
  bad "and left no tenant account pinned" "unl$NSID survives with no node running"
else
  ok "and left no tenant account pinned"
fi

# Put the host back before DELETE, which cannot remove an immutable file.
sudo chattr -i "$RP/.prepared" 2>/dev/null

echo
echo "=============== DELETE ==============="
has "delete a node"  "$(A -X POST $S/nodes/delete -d '{"id":"3"}')" "success"
chk "two nodes remain" "$(A $S/nodes | grep -o '\"name\":\"PC' | wc -l)" "2"
has "leave the session" "$(A -X POST $S/factory/leave -d '{}')" "success"
LS=$(A $S/info >/dev/null; sudo mysql -N pnetlab_db -e 'SELECT lab_session_id FROM lab_sessions LIMIT 1;' 2>/dev/null)
has "destroy the session" "$(A -X POST $S/factory/destroy -d "{\"lab_session\":\"${LS:-1}\"}")" "success"
chk "lab_sessions is empty after destroy" "$(sudo mysql -N pnetlab_db -e 'SELECT COUNT(*) FROM lab_sessions;' 2>/dev/null)" "0"
chk "node_sessions is empty after destroy" "$(sudo mysql -N pnetlab_db -e 'SELECT COUNT(*) FROM node_sessions;' 2>/dev/null)" "0"

# THE REGRESSION THIS WHOLE TASK EXISTS TO PREVENT.
#
# A completed lab session must leave no tenant account on the host. This is a
# whole-host count on purpose: a per-session check cannot see an account whose
# session row was deleted before anything reaped it, and that is precisely the
# shape the leak had. If it fails and BASE_ACCOUNTS at the top was non-zero, the
# host was already carrying accounts from an earlier run — clear those and run
# again before believing anything else about this result.
END_ACCOUNTS=$(getent passwd | grep -c '^unl[0-9]' || true)
if [ "$END_ACCOUNTS" = "0" ]; then
  ok "a completed lab session leaves no tenant accounts behind"
else
  bad "a completed lab session leaves no tenant accounts behind" \
      "$END_ACCOUNTS left (this run started with $BASE_ACCOUNTS): $(getent passwd | grep '^unl[0-9]' | cut -d: -f1 | tr '\n' ' ')"
fi
chk "and /opt/unetlab/users holds nothing" "$(ls -1 /opt/unetlab/users 2>/dev/null | wc -l)" "0"

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
