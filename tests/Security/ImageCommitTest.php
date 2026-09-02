<?php
/**
 * Exercises `unl_wrapper -a image-commit`, the action that replaced the largest
 * unquoted-path cluster in the tree.
 *
 * WHAT WAS THERE BEFORE
 *
 * Admin/Node_sessionsController::commitDevice() ran between four and fifteen
 * sudo commands per commit — qemu-img info, mkdir, cp, qemu-img rebase,
 * qemu-img commit, mv, rm -rf and chown -R — each built by concatenation, none
 * of them quoted, and the destination taken from request input:
 *
 *     $newName   = explode('-', $request->input('node_image'))[0] . '-' . $deviceName;
 *     $newFolder = '/opt/unetlab/addons/qemu/' . $newName;
 *     exec('sudo mkdir ' . $newFolder);
 *
 * old_commit_argv() below is that call site, used as a NEGATIVE CONTROL.
 *
 * The tests run against a fake qemu-img (a shell script that reports whatever
 * backing chain the fixture asks for), so the chain checks can be driven with
 * chains no real image would produce — which is the whole question, because the
 * qcow2 header that names a backing file lives in a mode-777 workspace and
 * `qemu-img commit` WRITES through that pointer.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/platform/wrappers/actions/UnlImageCommit.php';

// ---------------------------------------------------------------- scaffolding

$ws      = sys_get_temp_dir() . '/imgcommit-test-' . getmypid();
$addons  = $ws . '/opt/unetlab/addons/qemu';
$tmpRoot = $ws . '/opt/unetlab/tmp';
$bin     = $ws . '/bin';

mkdir($addons . '/linux-ubuntu', 0755, true);
mkdir($tmpRoot . '/7/42', 0777, true);
mkdir($bin, 0755, true);
file_put_contents($addons . '/linux-ubuntu/hda.qcow2', str_repeat('B', 4096));
file_put_contents($tmpRoot . '/7/42/hda.qcow2', str_repeat('D', 1024));
file_put_contents($tmpRoot . '/7/42/hda.qcow2.chain', 'image: ' . $addons . "/linux-ubuntu/hda.qcow2\n");

// A stand-in for qemu-img. `info --backing-chain` reports the file plus
// whatever <file>.chain says; convert copies; commit succeeds. It also records
// its own argv, so the test can prove nothing arrives as a shell string.
$fake = $bin . '/qemu-img';
file_put_contents($fake, "#!/bin/bash\n"
    . "printf '%s\\n' \"\$@\" >> \"\$(dirname \"\$0\")/argv.log\"\n"
    . "case \"\$1\" in\n"
    . "  info) echo \"image: \$3\"; [ -f \"\$3.chain\" ] && cat \"\$3.chain\"; exit 0 ;;\n"
    . "  convert) cp \"\$4\" \"\$5\"; exit 0 ;;\n"
    . "  commit) exit 0 ;;\n"
    . "esac\n"
    . "exit 1\n");
chmod($fake, 0755);

register_shutdown_function(function () use ($ws) {
    $rm = function ($p) use (&$rm) {
        if (is_link($p) || is_file($p)) { @unlink($p); return; }
        if (!is_dir($p)) return;
        foreach (scandir($p) as $n) if ($n !== '.' && $n !== '..') $rm($p . '/' . $n);
        @rmdir($p);
    };
    $rm($ws);
});

$rows = [
    42 => ['lab' => 7, 'type' => 'qemu'],
    43 => ['lab' => 7, 'type' => 'docker'],
    44 => ['lab' => 7, 'type' => 'qemu'],   // no workspace on disk
];

function ic(array $extra = [])
{
    global $rows, $addons, $tmpRoot, $fake;
    return new UnlImageCommit(array_merge([
        'addons_root' => $addons,
        'tmp_root'    => $tmpRoot,
        'qemu_img'    => $fake,
        'lookup'      => function ($s) use ($rows) { return isset($rows[$s]) ? $rows[$s] : null; },
    ], $extra));
}

/** Everything under the addons root, so "nothing was created" can be asserted. */
function tree($dir)
{
    $out = [];
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..') continue;
        $out[] = $n;
        if (is_dir($dir . '/' . $n)) foreach (scandir($dir . '/' . $n) as $c) {
            if ($c !== '.' && $c !== '..') $out[] = $n . '/' . $c;
        }
    }
    sort($out);
    return $out;
}

/** NEGATIVE CONTROL — the pre-change call site's command string. */
function old_commit_argv($nodeImage, $deviceName)
{
    $deviceName = preg_replace('/[^\w]/', '_', $deviceName);
    $imageQemu = explode('-', $nodeImage);
    $newFolder = '/opt/unetlab/addons/qemu/' . $imageQemu[0] . '-' . $deviceName;
    return ['/bin/sh', '-c', 'sudo mkdir ' . $newFolder];
}

$pristine = tree($addons);

// -------------------------------------------------------- the type enumeration

echo "  -- the type is a closed enumeration\n";
$badTypes = ['', 'delete', 'CHECK', 'check ', 'new;id', null, false, ['check'], 0, 'commit'];
$rejected = 0;
foreach ($badTypes as $bad) {
    $r = ic()->run(42, $bad, 'ok-name');
    if (!$r['ok']) $rejected++;
    else echo "        ACCEPTED a bad type: " . var_export($bad, true) . "\n";
}
assert_same(count($badTypes), $rejected,
    sprintf('rejects every type outside {check,existed,snapshot,new} (%d of %d)', $rejected, count($badTypes)));

// ------------------------------------------------------------ the session id

echo "  -- the session id is a bounded integer\n";
$badSessions = ['0', '-1', '', '42; id', '4 2', '../42', 'x', "42\n", '99999999999', null, ['42']];
$rejected = 0;
foreach ($badSessions as $bad) {
    $r = ic()->run($bad, 'check');
    if (!$r['ok']) $rejected++;
    else echo "        ACCEPTED a bad session: " . var_export($bad, true) . "\n";
}
assert_same(count($badSessions), $rejected,
    sprintf('rejects every malformed session id (%d of %d)', $rejected, count($badSessions)));

$r = ic()->run(43, 'check');
assert_true(!$r['ok'], 'refuses a node session that is not a QEMU node');
$r = ic()->run(99, 'check');
assert_true(!$r['ok'], 'refuses a node session that does not exist');
$r = ic()->run(44, 'check');
assert_true(!$r['ok'], 'refuses a node session with no workspace on disk');

// ------------------------------------------------------------------- the name

echo "  -- the name is a slug, never a path\n";
$badNames = ['', '.', '..', '../evil', 'a/b', '/etc/passwd', '-rf', '.hidden',
             'a b', 'x;id', 'x$(id)', 'x`id`', "ok\n", str_repeat('a', 65),
             'a/../../etc', null, ['ok'], 42];
$rejected = 0;
foreach ($badNames as $bad) {
    $r = ic()->run(42, 'snapshot', $bad);
    if (!$r['ok']) $rejected++;
    else echo "        ACCEPTED a bad name: " . var_export($bad, true) . "\n";
}
assert_same(count($badNames), $rejected,
    sprintf('rejects every name that is not a plain slug (%d of %d)', $rejected, count($badNames)));
assert_same($pristine, tree($addons), 'and created nothing while rejecting them');

// --------------------------------------------------------------- the workspace

echo "  -- refuses to act outside the roots it owns\n";
$rows[45] = ['lab' => 7, 'type' => 'qemu'];
mkdir($ws . '/elsewhere', 0777, true);
file_put_contents($ws . '/elsewhere/hda.qcow2', 'x');
symlink($ws . '/elsewhere', $tmpRoot . '/7/45');
$r = ic()->run(45, 'check');
assert_true(!$r['ok'], 'refuses a workspace that is a symlink to somewhere else');
unlink($tmpRoot . '/7/45');

// ------------------------------------------------- the backing chain is checked

echo "  -- the backing chain is validated before anything is written\n";

// The header that names a backing file lives in a mode-777 workspace. Point it
// at something outside the image roots and every type must refuse: `commit`
// would WRITE there, `convert` would read it into an image the web user can
// then download.
file_put_contents($tmpRoot . '/7/42/hda.qcow2.chain', "image: /etc/hostname\n");
foreach (['check', 'existed'] as $t) {
    $r = ic()->run(42, $t);
    assert_true(!$r['ok'], "refuses '$t' when the chain points outside the image roots");
}
$r = ic()->run(42, 'new', 'evil-name');
assert_true(!$r['ok'], "refuses 'new' when the chain points outside the image roots");
assert_same($pristine, tree($addons), 'and created no template folder while refusing');

// A chain longer than the cap is refused rather than walked.
$long = '';
for ($i = 0; $i < 40; $i++) $long .= 'image: ' . $addons . "/linux-ubuntu/hda.qcow2\n";
file_put_contents($tmpRoot . '/7/42/hda.qcow2.chain', $long);
$r = ic()->run(42, 'check');
assert_true(!$r['ok'], 'refuses a backing chain longer than the cap');

// Restore the honest chain.
file_put_contents($tmpRoot . '/7/42/hda.qcow2.chain', 'image: ' . $addons . "/linux-ubuntu/hda.qcow2\n");

// ------------------------------------------------------------------ happy paths

echo "  -- the happy paths\n";

$r = ic()->run('42', 'check');
assert_true($r['ok'], 'check succeeds');
assert_same(1024 + 4096, $r['size'], 'and reports the size of the whole chain');
assert_same(2, $r['files'], 'over both of its members');

$r = ic()->run(42, 'existed');
assert_true($r['ok'], 'existed commits the node disk down into its template');
assert_same($pristine, tree($addons), 'and creates no new template folder');

$r = ic()->run(42, 'snapshot', 'linux-mysnap');
assert_true($r['ok'], 'snapshot succeeds');
assert_same('linux-mysnap', $r['name'], 'and reports the name it created');
assert_true(is_file($addons . '/linux-mysnap/hda.qcow2'), 'the disk is in the new template folder');
assert_same(1024, filesize($addons . '/linux-mysnap/hda.qcow2'),
    'snapshot copies the delta, as the original did');

$r = ic()->run(42, 'snapshot', 'linux-mysnap');
assert_true(!$r['ok'], 'refuses a name that already exists');

$r = ic()->run(42, 'new', 'linux-flat');
assert_true($r['ok'], 'new succeeds');
assert_true(is_file($addons . '/linux-flat/hda.qcow2'), 'and produces a standalone image');

// The template it was cloned from must be untouched by any of that.
assert_same(4096, filesize($addons . '/linux-ubuntu/hda.qcow2'),
    'the original template is not modified by snapshot or new');

// ------------------------------------------------- no shell, anywhere

echo "  -- nothing is ever handed to a shell\n";

$argvLog = file($bin . '/argv.log', FILE_IGNORE_NEW_LINES);
assert_true(count($argvLog) > 0, 'the fake qemu-img was actually executed');
$joined = implode(' ', $argvLog);
assert_true(strpos($joined, '|') === false && strpos($joined, ';') === false,
    'no argument qemu-img received contained a shell metacharacter');
foreach ($argvLog as $arg) {
    assert_true(strpos($arg, ' ') === false || substr($arg, 0, 1) === '/',
        'each argument arrived whole: ' . $arg);
}

$k = ic(['run_commands' => false]);
$k->run(42, 'new', 'linux-recorded');
foreach ($k->commands as $c) {
    assert_true(is_array($c), 'every recorded operation is an argv array, not a string');
}

// ------------------------------------------- negative control: the old shape

echo "  -- negative control: what the same input did before\n";

$old = old_commit_argv('linux; touch /tmp/pwned -x', 'router');
assert_true($old[0] === '/bin/sh' && $old[1] === '-c',
    'the old call site handed a STRING to a root shell');
assert_true(strpos($old[2], 'touch /tmp/pwned') !== false,
    'and a node_image of "linux; touch /tmp/pwned -x" became a second root command');

$r = ic()->run(42, 'new', 'linux; touch /tmp/pwned -x');
assert_true(!$r['ok'], 'the same value is refused outright by the new action');
assert_true(!file_exists($addons . '/linux'), 'and nothing named after it exists');

// --------------------------------------------- the call sites are actually gone

echo "  -- the call sites are gone from the controller\n";

$controller = $root . '/store/app/Http/Controllers/Admin/Node_sessionsController.php';
$invoked = [];
foreach (file($controller) as $n => $line) {
    $t = ltrim($line);
    if ($t === '' || $t[0] === '#' || strpos($t, '//') === 0 || strpos($t, '*') === 0) continue;
    // The same expression SudoersPolicyTest uses, so the two agree on what
    // counts as an invocation.
    if (preg_match_all('/sudo\s+(?:-\S+\s+)*(\/[\w\/.-]+|[a-z_][\w.-]*)/', $line, $ms)) {
        foreach ($ms[1] as $b) $invoked[basename($b)] = true;
    }
}
ksort($invoked);
assert_same(['unl_wrapper'], array_keys($invoked),
    'the only binary the controller still reaches through sudo is unl_wrapper');

$policy = file_get_contents($root . '/install/sudoers.d/pnetlab');
foreach (['cp', 'mv', 'mkdir', 'link'] as $gone) {
    assert_true(!preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:\s*\S*\/' . $gone . '\s*(#.*)?$/m', $policy),
        "the sudo grant for $gone is gone from the policy");
}

test_summary();
