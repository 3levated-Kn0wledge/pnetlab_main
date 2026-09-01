<?php
/**
 * Pins what secureCmd() actually does, which is less than its callers assume.
 *
 * includes/functions.php defines
 *
 *     function secureCmd($cmd){
 *         $re = '/[#;|&]|\.{2,}/m';
 *         if (preg_match($re, $cmd, $matches)) { ... throw ... }
 *         return $cmd;
 *     }
 *
 * and it is the ONLY filter on several paths that end in exec(). devices/device.php
 * runs `$cmd = secureCmd($this->command()) . " 2>&1 &"` before executing the whole
 * emulator command line; Admin/DefaultController.php builds a `sudo chown` from
 * secureCmd($req->input('template')). A reader seeing a function called secureCmd
 * on a line above exec() will reasonably assume the command was made safe.
 *
 * It was not. The regex is a four-character blocklist plus a traversal check. It
 * does not contain a backtick, a dollar, a newline, a redirect or a space, so
 * command substitution — the shape that turns "a node name" into "run this" —
 * passes straight through.
 *
 * THIS TEST DELIBERATELY DOES NOT CHANGE THE FUNCTION. Its whole job is to turn
 * an unstated assumption into a recorded one: the assertions below describe the
 * behaviour as it is, and each of the "passes through" cases is a live hole. If
 * secureCmd() is ever hardened, these assertions will fail, and that failure is
 * the signal to move the case from the second group to the first — not to relax
 * the test.
 *
 * Related: tests/Security/ShellEscapingTest.php carries
 * `includes/functions.php $cmd` in its baseline for exactly this reason — the
 * sweep can see that secureCmd() hands its argument back unescaped.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/includes/functions.php';

/**
 * The harness has no assert_throws(), and adding one to tests/bootstrap.php would
 * collide with work in flight elsewhere in tests/. Five lines here instead.
 */
function secure_cmd_rejects($input)
{
    try {
        secureCmd($input);
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

// secureCmd() prints the match array with print_r() before throwing. That output
// belongs to the function under test, not to this suite's report.
ob_start();

// ---------------------------------------------------------- what it does block

$blocked = [
    'a; id'          => 'a semicolon command separator',
    'a | id'         => 'a pipe',
    'a & id'         => 'a background separator',
    'a && id'        => 'a conditional separator (via &)',
    'a # comment'    => 'a comment introducer',
    '../etc/passwd'  => 'a parent-directory traversal',
    'cat /a/..%2f'   => 'a doubled dot anywhere in the string',
];
$blockedResults = [];
foreach ($blocked as $input => $_) $blockedResults[$input] = secure_cmd_rejects($input);

// ------------------------------------------------------- what it lets through

// Each of these is a working command injection against any call site that treats
// secureCmd() as its escape step. They are asserted to PASS, because they do.
$allowed = [
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
$allowedResults = [];
foreach ($allowed as $input => $_) $allowedResults[$input] = secure_cmd_rejects($input);

$identity = secureCmd('qemu-system-x86_64') === 'qemu-system-x86_64';

ob_end_clean();

foreach ($blocked as $input => $why) {
    assert_true($blockedResults[$input], "secureCmd() throws on $why");
}
foreach ($allowed as $input => $why) {
    assert_true(!$allowedResults[$input],
        "GAP: secureCmd() accepts $why — " . json_encode($input));
}

assert_true($identity, 'secureCmd() returns its input unchanged when it does not throw');

// The function name is the problem as much as the regex: it reads as an escape
// and is a blocklist. Record where it is currently relied upon, so that moving a
// call site off it is a visible, reviewable change rather than a silent one.
$callers = [];
foreach ([
    'includes/functions.php',
    'devices/device.php',
    'devices/docker/device_docker.php',
    'store/app/Http/Controllers/Admin/DefaultController.php',
] as $rel) {
    $src = @file_get_contents($root . '/' . $rel);
    if ($src === false) continue;
    // token_get_all so a commented-out call does not count as a call.
    foreach (token_get_all($src) as $i => $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'secureCmd') $callers[$rel] = true;
    }
}
assert_true(isset($callers['devices/device.php']),
    'devices/device.php still relies on secureCmd() before exec()');

test_summary();
