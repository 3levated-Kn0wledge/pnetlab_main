<?php
/**
 * Exercises `unl_wrapper -a fixperms --scope <word>`, the action that replaced
 * the last six `sudo chown` call sites.
 *
 * WHAT WAS THERE BEFORE
 *
 *     User/DependenceController.php:61-64   four constant `chown -R` trees
 *     User/VersionsController.php:78        one more
 *     Admin/DefaultController.php:254       exec('sudo chown www-data:www-data '. $file);
 *
 * The last one is the reason this is a test and not a rename: $file is a
 * template path built from $req->input('template') through secureCmd(), which
 * is a blocklist of [#;|&] and '..' — it passes backticks, $( ), spaces and
 * quotes, as tests/Security/SecureCmdTest.php pins.
 *
 * WHAT IS ASSERTED HERE
 *
 *   - the scope is a closed enumeration and an unknown one is refused;
 *   - no scope can be made to touch anything outside its own root, including
 *     through a symlink planted inside a tree the web user can already write.
 *     The walk is GNU chown's (-R -h -P), not PHP's: a PHP walk that tests
 *     is_link() and then chown()s is a time-of-check/time-of-use race on a
 *     tree the web user can rewrite between the two calls, and PHP has no
 *     fchownat() to close it. So the argv is pinned flag by flag, AND a real
 *     run over a scratch tree proves the planted link's target is never
 *     visited;
 *   - the web-layer helper's copy of the enumeration matches the wrapper's;
 *   - the old `sudo chown` shapes are gone from all four rewritten call sites.
 *
 * Negative controls are run against reproductions of the pre-change files, so
 * the scanners below are known to be able to fail.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/platform/wrappers/actions/UnlFixPerms.php';
require_once $root . '/store/app/Helpers/System/Wrapper.php';

// ---------------------------------------------------------------- scaffolding

$ws = sys_get_temp_dir() . '/fixperms-test-' . getmypid();

/** Every root the action would hand to chown -R, in the order it would. */
function planned(UnlFixPerms $f, $scope)
{
    $r = $f->run($scope);
    $paths = [];
    foreach ($f->commands as $c) $paths[] = $c[count($c) - 1];
    return [$r, $paths];
}

function fp()
{
    global $ws;
    return new UnlFixPerms(['prefix' => $ws, 'run_commands' => false]);
}

/** A live instance: really runs /bin/chown, handing the tree to the user running this test. */
function fp_live()
{
    global $ws;
    $me = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
    if ($me === false) return null;
    return new UnlFixPerms(['prefix' => $ws, 'owner' => $me['name']]);
}

$roots = [
    '/opt/unetlab/addons',
    '/opt/unetlab/html/templates',
    '/opt/unetlab/html/images/icons',
    '/opt/unetlab/scripts',
    '/opt/unetlab/labs',
];
foreach ($roots as $r) mkdir($ws . $r, 0755, true);
mkdir($ws . '/opt/unetlab/addons/qemu/linux', 0755, true);
file_put_contents($ws . '/opt/unetlab/addons/qemu/linux/hda.qcow2', 'x');
file_put_contents($ws . '/opt/unetlab/labs/one.unl', 'x');
mkdir($ws . '/elsewhere', 0755, true);
file_put_contents($ws . '/elsewhere/secret', 'x');

register_shutdown_function(function () use ($ws) {
    $rm = function ($p) use (&$rm) {
        if (is_link($p) || is_file($p)) { @unlink($p); return; }
        if (!is_dir($p)) return;
        foreach (scandir($p) as $n) if ($n !== '.' && $n !== '..') $rm($p . '/' . $n);
        @rmdir($p);
    };
    $rm($ws);
});

// ------------------------------------------------------------ the enumeration

echo "  -- the scope is a closed enumeration\n";

$names = fp()->names();
assert_same(['addons', 'templates', 'icons', 'scripts', 'labs', 'dependencies'], $names,
    'the enumeration is exactly the six scopes the call sites need');

$badScopes = [
    '', ' ', 'ADDONS', 'addons ', 'Addons', 'addon', 'all', '..',
    '/opt/unetlab/addons', '/etc', 'addons;labs', 'addons labs',
    null, false, true, 0, 1, 42, ['addons'], new stdClass(),
];
$rejected = 0;
foreach ($badScopes as $bad) {
    $r = fp()->run($bad);
    if (!$r['ok']) $rejected++;
    else echo "        ACCEPTED a bad scope: " . var_export($bad, true) . "\n";
}
assert_same(count($badScopes), $rejected,
    sprintf('refuses every scope outside the enumeration (%d of %d)', $rejected, count($badScopes)));

// A path is never accepted as a scope, which is the whole design.
$r = fp()->run($ws . '/elsewhere');
assert_true(!$r['ok'], 'a path is not a scope');
assert_true(strpos($r['error'], 'scope must be one of') === 0,
    'and the refusal says so rather than interpreting it');

// The web layer's copy of the list must not drift from the wrapper's.
assert_same($names, array_values(\App\Helpers\System\Wrapper::SCOPES),
    'the web-layer helper and the wrapper agree on the scope words');

// ------------------------------------------------------ every scope stays home

echo "  -- no scope reaches outside its own root\n";

$owned = [
    'addons'    => ['/opt/unetlab/addons'],
    'templates' => ['/opt/unetlab/html/templates'],
    'icons'     => ['/opt/unetlab/html/images/icons'],
    'scripts'   => ['/opt/unetlab/scripts'],
    'labs'      => ['/opt/unetlab/labs'],
    'dependencies' => ['/opt/unetlab/addons', '/opt/unetlab/html/templates',
                       '/opt/unetlab/html/images/icons', '/opt/unetlab/scripts'],
];
foreach ($owned as $scope => $allowed) {
    list($r, $paths) = planned(fp(), $scope);
    assert_true($r['ok'], "scope $scope succeeds");
    assert_true(count($paths) > 0, "scope $scope touches something");
    $stray = [];
    foreach ($paths as $p) {
        $inside = false;
        foreach ($allowed as $a) {
            if ($p === $ws . $a || strpos($p, $ws . $a . '/') === 0) $inside = true;
        }
        if (!$inside) $stray[] = $p;
    }
    assert_same([], $stray, "scope $scope touches nothing outside " . implode(', ', $allowed));
}

// 'dependencies' is the union of four scopes and nothing more.
list(, $union) = planned(fp(), 'dependencies');
$parts = [];
foreach (['addons', 'templates', 'icons', 'scripts'] as $s) {
    list(, $p) = planned(fp(), $s);
    $parts = array_merge($parts, $p);
}
sort($union); sort($parts);
assert_same($parts, $union, "'dependencies' is exactly its four component scopes");

// ------------------------------------------------------------- the argv shape

echo "  -- the argv is GNU chown with every load-bearing flag\n";

$f = fp();
$f->run('labs');
assert_same(1, count($f->commands), 'one chown invocation per root');
$argv = $f->commands[0];
assert_same('/bin/chown', $argv[0], 'the binary is the fixed path');
foreach (['-R', '-h', '-P', '--preserve-root', '-v'] as $flag) {
    assert_true(in_array($flag, $argv, true), "the argv carries $flag");
}
$dd = array_search('--', $argv, true);
assert_true($dd !== false && $dd === count($argv) - 3,
    "'--' immediately precedes the owner and the root, so neither can be read as an option");
assert_same($ws . '/opt/unetlab/labs', $argv[count($argv) - 1], 'the root is the last word');
assert_true(!in_array('-L', $argv, true) && !in_array('-H', $argv, true),
    'and nothing asks chown to follow links');

// ------------------------------------------------------------ planted symlinks

echo "  -- a symlink planted inside a tree is inert\n";

// A root-owned target that a follow would either chown (as root) or fail on
// (as anyone else) is deliberately NOT used: this test may run as root, and
// a regression would then modify /etc/shadow. The targets are inside the
// scratch tree, and the oracle is chown's own -v log of what it visited.
mkdir($ws . '/elsewhere/deep', 0755, true);
file_put_contents($ws . '/elsewhere/deep/secret', 's');
symlink($ws . '/elsewhere', $ws . '/opt/unetlab/addons/escape');
symlink($ws . '/elsewhere/secret', $ws . '/opt/unetlab/addons/qemu/secret');
symlink($ws . '/elsewhere/deep/secret', $ws . '/opt/unetlab/labs/shadow');

$live = fp_live();
if ($live === null) {
    echo "  SKIP  ext-posix is not available; the live chown run is not exercised\n";
} else {
    $r = $live->run('addons');
    assert_true($r['ok'], 'a live run over a tree with planted links succeeds'
        . ($r['ok'] ? '' : ': ' . $r['error']));
    assert_true(count($live->visited) > 0, 'chown reported the paths it visited');
    $bad = [];
    foreach ($live->visited as $p) {
        if (strpos($p, $ws . '/elsewhere') === 0) $bad[] = $p;
        if (strpos($p, '/escape/') !== false) $bad[] = $p;
    }
    assert_same([], $bad, 'nothing behind a planted link was visited');
    assert_true(in_array($ws . '/opt/unetlab/addons/qemu/linux/hda.qcow2', $live->visited, true),
        'while the real tree underneath it still was');
    assert_true(in_array($ws . '/opt/unetlab/addons/escape', $live->visited, true),
        'the link itself is visited (chown -h changes the link, not its target)');

    $r = $live->run('labs');
    assert_true($r['ok'], 'a link to a file outside the root does not fail the run');
    assert_true(!in_array($ws . '/elsewhere/deep/secret', $live->visited, true),
        'and its target is not visited');
}

unlink($ws . '/opt/unetlab/addons/escape');
unlink($ws . '/opt/unetlab/addons/qemu/secret');
unlink($ws . '/opt/unetlab/labs/shadow');

// A root that is itself a symlink is skipped, not followed.
rename($ws . '/opt/unetlab/scripts', $ws . '/opt/unetlab/scripts.real');
symlink($ws . '/elsewhere', $ws . '/opt/unetlab/scripts');
list($r, $paths) = planned(fp(), 'scripts');
assert_true($r['ok'], 'a scope whose root is a symlink still succeeds');
assert_same([], $paths, 'but touches nothing');
assert_same([$ws . '/opt/unetlab/scripts'], $r['skipped'], 'and reports the root as skipped');
unlink($ws . '/opt/unetlab/scripts');
rename($ws . '/opt/unetlab/scripts.real', $ws . '/opt/unetlab/scripts');

// A root that does not exist is skipped, not an error: a box without the IOL
// addons has no addons tree, and a download that otherwise succeeded must not
// be reported as failed.
$missing = new UnlFixPerms(['prefix' => $ws . '/nowhere', 'run_commands' => false]);
$r = $missing->run('labs');
assert_true($r['ok'], 'a missing root is not an error');
assert_same([$ws . '/nowhere/opt/unetlab/labs'], $r['skipped'], 'and is reported as skipped');

// ------------------------------------------------- the PHP walk is really gone

echo "  -- the tree walk is not PHP's\n";

$action = code_without_comments($root . '/platform/wrappers/actions/UnlFixPerms.php');
assert_true(strpos($action, 'scandir(') === false && strpos($action, 'opendir(') === false,
    'the action never enumerates a directory itself');
assert_true(preg_match('/\bchown\s*\(/', $action) === 0 && preg_match('/\bchgrp\s*\(/', $action) === 0,
    'and never calls PHP\'s dereferencing chown()/chgrp()');
assert_true(strpos($action, 'proc_open(') !== false, 'chown is exec\'d with an argv array');

// ------------------------------------------- the call sites are really rewritten

echo "  -- the old call sites are gone\n";

/** The file with every comment removed: a comment is not code. */
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

/** Every privileged shape this change had to remove. */
function sudo_offences($path)
{
    $code = code_without_comments($path);
    $found = [];
    foreach (['sudo chown', 'sudo chmod', 'sudo echo', 'sudo touch', 'sudo tee'] as $needle) {
        if (strpos($code, $needle) !== false) $found[] = $needle;
    }
    return $found;
}

// User/DependenceController.php and User/VersionsController.php were on this
// list too, until Phase 05 deleted them with the rest of the lab marketplace.
$rewritten = [
    '/store/app/Http/Controllers/Admin/SystemController.php',
    '/store/app/Http/Controllers/Admin/DefaultController.php',
    '/store/app/Helpers/Request/Query.php',
];
foreach ($rewritten as $rel) {
    assert_same([], sudo_offences($root . $rel), "no privileged file-shape left in $rel");
}

// The IOL licence write was a shell pipeline as well as a broken sudo. Neither
// half may come back.
$system = code_without_comments($root . '/store/app/Http/Controllers/Admin/SystemController.php');
assert_true(strpos($system, 'CiscoIOUKeygen') !== false, 'the IOL keygen is still run');
assert_true(strpos($system, '| grep') === false, 'but not through a pipeline');
assert_true(strpos($system, 'iourc') !== false, 'and iourc is still written');
assert_true(strpos($system, '> /opt/unetlab') === false, 'not by a shell redirect');
assert_true(strpos($system, 'proc_open') !== false, 'the keygen is exec\'d with an argv array');

// NEGATIVE CONTROL — the scanner must find all of it in the pre-change shapes.
$beforeFile = $ws . '/Before.php';
file_put_contents($beforeFile, "<?php\nclass B {\n"
    . "  public function download(){\n"
    . "    exec('sudo chown www-data:www-data -R /opt/unetlab/addons');\n"
    . "    exec('sudo chown www-data:www-data '. \$file);\n"
    . "  }\n"
    . "  public function fixPermission(){\n"
    . "    exec('sudo chmod 755 /opt/unetlab/addons/iol/bin/CiscoIOUKeygen.py');\n"
    . "    exec('license=\$(python /opt/unetlab/addons/iol/bin/CiscoIOUKeygen.py"
    . " | grep \"=\" | grep -v \"hostname\") && sudo echo -e \"[license]\" > /opt/unetlab/addons/iol/bin/iourc');\n"
    . "  }\n}\n");
assert_same(['sudo chown', 'sudo chmod', 'sudo echo'], sudo_offences($beforeFile),
    'the scanner does find every one of them in the pre-change shapes');
$beforeCode = code_without_comments($beforeFile);
assert_true(strpos($beforeCode, '| grep') !== false,
    'and the pipeline check does fire on the pre-change pipeline');
assert_true(strpos($beforeCode, '> /opt/unetlab') !== false,
    'and the redirect check does fire on the pre-change redirect');

// ------------------------------------------------- the sudo policy went with it

echo "  -- the policy lost the grants those call sites held\n";
$policy = file_get_contents($root . '/install/sudoers.d/pnetlab');
$granted = [];
foreach (explode("\n", $policy) as $line) {
    if (preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:\s*(\S+)/', $line, $m)) {
        $granted[] = basename($m[1]);
    }
}
foreach (['chown', 'chmod', 'touch', 'tee', 'echo'] as $gone) {
    assert_true(!in_array($gone, $granted, true), "the policy no longer grants $gone");
}
assert_true(in_array('unl_wrapper', $granted, true), 'and still grants the wrapper itself');

test_summary();
