#!/usr/bin/env bash
#
# Round-trip test for `unl_wrapper -a backupdb` / `-a restoredb`. Run as a user
# with sudo, ON the appliance, against a working install:
#
#     sudo bash tools/integration/db-backup-restore.sh
#
# A backup you have never restored is not a backup, so this does the only thing
# that proves one: build state, back it up, DESTROY that state, restore, and
# assert the original state is back. It asserts the guacdb half as well as the
# pnetlab_db half, because guacdb holds the Guacamole console connections and a
# restore that misses them leaves every HTML5 console pointing at a node that no
# longer exists — which looks like a working restore right up until someone
# opens a console.
#
# THIS SCRIPT RESTORES DATABASES ON THE HOST IT RUNS ON. That is the feature. It
# is safe on a host it is allowed to disturb and nowhere else. It refuses to
# start if any lab session is open, and `-a restoredb` refuses again on its own
# account. Every restore it performs also leaves a safety dump of what was there
# before in /opt/unetlab/backup_database/pre-restore/.
#
# It SKIPS loudly rather than failing when a prerequisite is missing: no wrapper,
# no client, no application, no VPCS. A skip is not a pass, and the summary says
# which it was.
#
# Notes for anyone extending it, learned by getting these wrong:
#
#   - Logging in needs lib/http-login.sh: VerifyCsrfToken is on, so a bare POST
#     to /auth/login/login returns 419.
#   - `DROP DATABASE` does NOT drop the grants on that database — they live in
#     mysql.db and survive. That was measured, because if it were not true a
#     restore would leave the application unable to connect and every other
#     suite on this host would start failing for reasons that look unrelated.
#   - node_sessions rows survive a node STOP; only factory/destroy clears them.
#     A restore attempted between stop and destroy is refused, correctly, and
#     the refusal is asserted below.
#
# This script's exit status is a gate, not decoration.
set -uo pipefail
B=http://127.0.0.1
W=/opt/unetlab/wrappers/unl_wrapper
BK=/opt/unetlab/backup_database
LAB=/dbrt.unl
MARKER_POD=9911
MARKER_USER=dbrt_marker

PASS=0; FAIL=0; SKIPPED=0
ok()   { printf "  \033[32mok\033[0m   %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  \033[31mFAIL\033[0m %s\n" "$1"; [ -n "${2:-}" ] && printf "       %s\n" "$2"; FAIL=$((FAIL+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$3' not in: $(echo "$2" | head -c 160)";; esac; }
note() { printf "  \033[33mnote\033[0m %s\n" "$1"; }
skip() { printf "  \033[33mSKIP\033[0m %s\n" "$1"; SKIPPED=$((SKIPPED+1)); }

# A prerequisite that is missing means this suite cannot say anything. Say that
# and stop, with a zero exit — a skip is not a failure, and a gate that fails
# because VPCS is not installed teaches nobody anything.
bail_skip() { printf "\n  \033[33mSKIPPED: %s\033[0m\n" "$1"; exit 0; }

Q()  { sudo mysql -N pnetlab_db -e "$1" 2>/dev/null; }
QG() { sudo mysql -N guacdb -e "$1" 2>/dev/null; }

# shellcheck source=tools/integration/lib/http-login.sh
. "$(dirname "$0")/lib/http-login.sh"

echo "=============== PREREQUISITES ==============="

[ "$(id -u)" = 0 ] || sudo -n true 2>/dev/null || bail_skip "needs root or passwordless sudo"
command -v mysql >/dev/null 2>&1 || command -v mariadb >/dev/null 2>&1 || \
    bail_skip "no mysql/mariadb client on this host"
[ -x "$W" ] || bail_skip "no wrapper at $W"
grep -q "UnlBackupDatabase" "$W" || \
    bail_skip "$W predates the backupdb rewrite; deploy this tree first"
sudo mysql -N -e 'SELECT 1' >/dev/null 2>&1 || \
    bail_skip "cannot reach MariaDB as unix-socket root, which is how this authenticates"
[ "$(curl -s -o /dev/null -w '%{http_code}' -m 15 $B/auth/login/offline)" = "200" ] || \
    bail_skip "the application is not answering on $B"
ok "wrapper, client, socket root and the application are all present"

LIVE_LABS=$(Q 'SELECT COUNT(*) FROM lab_sessions;')
LIVE_NODES=$(Q 'SELECT COUNT(*) FROM node_sessions;')
if [ "${LIVE_LABS:-1}" != "0" ] || [ "${LIVE_NODES:-1}" != "0" ]; then
    bail_skip "sessions are open on this host (${LIVE_LABS:-?} lab, ${LIVE_NODES:-?} node).
           This suite restores databases; it will not do that under a running lab."
fi
ok "no lab or node session is open, so it is safe to proceed"

# The path device_vpcs.php actually execs, not whatever is on PATH.
HAVE_VPCS=0
[ -x /opt/vpcsu/bin/vpcs ] && HAVE_VPCS=1
[ "$HAVE_VPCS" = 1 ] || skip "no vpcs binary: the guacdb half will be exercised on existing rows only"

cleanup() {
    local rc=$?
    curl -s -m 20 -b "token=${TOK:-none}" -H 'Content-Type: application/json' \
        -X POST $B/api/labs/session/factory/destroy -d "{\"lab_session\":\"$(Q 'SELECT lab_session_id FROM lab_sessions LIMIT 1;')\"}" >/dev/null 2>&1
    curl -s -m 20 -b "token=${TOK:-none}" -H 'Content-Type: application/json' \
        -X DELETE $B/api/labs -d "{\"path\":\"$LAB\"}" >/dev/null 2>&1
    Q "DELETE FROM users WHERE pod=${MARKER_POD};" >/dev/null 2>&1
    sudo rm -f "$BK/remote/pnetlab_db.sql" "$BK/remote/guacdb.sql" 2>/dev/null
    return $rc
}
trap cleanup EXIT

echo
echo "=============== BUILD SOME STATE ==============="

csrf_session_start "$B" >/dev/null
csrf_refresh
HDRS=$(csrf_post $B/auth/login/login -i -H 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode 'username=admin' --data-urlencode 'password=pnet' --data-urlencode 'html=0')
TOK=$(echo "$HDRS" | grep -oP 'Set-Cookie: token=\K[0-9a-f-]+' | head -1)
[ -n "$TOK" ] || bail_skip "could not log in as admin/pnet (captcha on? password changed?)"
ok "logged in"

A() { curl -s -m 40 -b "token=$TOK" -H 'Content-Type: application/json' "$@"; }
S=$B/api/labs/session

# A row in pnetlab_db.users. A user account is exactly the kind of state a
# backup exists to protect, it survives a session teardown, and it is one row to
# assert on.
Q "DELETE FROM users WHERE pod=${MARKER_POD};"
Q "INSERT INTO users (pod, username, email, name, role) VALUES
   (${MARKER_POD}, '${MARKER_USER}', '${MARKER_USER}@example.invalid', 'backup round-trip marker', 'user');"
chk "a marker user exists in pnetlab_db" "$(Q "SELECT COUNT(*) FROM users WHERE pod=${MARKER_POD};")" "1"

# A row in guacdb, made the way the product makes one: start a node and ask for
# its HTML5 console link, which is what calls html5AddSession().
GCONN=''
if [ "$HAVE_VPCS" = 1 ]; then
    A -X DELETE $B/api/labs -d "{\"path\":\"$LAB\"}" >/dev/null
    has "lab create" "$(A -X POST $B/api/labs -d '{"path":"/","name":"dbrt","version":"1","author":"t","description":"backup round trip"}')" "60019"
    has "open a lab session" "$(A -X POST $S/factory/create -d "{\"path\":\"$LAB\"}")" "success"
    has "add a VPCS node" "$(A -X POST $S/nodes/add -d '{"type":"vpcs","name":"PCB","template":"vpcs","left":"100","top":"200","console":"telnet","ethernet":"1"}')" "60023"
    has "start it" "$(A -X POST $S/nodes/start -d '{"id":"1"}')" "80049"
    sleep 5

    LINK=$(A "$S/console_guac_link?node_id=1&index=1" | sed 's/\\\//\//g')
    GB64=$(printf '%s' "$LINK" | sed -n 's/.*client\/\([A-Za-z0-9+/=]*\)?token.*/\1/p')
    GCONN=$(printf '%s' "$GB64" | base64 -d 2>/dev/null | tr '\0' '|' | cut -d'|' -f1)
    if [ -n "$GCONN" ]; then
        ok "the console link carries a guacdb connection id ($GCONN)"
    else
        bad "the console link carries a guacdb connection id" "link was: $(echo "$LINK" | head -c 160)"
    fi

    A -X POST $S/nodes/stop -d '{"id":"1"}' >/dev/null; sleep 3
    A -X POST $S/factory/leave -d '{}' >/dev/null
    A -X POST $S/factory/destroy -d "{\"lab_session\":\"$(Q 'SELECT lab_session_id FROM lab_sessions LIMIT 1;')\"}" >/dev/null
    chk "the lab session is torn down again" "$(Q 'SELECT COUNT(*) FROM node_sessions;')" "0"
fi

# Whatever guacdb holds now is what the restore has to bring back. Using the
# whole table rather than only the row just made means this still asserts
# something when vpcs is absent.
G_CONNS_BEFORE=$(QG 'SELECT COUNT(*) FROM guacamole_connection;')
G_PARAMS_BEFORE=$(QG 'SELECT COUNT(*) FROM guacamole_connection_parameter;')
G_DIGEST_BEFORE=$(QG 'SELECT connection_id, connection_name, protocol FROM guacamole_connection ORDER BY connection_id;' | md5sum)
note "guacdb before the backup: ${G_CONNS_BEFORE} connections, ${G_PARAMS_BEFORE} parameters"
if [ "${G_CONNS_BEFORE:-0}" -gt 0 ]; then
    ok "there is guacdb state to round-trip"
else
    skip "guacdb holds no connections; the guacdb assertions below prove only that it survives"
fi

echo
echo "=============== THE DIRECTORY THAT WAS NEVER THERE ==============="
#
# The original `-a backupdb` was dead for one flat reason: nothing in the tree
# ever created /opt/unetlab/backup_database, so its shell redirect failed with
# "Directory nonexistent", shell_exec() discarded that, and the action exited 0
# having written nothing. Take the directory away and assert the new one does
# not have that failure mode. (This removes any previous backup on this host,
# which is why the header says to run it only where that is acceptable — a fresh
# one is taken in the next section.)
sudo rm -rf "$BK"
chk "the backup directory is gone" "$(sudo test -d "$BK" && echo yes || echo no)" "no"
OUT=$(sudo "$W" -a backupdb 2>&1); RC=$?
chk "backupdb creates it rather than failing into the void" "$RC" "0"
chk "and it is 0700 root-owned when it does"  "$(sudo stat -c '%a %U' $BK)" "700 root"
chk "with both dumps in it" \
    "$(sudo test -s $BK/pnetlab_db.sql && sudo test -s $BK/guacdb.sql && echo yes)" "yes"

echo
echo "=============== BACKUP ==============="

OUT=$(sudo "$W" -a backupdb 2>&1); RC=$?
chk "backupdb exits 0" "$RC" "0"
has "and reports success as JSON" "$OUT" '"ok":true'
has "naming pnetlab_db" "$OUT" 'pnetlab_db'
has "and guacdb" "$OUT" 'guacdb'

chk "the backup directory is 0700"      "$(sudo stat -c '%a %U' $BK)" "700 root"
chk "pnetlab_db.sql is 0600 root-owned" "$(sudo stat -c '%a %U' $BK/pnetlab_db.sql)" "600 root"
chk "guacdb.sql is 0600 root-owned"     "$(sudo stat -c '%a %U' $BK/guacdb.sql)" "600 root"
has "the pnetlab_db dump is complete"   "$(sudo tail -c 200 $BK/pnetlab_db.sql)" "Dump completed"
has "the guacdb dump is complete"       "$(sudo tail -c 200 $BK/guacdb.sql)" "Dump completed"
chk "and the dump carries the marker row" \
    "$(sudo grep -q "$MARKER_USER" $BK/pnetlab_db.sql && echo yes)" "yes"

# HOW AUTHENTICATION WORKS NOW, asserted from the other end.
#
# DO NOT TEST THIS AS ROOT. MariaDB's unix_socket plugin authenticates root by
# peer uid and IGNORES the password supplied, so `mysql -uroot -ppnetlab` run as
# root succeeds on this host and tells you nothing — which is how the old code
# could have looked fine to anyone who checked it from a root shell. Ask a
# non-root account instead: for anyone who is not already root, the appliance
# credential is ERROR 1698, and the socket path is the only way in.
chk "the appliance credential is refused for any account that is not root" \
    "$(sudo -u nobody mysql -uroot -ppnetlab -e 'SELECT 1' 2>&1 | grep -c 'Access denied')" "1"
chk "and unix-socket root, which is what this action uses, gets in" \
    "$(sudo mysql --protocol=socket -uroot -N -e 'SELECT 1' 2>/dev/null)" "1"

echo
echo "=============== DESTROY THE STATE ==============="

Q "DELETE FROM users WHERE pod=${MARKER_POD};"
QG "DELETE FROM guacamole_connection_parameter;"
QG "DELETE FROM guacamole_connection;"
chk "the marker user is gone from pnetlab_db" "$(Q "SELECT COUNT(*) FROM users WHERE pod=${MARKER_POD};")" "0"
chk "every guacdb connection is gone"         "$(QG 'SELECT COUNT(*) FROM guacamole_connection;')" "0"

echo
echo "=============== RESTORE ==============="

OUT=$(sudo "$W" -a restoredb 2>&1); RC=$?
chk "restoredb exits 0" "$RC" "0"
has "and reports success" "$OUT" '"ok":true'
has "having restored pnetlab_db" "$OUT" '"pnetlab_db"'
has "and guacdb" "$OUT" '"guacdb"'
has "and says where the safety dump went" "$OUT" 'pre-restore'
chk "the safety dump of the pre-restore state exists" \
    "$(sudo test -s $BK/pre-restore/pnetlab_db.sql && sudo test -s $BK/pre-restore/guacdb.sql && echo yes)" "yes"

chk "THE MARKER ROW IS BACK IN pnetlab_db" "$(Q "SELECT COUNT(*) FROM users WHERE pod=${MARKER_POD};")" "1"
chk "with the username it had"             "$(Q "SELECT username FROM users WHERE pod=${MARKER_POD};")" "$MARKER_USER"
chk "and the administrator is still there" "$(Q "SELECT COUNT(*) FROM users WHERE username='admin';")" "1"

# THE HALF THAT IS EASY TO FORGET. guacdb is a separate schema; a restore that
# skips it leaves consoles pointing at nodes from a previous generation of
# pnetlab_db.
chk "THE guacdb CONNECTIONS ARE BACK"      "$(QG 'SELECT COUNT(*) FROM guacamole_connection;')" "$G_CONNS_BEFORE"
chk "with their parameters"                "$(QG 'SELECT COUNT(*) FROM guacamole_connection_parameter;')" "$G_PARAMS_BEFORE"
chk "and identical rows, not merely the same count" \
    "$(QG 'SELECT connection_id, connection_name, protocol FROM guacamole_connection ORDER BY connection_id;' | md5sum)" \
    "$G_DIGEST_BEFORE"
if [ -n "$GCONN" ]; then
    chk "the console connection this run created is back by id" \
        "$(QG "SELECT COUNT(*) FROM guacamole_connection WHERE connection_id=${GCONN};")" "1"
fi

# The application must still be able to talk to what was just recreated. DROP
# DATABASE leaves the grants in mysql.db, and this is the assertion that says so.
chk "the application still answers after the restore" \
    "$(curl -s -o /dev/null -w '%{http_code}' -m 20 -b "token=$TOK" $B/api/auth)" "200"

echo
echo "=============== RESTORE REFUSES UNDER A RUNNING LAB ==============="

if [ "$HAVE_VPCS" = 1 ]; then
    A -X POST $S/factory/create -d "{\"path\":\"$LAB\"}" >/dev/null
    A -X POST $S/nodes/start -d '{"id":"1"}' >/dev/null
    sleep 5
    RUNNING=$(Q 'SELECT COUNT(*) FROM node_sessions;')
    if [ "${RUNNING:-0}" -gt 0 ]; then
        ok "a node session is open ($RUNNING)"
        OUT=$(sudo "$W" -a restoredb 2>&1); RC=$?
        chk "restoredb exits non-zero rather than restoring under it" "$RC" "49"
        has "and reports a refusal"                "$OUT" '"ok":false'
        has "naming the sessions it found"         "$OUT" 'node session'
        has "and saying what the damage would be"  "$OUT" 'orphaned'
        has "and refusing rather than half-doing it" "$OUT" '"restored":[]'
        chk "the databases were not touched" "$(Q "SELECT COUNT(*) FROM users WHERE pod=${MARKER_POD};")" "1"
    else
        skip "the node did not start, so the refusal path could not be exercised"
    fi
    A -X POST $S/nodes/stop -d '{"id":"1"}' >/dev/null; sleep 3

    # A stop is not a teardown: node_sessions survives it. The refusal must too,
    # because the tenant accounts and taps those rows name are still being
    # cleaned up.
    STOPPED=$(Q 'SELECT COUNT(*) FROM node_sessions;')
    if [ "${STOPPED:-0}" -gt 0 ]; then
        OUT=$(sudo "$W" -a restoredb 2>&1); RC=$?
        chk "and still refuses between stop and destroy" "$RC" "49"
    else
        skip "node_sessions cleared on stop here, so the between-stop-and-destroy case is moot"
    fi

    A -X POST $S/factory/leave -d '{}' >/dev/null
    A -X POST $S/factory/destroy -d "{\"lab_session\":\"$(Q 'SELECT lab_session_id FROM lab_sessions LIMIT 1;')\"}" >/dev/null
    chk "sessions are cleared again" "$(Q 'SELECT COUNT(*) FROM node_sessions;')" "0"
else
    skip "no vpcs: cannot open a real session, so the refusal path is untested here"
fi

echo
echo "=============== --source remote ==============="
#
# This is what `-a restoredb_remote` was. The directory is a staging area for a
# dump copied from another host; nothing in this tree writes it, so the test
# writes it the way an operator would.

# The installer creates this directory; the section above deleted it along with
# the rest of the backup root, so put it back the way install/lib/deploy.sh does.
sudo install -d -m 0700 -o root -g root "$BK/remote"

OUT=$(sudo "$W" -a restoredb --source remote 2>&1); RC=$?
chk "an empty remote/ is a refusal, not a crash" "$RC" "49"
has "which explains what the directory is for" "$OUT" 'another host'

sudo cp "$BK/pnetlab_db.sql" "$BK/remote/pnetlab_db.sql"
sudo cp "$BK/guacdb.sql"     "$BK/remote/guacdb.sql"
Q "DELETE FROM users WHERE pod=${MARKER_POD};"
OUT=$(sudo "$W" -a restoredb --source remote 2>&1); RC=$?
chk "a populated remote/ restores" "$RC" "0"
has "and says which source it used" "$OUT" '"source":"remote"'
chk "and the marker row is back again" "$(Q "SELECT COUNT(*) FROM users WHERE pod=${MARKER_POD};")" "1"

# The wrong dump in the right filename. The old code would have applied it.
sudo cp "$BK/guacdb.sql" "$BK/remote/pnetlab_db.sql"
OUT=$(sudo "$W" -a restoredb --source remote 2>&1); RC=$?
chk "a dump of the wrong schema is refused" "$RC" "49"
has "and says what it actually found" "$OUT" 'is a dump of guacdb, not of pnetlab_db'
chk "and nothing was restored"        "$(Q "SELECT COUNT(*) FROM users WHERE pod=${MARKER_POD};")" "1"

echo
echo "=============== THE ARGUMENTS ==============="

OUT=$(sudo "$W" -a restoredb --source /opt/unetlab 2>&1); RC=$?
chk "a source that is a path is refused" "$RC" "49"
has "as one of a closed set"             "$OUT" 'must be one of'

OUT=$(sudo "$W" -a restoredb --source local --source remote 2>&1); RC=$?
chk "a repeated --source is refused rather than resolved" "$RC" "49"

OUT=$(sudo "$W" -a restoredb_remote 2>&1); RC=$?
chk "the retired restoredb_remote exits 50" "$RC" "50"
has "and names its replacement" "$OUT" 'restoredb --source remote'

echo
echo "=============== CLEANUP ==============="

Q "DELETE FROM users WHERE pod=${MARKER_POD};"
chk "the marker user is removed" "$(Q "SELECT COUNT(*) FROM users WHERE pod=${MARKER_POD};")" "0"
if [ "$HAVE_VPCS" = 1 ]; then
    has "the test lab is deleted" "$(A -X DELETE $B/api/labs -d "{\"path\":\"$LAB\"}")" "success"
fi
sudo rm -f "$BK/remote/pnetlab_db.sql" "$BK/remote/guacdb.sql"
note "left in place: $BK/{pnetlab_db,guacdb}.sql and $BK/pre-restore/, which are"
note "this host's most recent backup and the state from before the last restore."

echo
echo "============================================"
printf "  %d passed, %d failed, %d skipped\n" "$PASS" "$FAIL" "$SKIPPED"
echo "============================================"

if [ "$FAIL" -ne 0 ]; then
  printf "  \033[31m%d assertion(s) failed\033[0m\n" "$FAIL"
  exit 1
fi
exit 0
