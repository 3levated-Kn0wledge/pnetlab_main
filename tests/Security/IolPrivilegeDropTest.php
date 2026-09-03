<?php
/**
 * The IOL node's in-process privilege drop is complete.
 *
 * IOL is the one node type that still drops privileges inside the wrapper's
 * own process rather than through device::spawnAsTenant(). Moving it onto the
 * fork-then-drop path is the real fix and is deferred, gated on a licensed IOL
 * image -- no IOL node has ever started on this platform, so nothing here would
 * catch a mistake in the start path itself (docs/HANDOVER.md,
 * docs/ROADMAP-STATUS.md, docs/inactive/PHASE-04-EXIT-FIXES.md).
 *
 * What this test guards is a DIFFERENT thing, which does not need an image: the
 * drop device_iol::prepare() already performs was incomplete. It read the uid
 * from the first line of `id -u` without validating it; it left root's
 * SUPPLEMENTARY groups in place, because device::prepare() sets only the
 * primary gid, so a compromised IOL process kept group 0; and it checked
 * neither posix_setgid() nor posix_setuid(), so a failed drop ran on toward
 * exec() still privileged.
 *
 * Source-level, in the house style (RootRoleTest, TenantDropTest): whether a
 * real IOL binary runs unprivileged is a property of a running host and a
 * licensed image, not of this source. What is asserted here is that the drop
 * the source performs computes and confirms the uid, clears the supplementary
 * groups, checks every step, and does them in the only order a one-way setuid
 * allows.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

echo "the IOL privilege drop\n";

$iol = code_only($root . '/devices/iol/device_iol.php');

// The drop lives in prepare(). Cut its body out so the assertions are about
// the drop, not about editParams() or command() elsewhere in the file.
$pos = strpos($iol, 'function prepare()');
assert_true($pos !== false, 'device_iol::prepare() exists');
$prep = substr($iol, $pos);
$next = strpos($prep, "\n    public function ", 10);
if ($next !== false) $prep = substr($prep, 0, $next);

// ---------------------------------------------------- the uid is not from `id`

assert_true(strpos($prep, 'id -u') === false,
    "the uid is not parsed out of `id -u` any more");
assert_true(strpos($prep, '32768 + (int) $this->getSession()') !== false,
    'it is computed as 32768 + session');
assert_true(strpos($prep, 'posix_getpwnam($user)') !== false,
    'and confirmed against the passwd database');
assert_true(preg_match('/\(int\)\s*\$entry\[.uid.\]\s*!==\s*\$expected/', $prep) === 1,
    'the account must actually hold that uid, or the node does not start');

// -------------------------------------------------- supplementary groups first

assert_true(strpos($prep, 'posix_initgroups($user, $gid)') !== false,
    "posix_initgroups() installs the tenant's group list and drops root's");
assert_true(strpos($prep, 'posix_setgroups([$gid])') !== false,
    'with posix_setgroups() as the fallback');

$pInit  = strpos($prep, 'posix_initgroups(');
$pSetGr = strpos($prep, 'posix_setgroups(');
$pGid   = strpos($prep, 'posix_setgid($gid)');
$pUid   = strpos($prep, 'posix_setuid($expected)');
assert_true($pInit !== false && $pInit < $pUid,
    'the supplementary groups are set BEFORE setuid() -- after it there is no way back');
assert_true($pSetGr !== false && $pSetGr < $pUid,
    'the fallback is before setuid() too');
assert_true($pGid !== false && $pGid < $pUid,
    'the primary gid is set before the uid');

// ------------------------------------------------------------ every step checked

assert_true(preg_match('/if\s*\(\s*!posix_setgid\(\$gid\)\s*\|\|\s*!posix_setuid\(\$expected\)\s*\)/', $prep) === 1,
    'setgid() and setuid() returns are both checked');
assert_true(preg_match('/posix_getuid\(\)\s*!==\s*\$expected\s*\|\|\s*posix_geteuid\(\)\s*!==\s*\$expected/', $prep) === 1,
    'and the real and effective uid are confirmed after the drop');
assert_true(strpos($prep, 'posix_getgroups()') !== false && strpos($prep, '$g === 0') !== false,
    'and no root supplementary group survives -- checked, not assumed');

// The verification comes after the drop, and the group check is part of it.
$pVerify = strpos($prep, 'posix_getuid()');
$pGroups = strpos($prep, 'posix_getgroups()');
assert_true($pVerify > $pUid, 'the uid is verified after setuid()');
assert_true($pGroups > $pUid, 'the groups are verified after the drop');

// Missing ext-posix is a refusal, not a silent continue as root.
assert_true(strpos($prep, "!function_exists('posix_getpwnam')") !== false,
    'a build without ext-posix refuses to start the node rather than running it as root');

// -------------------------------------------------- the drop is still in-process

// This test is NOT the deferred fix. IOL must still NOT claim the fork path,
// so that TenantDropTest's list stays honest and the day IOL moves, that is a
// deliberate change with an image behind it.
assert_true(preg_match('/function\s+runsAsTenant\s*\(/', $iol) !== 1,
    'device_iol does not (yet) claim device::spawnAsTenant(); that move is the deferred fix');

// ------------------------------------------------ the base prepare(), for context

// device::prepare() sets the wrapper's primary gid and used to ignore the
// result. It is logged now. It must NOT have become a start failure: the
// wrapper stays root there for the tenant-fork node types.
$dev = code_only($root . '/devices/device.php');
$basePos = strpos($dev, 'function prepare()');
$basePrep = substr($dev, $basePos, 400);
assert_true(strpos($basePrep, 'posix_setgid(32768)') !== false,
    'device::prepare() still sets the primary gid');
assert_true(strpos($basePrep, 'WARNING: posix_setgid') !== false,
    'and logs the failure it used to ignore');
assert_true(strpos($basePrep, 'return 80') === false,
    'without turning it into a start failure -- the wrapper stays root for the fork types');

test_summary();
