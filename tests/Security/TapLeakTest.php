<?php
/**
 * The tap that a failed node start left behind.
 *
 * WHAT WAS THERE BEFORE, AND WHY IT GOT WORSE
 *
 * prepare() creates one tap per interface, and it creates them FIRST -- before
 * the linked clone, before .lock, before .prepared. Every later error return in
 * prepare(), and every failure in start() after it, therefore left one
 * vunl<session>_* per interface on the host. Neither stop nor delete removed
 * them, because device::stopNode() did all of its work -- tap teardown included
 * -- inside `if ($this->getStatus() != 0)`, and a node whose start failed
 * reports status 0. Stop was a no-op on exactly the node that needed it.
 *
 * That was a leak. It became a second bug when tenant accounts started being
 * reaped: UnlTenantAccount refuses to remove an account while a
 * vunl<session>_* interface exists, so one orphaned tap now pins one Unix
 * account permanently. The refusal is correct -- it is what stops a running
 * node losing its uid -- which is why the fix is upstream of it.
 *
 * These are the checks that do not need a host. The end-to-end proof, which
 * forces a real start to fail after the tap exists and then asserts the host is
 * clean, is the FAILED START section of tools/integration/lab-functional.sh.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/includes/cli.php';

// ------------------------------------------------------- the name is anchored

// 'vunl1_' is a prefix of 'vunl12_0'. The shell pipeline this replaced matched
// by prefix -- `ip link | grep 'vunl1_'` -- so stopping session 1 on a host
// running a dozen labs tore down session 12's data plane, silently, as part of
// an ordinary stop. Nothing in the suite would have caught it: the two sessions
// have to coexist for it to show.
$fake = sys_get_temp_dir() . '/tapleak-test-' . getmypid();
@mkdir($fake, 0755, true);
foreach ([
    'vunl1_0', 'vunl1_1', 'vunl1_10',       // session 1
    'vunl12_0', 'vunl120_3',                // NOT session 1
    'vunl1_', 'vunl1_x', 'xvunl1_0',        // malformed, or not ours
    'vnet1_1', 'lo', 'eth0', 'docker0',     // bridges and host interfaces
] as $n) {
    @mkdir($fake . '/' . $n, 0755);
}
register_shutdown_function(function () use ($fake) {
    foreach (@scandir($fake) ?: [] as $n) if ($n !== '.' && $n !== '..') @rmdir($fake . '/' . $n);
    @rmdir($fake);
});

assert_same(['vunl1_0', 'vunl1_1', 'vunl1_10'], unl_session_taps(1, $fake),
    'session 1 claims its own three taps');
assert_same(['vunl12_0'], unl_session_taps(12, $fake),
    'and not session 12\'s, which shares its prefix');
assert_same(['vunl120_3'], unl_session_taps(120, $fake),
    'nor session 120\'s');
assert_same([], unl_session_taps(9999, $fake),
    'a session with no taps gets an empty list, not an error');
assert_same([], unl_session_taps(1, $fake . '/does-not-exist'),
    'an unreadable directory is an empty list, not a warning');

// The session is cast, so a caller that passes '1; rm -rf /' or '1 OR 1=1'
// cannot widen the pattern. The name never reaches a shell either way --
// delTap() escapes it -- but this is the control that decides WHICH taps a
// stop touches, so it is checked here rather than assumed.
assert_same(['vunl1_0', 'vunl1_1', 'vunl1_10'], unl_session_taps('1', $fake),
    'a numeric string session works');
assert_same(['vunl1_0', 'vunl1_1', 'vunl1_10'], unl_session_taps('1 or 1=1', $fake),
    'and anything else is cast to an int rather than interpolated');

// ------------------------------------------- every failure in start() unwinds

// Comments stripped (tests/bootstrap.php): device.php spells out both halves of
// this leak, quoting the `return;` and the shell pipeline that caused them, and
// a plain read of the file would find the explanation and call it the bug.
$device = code_only($root . '/devices/device.php');

/** The body of one method, from its signature to the matching closing brace. */
function method_body($src, $name)
{
    $at = strpos($src, 'function ' . $name . '(');
    if ($at === false) return '';
    $open = strpos($src, '{', $at);
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) return substr($src, $open, $i - $open + 1); }
    }
    return '';
}

$start = method_body($device, 'start');
assert_true($start !== '', 'device::start() was found');

// THE ASSERTION THIS FILE EXISTS FOR. Every way out of start() that is not
// success has to go through abandonStart(). Listing the codes rather than
// counting returns, because a count passes when someone adds a seventh exit.
foreach (['$result', '80047', '80046', '$rcp'] as $code) {
    assert_true(strpos($start, 'abandonStart(' . $code . ')') !== false,
        "start() unwinds when it fails with $code");
}

// The two returns that are NOT failures. `return 0` is the node type with no
// emulator command line -- Docker, whose container is started by
// device_docker::start() after this returns -- and the final `return $rcp` is
// reached only once $rcp is known to be 0.
assert_true(preg_match('/if \(\$cmd === \'\'\) return 0;/', $start) === 1,
    'an empty command line is success, not a failure to unwind');
assert_true(strpos($start, 'return;') === false,
    'start() no longer returns NULL, which every caller read as success');

// secureCmd() throws rather than returning, so it needs a catch or the unwind
// is bypassed by a template option string carrying '..' or '&'.
assert_true(strpos($start, 'catch (Exception') !== false,
    'a throw out of secureCmd() unwinds too');

// The type check has to precede secureCmd(). device_qemu::command() returns
// array(False, False) when it cannot resolve the architecture or the binary,
// and secureCmd() calls preg_match() on it -- a TypeError on PHP 8, which took
// the whole request down before any of the above could run.
assert_true(strpos($start, 'is_string($cmd)') < strpos($start, 'secureCmd($cmd)'),
    'command() is type-checked before secureCmd() can fatal on an array');

// ------------------------------------------ and stop releases them regardless

$stop = method_body($device, 'stopNode');
assert_true($stop !== '', 'device::stopNode() was found');

$guard   = strpos($stop, 'if ($this->getStatus() != 0)');
$release = strpos($stop, '$this->releaseTaps();');
assert_true($guard !== false && $release !== false, 'stopNode has both a status guard and a release');
// Brace depth at the release: 1 means top level of the method, i.e. outside
// the guard. This is the half of the leak that makes it permanent -- a node
// that failed to start reports status 0, so a guarded teardown never runs for
// the one node that has a stranded tap.
$depth = 0;
for ($i = 0; $i < $release; $i++) {
    if ($stop[$i] === '{') $depth++;
    elseif ($stop[$i] === '}') $depth--;
}
assert_same(1, $depth, 'taps are released outside the getStatus() guard');

// The prefix-matching shell pipeline is gone, not merely moved.
assert_true(strpos($stop, 'ip link | grep') === false,
    'the prefix-matching `ip link | grep vunl<session>_` pipeline is gone');

// ----------------------------------------------- the unwind reaches both ends

$abandon = method_body($device, 'abandonStart');
assert_true($abandon !== '', 'device::abandonStart() exists');
assert_true(strpos($abandon, '$this->releaseTaps();') !== false,
    'abandonStart releases the taps');
assert_true(strpos($abandon, '$this->reapTenant();') !== false,
    'and reaps the tenant account the failed start manufactured');

// releaseTaps must enumerate from the host, not from the node's interface list.
// A start that died inside prepare()'s loop created a PREFIX of that list, and
// an interface removed from the lab after a start leaves a tap the list no
// longer mentions.
$rel = method_body($device, 'releaseTaps');
assert_true(strpos($rel, 'unl_session_taps(') !== false,
    'releaseTaps enumerates the host, not the node definition');
assert_true(strpos($rel, 'delTap(') !== false,
    'and goes through delTap, which verifies the interface actually went');

test_summary();
