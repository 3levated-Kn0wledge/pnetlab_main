<?php
/**
 * Exercises `unl_wrapper -a reap-tenant`, and the account creation it is paired
 * with.
 *
 * WHAT WAS THERE BEFORE
 *
 * Nothing. That is the whole finding. Node start runs, once per NODE session:
 *
 *     sudo /usr/sbin/useradd -c "Unified Networking Lab TID=N" -d /opt/unetlab/users/N
 *          -g unl -M -s /bin/bash -u <32768+N> unlN
 *
 * and there is no userdel, no deluser and no expiry anywhere in the tree. The
 * accounts accumulated for the life of the appliance, each with a login shell.
 * The reference VM was carrying three of them from a previous test run when this
 * work started — and that is also why the existing functional assertion
 *
 *     chk "three tenant accounts created" "$(getent passwd | grep -c '^unl')" "3"
 *
 * could never have caught the leak: destroy empties node_sessions, ids restart
 * at 1, 2, 3, and the three accounts left behind by the LAST run satisfy the
 * count for the next one. The leak was what made the assertion pass.
 *
 * WHAT IS BEING GUARDED
 *
 * A reaper is a delete primitive running as root, so the tests below are mostly
 * about what it REFUSES. The dangerous shapes are:
 *
 *   - a name that is not unl<digits> — because the only thing standing between
 *     this and `userdel root` is that the name is constructed, not received;
 *   - an account outside the `unl` group, or holding the wrong uid — someone
 *     else's account wearing a tenant name;
 *   - a tenant whose node is still up — the account owns the tap interfaces and
 *     the running directory, and removing it strands both.
 *
 * Everything here runs against injected passwd, group, /proc and /sys/class/net
 * views and a recording spawn(), so the refusals can be driven with states a
 * real host would not hold still for. run_commands is false throughout except
 * where the home-directory removal is exercised on a real temporary tree.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/platform/wrappers/actions/UnlTenantAccount.php';

// ---------------------------------------------------------------- scaffolding

$ws = sys_get_temp_dir() . '/tenantacct-test-' . getmypid();
mkdir($ws . '/opt/unetlab/users', 0775, true);
mkdir($ws . '/sys/class/net', 0755, true);
file_put_contents($ws . '/sys/class/net/lo', '');
file_put_contents($ws . '/sys/class/net/eth0', '');

register_shutdown_function(function () use ($ws) {
    $rm = function ($p) use (&$rm) {
        if (is_link($p) || is_file($p)) { @unlink($p); return; }
        if (!is_dir($p)) return;
        foreach (scandir($p) as $n) if ($n !== '.' && $n !== '..') $rm($p . '/' . $n);
        @rmdir($p);
    };
    $rm($ws);
});

/**
 * The passwd view the tests run against.
 *
 *   unl1   a healthy tenant account
 *   unl2   a healthy tenant account, whose node is still running
 *   unl7   right name, WRONG uid   — not the platform's
 *   unl8   right name, WRONG group — not in unl
 *   unl9   right name and uid, but nothing is listening and no tap exists
 *   root   present so that "would it ever touch this" can be asked directly
 */
$passwd = [
    'root' => ['name' => 'root', 'uid' => 0,     'gid' => 0],
    'unl1' => ['name' => 'unl1', 'uid' => 32769, 'gid' => 32768],
    'unl2' => ['name' => 'unl2', 'uid' => 32770, 'gid' => 32768],
    'unl7' => ['name' => 'unl7', 'uid' => 4242,  'gid' => 32768],
    'unl8' => ['name' => 'unl8', 'uid' => 32776, 'gid' => 1000],
    'unl9' => ['name' => 'unl9', 'uid' => 32777, 'gid' => 32768],
];

/** Processes, as the /proc walk would report them. uid 32770 is unl2's. */
$procs = [
    ['pid' => 1,     'uid' => 0],
    ['pid' => 9001,  'uid' => 32770],
    ['pid' => 9002,  'uid' => 33],
];

/** Interfaces. vunl2_0 belongs to session 2, and must pin its account. */
$links = ['lo', 'eth0'];

/** Node session statuses, as getNodeStatus() would return them. */
$status = [];

function ta(array $extra = [])
{
    global $ws, $passwd, $procs, $links, $status;
    return new UnlTenantAccount(array_merge([
        'users_root'  => $ws . '/opt/unetlab/users',
        'sys_net'     => $ws . '/sys/class/net',
        'run_commands' => false,
        'wait_polls'  => 1,
        'pwnam'       => function ($n) use (&$passwd) { return isset($passwd[$n]) ? $passwd[$n] : null; },
        'grnam'       => function ($n) { return $n === 'unl' ? ['name' => 'unl', 'gid' => 32768] : null; },
        'accounts'    => function () use (&$passwd) { return array_keys($passwd); },
        'proc_lister' => function () use (&$procs) { return $procs; },
        'status'      => function ($s) use (&$status) { return isset($status[$s]) ? $status[$s] : null; },
    ], $extra));
}

/** Every argv the action would have run, flattened for matching. */
function argvs($action)
{
    $out = [];
    foreach ($action->commands as $c) $out[] = implode(' ', $c);
    return $out;
}

/**
 * NEGATIVE CONTROL — a reaper written the way the rest of this tree writes
 * privileged deletes. It takes a name, builds a string, and hands it to a root
 * shell. This is not hypothetical shape: `sudo rm -rf ' . $runningPath` and
 * `sudo kill -9 <field from ps | grep>` are both still in the tree.
 */
function old_reap_argv($username)
{
    return ['/bin/sh', '-c', 'sudo userdel -r ' . $username];
}

// ------------------------------------------------------- the name is built here

echo "  -- the account name is constructed, never received\n";

$badNames = ['root', 'unl', 'unl1x', 'Unl1', 'unl 1', 'unl-1', 'unl1 root', '',
             'unl1;id', "unl1\n", '../root', 'www-data', null, false, ['unl1'], 42,
             'unlx', 'unl01root'];
$rejected = 0;
foreach ($badNames as $bad) {
    if (!UnlTenantAccount::isTenantName($bad)) $rejected++;
    else echo "        ACCEPTED as a tenant name: " . var_export($bad, true) . "\n";
}
assert_same(count($badNames), $rejected,
    sprintf('isTenantName() rejects every name that is not unl<digits> (%d of %d)',
            $rejected, count($badNames)));

assert_true(UnlTenantAccount::isTenantName('unl0'), 'and accepts unl0');
assert_true(UnlTenantAccount::isTenantName('unl29999'), 'and accepts unl29999');
assert_same(1, UnlTenantAccount::sessionOfName('unl1'), 'sessionOfName() reads the id back');
assert_same(null, UnlTenantAccount::sessionOfName('unl30000'),
    'and refuses an id createNodeSession() cannot produce');
assert_same(null, UnlTenantAccount::sessionOfName('root'), 'and refuses root outright');

// The public entry point takes a SESSION, so there is no argument through which
// a name could arrive at all. Prove the malformed ones are refused there too.
$badSessions = ['', '-1', 'root', '1 root', '1;id', "1\n", '../1', '30000',
                '99999999999', null, false, ['1'], 'unl1', '0x1', '+1', ' 1'];
$rejected = 0;
foreach ($badSessions as $bad) {
    $r = ta()->reap($bad);
    if (!$r['ok']) $rejected++;
    else echo "        ACCEPTED a bad session: " . var_export($bad, true) . "\n";
}
assert_same(count($badSessions), $rejected,
    sprintf('reap() rejects every malformed session id (%d of %d)', $rejected, count($badSessions)));

// ---------------------------------------------------- the group and uid checks

echo "  -- an account outside the unl group is not the platform's\n";

$r = ta()->reap(8);
assert_true(!$r['ok'], 'refuses unl8, which has the right name but gid 1000');
assert_true(strpos((string) $r['error'], 'unl group') !== false,
    'and says the group is why');

$r = ta()->reap(7);
assert_true(!$r['ok'], 'refuses unl7, which holds uid 4242 rather than 32775');
assert_same(0, $r['reaped'], 'and reaps nothing while refusing');

// Both refusals must happen before anything is spawned.
$a = ta();
$a->reap(7);
$a->reap(8);
assert_same([], argvs($a), 'neither refusal reached userdel at all');

// The gid check is a comparison against the group resolved BY NAME. If `unl`
// cannot be resolved the comparison still happens against the stock gid, so a
// broken install refuses rather than reaping with no group check.
$r = ta(['grnam' => function ($n) { return null; }])->reap(8);
assert_true(!$r['ok'], 'with no resolvable unl group, a gid mismatch is still refused');

// ------------------------------------------------------ a running tenant is safe

echo "  -- a tenant with a running node is not reaped\n";

$a = ta();
$r = $a->reap(2);
assert_true($r['ok'], 'a running tenant is not an error');
assert_same(0, $r['reaped'], 'but nothing is reaped');
assert_true(strpos((string) $r['kept'], '32770') !== false,
    'and the reason names the uid that is still busy');
assert_same([], argvs($a), 'userdel was never reached');

// The tap check fires before the process check and independently of it: a tap
// can outlive the emulator, and it is the resource whose loss breaks a lab.
$links[] = 'vunl9_0';
file_put_contents($ws . '/sys/class/net/vunl9_0', '');
$a = ta();
$r = $a->reap(9);
assert_true($r['ok'] && $r['reaped'] === 0, 'a tenant with a surviving tap is kept');
assert_true(strpos((string) $r['kept'], 'vunl9_') !== false, 'and the reason names the tap');
assert_same([], argvs($a), 'and userdel was not reached');
unlink($ws . '/sys/class/net/vunl9_0');

// vunl19_0 must not pin session 1. Prefix matching without the underscore is
// the bug that would make session 1 unreapable for as long as session 19 runs.
file_put_contents($ws . '/sys/class/net/vunl19_0', '');
$a = ta();
$r = $a->reap(1);
assert_same(1, $r['reaped'], 'vunl19_0 does not pin session 1');
unlink($ws . '/sys/class/net/vunl19_0');

// And the database's own view is honoured when it says running.
$status[9] = 2;
$a = ta();
$r = $a->reap(9);
assert_true($r['ok'] && $r['reaped'] === 0, 'a session the database reports running is kept');
assert_true(strpos((string) $r['kept'], 'status 2') !== false, 'and the reason names the status');
$status[9] = 3;
$r = ta()->reap(9);
assert_same(0, $r['reaped'], 'status 3 (running and locked) is also running');
$status[9] = 1;
$r = ta()->reap(9);
assert_same(1, $r['reaped'], 'status 1 (stopped, stale .lock) is not, and is reaped');
unset($status[9]);

// ------------------------------------------------------------- the happy path

echo "  -- the happy path, and what it runs\n";

$a = ta();
$r = $a->reap(1);
assert_true($r['ok'], 'reaps a finished tenant');
assert_same(1, $r['reaped'], 'and says it removed one');
assert_same('unl1', $r['name'], 'and names it');
assert_same(null, $r['kept'], 'with nothing kept');

$cmds = $a->commands;
assert_true(count($cmds) >= 1, 'something was run');
assert_same(['/usr/sbin/userdel', 'unl1'], $cmds[0],
    'userdel is exec\'d as an argv array with exactly the constructed name');
assert_true(!in_array('-r', $cmds[0], true),
    'and WITHOUT -r; the home directory is removed by a path this class checks');
foreach ($cmds as $c) {
    assert_true(is_array($c), 'every recorded operation is an argv array, not a string');
}
$joined = implode(' ', array_map(function ($c) { return implode(' ', $c); }, $cmds));
assert_true(strpos($joined, 'sh') === false && strpos($joined, ';') === false
    && strpos($joined, '|') === false,
    'no shell and no metacharacter anywhere in what would have run');

// ---------------------------------------------------------------- idempotence

echo "  -- calling it twice is a success, not an error\n";

// Every teardown path calls this, and several of them overlap: node stop, the
// stopall sweep, and destroying a broken session can all fire for one id.
$gone = ta(['pwnam' => function ($n) { return null; }]);
$r1 = $gone->reap(1);
$r2 = $gone->reap(1);
assert_true($r1['ok'] && $r2['ok'], 'an account that is already gone is a success');
assert_same(0, $r1['reaped'] + $r2['reaped'], 'and reaps nothing, twice');
assert_same([], argvs($gone), 'and does not run userdel on an account that is not there');

// userdel exiting 6 ("no such user") is the same race and is not a failure.
$racing = new UnlTenantAccount([
    'users_root' => $ws . '/opt/unetlab/users',
    'sys_net'    => $ws . '/sys/class/net',
    'userdel'    => '/bin/false',   // exits 1, so the failure path is real
    'wait_polls' => 0,
    'pwnam'      => function ($n) use (&$passwd) { return isset($passwd[$n]) ? $passwd[$n] : null; },
    'grnam'      => function ($n) { return $n === 'unl' ? ['name' => 'unl', 'gid' => 32768] : null; },
    'proc_lister' => function () { return []; },
]);
$r = $racing->reap(1);
assert_true(!$r['ok'], 'a userdel that genuinely fails is reported as a failure');

// ------------------------------------------------------ the home directory

echo "  -- the home directory is removed one level deep, never recursively\n";

$users = $ws . '/opt/unetlab/users';
mkdir($users . '/1', 0755, true);
symlink('/opt/unetlab/wrappers/unl_profile', $users . '/1/.profile');
$real = new UnlTenantAccount([
    'users_root'  => $users,
    'sys_net'     => $ws . '/sys/class/net',
    'userdel'     => '/bin/true',
    'wait_polls'  => 0,
    'pwnam'       => function ($n) use (&$passwd) { return isset($passwd[$n]) ? $passwd[$n] : null; },
    'grnam'       => function ($n) { return $n === 'unl' ? ['name' => 'unl', 'gid' => 32768] : null; },
    'proc_lister' => function () { return []; },
]);
$r = $real->reap(1);
assert_true($r['ok'] && $r['reaped'] === 1, 'the real path reaps');
assert_true(!file_exists($users . '/1'), 'and the home directory is gone with it');

// A home directory holding a subdirectory is LEFT. /opt/unetlab/users is 2775
// root:unl, so its contents are tenant-writable; a recursive root delete driven
// by them is the primitive this whole phase has been removing.
mkdir($users . '/1/keep', 0755, true);
$r = $real->reap(1);
assert_true($r['ok'], 'a home directory holding a subdirectory still reaps the account');
assert_true(is_dir($users . '/1/keep'), 'but the subdirectory is not deleted');
assert_true(is_dir($users . '/1'), 'and neither is the home directory itself');
rmdir($users . '/1/keep');
rmdir($users . '/1');

// A symlinked home directory is not followed.
mkdir($ws . '/elsewhere', 0755, true);
file_put_contents($ws . '/elsewhere/precious', 'x');
symlink($ws . '/elsewhere', $users . '/1');
$r = $real->reap(1);
assert_true($r['ok'], 'a symlinked home directory does not stop the account being reaped');
assert_true(is_file($ws . '/elsewhere/precious'),
    'but nothing behind the symlink is touched');
unlink($users . '/1');

// -------------------------------------------------------------- the sweep

echo "  -- the --scope all sweep\n";

$links = ['lo', 'eth0'];
$a = ta();
$r = $a->reapAll();
assert_true($r['ok'], 'the sweep succeeds');
// unl1 and unl9 are finished; unl2 is running; unl7 and unl8 are refused.
assert_same(2, $r['reaped'], 'it reaps the two finished tenants and no others');
assert_true(isset($r['kept']['unl2']), 'and reports the running one as kept');
assert_true(isset($r['kept']['unl7']) && isset($r['kept']['unl8']),
    'and the two that are not the platform\'s');
assert_true(!isset($r['kept']['root']), 'root is not a candidate and is not reported');

$swept = [];
foreach ($a->commands as $c) {
    if ($c[0] !== '/usr/sbin/userdel') continue;
    $swept[] = implode(' ', $c);
}
foreach ($swept as $line) {
    assert_true(strpos($line, 'userdel unl') !== false,
        'every account the sweep ran userdel on was a tenant account: ' . $line);
}
assert_same(2, count($swept), 'and it ran userdel exactly twice');

// The sweep must not consider anything that is not unl<digits>, whatever the
// passwd file holds.
$hostile = ta(['accounts' => function () {
    return ['root', 'unl', 'unlroot', 'unl1;id', '../root', 'unl1'];
}]);
$hostile->reapAll();
$hostileDeletes = [];
foreach ($hostile->commands as $c) {
    if ($c[0] === '/usr/sbin/userdel') $hostileDeletes[] = implode(' ', $c);
}
assert_same(['/usr/sbin/userdel unl1'], $hostileDeletes,
    'a hostile passwd listing yields exactly one candidate, and it is unl1');

// --------------------------------------------------------------- creation

echo "  -- creation agrees with reaping about name, uid and group\n";

$a = ta(['pwnam' => function ($n) { return null; }]);
$r = $a->create(5);
assert_true($r['ok'] && $r['created'], 'creates a missing tenant account');
assert_same('unl5', $r['name'], 'with the constructed name');
assert_same(32773, $r['uid'], 'and uid 32768 + session');
$argv = $a->commands[0];
assert_same('/usr/sbin/useradd', $argv[0], 'through useradd, as an argv array');
assert_true(!in_array('sudo', $argv, true),
    'and WITHOUT sudo — checkUsername() is only ever reached as root');
assert_true(in_array('unl', $argv, true), 'in the unl group');
assert_true(in_array('unl5', $argv, true), 'for the constructed name');
assert_true(in_array('32773', $argv, true), 'with the computed uid');

assert_true(!in_array('-G', $argv, true),
    'and no supplementary group when the host does not have one');

// kvm, when the host has it: QEMU needs /dev/kvm (0660 root:kvm) and
// device::spawnAsTenant() cannot add a group after it has dropped the uid.
$withKvm = ta([
    'pwnam' => function ($n) { return null; },
    'grnam' => function ($n) {
        if ($n === 'unl') return ['name' => 'unl', 'gid' => 32768];
        if ($n === 'kvm') return ['name' => 'kvm', 'gid' => 994];
        return null;
    },
]);
$withKvm->create(5);
$argv = $withKvm->commands[0];
$g = array_search('-G', $argv, true);
assert_true($g !== false, 'a host with a kvm group gets a supplementary group');
assert_same('kvm', $argv[$g + 1], 'and it is kvm');
assert_same('unl5', $argv[count($argv) - 1],
    'the account name is still the last argument, after the group list');

// The list is a constant. Nothing a caller says can extend it.
assert_same(array('kvm'), UnlTenantAccount::EXTRA_GROUPS,
    'the supplementary group list is a fixed constant on the class');

$r = ta()->create(1);
assert_true($r['ok'] && !$r['created'], 'an existing healthy account is a no-op success');
$r = ta()->create(7);
assert_true(!$r['ok'], 'an existing account with the wrong uid is refused, not reused');
$r = ta()->create(8);
assert_true(!$r['ok'], 'an existing account outside the unl group is refused, not reused');

$rejected = 0;
foreach ($badSessions as $bad) {
    if (!ta()->create($bad)['ok']) $rejected++;
}
assert_same(count($badSessions), $rejected,
    sprintf('create() rejects the same malformed session ids reap() does (%d of %d)',
            $rejected, count($badSessions)));

// -------------------------------------------- negative control: the old shape

echo "  -- negative control: what a name-taking reaper would have done\n";

$old = old_reap_argv('root');
assert_true($old[0] === '/bin/sh' && $old[1] === '-c',
    'a reaper that takes a name hands a STRING to a root shell');
assert_true(strpos($old[2], 'userdel -r root') !== false,
    'and "root" reaches userdel -r as the account to delete');
$old = old_reap_argv('unl1; userdel -r www-data');
assert_true(strpos($old[2], '; userdel -r www-data') !== false,
    'and a semicolon in the name becomes a second root command');

// The same two values, through the action. Neither is expressible: the entry
// point takes a session id, and every one of these is refused by it.
foreach (['root', 'unl1; userdel -r www-data'] as $value) {
    $a = ta();
    $r = $a->reap($value);
    assert_true(!$r['ok'], 'the action refuses ' . var_export($value, true) . ' outright');
    assert_same([], argvs($a), 'and runs nothing while refusing it');
}

// ------------------------------------------------- the call sites are wired up

echo "  -- the call sites, with comments stripped so only code counts\n";

/** The file with every comment removed, so the assertions see only code. */
function code_without_comments($path)
{
    $out = '';
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { $out .= "\n"; continue; }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
}

$wrapper = code_without_comments($root . '/platform/wrappers/unl_wrapper');
assert_true(strpos($wrapper, "case 'reap-tenant':") !== false,
    'unl_wrapper dispatches -a reap-tenant');
assert_true(strpos($wrapper, 'UnlTenantAccount') !== false,
    'and instantiates the action class');
assert_true(substr_count($wrapper, 'reapAll()') === 2,
    'the sweep is reachable from --scope all and from stopall, and nowhere else');
assert_true(strpos($wrapper, "unl_single_option(\$options, 'S')") !== false,
    'the session arrives through unl_single_option, so a repeated -S is refused');

$device = code_without_comments($root . '/devices/device.php');
assert_true(strpos($device, 'reap-tenant') !== false,
    'device::stop() reaps the tenant account');
assert_true(strpos($device, '(int) $this->getSession()') !== false,
    'and the only value it interpolates is an int cast');

$functions = code_without_comments($root . '/includes/functions.php');
assert_true(strpos($functions, 'reap-tenant') !== false,
    'destroyBrokenLabSession() reaps too — it never calls device::stop()');

// ...and in an order the reaper can accept. It refuses while a vunl<N>_* tap
// exists or while node_sessions still reports the node as running, so the
// taps have to be released and the rows deleted BEFORE it is called. The
// first revision called it straight after the kill, so every call refused
// and the leak stayed open behind a comment saying it was closed.
$fnStart = strpos($functions, 'function destroyBrokenLabSession');
$fnBody = substr($functions, $fnStart);
$fnBody = substr($fnBody, 0, strpos($fnBody, "\nfunction ") ?: strlen($fnBody));
$reapAt = strpos($fnBody, 'reap-tenant');
$tapsAt = strpos($fnBody, 'unl_session_taps(');
$rowsAt = strpos($fnBody, 'DELETE FROM node_sessions');
assert_true($tapsAt !== false && $tapsAt < $reapAt,
    'destroyBrokenLabSession() releases the taps before it reaps');
assert_true($rowsAt !== false && $rowsAt < $reapAt,
    'and deletes the node_sessions rows before it reaps');
assert_true(strpos($fnBody, 'delTap(') !== false,
    'the taps go through delTap(), the same path device::releaseTaps() uses');

$cli = code_without_comments($root . '/includes/cli.php');
assert_true(strpos($cli, 'UnlTenantAccount') !== false,
    'checkUsername() creates the account through the shared action');
assert_true(strpos($cli, 'sudo /usr/sbin/useradd') === false
    && strpos($cli, 'sudo useradd') === false,
    'and the sudo useradd call site is gone from the code, not just commented out');

// ------------------------------------------------- and the grant went with it

$policy = file_get_contents($root . '/install/sudoers.d/pnetlab');
assert_true(!preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:\s*\S*\/useradd\s*(#.*)?$/m', $policy),
    'the sudo grant for useradd is gone from the policy');
assert_true(!preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:\s*\S*\/userdel/m', $policy),
    'and no grant for userdel was added in its place — the reap runs inside the wrapper');

test_summary();
