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
// devices/qemu/device_qemu.php is deliberately NOT here yet. Its command-level
// injection is fixed (see the commit removing the quote-collapsing rewrite), but
// its -device/-netdev builds assemble comma-separated QEMU option values rather
// than shell arguments, so they need restructuring rather than escaping, and
// that cannot be verified without QEMU images and /dev/kvm. Listing it here
// would assert a completeness this sweep has not reached.
$swept = [
    'includes/cli.php',
    'includes/functions.php',
    'devices/interfc.php',
];

$violations = [];
foreach ($swept as $rel) {
    $path = $root . '/' . $rel;
    foreach (file($path) as $n => $line) {
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
