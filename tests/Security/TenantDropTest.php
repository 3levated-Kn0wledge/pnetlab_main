<?php
/**
 * Guards the drop from root to the tenant account on the node start path.
 *
 * WHAT WAS THERE BEFORE
 *
 * device::prepare() called posix_setsid() and posix_setgid(32768), and exactly
 * one device type — IOL — went further:
 *
 *     $cmd = 'id -u ' . escapeshellarg($user) . ' 2>&1';
 *     exec($cmd, $o, $rc);
 *     $uid = $o[0];
 *     if (!posix_setuid($uid)) { ... }
 *
 * Two things about that are worth keeping in view, because this test exists to
 * stop either coming back.
 *
 * FIRST, the uid came out of `id -u`, parsed from the first line of a command's
 * output. device::spawnAsTenant() COMPUTES 32768+session and then confirms it
 * against the passwd entry, exactly as UnlIolKeepalive does, so an account
 * wearing the tenant's name but holding a different uid stops the node rather
 * than becoming the uid it runs as.
 *
 * SECOND, that setuid happened in the WRAPPER's own process. Once it fired, the
 * wrapper could not create the next tenant account, bring up the next tap or
 * start the next node — which is why unl_wrapper's start-all loop has a
 * hand-written pass that postpones every IOL node to the end, with the comment
 * "IOL nodes drop privileges, so need to be postponed". A fork is what makes a
 * drop composable, and the fork is the part this file asserts is still there.
 *
 * THE PART THAT IS NOT ASSERTABLE HERE
 *
 * Whether a given emulator actually WORKS unprivileged is not a property of this
 * source; it is a property of a running host, and it lives in
 * tools/integration/lab-functional.sh ("no vpcs process runs as root") and
 * node-types.sh ("qemu-system runs as the tenant account"). What is asserted
 * here is that the mechanism is the safe one, that the types claiming the drop
 * are the ones that were measured, and that the tap group the drop depends on
 * has not been quietly changed back.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

/** The file with every comment removed, so the assertions see only code. */
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

$device = code_without_comments($root . '/devices/device.php');

// ------------------------------------------------------------ the mechanism

echo "  -- the drop happens in a forked child, not in the wrapper\n";

assert_true(strpos($device, 'pcntl_fork()') !== false,
    'device::spawnAsTenant() forks');
assert_true(strpos($device, 'posix_setuid(') !== false,
    'and drops the uid');
assert_true(strpos($device, 'pcntl_exec(') !== false,
    'and execs, rather than returning into PHP');

// Ordering. setuid is one-way, so everything that needs privilege has to come
// first, and the positions are asserted rather than the presence.
$posInit = strpos($device, 'posix_initgroups(');
$posGid  = strpos($device, 'posix_setgid(');
$posUid  = strpos($device, 'posix_setuid(');
$posExec = strpos($device, 'pcntl_exec(');
assert_true($posInit !== false, 'supplementary groups are applied');
assert_true($posInit < $posUid,
    'posix_initgroups() comes BEFORE posix_setuid() — kvm cannot be added afterwards');
assert_true($posGid < $posUid,
    'posix_setgid() comes before posix_setuid(), for the same reason');
assert_true($posUid < $posExec,
    'and the exec is after both drops');

// The uid is computed and confirmed, never read out of a command's output.
assert_true(strpos($device, 'posix_getpwnam(') !== false,
    'the uid is confirmed against the passwd database');
assert_true(strpos($device, '32768 + $session') !== false,
    'and compared against a COMPUTED 32768 + session');
assert_true(strpos($device, 'id -u') === false,
    "the uid is not parsed out of `id -u`, which is what device_iol::prepare() did");

// The parent stays root. A setuid outside the child would take the wrapper with
// it, and every node started after this one would fail.
$child = substr($device, strpos($device, 'if ($pid === 0)'));
assert_true(strpos($child, 'posix_setuid(') !== false,
    'the setuid is inside the `$pid === 0` branch');
assert_same(1, substr_count($device, 'posix_setuid('),
    'and there is exactly one setuid in device.php, so the parent cannot drop');

// ------------------------------------------------------- who claims the drop

echo "  -- the node types claiming the drop are the ones that were measured\n";

$claims = [];
foreach (['vpcs/device_vpcs', 'qemu/device_qemu', 'qemu/device_qemu_directly',
          'qemu/device_qemu_wp', 'docker/device_docker', 'dynamips/device_dynamips',
          'iol/device_iol'] as $rel) {
    $path = $root . '/devices/' . $rel . '.php';
    if (!is_file($path)) continue;
    $src = code_without_comments($path);
    if (preg_match('/function\s+runsAsTenant\s*\(\s*\)\s*\{\s*return\s+true\s*;/', $src)) {
        $claims[] = basename($rel);
    }
}
sort($claims);

// device_qemu_directly and device_qemu_wp EXTEND device_qemu, so they inherit
// the drop without declaring it. That is intended; only the declarations are
// listed here.
assert_same(['device_qemu', 'device_vpcs'], $claims,
    'exactly VPCS and QEMU declare that they run as the tenant');

$base = $root . '/devices/device.php';
assert_true(preg_match('/function\s+runsAsTenant\s*\(\s*\)\s*\{\s*return\s+false\s*;/',
    code_without_comments($base)) === 1,
    'and the base class still defaults to root, so a new device type opts IN');

// Docker must NOT be on that list. There is no emulator process for it to drop:
// device_docker has no command(), the container is created and run by the
// daemon, and the host-side work left over — moving a veth into the container's
// netns, nsenter into its namespaces, docker_wrapper — needs CAP_NET_ADMIN and
// CAP_SYS_ADMIN. See the commit message; this is a deliberate exclusion.
assert_true(!in_array('device_docker', $claims, true),
    'Docker does not claim the drop');
assert_true(!in_array('device_iol', $claims, true),
    'and neither does IOL, which still drops in-process in its own prepare()');

// ------------------------------------------------------------ the tap group

echo "  -- the tap group the drop depends on\n";

// This single word decides whether an emulator running as unl<N> can open the
// tap it owns. The kernel's tun_not_capable() denies the open unless the caller
// is BOTH the owner AND in the tap's group, so `-g root` leaves the owning
// tenant locked out of its own interface — measured on the reference host, with
// -g root refused and -g unl allowed for the owner and still refused for a
// different tenant.
$cli = code_without_comments($root . '/includes/cli.php');
assert_true(strpos($cli, "-g unl -t ") !== false,
    'addTap() creates the tap with -g unl');
assert_true(strpos($cli, '-g root -t ') === false,
    'and -g root is gone from the code, not merely commented out');

// -------------------------------------------- the disk the tenant has to write

echo "  -- the linked clone is handed to the tenant\n";

$qemu = code_without_comments($root . '/devices/qemu/device_qemu.php');
assert_true(strpos($qemu, 'chown(') !== false,
    'device_qemu::prepare() chowns the linked clone');
assert_true(strpos($qemu, 'chmod(') !== false, 'and sets its mode');
// qemu-img creates it 0644 root:unl, and QEMU opens its drive read-write, so as
// the tenant it exits at once — silently, because command() sends its output to
// wrapper.txt and qemu_wrapper_telnet truncates the same file a second later.
assert_true(strpos($qemu, '0660') !== false,
    'to a mode the tenant can write');
assert_true(strpos($qemu, 'shell_exec') === false && strpos($qemu, 'sudo chown') === false,
    'through PHP, not through a shell or a sudo grant');

// --------------------------------------------- and no new sudo grant appeared

$policy = file_get_contents($root . '/install/sudoers.d/pnetlab');
foreach (['chown', 'chmod', 'usermod', 'setpriv', 'runuser', 'su'] as $gone) {
    assert_true(!preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:\s*\S*\/' . $gone . '\s*(#.*)?$/m', $policy),
        "running nodes unprivileged did not add a sudo grant for $gone");
}

test_summary();
