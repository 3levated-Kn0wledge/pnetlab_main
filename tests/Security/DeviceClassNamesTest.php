<?php
/**
 * Every device_<name>.php must declare class device_<name>.
 *
 * includes/__node.php builds the device object like this:
 *
 *     require_once('.../devices/' . $type . '/device_' . $type . '.php');
 *     if (is_file('.../devices/' . $type . '/device_' . $template . '.php')) {
 *         require_once('.../devices/' . $type . '/device_' . $template . '.php');
 *         $class = 'device_' . $template;
 *         $this->deviceFactory = new $class($this);
 *     }
 *
 * So a template may override the device class by shipping a file named after
 * itself -- and the loader will instantiate exactly `device_<template>`. A file
 * whose class is named anything else is a fatal error waiting for the first node
 * that selects that template, in one of two ways:
 *
 *   - "Cannot redeclare class device_<type>", if it reuses the name of the
 *     device_<type>.php already required on the line above; or
 *   - "Class not found", on the `new $class` when nothing declares the name the
 *     loader derived from the filename.
 *
 * Three files were wrong when this test was written, and one of them was live:
 * templates/paloalto1.yml ships with the product, and devices/qemu/
 * device_paloalto1.php declared `device_paloalto` -- a byte-identical copy of
 * device_paloalto.php that had never been renamed inside. Any node using the
 * paloalto1 template died on instantiation. device_qemu_wp.php and
 * device_qemu_directly.php both declared `device_qemu`; those two are genuine
 * alternative implementations rather than copies, and they were unloadable.
 *
 * This is cheap to assert and expensive to discover: nothing fails until a user
 * picks the one template nobody tested.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$deviceDir = $root . '/devices';

echo "device class names\n";

assert_true(is_dir($deviceDir), 'devices/ exists');

/** Class declarations in a file, with comments stripped so a commented-out one does not count. */
function declared_classes($path)
{
    $out = [];
    $tokens = token_get_all((string) file_get_contents($path));
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) continue;
        // ::class and anonymous classes are not declarations.
        $prev = $i > 0 && is_array($tokens[$i - 1]) ? $tokens[$i - 1][0] : null;
        if ($prev === T_DOUBLE_COLON || $prev === T_NEW) continue;
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) $out[] = $tokens[$j][1];
            break;
        }
    }
    return $out;
}

$files = [];
foreach (glob($deviceDir . '/*/device_*.php') as $path) $files[] = $path;
sort($files);

assert_true(count($files) > 0, 'device files were found to check');

$mismatched = [];
foreach ($files as $path) {
    $expected = basename($path, '.php');
    $classes = declared_classes($path);
    // A file may declare helpers; what matters is that it declares the one the
    // loader will instantiate.
    if (!in_array($expected, $classes, true)) {
        $mismatched[] = basename(dirname($path)) . '/' . basename($path)
            . ' declares ' . (count($classes) ? implode(', ', $classes) : 'no class')
            . ', not ' . $expected;
    }
}

assert_same([], $mismatched,
    sprintf('every device_<name>.php declares class device_<name> (%d files)', count($files)));
foreach ($mismatched as $m) echo "        $m\n";

// The specific case that shipped broken, pinned by name so a regression is
// recognisable rather than just a count going up.
$paloalto1 = $deviceDir . '/qemu/device_paloalto1.php';
if (is_file($paloalto1)) {
    assert_true(in_array('device_paloalto1', declared_classes($paloalto1), true),
        'device_paloalto1.php declares device_paloalto1 (templates/paloalto1.yml ships)');
}

// And the invariant the loader actually depends on: no two files in the same
// device directory declare the same class, because device_<type>.php is always
// required before device_<template>.php and PHP cannot redeclare.
$byDir = [];
foreach ($files as $path) {
    $dir = basename(dirname($path));
    foreach (declared_classes($path) as $c) {
        $byDir[$dir][$c][] = basename($path);
    }
}
$collisions = [];
foreach ($byDir as $dir => $classes) {
    foreach ($classes as $class => $where) {
        if (count($where) > 1) $collisions[] = "$dir: $class declared by " . implode(' and ', $where);
    }
}
assert_same([], $collisions,
    'no two device files in one directory declare the same class');
foreach ($collisions as $c) echo "        $c\n";

test_summary();
