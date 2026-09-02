<?php
/**
 * Pins what secureCmd() permits — an inventory of what is allowed, not a list of
 * what leaks.
 *
 * WHAT THIS FILE USED TO SAY
 *
 * The previous revision documented a blocklist:
 *
 *     $re = '/[#;|&]|\.{2,}/m';
 *     if (preg_match($re, $cmd, $matches)) { ... throw ... }
 *     return $cmd;
 *
 * and asserted, one by one, the ten metacharacters it let through: backticks,
 * $( ), a newline, > and < redirects, a bare space, $HOME, quotes, globs and an
 * encoded newline. Each was labelled GAP and asserted to PASS, because it did.
 * That was deliberate — the point was to turn an unstated assumption into a
 * recorded one, and to make hardening the function fail this test loudly.
 *
 * It has been hardened. Every one of those ten is asserted to be REJECTED
 * below, in the same order, under the same descriptions, so the two revisions
 * of this file read against each other as a before and after.
 *
 * WHAT IT SAYS NOW
 *
 * secureCmd($value, $shape) is an allowlist, and every call site declares which
 * of three shapes it means. The shapes exist because the old function was asked
 * to judge `x86_64` and
 * `sudo unl_wrapper -a start -T 'x' -S '1' 2>> /path/log` with one regex, and
 * an answer that is right for a bare identifier is wrong for a command line.
 *
 *   SECURE_TOKEN  one shell word — includes/cli.php's interface, bridge and OVS
 *                 names, tenant ids, ports, usernames; the two ids in
 *                 addWiresharkSystem().
 *   SECURE_PATH   a path fragment off the request — apiDeleteFolder(),
 *                 apiEditFolder(), Admin/LabsController::getDepends().
 *   SECURE_LINE   a whole command line — the seven unl_wrapper invocations in
 *                 api_nodes.php, three in api_labs.php, devices/interfc.php,
 *                 device_docker.php, device_dynamips.php, and the emulator
 *                 command line in devices/device.php.
 *
 * IT IS STILL NOT THE CONTROL, AND THAT IS THE OTHER HALF OF THE CHANGE.
 *
 * SECURE_LINE proves a string cannot spawn a second command. It does NOT prove
 * the arguments are the intended ones: an unquoted space is still a word
 * separator, so a value interpolated raw can still become several arguments.
 * The control is escapeshellarg() at the interpolation, or proc_open() with an
 * argv array. The last section of this file asserts that the call sites which
 * were correct only because secureCmd() ran are no longer shaped that way.
 *
 * Related: tests/Security/ShellEscapingTest.php still carries
 * `includes/functions.php $cmd` in its baseline, and correctly — secureCmd()
 * hands its argument back unescaped whatever shape it validated.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/includes/functions.php';

/**
 * The harness has no assert_throws(), and adding one to tests/bootstrap.php
 * would collide with work in flight elsewhere in tests/. Five lines here.
 */
function secure_cmd_rejects($input, $shape)
{
    try {
        secureCmd($input, $shape);
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

function secure_cmd_accepts($input, $shape)
{
    return !secure_cmd_rejects($input, $shape);
}

// ------------------------------------- the ten that used to be labelled GAP

// Verbatim from the previous revision of this file, values and descriptions
// unchanged, with the sense of the assertion flipped. Each was a working
// command injection against any call site that treated secureCmd() as its
// escape step. A diff of this file against its parent is the change.
$wasAGap = [
    'x86_64 `id`'            => 'backtick command substitution',
    'x86_64 $(id)'           => 'a $( ) command substitution',
    "x86_64\nid"             => 'a newline, which starts a second command',
    'x86_64 > /etc/cron.d/x' => 'an output redirect',
    'x86_64 < /etc/shadow'   => 'an input redirect',
    'x86_64 --extra-flag'    => 'a bare space, so one argument becomes several',
    'x86_64 $HOME'           => 'a variable expansion',
    "x86_64 'quoted'"        => 'quote characters, which can close a quoted context',
    'x86_64 * ?'             => 'glob metacharacters',
    'x86_64 %0a id'          => 'an encoded newline the caller may decode later',
];
foreach ($wasAGap as $input => $why) {
    assert_true(secure_cmd_rejects($input, SECURE_TOKEN),
        "no longer a gap: SECURE_TOKEN rejects $why");
}

// The seven the old blocklist did catch. They are still caught, so nothing was
// traded away to gain the ten above.
$alwaysBlocked = [
    'a; id'          => 'a semicolon command separator',
    'a | id'         => 'a pipe',
    'a & id'         => 'a background separator',
    'a && id'        => 'a conditional separator (via &)',
    'a # comment'    => 'a comment introducer',
    '../etc/passwd'  => 'a parent-directory traversal',
    'cat /a/..%2f'   => 'a doubled dot anywhere in the string',
];
foreach ($alwaysBlocked as $input => $why) {
    assert_true(secure_cmd_rejects($input, SECURE_TOKEN),
        "SECURE_TOKEN still rejects $why");
}

// ------------------------------------------------ SECURE_TOKEN: what it allows

// One shell word. Every value below is one this application actually produces,
// so the inventory is a claim about the tree and not about the regex.
foreach ([
    'x86_64'              => 'a QEMU architecture',
    'qemu-system-x86_64'  => 'an emulator binary name',
    'vunl12_0'            => 'a tap name, as addTap() builds it',
    'vnet1_1'             => 'a bridge name, as addBridge() builds it',
    'pnet0'               => 'a cloud interface',
    'docker0'             => 'the docker bridge',
    'unl12'               => 'a tenant account name',
    '32769'               => 'a console port',
    '0'                   => 'a session id of zero',
    '2.12.0'              => 'a pinned QEMU version',
    'nat0'                => 'the NAT cloud',
] as $ok => $why) {
    assert_true(secure_cmd_accepts($ok, SECURE_TOKEN), "SECURE_TOKEN accepts $why: $ok");
}

// Ports and session ids arrive from accessors as ints, so an int is a token.
assert_same('32769', secureCmd(32769, SECURE_TOKEN), 'SECURE_TOKEN stringifies an int');

// And what it refuses, beyond the seventeen above.
foreach ([
    ''                => 'the empty string',
    '-rf'             => 'a value that would be read as an option',
    ' vnet1_1'        => 'a leading space',
    "vnet1_1\n"       => 'a trailing newline, which \z catches and $ would not',
    'vnet1_1 vnet1_2' => 'two words',
    'a/b'             => 'a slash, because a token is not a path',
    'a\\b'            => 'a backslash',
    'a~b'             => 'a tilde',
    'a!b'             => 'a bang',
    '$PATH'           => 'a bare variable reference',
] as $bad => $why) {
    assert_true(secure_cmd_rejects($bad, SECURE_TOKEN), "SECURE_TOKEN rejects $why");
}

// ------------------------------------------------- SECURE_PATH: what it allows

// Lab and folder paths are user-facing text, so this shape is wider than a
// token: spaces and non-ASCII letters are ordinary in a lab name, and no shell
// metacharacter lives above U+007F.
foreach ([
    '/'                                      => 'the root of the lab tree',
    '/My Labs'                               => 'a folder name with a space',
    '/Users/bob/lab 1.unl'                   => 'a lab file',
    '/opt/unetlab/addons/qemu/linux-cirros'  => 'an absolute image directory',
    '/Réseaux/Étude'                         => 'accented letters',
    '/实验室'                                 => 'a non-Latin script',
    '/a-b_c(1)[2]'                           => 'punctuation a user will type',
    ''                                       => 'the empty path, which callers test for themselves',
] as $ok => $why) {
    assert_true(secure_cmd_accepts($ok, SECURE_PATH), "SECURE_PATH accepts $why");
}

foreach ([
    '/labs/../../etc/passwd' => 'a traversal',
    '/labs/..'               => 'a bare parent segment',
    '/labs/$(id)'            => 'command substitution',
    '/labs/`id`'             => 'a backtick',
    "/labs/a\nb"             => 'a newline',
    '/labs/a;id'             => 'a separator',
    '/labs/a&b'              => 'an ampersand',
    '/labs/*'                => 'a glob',
    '/labs/a|b'              => 'a pipe',
    '/labs/a>b'              => 'a redirect',
    "/labs/a'b"              => 'an apostrophe, which would close a quoted context',
    '/labs/a"b'              => 'a double quote',
    "/labs/\xff\xfe"         => 'bytes that are not valid UTF-8',
] as $bad => $why) {
    assert_true(secure_cmd_rejects($bad, SECURE_PATH), "SECURE_PATH rejects $why");
}

// ------------------------------------------------- SECURE_LINE: what it allows

// Every string below is a command line this tree actually builds. If one of
// these starts failing, a node type or an API route has stopped working, so
// they are compatibility assertions as much as security ones.
$realLines = [
    "sudo /opt/unetlab/wrappers/unl_wrapper -a start -T '0' -S '1' -D '1'"
        . " -F '/Users/bob/lab 1.unl' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt"
        => 'api_nodes.php: an unl_wrapper invocation with a 2>> redirect',
    "sudo tc qdisc del dev 'vunl1_0' root"
        => 'devices/interfc.php: unApplyQuality()',
    'docker -H=unix:///var/run/docker.sock inspect --format="{{ .State.Running }}" \'docker1\''
        => 'device_docker.php: a Go template inside double quotes',
    "/opt/unetlab/scripts/wrconf_dyn.py -p '32769' -t 30"
        => 'device_dynamips.php: export()',
    "zip -r '/tmp/x.zip' './My Labs'"
        => 'api_labs.php: a folder name with a space, escaped',
    "/opt/qemu/bin/qemu-system-x86_64 -machine type=pc,accel=kvm -cpu host"
        . " -smbios type=1,manufacturer=\"Cisco Systems\" -serial mon:stdio -nographic"
        . " -hda 'virtioa.qcow2' > '/opt/unetlab/tmp/0/abc/1/wrapper.txt'"
        => 'devices/device.php: a QEMU command line, template options included',
    "dynamips -T '32769' -P 3725 -o 4 -c 0x2102 -X --disk0 128 -l dynamips.txt"
        . " -N 'R1' > '/opt/unetlab/tmp/0/abc/1/wrapper.txt'"
        => 'devices/device.php: a dynamips command line',
    "vpcs -m '1' -N 'PC1' -d 'vunl1_0' -F -R 2>&1"
        => 'a 2>&1, the one place & is permitted',
    "cp 'it'\\''s.qcow2' '/tmp/x'"
        => "escapeshellarg()'s '\\'' joiner for an embedded apostrophe",
];
foreach ($realLines as $line => $why) {
    assert_true(secure_cmd_accepts($line, SECURE_LINE), "SECURE_LINE accepts $why");
}

// Every shipped template's option string has to survive the shape, or that node
// type cannot start. Asserted against the tree rather than against a sample.
$optionStrings = 0;
$optionRejects = [];
foreach (glob($root . '/templates/*.yml') as $tpl) {
    $lines = file($tpl, FILE_IGNORE_NEW_LINES);
    for ($i = 0; $i < count($lines); $i++) {
        if (!preg_match('/^(?:qemu|dynamips|docker|iol)_options:\s*(.*)$/', $lines[$i], $m)) continue;
        // A YAML plain scalar folds across indented continuation lines, and two
        // templates use that: xrv9k.yml and vwaas.yml both open a double quote
        // on the first line and close it on the second. Reading line by line
        // hands the parser an unterminated quote that the real value does not
        // have, which would be a bug in this test rather than in the template.
        $opts = $m[1];
        for ($j = $i + 1; $j < count($lines); $j++) {
            if (!preg_match('/^\s+\S/', $lines[$j])) break;
            $opts .= ' ' . trim($lines[$j]);
            $i = $j;
        }
        // Strip YAML's OWN quoting only — a matching leading/trailing pair. The
        // first version of this used trim($opts, " \t'\""), which also ate the
        // closing quote of values that legitimately end in one:
        // vwaas.yml's product="KVM", nokia16.yml's product='TIMOS:...' and
        // macos_simple_kvm.yml's osk="...". All three then looked like
        // unterminated quotes and were reported as templates this shape breaks.
        // They are not; the reader was.
        $opts = trim($opts);
        if (strlen($opts) > 1 && ($opts[0] === '"' || $opts[0] === "'")
            && substr($opts, -1) === $opts[0]) {
            $opts = substr($opts, 1, -1);
        }
        if ($opts === '') continue;
        $optionStrings++;
        $probe = "/opt/qemu/bin/qemu-system-x86_64 " . $opts . " > '/tmp/wrapper.txt'";
        if (secure_cmd_rejects($probe, SECURE_LINE)) $optionRejects[] = basename($tpl) . ': ' . $opts;
    }
}
assert_true($optionStrings > 50, "read the shipped option strings ($optionStrings of them)");
assert_same([], $optionRejects, 'SECURE_LINE accepts every shipped template option string');
foreach ($optionRejects as $r) echo "        template rejected: $r\n";

// ------------------------------------------------ SECURE_LINE: what it refuses

foreach ([
    'unl_wrapper -a start; id'        => 'a command separator',
    'unl_wrapper -a start && id'      => 'a conditional',
    'unl_wrapper -a start | id'       => 'a pipe',
    'unl_wrapper -a start &'          => 'a bare backgrounding &',
    'unl_wrapper -a $(id)'            => 'command substitution',
    'unl_wrapper -a `id`'             => 'a backtick',
    "unl_wrapper -a start\nid"        => 'a newline',
    'unl_wrapper -a "$(id)"'          => 'expansion inside double quotes',
    'unl_wrapper -a "`id`"'           => 'a backtick inside double quotes',
    'unl_wrapper -a $HOME'            => 'a variable expansion',
    'unl_wrapper -a ~/x'              => 'tilde expansion',
    'unl_wrapper -a *'                => 'an unquoted glob',
    'unl_wrapper -a x?'               => 'an unquoted single-character glob',
    'unl_wrapper -a {a,b}'            => 'brace expansion',
    'unl_wrapper -a [ab]'             => 'an unquoted bracket glob',
    'unl_wrapper -a x # comment'      => 'a comment introducer',
    "unl_wrapper -a 'unterminated"    => 'an unterminated single quote',
    'unl_wrapper -a "unterminated'    => 'an unterminated double quote',
    'unl_wrapper -a x\\y'             => 'a backslash that is not the escapeshellarg joiner',
    "unl_wrapper -a x\x00y"           => 'a NUL byte',
    "unl_wrapper -a x\ry"             => 'a carriage return',
] as $bad => $why) {
    assert_true(secure_cmd_rejects($bad, SECURE_LINE), "SECURE_LINE rejects $why");
}

// The one thing SECURE_LINE deliberately does NOT stop, stated as an assertion
// so it cannot be misread as an oversight. A raw value carrying a space still
// becomes several arguments; that is argument injection, it is what the
// sweep-exempt markers on qemu_options/docker_options/iol_options describe, and
// escapeshellarg() at the interpolation is what fixes it.
assert_true(secure_cmd_accepts('qemu-system-x86_64 -drive file=/etc/shadow', SECURE_LINE),
    'SECURE_LINE does not claim to stop argument injection — only command injection');

// ---------------------------------------------------- non-string input

// device_qemu::command() returns array(False, False) when it cannot resolve an
// architecture. The old body handed that to preg_match(), which is a TypeError
// on PHP 8 — reached BEFORE any caller's emptiness check, so a QEMU node with an
// unresolvable template took the request down with a fatal and left its taps
// behind. Refusing by name is deliberate; it must never fatal.
foreach ([
    [array(False, False), 'the array(False, False) that command() returns on failure'],
    [null,               'null'],
    [false,              'false'],
    [1.5,                'a float'],
    [new stdClass(),     'an object'],
] as [$value, $why]) {
    foreach ([SECURE_TOKEN, SECURE_PATH, SECURE_LINE] as $shape) {
        $threw = false;
        try { secureCmd($value, $shape); } catch (Throwable $e) { $threw = $e instanceof Exception; }
        assert_true($threw, "refuses $why with an Exception, not a TypeError ($shape)");
    }
}

// An unknown shape is a refusal, not a pass, and the default is the strictest
// shape so a call site added without declaring one fails closed.
assert_true(secure_cmd_rejects('anything at all', 'no-such-shape'),
    'an unrecognised shape is refused');
assert_true(secure_cmd_rejects('a b', null),
    'the default shape is SECURE_TOKEN, so an undeclared call site fails closed');

// The identity property the callers depend on: it returns, it does not rewrite.
assert_same('qemu-system-x86_64', secureCmd('qemu-system-x86_64', SECURE_TOKEN),
    'secureCmd() returns its input unchanged when it does not throw');
assert_same("sudo x -F 'a b'", secureCmd("sudo x -F 'a b'", SECURE_LINE),
    'and does not rewrite a command line either');

// ------------------------------------------ every call site declares a shape

// The shape argument is the substantive half of this change, so no call site is
// allowed to omit it. token_get_all() rather than a grep, so a commented-out
// call does not count and a call inside a string does not either.
$undeclared = [];
$declared = 0;
$skip = ['/.git/', '/.claude/', '/store/vendor/', '/store/node_modules/', '/tests/'];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $f->getPathname());
    foreach ($skip as $s) if (strpos($path, $s) !== false) continue 2;
    $rel = substr($path, strlen($root) + 1);
    $tokens = array_values(array_filter(token_get_all(file_get_contents($path)), function ($t) {
        return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }));
    foreach ($tokens as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'secureCmd') continue;
        if (!isset($tokens[$i + 1]) || $tokens[$i + 1] !== '(') continue;
        // Skip the definition itself.
        if ($i > 0 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_FUNCTION) continue;
        $declared++;
        // Walk to the matching ')' at depth 0 and look for a top-level comma.
        $depth = 0; $hasComma = false;
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $tk = $tokens[$j];
            if ($tk === '(' || $tk === '[') { $depth++; continue; }
            if ($tk === ')' || $tk === ']') { $depth--; if ($depth === 0) break; continue; }
            if ($tk === ',' && $depth === 1) $hasComma = true;
        }
        if (!$hasComma) $undeclared[] = $rel . ' line ' . $t[2];
    }
}
assert_true($declared > 25, "found the call sites ($declared of them)");
assert_same([], $undeclared, 'every secureCmd() call declares which shape it means');
foreach ($undeclared as $u) echo "        no shape declared: $u\n";

// --------------------------- the call sites that LOOKED held up by secureCmd()

// Two call sites built a shell command with a request-derived value and no
// escaping, with secureCmd() the only thing between. Both are argv arrays now,
// asserted against the source so reinstating either is a visible change.
//
// Only ONE of them was actually held up by secureCmd(), and the difference was
// measured rather than reasoned about, against the parent commit on the
// reference host:
//
//   api_folders.php was NOT. checkFolder() in devices/functions.php is
//   preg_match('/^\/[\/A-Za-z0-9_\s-]*$/', $s), an allowlist over the whole
//   path, and it runs before the exec. A folder named `x$(touch proof)y` CAN be
//   created — apiAddFolder() validated nothing — and deleting it is then refused
//   with 60009, nothing executed. The undocumented allowlist was the control and
//   nobody had written that down. The half that WAS open is apiAddFolder(),
//   which is why it is validated now too.
//
//   Admin/LabsController::getDepends() WAS. `sudo qemu-img info --backing-chain
//   <path> | grep image` with the path built from an uploaded lab's `image`
//   attribute and a scandir() filename, no checkFolder() anywhere near it, and
//   secureCmd()'s blocklist carrying neither a dollar nor a backtick. It needs a
//   crafted name on disk under /opt/unetlab/addons/qemu and the root role, so it
//   is not a drive-by; it is still a sudo-prefixed shell command whose only
//   filter did not filter.
/**
 * Source with comments removed. Each fix below is DESCRIBED in a comment at its
 * call site, so a plain strpos() over the file would match the prose recording
 * what was removed and report the bug as still present.
 */
function secure_cmd_code($path)
{
    $out = '';
    foreach (token_get_all(file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

$folders = secure_cmd_code($root . '/includes/api_folders.php');
// The route that was genuinely unvalidated, and is the one that let a folder
// whose NAME is a command substitution onto the disk in the first place.
assert_true(preg_match('/function apiAddFolder.*?secureCmd\(\$name, SECURE_PATH\)/s', $folders) === 1,
    'apiAddFolder() validates the name it is given, which it never did before');
assert_true(strpos($folders, "rm -rf \"") === false,
    'apiDeleteFolder() no longer interpolates a request path into `rm -rf "..."`');
assert_true(strpos($folders, "mv \"") === false,
    'apiEditFolder() no longer interpolates two request paths into `mv "..." "..."`');
assert_true(substr_count($folders, 'unl_exec_argv(') === 2,
    'both are argv arrays, which reach no shell');

$labsCtl = secure_cmd_code($root . '/store/app/Http/Controllers/Admin/LabsController.php');
assert_true(strpos($labsCtl, 'qemu-img info --backing-chain') === false,
    'getDepends() no longer interpolates an image path into a sudo qemu-img command');
assert_true(strpos($labsCtl, "'qemu-img', 'info', '--backing-chain', '--', \$file") !== false,
    'it execs qemu-img through an argv array instead');
assert_true(strpos($labsCtl, 'sudo') === false,
    'and without sudo, because reading a qcow2 header needs no privilege');

// checkFolder() is what actually held the folder routes, so it is asserted here
// rather than left as a comment. If this allowlist is ever widened, the note
// above stops being true and these fail.
require_once $root . '/devices/functions.php';
assert_same(0, checkFolder('/'), 'checkFolder() accepts the lab root');
assert_same(1, checkFolder('/My Labs'), 'and a folder name with a space, which is why \\s was there');
foreach (['/x$(id)y', '/x`id`y', '/x;idy', '/x|idy', '/x>y', '/x&y', "/x'y", '/x"y',
          '/x*y', '/x~y', '/x!y', '/x#y'] as $bad) {
    assert_same(2, checkFolder($bad), 'checkFolder() refuses ' . json_encode($bad));
}

// The two characters that WERE wrong in all four validators in
// devices/functions.php. \s is [ \t\n\r\f\v], not a space, so a newline
// passed; and PCRE's $ matches before a trailing newline, so a name ending in
// one passed as well. Both were found by asserting the class rather than
// reading it, which is why they are pinned here.
foreach (["/x\ny", "/x\ty", "/x\ry", "/xy\n"] as $ws) {
    assert_same(2, checkFolder($ws),
        'checkFolder() refuses whitespace that is not a space: ' . json_encode($ws));
}
assert_true(!checkLabName("MyLab\n"), 'checkLabName() anchors with \\z, so a trailing newline fails');
assert_true(!checkLabFilename("a\nb.unl"), 'and checkLabFilename() the same');
assert_true(checkLabName('My Lab'), 'a space is still a legal lab name');
assert_true(checkLabFilename('My Lab.unl'), 'and still a legal lab filename');

$policy = file_get_contents($root . '/install/sudoers.d/pnetlab');
assert_true(!preg_match('/^www-data.*qemu-img/m', $policy),
    'so the qemu-img sudo grant is retired with it');

// devices/device.php is still the highest-value caller and still relies on the
// function before exec(). That has not changed and should not: what changed is
// what the function proves.
$device = secure_cmd_code($root . '/devices/device.php');
assert_true(strpos($device, 'secureCmd($cmd, SECURE_LINE)') !== false,
    'devices/device.php validates the emulator command line as a whole line');
assert_true(strpos($device, 'is_string($cmd)') < strpos($device, 'secureCmd($cmd'),
    'and still type-checks command() first, so array(False,False) cannot fatal');

test_summary();
