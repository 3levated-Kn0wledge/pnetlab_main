<?php
/**
 * Exercises `unl_wrapper -a iol-keepalive`, the action that replaced the worst
 * gadget in the tree.
 *
 * WHAT WAS THERE BEFORE
 *
 * devices/interfc.php::setLinkState() read a uid out of `id -u`, built a shell
 * string, and ran:
 *
 *     sudo php /opt/unetlab/html/store/app/Console/Commands/wrapper 32768 <uid> '<string>'
 *
 * That script was thirteen lines — posix_setsid, posix_setgid($argv[1]),
 * posix_setuid($argv[2]), shell_exec($argv[3]) — and both drops were guarded by
 * `> 0`, so passing 0 kept root. It is a general-purpose "run this as root, or
 * first drop to any uid you name" primitive, and it was reachable from an
 * ordinary link-state change.
 *
 * old_gadget_argv() below is that call site, copied from the pre-change file at
 * rev f6825bc and used as a NEGATIVE CONTROL: the same inputs are pushed
 * through it and through the new action, and the assertions show what each one
 * does with them. It is a fixture, not shipped code.
 *
 * WHAT IS TESTED
 *
 *   - every argument is validated as if the caller were hostile, because the
 *     web layer is the caller;
 *   - the uid is computed and then confirmed against the passwd entry, so no
 *     input names a uid and 0 is unreachable;
 *   - the script is the fixed addons path and never the copy in the mode-777
 *     workspace;
 *   - bringing a link down signals processes identified by tenant uid and script
 *     path, not by a `ps -aux | grep` match.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/platform/wrappers/actions/UnlIolKeepalive.php';

// ---------------------------------------------------------------- scaffolding

$workspace = sys_get_temp_dir() . '/iolka-test-' . getmypid();
$tmpRoot   = $workspace . '/opt/unetlab/tmp';
$addons    = $workspace . '/opt/unetlab/addons/iol/bin';
mkdir($tmpRoot . '/7/42', 0777, true);
mkdir($tmpRoot . '/7/48', 0777, true);
mkdir($addons, 0755, true);
file_put_contents($addons . '/keepalive.pl', "#!/usr/bin/perl\n");
// The workspace copy the old call site executed. Nothing here may ever run it.
file_put_contents($tmpRoot . '/7/42/keepalive.pl', "#!/usr/bin/perl\nprint 'pwned';\n");

register_shutdown_function(function () use ($workspace) {
    $rm = function ($path) use (&$rm) {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) as $n) if ($n !== '.' && $n !== '..') $rm($path . '/' . $n);
        @rmdir($path);
    };
    $rm($workspace);
});

/** node_sessions rows the action is allowed to see. */
$rows = [
    42 => ['lab' => 7, 'type' => 'iol',    'iol_id' => 3],
    43 => ['lab' => 7, 'type' => 'qemu',   'iol_id' => 0],
    44 => ['lab' => 7, 'type' => 'iol',    'iol_id' => 3],   // account holds the wrong uid
    46 => ['lab' => 7, 'type' => 'iol',    'iol_id' => 3],   // no workspace on disk
    47 => ['lab' => 7, 'type' => 'iol',    'iol_id' => 0],   // never got an IOL id
    48 => ['lab' => 7, 'type' => 'iol',    'iol_id' => 3],   // account has the wrong primary group
];

/** passwd, with 42 and 45 present and 44 deliberately holding the wrong uid. */
$passwd = [
    'unl42' => ['uid' => 32768 + 42, 'gid' => 32768],
    'unl43' => ['uid' => 32768 + 43, 'gid' => 32768],
    'unl44' => ['uid' => 1000, 'gid' => 1000],   // an account of that name that is not ours
    'unl45' => ['uid' => 32768 + 45, 'gid' => 32768],
    'unl46' => ['uid' => 32768 + 46, 'gid' => 32768],
    'unl47' => ['uid' => 32768 + 47, 'gid' => 32768],
    'unl48' => ['uid' => 32768 + 48, 'gid' => 100],  // the right uid, the wrong primary group
];

function ka(array $extra = [])
{
    global $rows, $passwd, $tmpRoot, $addons;
    return new UnlIolKeepalive(array_merge([
        'script'       => $addons . '/keepalive.pl',
        'tmp_root'     => $tmpRoot,
        'run_commands' => false,
        'lookup'       => function ($s) use ($rows) { return isset($rows[$s]) ? $rows[$s] : null; },
        'pwnam'        => function ($n) use ($passwd) { return isset($passwd[$n]) ? $passwd[$n] : null; },
    ], $extra));
}

/**
 * NEGATIVE CONTROL — the pre-change call site, verbatim in shape.
 * Returns the argv that reached execve, i.e. ['/bin/sh', '-c', <string>].
 */
function old_gadget_argv($session, $iface, $uid)
{
    $cmd = 'sudo perl ' . escapeshellarg('/opt/unetlab/tmp/7/' . $session . '/keepalive.pl')
        . ' -i ' . escapeshellarg(3)
        . ' -p ' . escapeshellarg($iface)
        . ' -n ' . escapeshellarg($session . '_' . $iface)
        . ' > ' . escapeshellarg('/opt/unetlab/tmp/7/' . $session . '/keepalive.log') . ' 2>&1 &';
    $wrapper = "sudo php /opt/unetlab/html/store/app/Console/Commands/wrapper 32768 " . $uid . " '" . $cmd . "'";
    return ['/bin/sh', '-c', $wrapper];
}

// ------------------------------------------------------------ argument rejection

echo "  -- rejects arguments that are not bounded integers\n";
$badSessions = ['0', '-1', '', '1; id', '1 2', '4 2', '../42', '42/../43', 'a42',
                '1e3', ' 42', "42\n", '999999999999', null, false, true,
                ['42', '43']];
$rejected = 0;
foreach ($badSessions as $bad) {
    $r = ka()->up($bad, 0);
    if (!$r['ok']) $rejected++;
    else echo "        ACCEPTED a bad session: " . var_export($bad, true) . "\n";
}
assert_same(count($badSessions), $rejected,
    sprintf('up() rejects every malformed session id (%d of %d)', $rejected, count($badSessions)));

$badIfaces = ['-1', '256', '', 'x', '0; id', '../1', null, ['0', '1'], '1.0'];
$rejected = 0;
foreach ($badIfaces as $bad) {
    $r = ka()->up(42, $bad);
    if (!$r['ok']) $rejected++;
    else echo "        ACCEPTED a bad interface: " . var_export($bad, true) . "\n";
}
assert_same(count($badIfaces), $rejected,
    sprintf('up() rejects every malformed interface id (%d of %d)', $rejected, count($badIfaces)));

// A repeated option is the shape getopt() turns into an array, and (int) on an
// array is 1 — which is a valid-looking session id that no validator ever saw.
$r = ka()->up(['42', '43'], 0);
assert_true(!$r['ok'], 'a repeated option (getopt array) is refused, not cast to 1');

// ------------------------------------------------------------------- the roots

echo "  -- refuses to act outside what it owns\n";

$r = ka()->up(43, 0);
assert_true(!$r['ok'], 'refuses a node session that is not an IOL node');

$r = ka()->up(99, 0);
assert_true(!$r['ok'], 'refuses a node session that does not exist');

$r = ka()->up(44, 0);
assert_true(!$r['ok'] && strpos($r['error'], 'uid') !== false,
    'refuses when unl<session> exists but does not hold uid 32768+session');

// The workspace is reached only at $tmp_root/<lab>/<session>. Point that name
// at somewhere else and the action must decline rather than chdir into it.
mkdir($tmpRoot . '/7/45', 0777, true);
$elsewhere = $workspace . '/elsewhere';
mkdir($elsewhere, 0777, true);
$rows[45] = ['lab' => 7, 'type' => 'iol', 'iol_id' => 3];
rmdir($tmpRoot . '/7/45');
symlink($elsewhere, $tmpRoot . '/7/45');
$r = ka()->up(45, 0);
assert_true(!$r['ok'], 'refuses a workspace that is a symlink to somewhere else');
unlink($tmpRoot . '/7/45');

$r = ka(['script' => $addons . '/does-not-exist.pl'])->up(42, 0);
assert_true(!$r['ok'], 'refuses to run when keepalive.pl is not installed');

// ------------------------------------------------------------------ happy path

echo "  -- the happy path\n";
$k = ka();
$r = $k->up('42', '3');
assert_true($r['ok'], 'starts the helper for a real IOL session and interface');
assert_same(1, count($k->commands), 'exactly one process would be started');
$c = $k->commands[0];

assert_same([$addons . '/keepalive.pl', '-i', '3', '-p', '3', '-n', '42_3'], $c['argv'],
    'argv is an array, fixed script first, then -i/-p/-n');
assert_same(32768 + 42, $c['uid'], 'drops to uid 32768 + session');
assert_same(32768, $c['gid'], 'drops to the unl group, as read from the passwd entry');
assert_true(!empty($c['initgroups']),
    'and sets the supplementary groups from the passwd database before the drop, '
    . 'as device::spawnAsTenant() does -- this was the one drop site that skipped it');
assert_same($tmpRoot . '/7/42', $c['cwd'], 'runs in the node workspace');
assert_same($tmpRoot . '/7/42/keepalive.log', $c['log'], 'logs inside the node workspace');
assert_true($c['bin'] === '/usr/bin/perl', 'the interpreter is a fixed absolute path');

// The old call site ran <runningPath>/keepalive.pl. /opt/unetlab/tmp is mode
// 777, so that file is replaceable by anyone who can write a node workspace.
assert_true($c['argv'][0] !== $tmpRoot . '/7/42/keepalive.pl',
    'never executes the copy of keepalive.pl inside the writable workspace');

// A workspace with no directory on disk is not a workspace.
// The gid is read from the passwd entry and checked against the platform
// group, not assumed: an account called unl<N> with the right uid but some
// other primary group is not the platform's, and is refused rather than
// dropped into a group it was never meant to be in.
$r = ka()->up(48, 0);
assert_true(!$r['ok'], 'refuses a tenant whose primary group is not unl');
assert_true(strpos($r['error'], 'platform group') !== false, '...saying so');

$r = ka()->up(46, 0);
assert_true(!$r['ok'], 'refuses when the node workspace does not exist');

// The IOL id comes out of the database, and the database is written by the web
// layer, so it is revalidated here like everything else.
$r = ka()->up(47, 0);
assert_true(!$r['ok'], 'refuses a node session whose IOL id is not a bounded integer');

// ------------------------------------------------------------- the down path

echo "  -- stopping the helper\n";

$script = $addons . '/keepalive.pl';
$procs = [
    ['pid' => 4242, 'uid' => 32768 + 42, 'argv' => ['/usr/bin/perl', $script, '-i', '3', '-p', '3', '-n', '42_3']],
    ['pid' => 4243, 'uid' => 32768 + 42, 'argv' => ['/usr/bin/perl', $script, '-i', '3', '-p', '4', '-n', '42_4']],
    ['pid' => 4244, 'uid' => 32768 + 43, 'argv' => ['/usr/bin/perl', $script, '-i', '3', '-p', '3', '-n', '43_3']],
    // The shape the old `ps -aux | grep keepalive | grep 42_3` matched and
    // killed as root. Nothing here may touch it.
    ['pid' => 1,    'uid' => 0,          'argv' => ['/sbin/init']],
    ['pid' => 999,  'uid' => 0,          'argv' => ['/usr/sbin/sshd', '-D', '# keepalive 42_3']],
    ['pid' => 998,  'uid' => 32768 + 42, 'argv' => ['/usr/bin/perl', '/tmp/keepalive.pl', '-n', '42_3']],
];
$lister = function () use ($procs) { return $procs; };

$k = ka(['proc_lister' => $lister]);
$r = $k->down('42', '3');
assert_true($r['ok'], 'down() succeeds');
assert_same(1, $r['killed'], 'kills exactly the helper for that session and interface');
assert_same([['pid' => 4242, 'signal' => 9]], $k->signals, 'and signals only that pid');

$k = ka(['proc_lister' => $lister]);
$r = $k->down('42', null);
assert_same(2, $r['killed'], 'with no interface, kills every helper of that session');
assert_same([4242, 4243], array_column($k->signals, 'pid'),
    'and still nothing belonging to another session, to root, or to another script');

$k = ka(['proc_lister' => $lister]);
$r = $k->down('1; id', null);
assert_true(!$r['ok'], 'down() rejects a malformed session id');
assert_same([], $k->signals, 'and signals nothing when it does');

// Teardown asks for this constantly once the tenant account has gone.
$k = ka(['proc_lister' => $lister]);
$r = $k->down('99', null);
assert_true($r['ok'] && $r['killed'] === 0,
    'a session with no tenant account is a no-op, not an error');
assert_same([], $k->signals, 'and signals nothing');

// -------------------------------------------------- negative control: the old shape

echo "  -- negative control: what the same inputs did before\n";

$old = old_gadget_argv('42', '3', 0);
assert_true($old[0] === '/bin/sh' && $old[1] === '-c',
    'the old call site handed a STRING to a shell');
assert_true(strpos($old[2], 'wrapper 32768 0 ') !== false,
    'and a uid of 0 in that string meant the payload stayed root');

$k = ka();
$k->up('42', '3');
foreach ($k->commands[0]['argv'] as $arg) {
    assert_true(strpos($arg, ';') === false && strpos($arg, '`') === false
        && strpos($arg, '$(') === false && strpos($arg, '&') === false,
        'the new argv carries no shell metacharacter: ' . $arg);
}
assert_true($k->commands[0]['uid'] >= 32768,
    'and the uid it drops to cannot be chosen by the caller');

// ------------------------------------------------- the gadget is actually gone

echo "  -- the gadget is gone from the tree\n";

assert_true(!file_exists($root . '/store/app/Console/Commands/wrapper'),
    'store/app/Console/Commands/wrapper no longer exists');

/** Non-comment lines only, the same rule SudoersPolicyTest uses. */
function ka_live_lines($path)
{
    $out = [];
    if (!is_file($path)) return $out;
    foreach (file($path) as $n => $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '//') === 0 || strpos($t, '*') === 0) continue;
        $out[$n + 1] = $line;
    }
    return $out;
}

$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $f->getPathname());
    foreach (['/.git/', '/.claude/', '/store/vendor/', '/node_modules/', '/tests/'] as $skip) {
        if (strpos($path, $skip) !== false) continue 2;
    }
    foreach (ka_live_lines($path) as $n => $line) {
        if (preg_match('/sudo\s+(-\S+\s+)*(\/\S*\/)?(perl|php)\s+\S*Console\/Commands\/wrapper/', $line)
            || preg_match('/Console\/Commands\/wrapper\s/', $line)) {
            $offenders[] = substr($path, strlen($root) + 1) . ':' . $n;
        }
    }
}
assert_same([], $offenders, 'nothing in the tree still invokes the setuid-and-shell_exec helper');

// The policy line that existed only for this path.
$policy = file_get_contents($root . '/install/sudoers.d/pnetlab');
assert_true(!preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:\s*\S*\/perl\s*$/m', $policy),
    'the sudo grant for perl is gone from the policy');
assert_true(!preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:\s*\S*\/kill\s*$/m', $policy),
    'the sudo grant for kill is gone from the policy');

// The wrapper must dispatch on a two-value enum, not on whatever --state says.
$wrapper = file_get_contents($root . '/platform/wrappers/unl_wrapper');
assert_true(strpos($wrapper, "\$state !== 'up' && \$state !== 'down'") !== false,
    'unl_wrapper validates --state against a closed two-value enum');
assert_true(strpos($wrapper, 'function unl_single_option') !== false,
    'unl_wrapper refuses a repeated option rather than casting the array');

test_summary();
