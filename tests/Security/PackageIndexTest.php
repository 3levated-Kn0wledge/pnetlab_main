<?php
/**
 * The repository index is data from the network, and is treated as such.
 *
 * PACKAGE_CENTER/index.json replaced two upstream calls -- the device listing
 * and the update check -- when Phase 05 severed them (docs/PACKAGES.md,
 * "The index"). Its contents reach an admin's browser: a device name, a
 * description, an image URL, a guide link, an update note. So the parser is
 * asserted here against the shapes a hostile or merely broken repository
 * could serve, and the contract is: keep only known fields, only in the
 * expected shapes, drop what does not fit, and never let a URL that is not
 * http(s) with a host through.
 *
 * PackageClient::parseIndex() is pure, so no server is needed.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/store/app/Constants/Admin/devices_cts.php';
require_once $root . '/store/app/Constants/Admin/packages_cst.php';
require_once $root . '/store/app/Helpers/Packages/PackageClient.php';

use App\Helpers\Packages\PackageClient;

echo "the repository index\n";

// -------------------------------------------------------------- not an index

foreach (['', 'not json', '[]', '"a string"', '42', 'null'] as $bad) {
    $r = PackageClient::parseIndex($bad);
    assert_true($r['result'] === false || ($r['devices'] === [] && $r['appliance'] === null),
        'yields nothing for ' . var_export($bad, true));
}
$r = PackageClient::parseIndex('{}');
assert_same(true, $r['result'], 'an empty object is a valid, empty index');
assert_same([], $r['devices'], 'with no devices');
assert_same(null, $r['appliance'], 'and no appliance');

// ------------------------------------------------------------ a good record

$good = json_encode([
    'devices' => [[
        'device_id' => 'vios',
        'device_name' => ' Cisco vIOS ',
        'device_des' => 'IOSv 15.9',
        'device_img' => 'https://packages.example.com/img/vios.png',
        'device_package' => 'https://packages.example.com/devices/vios.pnetpkg',
        'device_package_sha256' => strtoupper(str_repeat('ab', 32)),
        'device_guide' => 'http://packages.example.com/guides/vios.html',
        'device_script' => 'rm -rf /',
    ]],
    'appliance' => ['version' => '5.3.14', 'package' => 'https://packages.example.com/p.pnetpkg',
                    'sha256' => str_repeat('cd', 32), 'note' => "Fixes.\n"],
]);
$r = PackageClient::parseIndex($good);
assert_same(true, $r['result'], 'a well-formed index parses');
assert_same(1, count($r['devices']), 'one device');
$d = $r['devices'][0];
assert_same('vios', $d[DEVICE_ID], 'id kept');
assert_same('Cisco vIOS', $d[DEVICE_NAME], 'name trimmed');
assert_same('IOSv 15.9', $d[DEVICE_DES], 'description kept');
assert_same('https://packages.example.com/img/vios.png', $d[DEVICE_IMG], 'image URL kept');
assert_same('https://packages.example.com/devices/vios.pnetpkg', $d[DEVICE_PACKAGE], 'package URL kept');
assert_same(str_repeat('ab', 32), $d[DEVICE_PACKAGE_SHA256], 'digest kept, lower-cased');
assert_same('http://packages.example.com/guides/vios.html', $d[DEVICE_GUIDE], 'guide URL kept');
assert_true(!array_key_exists('device_script', $d), 'and the legacy script field does not come through');
assert_same(['device_id', 'device_name', 'device_des', 'device_img', 'device_package', 'device_package_sha256', 'device_guide'],
    array_keys($d), 'exactly the known fields, in a fixed order');
assert_same(['version' => '5.3.14', 'package' => 'https://packages.example.com/p.pnetpkg',
             'sha256' => str_repeat('cd', 32), 'note' => 'Fixes.'], $r['appliance'], 'the appliance record');

// ------------------------------------------------------- hostile records

$hostile = json_encode(['devices' => [
    ['device_id' => '../../etc', 'device_name' => 'traversal'],
    ['device_id' => 'a b', 'device_name' => 'space'],
    ['device_id' => '', 'device_name' => 'empty'],
    ['device_id' => str_repeat('x', 65), 'device_name' => 'too long'],
    ['device_name' => 'no id'],
    'not a record',
    ['device_id' => 'ok1',
     'device_img' => 'javascript:alert(1)',
     'device_package' => 'file:///etc/passwd',
     'device_guide' => 'data:text/html,<script>alert(1)</script>',
     'device_package_sha256' => 'not-a-digest',
     'device_name' => str_repeat('n', 500),
     'device_des' => "a\x00b\x07c" . str_repeat('d', 3000)],
    ['device_id' => 'ok1', 'device_name' => 'duplicate'],
    ['device_id' => 'ok2', 'device_name' => ['array'], 'device_des' => 12, 'device_img' => '//cdn/x.png'],
]]);
$r = PackageClient::parseIndex($hostile);
assert_same(true, $r['result'], 'a hostile index still parses');
assert_same(['ok1', 'ok2'], array_map(function ($d) { return $d[DEVICE_ID]; }, $r['devices']),
    'only the records with a valid id survive, first of a duplicate wins');
$d = $r['devices'][0];
assert_same('', $d[DEVICE_IMG], 'a javascript: image is dropped');
assert_same('', $d[DEVICE_PACKAGE], 'a file: package is dropped');
assert_same('', $d[DEVICE_GUIDE], 'a data: guide is dropped');
assert_same('', $d[DEVICE_PACKAGE_SHA256], 'a malformed digest is dropped');
assert_same(128, strlen($d[DEVICE_NAME]), 'a name is bounded');
assert_same(2000, strlen($d[DEVICE_DES]), 'a description is bounded');
assert_true(strpos($d[DEVICE_DES], "\x00") === false && strpos($d[DEVICE_DES], "\x07") === false,
    'and control characters are stripped from it');
$d = $r['devices'][1];
assert_same('ok2', $d[DEVICE_NAME], 'a non-string name falls back to the id');
assert_same('', $d[DEVICE_DES], 'a non-string description is empty');
assert_same('', $d[DEVICE_IMG], 'a scheme-relative image URL is dropped (no scheme, no host)');

// ------------------------------------------------------ hostile appliance

$cases = [
    ['no package', ['version' => '5.3.14']],
    ['no version', ['package' => 'https://x.example/p.pnetpkg']],
    ['a version that is not a version', ['version' => '<script>', 'package' => 'https://x.example/p.pnetpkg']],
    ['a package that is not a URL', ['version' => '5.3.14', 'package' => 'javascript:1']],
    ['not an object', 'string'],
];
foreach ($cases as $c) {
    $r = PackageClient::parseIndex(json_encode(['appliance' => $c[1]]));
    assert_same(null, $r['appliance'], 'appliance ignored: ' . $c[0]);
}
$r = PackageClient::parseIndex(json_encode(['appliance' => [
    'version' => '6.0.0-rc1', 'package' => 'https://x.example/p.pnetpkg',
    'sha256' => 'zz', 'note' => '<b>bold</b>' . str_repeat('!', 5000)]]));
assert_same('6.0.0-rc1', $r['appliance']['version'], 'a pre-release version is accepted');
assert_same('', $r['appliance']['sha256'], 'a bad appliance digest is dropped');
assert_same(2000, strlen($r['appliance']['note']), 'the note is bounded');

// ---------------------------------------------------------- the index URL

assert_same(null, PackageClient::indexUrl(), 'with no PNET_PACKAGE_CENTER there is no index URL, so no request');

test_summary();
