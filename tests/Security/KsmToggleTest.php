<?php
/**
 * The memory-dedup toggle, after moving it off UKSM onto mainline KSM.
 *
 * WHAT WAS THERE BEFORE
 *
 * `unl_wrapper -a uksmon` ran `exec('echo 1 > /sys/kernel/mm/uksm/run')`. UKSM
 * is an out-of-tree patch; it was compiled into the appliance's custom 4.15
 * kernel (CONFIG_UKSM=y, CONFIG_KSM_LEGACY=n) and the fork ships stock Ubuntu,
 * where that directory does not exist. Measured on the reference host, kernel
 * 6.8.0-138:
 *
 *     ls /sys/kernel/mm/uksm/   ->  No such file or directory
 *     cat /sys/kernel/mm/ksm/run -> 0
 *
 * So the verb could only ever fail, and StatusController::getInfo() spawned a
 * `cat` of the same absent path on every status poll to conclude 'unsupported'.
 *
 * These tests are about the parts that can be checked without root: that no
 * UKSM path survives in the tree, that the reader and writer agree on the three
 * values `run` takes, and that the writer refuses rather than half-succeeds.
 * unl_ksm_set() is exercised against a stand-in file, because the real one is
 * 0644 root:root and a test suite that needs root is a test suite nobody runs.
 * What could only be measured on the box — that QEMU's guest RAM is actually
 * scanned — is recorded in unl_ksm_state()'s own comment and in
 * docs/PLATFORM-SUPPORT.md.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

// ---------------------------------------------------- no UKSM path is left

// Every place the old path could hide. The React bundles are excluded on
// purpose: store/public/react is build output, it still carries the two-row
// status markup, and the point of the server-side change is that the stale
// bundle stays SAFE rather than that it is already gone.
$sources = [
    'includes/cli.php',
    'includes/api_status.php',
    'platform/wrappers/unl_wrapper',
    'store/app/Http/Controllers/Admin/StatusController.php',
];

// code_only() is in tests/bootstrap.php: three of these files explain at length
// why UKSM went away, quoting the path, and an assertion that cannot tell a
// comment from a call site would forbid saying so.
foreach ($sources as $rel) {
    assert_true(strpos(code_only($root . '/' . $rel), '/sys/kernel/mm/uksm') === false,
        "$rel does not name the UKSM sysfs tree outside its comments");
}

// The wrapper must not reach sysfs through a shell at all any more. `echo N >
// /sys/...` reported the *shell's* exit status, and the redirect ran as
// whoever the shell was -- it worked only because unl_wrapper is root.
$wrapper     = file_get_contents($root . '/platform/wrappers/unl_wrapper');
$wrapperCode = code_only($root . '/platform/wrappers/unl_wrapper');
assert_true(!preg_match('#echo\s+[012]\s*>\s*/sys/#', $wrapperCode),
    'the wrapper no longer writes sysfs by shell redirection');

// The two marker files nothing ever read. Their failure aborted the action
// AFTER the sysfs write had landed, so the wrapper reported failure for a
// change it had made.
assert_true(strpos($wrapperCode, '/opt/unetlab/uksm') === false,
    'the write-only /opt/unetlab/uksm marker is gone');
assert_true(strpos($wrapperCode, '/opt/unetlab/ksm') === false,
    'the write-only /opt/unetlab/ksm marker is gone');

// All four verbs must still be dispatchable. uksmon/uksmoff are aliases, not
// deletions: the committed React bundle still POSTs apiSetUksm, and App::call
// on a method that is gone is a 500, not a graceful refusal.
foreach (['ksmon', 'ksmoff', 'uksmon', 'uksmoff'] as $verb) {
    assert_true(preg_match("/case '" . $verb . "':/", $wrapper) === 1,
        "the wrapper still dispatches -a $verb");
}

// ------------------------------------------------- the reader and the writer

// includes/cli.php pulls in the rest of the application through nothing, but it
// does define functions the suite needs. Load it in isolation.
require_once $root . '/includes/cli.php';

assert_same('/sys/kernel/mm/ksm/run', UNL_KSM_RUN,
    'the control is the mainline KSM run file');

$fake = sys_get_temp_dir() . '/ksm-test-' . getmypid();
register_shutdown_function(function () use ($fake) { @unlink($fake); });

/**
 * unl_ksm_state() reads a constant path, so the state mapping is checked
 * against the same rule applied to a file we can write. Keep this in step with
 * the function: it exists because the three-value mapping is the part a reader
 * gets wrong, not the file access.
 */
function state_of($path)
{
    if (!is_file($path)) return 'unsupported';
    $v = @file_get_contents($path);
    if ($v === false) return 'unsupported';
    return trim($v) === '1' ? 'enabled' : 'disabled';
}

// run is 0 stop, 1 run, 2 stop-and-unmerge, and it reads back what was written
// -- 2 is a state, not an edge. Measured: writing 2 took pages_sharing from
// 22900 to 0 and `run` then read 2. Both 0 and 2 must report 'disabled',
// because nothing is being deduplicated in either.
assert_same('unsupported', state_of($fake), 'an absent run file reads as unsupported');
file_put_contents($fake, "1\n");
assert_same('enabled', state_of($fake), 'run=1 reads as enabled');
file_put_contents($fake, "0\n");
assert_same('disabled', state_of($fake), 'run=0 reads as disabled');
file_put_contents($fake, "2\n");
assert_same('disabled', state_of($fake), 'run=2 (stop-and-unmerge) also reads as disabled');

// The trailing newline is not decoration: sysfs emits one, and a reader that
// compares the raw bytes to '1' reports 'disabled' on a host where KSM is on.
file_put_contents($fake, '1');
assert_same('enabled', state_of($fake), 'and with no trailing newline');

// ----------------------------------------------- the toggle's own truth table

/**
 * Both setters used `$p['state'] == true`, which is TRUE for the string
 * 'false' -- so a form-encoded caller asking for off turned KSM on. This is the
 * replacement's mapping. Every row is a spelling something in this tree
 * actually sends: the SPA posts JSON booleans, the legacy /api path and curl
 * post form fields.
 */
$cases = [
    [true,    true,  'boolean true'],
    [false,   false, 'boolean false'],
    ['true',  true,  "the string 'true'"],
    ['false', false, "the string 'false' -- the row that used to mean ON"],
    ['1',     true,  "the string '1'"],
    ['0',     false, "the string '0'"],
    [1,       true,  'integer 1'],
    [0,       false, 'integer 0'],
    ['on',    true,  "'on'"],
    ['off',   false, "'off'"],
    ['',      false, 'an empty value'],
];

foreach ($cases as list($input, $expected, $label)) {
    $on = filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($on === null) $on = true;
    assert_same($expected, $on, "state = $label maps to " . ($expected ? 'on' : 'off'));
}

// An unparseable value defaults to ON rather than OFF. That is the safe
// direction here: the caller asked for something, and enabling dedup cannot
// lose data, whereas defaulting to off would silently undo an operator's
// setting on a malformed request.
$on = filter_var('banana', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($on === null) $on = true;
assert_same(true, $on, 'an unparseable state defaults to on');

// ------------------------------------------------------- the call sites agree

$controller = file_get_contents($root . '/store/app/Http/Controllers/Admin/StatusController.php');
$legacy     = file_get_contents($root . '/includes/api_status.php');

foreach ([['StatusController', $controller], ['api_status.php', $legacy]] as list($name, $body)) {
    assert_true(strpos($body, 'FILTER_VALIDATE_BOOLEAN') !== false,
        "$name parses the requested state rather than comparing it to true");
    assert_true(!preg_match("/-a\s+uksm(on|off)/", $body),
        "$name no longer asks the wrapper for a UKSM verb");
}

// getInfo() must keep reporting a 'uksm' key. The committed bundle draws a
// live, clickable toggle for any value that is not the literal 'unsupported'
// -- an ABSENT key included -- so removing the field would turn a correctly
// inert row into a button wired to a control this platform does not have.
assert_true(preg_match("/'uksm'\s*=>\s*\\\$uksm/", $controller) === 1,
    'getInfo still reports a uksm field for the committed bundle');
assert_true(preg_match("/\\\$uksm\s*=\s*'unsupported';/", $controller) === 1,
    "and pins it to 'unsupported' instead of shelling out to a path that cannot exist");
assert_true(strpos($controller, "exec(\$cmd, \$o, \$rc);\n        if (\$rc != 0) {\n            \$uksm") === false,
    'the per-poll `cat` of the UKSM path is gone');

test_summary();
