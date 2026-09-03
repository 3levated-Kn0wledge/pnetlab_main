<?php
/**
 * Every state-changing lab-session action checks edit permission and the lock.
 *
 * checkLabPermission() distinguishes EDIT_LAB from OPEN_LAB and JOIN_LAB: a lab
 * can be shared so that others may open or join it while only the owner may
 * change it. api.php's /labs/session/{object}/{action} switch enforces that
 * with two calls at the top of each mutating branch,
 *
 *     checkLabPermission($lab, USER_PER_EDIT_LAB);
 *     checkLockLab($lab);
 *
 * and six branches did not have them: interfaces/setquality and setSuspend,
 * all three wireshark actions, and multi_cfg/active -- which changes the lab's
 * startup configuration, saves it, and announces that every node is wiped for
 * it. A participant who could open a shared lab, and nothing more, could do all
 * of that, and the wireshark branches reach the Docker daemon.
 *
 * This is the action table Codex asked for, as a test rather than a refactor:
 * every branch of the switch is found by the tokenizer and must EITHER carry
 * both checks OR be listed below as deliberately open, with the reason. A new
 * branch that carries neither fails here. Branches that check permission but
 * not the lock are listed too, because a lock is about the topology and those
 * actions do not change it.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

echo "lab-session action permissions\n";

// Runtime control of a session the caller was allowed to open or join. These
// start, stop and wipe the nodes of the caller's OWN session (the tenant is
// part of the key) and change nothing in the lab file. Whether a participant
// should have them is a policy question the product answers yes to; they are
// not the edit right and are listed so that the answer is written down.
$open = [
    'nodes/start', 'nodes/stop', 'nodes/wipe',
    'nodes/unlock',     // clears a node's own lock flag, no XML change
];

// Permission is checked; the lock is not, because nothing in the topology
// changes: the lock is being set or cleared, a config or export is read, or a
// console port is reassigned.
$noLock = [
    'lab/lock', 'lab/unlock', 'nodes/export', 'nodes/port', 'configs/edit',
];

$api = code_only($root . '/api.php');

// The session switch is the LAST `case 'lab':` at three tabs; the routes above
// it have their own switches over the same object names.
$start = strrpos($api, "\t\t\tcase 'lab':");
assert_true($start !== false, 'found the /labs/session/{object}/{action} switch');
$end = strpos($api, "\n\t\t}", $start);
$switch = substr($api, $start, $end - $start);

preg_match_all("/^\t\t\tcase '(\w+)':/m", $switch, $cases, PREG_OFFSET_CAPTURE);
assert_true(count($cases[1]) >= 12, 'the switch has its ' . count($cases[1]) . ' objects');

$seen = [];
foreach ($cases[1] as $i => $case) {
    $from = $case[1];
    $to = isset($cases[1][$i + 1]) ? $cases[1][$i + 1][1] : strlen($switch);
    $body = substr($switch, $from, $to - $from);

    preg_match_all("/if\s*\(\s*\\\$action\s*==\s*'(\w+)'\s*\)\s*\{/", $body, $acts, PREG_OFFSET_CAPTURE);
    foreach ($acts[1] as $j => $act) {
        $afrom = $act[1];
        $ato = isset($acts[1][$j + 1]) ? $acts[1][$j + 1][1] : strlen($body);
        $branch = substr($body, $afrom, $ato - $afrom);
        $name = $case[0] . '/' . $act[0];
        $seen[] = $name;

        $perm = strpos($branch, 'checkLabPermission($lab, USER_PER_EDIT_LAB)') !== false;
        $lock = strpos($branch, 'checkLockLab($lab)') !== false;

        if (in_array($name, $open, true)) {
            assert_true(!$perm, "$name is listed as open and does not check edit permission");
            continue;
        }
        assert_true($perm, "$name checks edit permission");
        if (in_array($name, $noLock, true)) {
            continue;
        }
        assert_true($lock, "$name checks the lab lock");

        // And before anything else in the branch: the checks throw, so what
        // comes first is what runs for everyone.
        $pPerm = strpos($branch, 'checkLabPermission(');
        $pLock = strpos($branch, 'checkLockLab(');
        $pSave = strpos($branch, '->save()');
        $pFirstStmt = strpos($branch, ';');
        assert_true($pPerm < $pFirstStmt + 1 && $pLock < ($pSave === false ? PHP_INT_MAX : $pSave),
            "$name checks before it acts");
    }
}

// The six that were missing, named, so the fix is asserted and not just the
// invariant.
foreach (['interfaces/setquality', 'interfaces/setSuspend', 'wireshark/add',
          'wireshark/capture', 'wireshark/delete', 'multi_cfg/active'] as $fixed) {
    assert_true(in_array($fixed, $seen, true), "$fixed is a branch the sweep saw");
}

// Nothing in the open list has quietly disappeared or been renamed, which
// would leave a stale entry that could later cover a new branch.
foreach (array_merge($open, $noLock) as $listed) {
    assert_true(in_array($listed, $seen, true), "$listed, listed above, still exists");
}

test_summary();
