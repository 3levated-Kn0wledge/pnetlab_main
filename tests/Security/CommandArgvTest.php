<?php
/**
 * unl_command_argv(): the tokeniser that lets device::spawnAsTenant() exec the
 * emulator directly instead of handing its command line to /bin/sh.
 *
 * WHY THIS IS THE INTERESTING TEST
 *
 * Four values reach the emulator command line unescaped, on purpose:
 * qemu_options, dynamips_options, iol_options and the per-interface flags
 * getFlag() concatenates. They are multi-argument by design -- a template's
 * `-machine type=pc,accel=kvm -vga std` has to arrive as four words -- so
 * escapeshellarg() would break every template that uses one. They are the last
 * entries in shell-escaping-baseline.txt for exactly that reason.
 *
 * Removing the shell is the fix that keeps the feature: word splitting is
 * preserved, and nothing downstream interprets the result. But that is only
 * true if the splitting really matches a shell's. A tokeniser that split
 * `-drive file='a b'` into two words would corrupt every escaped value
 * containing a space, and one that split it into one word incorrectly would
 * silently change what QEMU is asked to do.
 *
 * So the central assertion here is differential: for every line, this
 * tokeniser's argv is compared against what /bin/sh ACTUALLY produces for the
 * same line, obtained with `printf '%s\0'`. That is ground truth rather than
 * an opinion about shell grammar.
 *
 * WHAT IS ASSERTED
 *
 *   - argv matches /bin/sh's own splitting, over realistic and adversarial lines;
 *   - redirection is extracted rather than left in argv, `2>&1` included;
 *   - the grammar is exactly secure_line_parse()'s: everything SECURE_LINE
 *     refuses, this refuses too, so a line cannot pass the guard and then
 *     surprise the splitter;
 *   - a hostile option string adds ARGUMENTS and cannot add a command.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/..' . '/..');
require_once $root . '/includes/functions.php';

// ---------------------------------------------------------------- helpers

/** What /bin/sh really makes of a line's words. NUL-separated so a word may contain anything. */
function sh_words($line)
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['/bin/sh', '-c', "printf '%s\\0' " . $line], $desc, $pipes);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $words = explode("\0", $out);
    array_pop($words);           // trailing empty after the last NUL
    return $words;
}

function argv_of($line)
{
    $r = unl_command_argv($line);
    return $r['argv'];
}

function refuses($line)
{
    try {
        unl_command_argv($line);
        return false;
    } catch (Exception $e) {
        return true;
    }
}

// ------------------------------------------------- differential against /bin/sh

echo "  -- argv matches what /bin/sh actually does\n";

$lines = [
    // the shape a real QEMU node produces
    "'/opt/qemu/bin/qemu-system-x86_64' -machine type=pc,accel=kvm -vga std -usbdevice tablet -boot order=dc",
    // escapeshellarg output with a space inside, adjacent to unquoted text
    "'/bin/prog' -drive 'file=/opt/unetlab/tmp/1/2/hda.qcow2,if=ide'",
    "'/bin/prog' -name 'Node One'",
    // adjacent runs concatenate into ONE word -- the case that would corrupt values
    "'/bin/prog' file='a b'",
    "'/bin/prog' -append 'root=/dev/sda console=ttyS0'",
    // the escapeshellarg apostrophe splice
    "'/bin/prog' 'it'\\''s'",
    // double quotes, which SECURE_LINE permits when they expand nothing
    "'/bin/prog' \"plain text\"",
    // the option-string case: many bare words
    "'/bin/prog' -smbios type=1,product=asa5520 -icount 1 -rtc base=utc",
    // runs of whitespace
    "'/bin/prog'    -a     -b",
];

foreach ($lines as $line) {
    $mine = argv_of($line);
    $real = sh_words($line);
    assert_same($real, $mine, 'argv matches /bin/sh for: ' . $line);
}

// ------------------------------------------------------------- redirection

echo "  -- redirection is extracted, not left in argv\n";

$r = unl_command_argv("'/bin/prog' -a > '/opt/unetlab/tmp/1/2/wrapper.txt' 2>&1");
assert_same(['/bin/prog', '-a'], $r['argv'], 'the redirect is not an argument');
assert_same('/opt/unetlab/tmp/1/2/wrapper.txt', $r['stdout'], 'the target is captured');
assert_true($r['stderr_to_stdout'], '2>&1 is recognised');
assert_true(!$r['append'], 'a single > is truncate');

$r = unl_command_argv("'/bin/prog' >> '/tmp/log'");
assert_true($r['append'], '>> is append');
assert_same('/tmp/log', $r['stdout'], 'and still captures the target');

$r = unl_command_argv("'/bin/prog' -a");
assert_same(null, $r['stdout'], 'a line with no redirect reports none');
assert_true(!$r['stderr_to_stdout'], 'and no stderr join');

// `2>&1` is a word, not a fragment: inside one it is literal, as in a shell.
$r = unl_command_argv("'/bin/prog' 'x2>&1y'");
assert_same(['/bin/prog', 'x2>&1y'], $r['argv'], 'quoted 2>&1 stays literal');
assert_true(!$r['stderr_to_stdout'], 'and does not join the streams');

// ------------------------------------------ the grammar is secure_line_parse's

echo "  -- refuses everything SECURE_LINE refuses\n";

$hostile = [
    "'/bin/prog' ; touch /tmp/pwned",
    "'/bin/prog' | tee /tmp/pwned",
    "'/bin/prog' && touch /tmp/pwned",
    "'/bin/prog' \$(touch /tmp/pwned)",
    "'/bin/prog' `touch /tmp/pwned`",
    "'/bin/prog' \"\$(id)\"",
    "'/bin/prog' &",
    "'/bin/prog' -a\nid",
    "'/bin/prog' 'unterminated",
    "'/bin/prog' \"unterminated",
    "'/bin/prog' back\\slash",
];
foreach ($hostile as $line) {
    assert_true(refuses($line), 'refuses: ' . json_encode($line));
}

// And everything this accepts, secure_line_parse() accepts too: the two
// describe one grammar, so a line cannot pass the guard and then surprise the
// splitter, nor the reverse.
echo "  -- and accepts nothing SECURE_LINE would reject\n";
foreach ($lines as $line) {
    $guarded = true;
    try { secureCmd($line, SECURE_LINE); } catch (Exception $e) { $guarded = false; }
    assert_true($guarded, 'SECURE_LINE also accepts: ' . $line);
}
foreach ($hostile as $line) {
    $guarded = true;
    try { secureCmd($line, SECURE_LINE); } catch (Exception $e) { $guarded = false; }
    assert_true(!$guarded, 'SECURE_LINE also refuses: ' . json_encode($line));
}

// -------------------------------------- the option string: arguments, not commands

echo "  -- a hostile option string adds arguments and cannot add a command\n";

// This is what the feature is, and it still works: the operator's words arrive
// as separate arguments.
$r = unl_command_argv("'/bin/qemu' -machine type=pc,accel=kvm -vga std");
assert_same(['/bin/qemu', '-machine', 'type=pc,accel=kvm', '-vga', 'std'], $r['argv'],
    'a legitimate multi-argument option string still splits into its words');

// Redirection is the case that needed care, and it is worth being exact about
// where it is closed. SECURE_LINE PERMITS `>`, because the call sites build
// their own -- so an option string containing one passes the guard, and this
// tokeniser will faithfully report it. It is device::spawnAsTenant() that
// refuses, on two rules this exposes: at most one redirection, and a target
// inside the node's own running directory.
$r = unl_command_argv("'/bin/qemu' -vga std > /tmp/stolen");
assert_same('/tmp/stolen', $r['stdout'], 'a smuggled redirect is reported, not hidden');
assert_same(1, $r['redirects'], 'and counted');

$r = unl_command_argv("'/bin/qemu' > /tmp/stolen > '/opt/unetlab/tmp/1/2/wrapper.txt'");
assert_same(2, $r['redirects'],
    'two redirections are counted, so the caller can refuse the line');
assert_same('/opt/unetlab/tmp/1/2/wrapper.txt', $r['stdout'],
    'and only the last is reported -- a shell would open and TRUNCATE both');

// The caller's rule, asserted against the source so it cannot quietly go away.
$device = code_only($root . '/devices/device.php');
assert_true(strpos($device, "\$parsed['redirects'] > 1") !== false,
    'spawnAsTenant() refuses a line with more than one redirection');
assert_true(strpos($device, "strpos(\$parsed['stdout'], \$cwd") !== false,
    'and refuses a redirect target outside the node running directory');
assert_true(strpos($device, "pcntl_exec('/bin/sh'") === false,
    'and no longer execs a shell at all');

// The one thing it CAN still do, stated so nobody mistakes this for more than
// it is: add arguments the operator did not intend. That is the design
// decision the fork owes, and no tokeniser can answer it.
$r = unl_command_argv("'/bin/qemu' -vga std -drive file=/etc/hostname");
assert_same('-drive', $r['argv'][3], 'an option string can still add an argument -- by design');

// ------------------------------------------------------------------ misc

echo "  -- rejects nonsense rather than guessing\n";
assert_true(refuses(''), 'an empty line has no program');
assert_true(refuses('   '), 'whitespace alone has no program');
assert_true(refuses("> '/tmp/x'"), 'a redirect with no program is refused');

// Stricter than SECURE_LINE in one place, deliberately: the guard lets a bare
// trailing `>` through as a character it permits, and a splitter cannot act on
// a redirection with nothing to redirect to.
assert_true(refuses("'/bin/prog' >"), 'a redirection with no target is refused');
$guarded = true;
try { secureCmd("'/bin/prog' >", SECURE_LINE); } catch (Exception $e) { $guarded = false; }
assert_true($guarded, '...even though SECURE_LINE itself permits that line');

test_summary();
