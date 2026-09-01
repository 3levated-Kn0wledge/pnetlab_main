<?php
/**
 * Guards the shell-injection sweep against regression.
 *
 * The application builds shell commands by string concatenation in 368 places
 * and, before this sweep, used escapeshellarg() in none of them. Files are
 * added to $swept as each is converted, so this test only ever tightens.
 *
 * It looks for a variable interpolated into a command string without passing
 * through escapeshellarg() — the exact shape the sweep removes.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

// Files fully converted by the shell sweep. Append as the sweep progresses.
//
$swept = [
    'includes/cli.php',
    'includes/functions.php',
    'includes/api_nodes.php',
    'includes/api_labs.php',
    'devices/device.php',
    'devices/interfc.php',
    'devices/qemu/device_qemu.php',
    'devices/qemu/device_qemu_wp.php',
    'devices/qemu/device_qemu_directly.php',
    'devices/qemu/device_linux.php',
    'devices/docker/device_docker.php',
    'devices/iol/device_iol.php',
    'devices/dynamips/device_dynamips.php',
    'devices/vpcs/device_vpcs.php',
    'store/app/Console/Commands/ScandHardDisk.php',
    'store/app/Http/Controllers/Admin/DefaultController.php',
];

$violations = [];
foreach ($swept as $rel) {
    $path = $root . '/' . $rel;
    $src  = file_get_contents($path);

    // Only files that actually execute something can carry shell injection.
    // includes/models/model_basic.php, for instance, assigns SQL to a variable
    // named $cmd and binds every value through PDO — flagging it on the variable
    // name alone would mean adding a false exemption to silence a false positive.
    if (!preg_match('/\b(exec|shell_exec|system|passthru|popen|proc_open)\s*\(/', $src)) {
        continue;
    }

    $lines = file($path);
    foreach ($lines as $n => $line) {
        // A line may be exempted only by an explicit marker on the preceding
        // lines, which must say why. Silence is not an exemption.
        $exempt = false;
        for ($k = $n - 1; $k >= 0 && $k >= $n - 4; $k--) {
            if (strpos($lines[$k], 'sweep-exempt:') !== false) { $exempt = true; break; }
            if (trim($lines[$k]) !== '' && strpos(ltrim($lines[$k]), '//') !== 0) break;
        }
        if ($exempt) continue;
        // A command assignment that concatenates a bare variable.
        // Commented-out code is not executed.
        if (preg_match('/^\s*(\/\/|\*|#)/', $line)) continue;
        if (!preg_match('/\$cmd\s*\.?=/', $line)) continue;
        if (!preg_match('/[\'"]\s*\.\s*\$/', $line)) continue;
        // Escaped, or a variable that already holds an escaped value.
        if (strpos($line, 'escapeshellarg') !== false) continue;
        if (preg_match('/\.\s*\$esc\b/', $line)) continue;
        $violations[] = sprintf('%s:%d %s', $rel, $n + 1, trim($line));
    }
}

assert_true(count($swept) > 0, 'the swept-file list is not empty');
assert_same([], $violations, 'no unescaped interpolation in swept files');
foreach ($violations as $v) echo "        $v\n";

// The allowlist validator must reject the shapes that matter.
require_once $root . '/includes/cli.php';

foreach (['vnet1_1', 'vunl12_0', 'pnet0', 'nat0', 'docker0', 'eth0'] as $good) {
    assert_true(unl_valid_ifname($good), "accepts a real interface name: $good");
}
foreach (['a;id', 'a$(id)', 'a`id`', "a\nid", 'a>b', 'a b', '../etc', '',
          'toolongtoolong16'] as $bad) {
    assert_true(!unl_valid_ifname($bad), 'rejects ' . json_encode($bad));
}

test_summary();
