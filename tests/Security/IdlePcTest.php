<?php
/**
 * Exercises `unl_wrapper -a idlepc`, the action that deleted a root backdoor.
 *
 * WHAT WAS THERE BEFORE
 *
 * store/app/Http/Controllers/Admin/DefaultController.php::idlepc():
 *
 *     $option = secureCmd(get($p['dynamips_options'], ''));
 *     $cmd = 'sudo /opt/unetlab/html/store/app/Console/Commands/idlepc --option='
 *          . escapeshellarg($option) . ' -f ' . escapeshellarg($dynamipFolder . '/' . $ios);
 *     exec($cmd, $o, $r);
 *
 * The escaping was not the problem. The binary was: a 9.4 MB stripped
 * PyInstaller bundle, no source, no licence, which ran
 *
 *     ssh-keygen -t rsa -N '' -f /root/.ssh/id_rsa_dy
 *     cat /root/.ssh/id_rsa_dy.pub >> /root/.ssh/authorized_keys
 *
 * and SSHed to root@127.0.0.1 with the result, before computing anything.
 *
 * WHAT IS ASSERTED HERE
 *
 *   - every rejected input shape: traversal, absolute path, separator, a
 *     leading dash, a trailing newline, a repeated option, a missing template,
 *     a missing image, a symlinked template or image;
 *   - that the ROOT KEY IS GONE FROM THE TREE. That is the entire point of the
 *     change, so it is asserted against the source rather than inferred: no
 *     first-party file may reach ssh-keygen, authorized_keys, id_rsa_dy or a
 *     loopback SSH hop, and the blob and its sudo grant must both be absent;
 *   - that the template's option string is read by the ACTION and filtered
 *     through an allowlist, so no template can name a file a root process then
 *     opens;
 *   - that dynamips is invoked with an argv ARRAY and a bounded lifetime;
 *   - the clean, specific failure when no image is present, which is the only
 *     path this project can actually exercise. It carries no Cisco IOS image
 *     and never will, so the calibration itself is NOT tested here and must not
 *     be described as proven.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/platform/wrappers/actions/UnlIdlePc.php';

// ---------------------------------------------------------------- scaffolding

$ws = sys_get_temp_dir() . '/idlepc-test-' . getmypid();
$templates = $ws . '/templates';
$addons    = $ws . '/addons';
$tmp       = $ws . '/tmp';
foreach ([$ws, $templates, $addons, $tmp] as $d) @mkdir($d, 0755, true);

register_shutdown_function(function () use ($ws, $templates, $addons, $tmp) {
    foreach ([$templates, $addons, $tmp] as $d) {
        if (!is_dir($d)) continue;
        foreach (scandir($d) as $n) {
            if ($n === '.' || $n === '..') continue;
            @unlink($d . '/' . $n);
        }
        @rmdir($d);
    }
    @rmdir($ws);
});

/**
 * An action wired to the scaffolding, with run_commands off.
 *
 * run_commands off means the argv is RECORDED instead of executed. Everything
 * before the spawn is real: the validators, the path checks, the template read
 * and the option allowlist all run exactly as they do under sudo.
 */
function ip_action(array $extra = [])
{
    global $templates, $addons, $tmp;
    return new UnlIdlePc($extra + [
        'templates_root' => $templates,
        'addons_root'    => $addons,
        'tmp_root'       => $tmp,
        'dynamips'       => '/bin/sh',      // exists and is executable; never run
        'run_commands'   => false,
    ]);
}

function ip_template($name, $body)
{
    global $templates;
    file_put_contents($templates . '/' . $name . '.yml', $body);
}

function ip_image($name)
{
    global $addons;
    file_put_contents($addons . '/' . $name, "not an IOS image\n");
}

ip_template('c3725', "---\ntype: dynamips\nname: \"C3725\"\nidlepc: \"0x60bf8288\"\n"
    . "nvram: 128\nram: 256\nslot1:\n    value: \"\"\n    ram: 9999\n"
    . "dynamips_options: -P 3725 -o 4 -c 0x2102 -X --disk0 128 --disk1 128\n...\n");
ip_image('c3725-adventerprisek9-mz.124-15.T14.image');

// -------------------------------------------------------------- the two names

echo "  -- a template name is a slug, and a slug is not a path\n";

$goodTemplates = ['c3725', 'c7200', 'C1710', 'a', 'my-template_2.v3',
    str_repeat('x', 64)];
foreach ($goodTemplates as $t) {
    assert_same($t, UnlIdlePc::templateName($t), "accepts template name $t");
}

$badTemplates = [
    '', '.', '..', '../etc/passwd', 'a/b', '/etc/passwd', './c3725',
    'c3725/../../../etc/passwd', '-rf', '.hidden', '_lead', 'a b',
    "c3725\n", "c3725\n../etc/passwd", "c3725\0", 'c3725;id', 'c3725$(id)',
    'c3725`id`', "c3725'", 'c3725"', 'c3725|id', str_repeat('x', 65),
    null, false, true, 42, ['c3725'], new stdClass(),
];
foreach ($badTemplates as $t) {
    assert_same(null, UnlIdlePc::templateName($t),
        'refuses template name ' . var_export(is_object($t) ? 'stdClass' : $t, true));
}

echo "  -- an image name is a filename, and a filename is not a path either\n";

foreach (['c3725-adventerprisek9-mz.124-15.T14.image', 'x', str_repeat('i', 128)] as $i) {
    assert_same($i, UnlIdlePc::imageName($i), "accepts image name $i");
}
foreach (['', '.', '..', '/opt/unetlab/addons/dynamips/x.image', '../qemu/x',
          'a/b', "x.image\n", '-x', str_repeat('i', 129), null, ['x']] as $i) {
    assert_same(null, UnlIdlePc::imageName($i),
        'refuses image name ' . var_export($i, true));
}

// The traversal names are refused by the validator, so they never reach a path.
// Assert the outcome as well as the validator, because the outcome is what the
// wrapper actually returns.
foreach (['../../../etc/passwd', 'c3725/../../../etc/passwd', '/etc/passwd'] as $t) {
    $r = ip_action()->run($t, 'c3725-adventerprisek9-mz.124-15.T14.image');
    assert_true(!$r['ok'], "run() refuses the traversing template name $t");
}
foreach (['../../../etc/passwd', '/etc/shadow'] as $i) {
    $r = ip_action()->run('c3725', $i);
    assert_true(!$r['ok'], "run() refuses the traversing image name $i");
}

// ---------------------------------------------- the wrapper refuses repeats

echo "  -- a repeated option is refused, not resolved\n";

// getopt() returns an ARRAY the moment an option is repeated, and (int) on an
// array is 1. unl_single_option() turns that into null; these assert that null,
// and any other non-string, is refused rather than coerced.
$r = ip_action()->run(null, 'c3725-adventerprisek9-mz.124-15.T14.image');
assert_true(!$r['ok'], 'a repeated --template (null from unl_single_option) is refused');
$r = ip_action()->run(['c3725', 'c7200'], 'c3725-adventerprisek9-mz.124-15.T14.image');
assert_true(!$r['ok'], 'an array template name is refused');
$r = ip_action()->run('c3725', null);
assert_true(!$r['ok'], 'a repeated --image is refused');

$wrapper = (string) file_get_contents($root . '/platform/wrappers/unl_wrapper');
assert_true(strpos($wrapper, "unl_single_option(\$options, 'template')") !== false,
    'the wrapper takes --template through unl_single_option()');
assert_true(strpos($wrapper, "unl_single_option(\$options, 'image')") !== false,
    'the wrapper takes --image through unl_single_option()');
assert_true(strpos($wrapper, "'template:'") !== false && strpos($wrapper, "'image:'") !== false,
    'both long options are declared to getopt()');

// ------------------------------------------------- missing template or image

echo "  -- a missing template and a missing image each fail specifically\n";

$r = ip_action()->run('nosuchtemplate', 'c3725-adventerprisek9-mz.124-15.T14.image');
assert_true(!$r['ok'], 'a template that does not exist is refused');
assert_true(strpos((string) $r['error'], 'no template called nosuchtemplate') === 0,
    'and the error names the template, not "failed"');

// THE ONLY FAILURE THIS PROJECT CAN EXERCISE. It ships no Cisco IOS image, so
// this is the branch a maintainer running the suite will actually see.
$r = ip_action()->run('c3725', 'c7200-adventerprisek9-mz.124-24.T5.image');
assert_true(!$r['ok'], 'an image that is not installed is refused');
assert_true(strpos((string) $r['error'], 'no dynamips image called') === 0,
    'and the error names the image');
assert_true(strpos((string) $r['error'], 'this fork ships none') !== false,
    'and says why the appliance has none, rather than implying a bug');

echo "  -- a symlinked template or image is refused\n";

if (@symlink('/etc/passwd', $templates . '/linked.yml')) {
    $r = ip_action()->run('linked', 'c3725-adventerprisek9-mz.124-15.T14.image');
    assert_true(!$r['ok'], 'a template that is a symlink is refused');
    @unlink($templates . '/linked.yml');
}
if (@symlink('/etc/passwd', $addons . '/linked.image')) {
    $r = ip_action()->run('c3725', 'linked.image');
    assert_true(!$r['ok'], 'an image that is a symlink is refused');
    @unlink($addons . '/linked.image');
}

// ---------------------------------------------------------- the option string

echo "  -- the option string is read from the template, and filtered\n";

assert_same(['-P', '3725', '-o', '4', '-c', '0x2102', '-X', '--disk0', '128',
             '--disk1', '128'],
    UnlIdlePc::tokeniseOptions('-P 3725 -o 4 -c 0x2102 -X --disk0 128 --disk1 128'),
    'the shipped c3725 option string tokenises to the same argv');
assert_same([], UnlIdlePc::tokeniseOptions(''), 'an empty option string is an empty argv');
assert_same([], UnlIdlePc::tokeniseOptions(null), 'a missing option string is an empty argv');

// Every one of these is an option dynamips really has, and every one of them
// would hand a root process a path, a different console, or a defeated
// calibration. They are refused BY NAME, because a blocklist of metacharacters
// would pass all of them.
$refusedOptions = [
    '-l /root/.ssh/authorized_keys' => 'a log file path',
    '-C /root/.ssh/id_rsa'          => 'a startup-config path',
    '--private-config /etc/shadow'  => 'a private-config path',
    '-R /etc/passwd'                => 'an alternate ROM path',
    '-G /root/ghost'                => 'a ghost file',
    '-g /root/ghost'                => 'a ghost file to generate',
    '-b /etc/passwd'                => 'a bridge config file',
    '-E /etc/passwd'                => 'an ethernet switch config file',
    '-a /etc/passwd'                => 'an ATM switch config file',
    '-f /etc/passwd'                => 'a frame-relay config file',
    '--filepid /root/pid'           => 'a pid file path',
    '-T 4000'                       => 'a console port of its own',
    '-A 4001'                       => 'an AUX port',
    '-U /dev/ttyS0'                 => 'a serial console',
    '--noctrl'                      => 'the escape this action depends on, disabled',
    '--idle-pc 0x60bf8288'          => 'an idle PC, which makes calibration refuse',
    '-H 7200'                       => 'hypervisor mode',
    '-i 5'                          => 'an instance id',
    '-p 1:NM-4T'                    => 'a network module',
    '-s 1:0:udp:10000:127.0.0.1:10001' => 'a NIO binding',
    '-e'                            => 'a host device listing',
];
foreach ($refusedOptions as $opts => $what) {
    $error = null;
    assert_same(null, UnlIdlePc::tokeniseOptions($opts, $error),
        "dynamips_options may not carry $what");
}

// Values are checked too, so an allowed option name cannot smuggle one.
foreach (['-P ../../etc', '-P 3725;id', '-c notahexnumber', '--disk0 -1',
          '--disk0 /root', '-t $(id)', '-P', '--disk0'] as $opts) {
    assert_same(null, UnlIdlePc::tokeniseOptions($opts),
        'refuses the value in: ' . $opts);
}
assert_same(null, UnlIdlePc::tokeniseOptions(str_repeat('-X ', 200)),
    'refuses an option string longer than the cap');

echo "  -- the template reader reads three top-level scalars and nothing else\n";

$keys = UnlIdlePc::readTemplateKeys($templates . '/c3725.yml');
assert_same('-P 3725 -o 4 -c 0x2102 -X --disk0 128 --disk1 128',
    $keys['dynamips_options'], 'reads dynamips_options');
assert_same('256', $keys['ram'], 'reads the TOP-LEVEL ram, not the indented one in slot1');
assert_same('128', $keys['nvram'], 'reads nvram');
assert_true(!isset($keys['idlepc']) && !isset($keys['name']),
    'and reads nothing else out of the file');

// A duplicate top-level key is ambiguous. A YAML parser silently picks one;
// this refuses, because the one it picked would decide dynamips' argv.
ip_template('dupe', "ram: 256\nram: 512\ndynamips_options: -X\n");
assert_same(null, UnlIdlePc::readTemplateKeys($templates . '/dupe.yml', $e),
    'a template that defines ram twice is refused');
@unlink($templates . '/dupe.yml');

// If ext-yaml is available, hold the reader against it on the real templates.
// CI has no ext-yaml, which is exactly why the reader exists; where the
// extension IS present the two must not disagree.
if (function_exists('yaml_parse_file')) {
    $checked = 0;
    foreach (glob($root . '/templates/*.yml') as $file) {
        $parsed = @yaml_parse_file($file);
        if (!is_array($parsed) || !isset($parsed['dynamips_options'])) continue;
        $read = UnlIdlePc::readTemplateKeys($file);
        assert_same((string) $parsed['dynamips_options'],
            isset($read['dynamips_options']) ? $read['dynamips_options'] : null,
            'the reader agrees with yaml_parse_file on ' . basename($file));
        $checked++;
    }
    assert_true($checked > 0, 'at least one shipped dynamips template was cross-checked');
} else {
    echo "  note  ext-yaml is absent, so the reader could not be cross-checked here.\n";
}

// Every shipped dynamips template must survive the allowlist, or the button is
// broken for it. This is the assertion that would have caught an allowlist too
// narrow to run the product.
foreach (glob($root . '/templates/*.yml') as $file) {
    $read = UnlIdlePc::readTemplateKeys($file);
    if (!is_array($read) || !isset($read['dynamips_options'])) continue;
    $error = null;
    $argv = UnlIdlePc::tokeniseOptions($read['dynamips_options'], $error);
    assert_true(is_array($argv),
        'the shipped template ' . basename($file) . ' passes the option allowlist'
            . ($argv === null ? ' (' . $error . ')' : ''));
}

// ------------------------------------------------------------------- the argv

echo "  -- dynamips is invoked with an argv array, and told where its console is\n";

$action = ip_action();
$r = $action->run('c3725', 'c3725-adventerprisek9-mz.124-15.T14.image');
assert_true($r['ok'], 'a template and an image that both exist are accepted');
assert_same(1, count($action->commands), 'exactly one command would be run');

$argv = $action->commands[0];
assert_true(is_array($argv), 'and it is an ARRAY, so no shell parses it');
assert_same('/bin/sh', $argv[0], 'argv[0] is the configured dynamips binary');
assert_same('-T', $argv[1], 'the console is put on TCP');
assert_true(preg_match('/^[0-9]+\z/', $argv[2]) === 1, 'and the port is a number');
$port = (int) $argv[2];
assert_true($port >= UnlIdlePc::PORT_MIN && $port <= UnlIdlePc::PORT_MAX,
    'the console port is inside the dedicated range');
// 30000-40000 are node console ports (apiEditNodePort enforces it) and 40000+
// are their secondary ports; the ephemeral range ends at 60999.
assert_true($port > 60999, 'and clear of both the node ports and the ephemeral range');

assert_same($addons . '/c3725-adventerprisek9-mz.124-15.T14.image',
    $argv[count($argv) - 1], 'the image is the last argument, as an absolute path');
assert_true(in_array('-r', $argv, true) && in_array('256', $argv, true),
    'RAM comes from the template');
assert_true(in_array('-n', $argv, true) && in_array('128', $argv, true),
    'NVRAM comes from the template');
assert_true(!in_array('--idle-pc', $argv, true),
    'no --idle-pc: dynamips refuses to calibrate when one is already set');
assert_true(!in_array('-l', $argv, true),
    'no -l: the log file goes to the cwd this action owns');
$smuggled = [];
foreach ($argv as $a) if (preg_match('/\s/', $a)) $smuggled[] = $a;
assert_same([], $smuggled, 'no single argument carries whitespace, so none is two arguments');

assert_same('c3725', $r['template'], 'the accepted template name is echoed back');
assert_same('c3725-adventerprisek9-mz.124-15.T14.image', $r['image'],
    'and so is the accepted image name');

// ----------------------------------------------------------- the result parser

echo "  -- the parser reads dynamips' own sentence, and only that\n";

// Verbatim from stable/mips64.c:271 and stable/ppc32.c:234.
$real = "\nPlease wait while gathering statistics...\nDone. Suggested idling PC:\n"
      . "   0x60483ae4 (count=44)\n   0x604868a0 (count=21)\n"
      . "Restart the emulator with \"--idle-pc=0x60483ae4\" (for example)\n";
assert_same('0x60483ae4', UnlIdlePc::parseIdlePc($real),
    'parses the value out of the real output');
assert_same(null, UnlIdlePc::parseIdlePc(
    "\nYou already use an idle PC, using the calibration would give incorrect results.\n"),
    'returns nothing when dynamips refuses to calibrate');
foreach (['', 'idle-pc=0x60483ae4', '--idle-pc=0x60483ae4', 'nothing here',
          '"--idle-pc=notahexnumber"', null, ['x']] as $bad) {
    assert_same(null, UnlIdlePc::parseIdlePc($bad),
        'refuses ' . var_export($bad, true));
}

// ------------------------------------------------ bounded, and killed by pid

echo "  -- the emulator has a bounded lifetime and is killed by pid\n";

$source = (string) file_get_contents($root . '/platform/wrappers/actions/UnlIdlePc.php');

/**
 * The same source with comments dropped.
 *
 * The header quotes the blob's `pkill -9 -f` and its ssh-keygen line verbatim,
 * because a reader who does not know what was there cannot judge what replaced
 * it. Assertions about what the CODE does therefore run against the code, the
 * way ShellEscapingTest's tokenizer does — a comment can neither satisfy nor
 * trip one.
 */
function ip_code($path)
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

$code = ip_code($root . '/platform/wrappers/actions/UnlIdlePc.php');

assert_true(strpos($source, 'proc_open($argv, $desc, $pipes, $cwd)') !== false,
    'dynamips is started through proc_open() with an argv array');
assert_true(strpos($source, 'private function drive(array $argv') !== false,
    'and the argv is an array-TYPED parameter, which the sweep can prove is not a shell');
assert_true(preg_match('/\bshell_exec\b|\bpassthru\b|\bsystem\s*\(|`/', $code) !== 1,
    'the action reaches no shell at all');
assert_true(stripos($code, 'pkill') === false,
    'nothing is killed by command-line pattern, as the blob did');
assert_true(stripos($code, 'ssh') === false && stripos($code, 'authorized_keys') === false,
    'and no code in the action mentions SSH in any form');
assert_true(strpos($source, 'proc_terminate($proc, 15)') !== false
    && strpos($source, 'proc_terminate($proc, 9)') !== false,
    'the process is signalled TERM and then KILL, by the pid proc_open returned');
assert_true(strpos($source, 'register_shutdown_function') !== false
    && strpos($source, 'posix_kill') !== false,
    'a shutdown guard reaps it on the exits finally cannot cover');
assert_true(strpos($source, '} finally {') !== false,
    'and the ordinary paths go through finally');

foreach (['connectTimeout', 'bootTimeout', 'computeTimeout'] as $bound) {
    assert_true(substr_count($code, '$this->' . $bound) >= 2,
        "there is a $bound and it is read, not just stored");
}

// ------------------------------------- NO PATH CREATES A KEY. THE WHOLE POINT.

echo "  -- no code path in this tree can create an SSH key or touch authorized_keys\n";

/**
 * Walked over the whole first-party tree, not just the action.
 *
 * The finding was not "this file is bad", it was "this appliance grows a
 * passwordless root key when an admin presses a button". The assertion that
 * matches that finding is a property of the TREE, so that is what is asserted.
 *
 * COMMENT LINES ARE SKIPPED, the way SudoersPolicyTest skips them. Four files
 * quote the removed strings on purpose — the sudo policy, the controller, the
 * action, and tools/integration/wrapper-docker.sh, which records the same key
 * being deleted from docker_wrapper. A reader who does not know what was there
 * cannot judge what replaced it, so the prose stays and the assertion reads
 * code. store/vendor and node_modules are excluded because they are not
 * committed; docs/ and tests/ are prose by definition.
 */
$offenders = [];
$patterns = [
    'ssh-keygen'      => 'generates an SSH key pair',
    'authorized_keys' => 'writes an authorized_keys file',
    'id_rsa_dy'       => 'names the blob\'s key',
    'paramiko'        => 'reaches for an SSH client library',
];
$skipDirs = ['/.git', '/store/vendor', '/store/node_modules', '/node_modules',
             '/docs', '/tests'];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($cur) use ($root, $skipDirs) {
            $path = str_replace('\\', '/', $cur->getPathname());
            foreach ($skipDirs as $skip) {
                if (strpos($path, $root . $skip) === 0) return false;
            }
            if ($cur->isDir()) return true;
            return preg_match('/\.(php|sh|js|yml|yaml|conf|in|sql|c|h|py)\z/', $path) === 1
                || $path === $root . '/platform/wrappers/unl_wrapper'
                || $path === $root . '/install/sudoers.d/pnetlab';
        }
    )
);
foreach ($it as $file) {
    if ($file->isDir()) continue;
    $lines = @file($file->getPathname(), FILE_IGNORE_NEW_LINES);
    if ($lines === false) continue;
    foreach ($lines as $n => $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '#' || $t[0] === '*'
            || strpos($t, '//') === 0 || strpos($t, '/*') === 0) continue;
        foreach ($patterns as $needle => $why) {
            if (stripos($t, $needle) === false) continue;
            $offenders[] = str_replace($root . '/', '', $file->getPathname())
                . ':' . ($n + 1) . ' ' . $why;
        }
    }
}
sort($offenders);
assert_same([], $offenders,
    'no code in the tree generates an SSH key, writes authorized_keys, or speaks SSH');
foreach ($offenders as $o) echo "        $o\n";

echo "  -- the blob is gone, and the sudo grant with it\n";

assert_true(!file_exists($root . '/store/app/Console/Commands/idlepc'),
    'the PyInstaller blob is not in the tree');
$policy = (string) file_get_contents($root . '/install/sudoers.d/pnetlab');
assert_true(preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:.*Console\/Commands\/idlepc/m',
    $policy) !== 1, 'the sudo policy no longer grants the blob');
assert_true(strpos($policy, 'unl_wrapper -a idlepc') !== false,
    'and it names the action that replaced it');

// Comments stripped again, for the same reason: both files explain at length
// what they used to do, and quoting the old call site is the explanation.
$controller = ip_code($root . '/store/app/Http/Controllers/Admin/DefaultController.php');
assert_true(strpos($controller, 'Console/Commands/idlepc') === false,
    'the controller no longer execs the blob');
assert_true(strpos($controller, 'Wrapper::idlepc(') !== false,
    'the controller goes through the wrapper helper instead');
assert_true(strpos($controller, 'dynamips_options') === false,
    'and no longer sends the template\'s option string across the sudo boundary');
assert_true(strpos($controller, 'secureCmd') === false,
    'and no longer launders either name through the secureCmd blocklist');

$helper = ip_code($root . '/store/app/Helpers/System/Wrapper.php');
assert_true(strpos($helper, "'-a', 'idlepc'") !== false,
    'the helper asks for the enumerated action by name');
assert_true(strpos($helper, 'dynamips_options') === false,
    'and the option string is nowhere on the web side of the boundary');

test_summary();
