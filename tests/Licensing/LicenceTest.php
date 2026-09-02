<?php
/**
 * Keeps the licence position from drifting back.
 *
 * docs/LICENSING.md is the reasoning; this file is the part a machine can
 * check. Everything asserted here was, at some point, wrong in this tree:
 *
 *   - the only licence declaration in the repository was `"license": "MIT"` in
 *     store/composer.json, left behind by the Laravel skeleton, asserting MIT
 *     over 445 files nobody here holds copyright in
 *   - nine files inherited from UNetLab reached this fork with their
 *     BSD-3-Clause notice removed, and one with it replaced by a different
 *     attribution — which is the single condition BSD-3 actually imposes
 *   - a proprietary Monotype Arial Bold was committed and redistributed
 *
 * Each of those is cheap to reintroduce by accident: a `composer init`, a
 * "tidy the headers" pass, a font dropped next to the class that wants it. So
 * each has an assertion.
 *
 * Like EnvNotTrackedTest, the tracking questions are asked of git's index
 * rather than the filesystem — a developer's untracked scratch font is their
 * business; what the repository *carries* is not.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

echo "licensing\n";

// ------------------------------------------------------------------ LICENSE

$licensePath = $root . '/LICENSE';
assert_true(is_file($licensePath), 'LICENSE exists at the repository root');
$license = (string) @file_get_contents($licensePath);

assert_true(strpos($license, 'BSD 3-Clause License') !== false,
    'LICENSE names BSD 3-Clause');
assert_true(strpos($license, 'Neither the name of the copyright holder nor the names of its') !== false,
    'LICENSE carries the third (non-endorsement) condition');
assert_true(strpos($license, 'THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS') !== false,
    'LICENSE carries the disclaimer');

// The scope note is the load-bearing half. A bare BSD-3 text over this tree
// would claim to relicense both the unlicensed PNETLab body and the
// GPL-carrying frontend bundles, which is exactly the misstatement the licence
// decision was taken to avoid.
foreach ([
    'WHAT THIS LICENCE COVERS, AND WHAT IT DOES NOT' => 'LICENSE has a scope section',
    'NO LICENCE GRANT AT ALL'                        => 'LICENSE says the PNETLab body carries no grant',
    'GPL-2.0-or-later'                               => 'LICENSE says the built bundles carry GPL terms',
    'THIRD-PARTY.md'                                 => 'LICENSE points at the attribution file',
] as $needle => $desc) {
    assert_true(strpos($license, $needle) !== false, $desc);
}

// -------------------------------------------------------------- composer.json

$composerPath = $root . '/store/composer.json';
$composer = json_decode((string) @file_get_contents($composerPath), true);
assert_true(is_array($composer), 'store/composer.json parses');
assert_same('BSD-3-Clause', isset($composer['license']) ? $composer['license'] : null,
    'store/composer.json declares BSD-3-Clause, not the skeleton default');

// ------------------------------------------------------- THIRD-PARTY.md ships

assert_true(is_file($root . '/THIRD-PARTY.md'), 'THIRD-PARTY.md exists');

// BSD-3 condition 2 asks for the notice to accompany a binary distribution.
// The installer rsyncs the repository root to /opt/unetlab/html, so the file
// gets there for free -- unless someone adds it to the exclude list. That is
// the only way this can silently break, so it is the thing to assert.
$deploy = (string) @file_get_contents($root . '/install/lib/deploy.sh');
assert_true($deploy !== '', 'install/lib/deploy.sh is readable');
$excludes = '';
if (preg_match('/deploy_excludes\(\)\s*\{(.*?)\n\}/s', $deploy, $m)) $excludes = $m[1];
assert_true($excludes !== '', 'deploy_excludes() was found in the installer');
assert_true(strpos($excludes, 'THIRD-PARTY.md') === false,
    'THIRD-PARTY.md is NOT excluded from the deploy, so it reaches the appliance');
assert_true(strpos($excludes, "'/LICENSE'") === false && strpos($excludes, '/LICENSE ') === false,
    'LICENSE is not excluded from the deploy either');

// ------------------------------------------------- the restored BSD-3 notices

/*
 * The nine files that reached this fork stripped. Restoring the notice is what
 * BSD-3 condition 1 asks of a source redistribution, and it is the one
 * obligation in this tree that was being missed on material we can prove we
 * redistribute. Each must carry Dainese's attribution and the licence line.
 */
$restored = [
    'api.php',
    'includes/functions.php',
    'includes/init.php',
    'includes/__lab.php',
    'includes/__node.php',
    'devices/interfc.php',
    'themes/default/js/functions.js',
    'themes/default/js/javascript.js',
    'platform/wrappers/unl_wrapper',
];

foreach ($restored as $rel) {
    $head = '';
    $fh = @fopen($root . '/' . $rel, 'r');
    if ($fh) { $head = (string) fread($fh, 4096); fclose($fh); }

    assert_true(strpos($head, 'Andrea Dainese') !== false,
        "$rel credits Andrea Dainese");
    assert_true(strpos($head, '@license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE') !== false,
        "$rel carries the BSD-3-Clause licence line");
}

// devices/interfc.php is the one where the notice was REPLACED rather than
// dropped. Both attributions belong there; losing either is a regression.
$interfc = (string) @file_get_contents($root . '/devices/interfc.php');
assert_true(strpos($interfc, '@copyright pnetlab.com') !== false,
    'devices/interfc.php keeps the PNETLab attribution alongside the restored one');

// ------------------------------------------- the inherited notices, in bulk

/*
 * A floor, not an exact count: 130 files carry an intact UNetLab/EVE-NG
 * BSD-3-Clause notice, and adding templates should raise that number, never
 * lower it. The failure mode this catches is a bulk header rewrite -- the kind
 * of tidy-up that produced the nine stripped files in the first place.
 */
$bsd = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    foreach (['/.git/', '/.claude/', '/node_modules/', '/store/vendor/', '/docs/', '/tests/'] as $skip) {
        if (strpos($path, $skip) !== false) continue 2;
    }
    if (!$file->isFile() || $file->getSize() > 262144) continue;
    $head = (string) @file_get_contents($path, false, null, 0, 4096);
    if (strpos($head, 'Andrea Dainese') !== false || strpos($head, 'Alain Degreffe') !== false) $bsd++;
}
assert_true($bsd >= 130,
    "at least 130 files still carry a UNetLab/EVE-NG notice (found $bsd)");

// ------------------------------------------------------------ tracked fonts

/*
 * ARIALBD.TTF was Arial Bold, Monotype, all rights reserved, redistributed in
 * a public repository by inheritance rather than by anyone's decision. The
 * assertion is not "that one file is gone" but "every font this repository
 * carries is one we can name a licence for", because the next one will have a
 * different name.
 */
$tracked = [];
$out = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $out, $rc);
if ($rc !== 0 || !$out) {
    echo "  note  not a git checkout, or git is unavailable; skipping the tracked-font\n";
    echo "        assertions.\n";
} else {
    foreach ($out as $line) $tracked[] = trim($line);

    assert_true(!in_array('store/app/Helpers/Captcha/ARIALBD.TTF', $tracked, true),
        'the proprietary Arial Bold is not tracked');

    // Every tracked font must sit under a prefix THIRD-PARTY.md accounts for.
    // Adding a prefix here means adding a row there; that is the point.
    $allowed = [
        'themes/default/fonts/'                        => 'Ubuntu Font Licence 1.0 / OFL-1.1 (Font Awesome)',
        'themes/default/bootstrap/fonts/'              => 'Glyphicons Halflings, via Bootstrap (MIT)',
        'store/public/extensions/icons/fonts/'         => 'Font Awesome (OFL-1.1 fonts, MIT code)',
        'store/public/main/css/fonts/'                 => 'Font Awesome (OFL-1.1 fonts, MIT code)',
        'fonts/vendor/primeicons/'                     => 'PrimeIcons (MIT)',
        'fonts/vendor/primereact/'                     => 'Open Sans (Apache-2.0)',
    ];
    $unaccounted = [];
    foreach ($tracked as $path) {
        if (!preg_match('/\.(ttf|otf|woff2?|eot)$/i', $path)) continue;
        $ok = false;
        foreach ($allowed as $prefix => $_) if (strpos($path, $prefix) === 0) { $ok = true; break; }
        if (!$ok) $unaccounted[] = $path;
    }
    assert_same([], $unaccounted,
        'every tracked font sits under a prefix THIRD-PARTY.md accounts for');
    foreach ($unaccounted as $u) echo "        unaccounted font: $u\n";

    // The captcha's guaranteed fallback must actually be in the repository, or
    // an offline appliance with no font package renders a blank challenge and
    // the offline login becomes unusable.
    assert_true(in_array('themes/default/fonts/Ubuntu-B.ttf', $tracked, true),
        'the in-tree captcha fallback font is tracked');

    // And no vendor image, ever. The boundary the project has held by care
    // since the first commit, held by a test instead.
    $images = [];
    foreach ($tracked as $path) {
        if (preg_match('/\.(qcow2|vmdk|vdi|vhdx?|iso|ova|ovf|img|bin)$/i', $path)) $images[] = $path;
    }
    assert_same([], $images, 'no vendor disk or emulator image is tracked');
    foreach ($images as $i) echo "        tracked image: $i\n";

    // The Cisco IOU/IOL keygen is not here and must not arrive. Shipping one
    // is a different act from calling an operator-supplied copy, and the
    // difference is the project's whole position on IOL.
    $keygens = [];
    foreach ($tracked as $path) {
        if (stripos(basename($path), 'keygen') !== false) $keygens[] = $path;
    }
    assert_same([], $keygens, 'no licence-key generator is tracked');
    foreach ($keygens as $k) echo "        tracked keygen: $k\n";

    /*
     * The idlepc blob is a KNOWN GAP, not a clean state, so this asserts the
     * shape of the exception rather than its absence.
     *
     * It is unlicensed, unbuildable, embeds LGPL-2.1 paramiko with no source,
     * and installs a passwordless root SSH key when it runs. It is still here
     * because deleting it would remove a working capability and leave a hole,
     * and the replacement cannot be verified without a Cisco IOS image this
     * project does not carry. docs/LICENSING.md tracks it as gap G3.
     *
     * So: either it is gone -- in which case the sudo grant must be gone too,
     * and SudoersPolicyTest enforces that half -- or it is present and the
     * exception is documented in both places a maintainer would look. What
     * must never happen is that it sits here unremarked.
     */
    if (in_array('store/app/Console/Commands/idlepc', $tracked, true)) {
        $policy    = (string) @file_get_contents($root . '/install/sudoers.d/pnetlab');
        $licensing = (string) @file_get_contents($root . '/docs/LICENSING.md');

        assert_true(strpos($policy, 'authorized_keys') !== false,
            'the idlepc sudo grant records that the binary installs a root SSH key');
        assert_true(strpos($licensing, 'G3') !== false,
            'docs/LICENSING.md tracks idlepc as a numbered gap');
        assert_true(strpos($licensing, 'unl_wrapper -a idlepc') !== false,
            'docs/LICENSING.md names the replacement that closes it');
        echo "  note  idlepc is still tracked: gap G3, documented, gates publication.\n";
    } else {
        assert_true(strpos((string) @file_get_contents($root . '/install/sudoers.d/pnetlab'),
            'Console/Commands/idlepc') === false,
            'idlepc is gone and its sudo grant went with it');
    }
}

// ------------------------------------- the captcha still has a font to use

$captcha = (string) @file_get_contents($root . '/store/app/Helpers/Captcha/Captcha.php');

// Not "the string ARIALBD does not appear" -- the class explains at length why
// that font was removed, and that explanation is the most useful thing in the
// file. What must not come back is ARIALBD as a font CANDIDATE.
$candidates = [];
if (preg_match('/\$FONTS\s*=\s*\[(.*?)\];/s', $captcha, $m)) {
    preg_match_all("/'([^']+)'/", $m[1], $c);
    $candidates = $c[1];
}
assert_true($candidates !== [], 'Captcha::$FONTS is a non-empty candidate list');
$proprietary = [];
foreach ($candidates as $c) if (stripos($c, 'arial') !== false) $proprietary[] = $c;
assert_same([], $proprietary, 'no proprietary font is a captcha candidate');

assert_true(in_array('../../../../themes/default/fonts/Ubuntu-B.ttf', $candidates, true),
    'the captcha falls back to the in-tree Ubuntu Bold');

// Resolve the fallback the way the class does, and check it is really there.
$fallback = $root . '/store/app/Helpers/Captcha/../../../../themes/default/fonts/Ubuntu-B.ttf';
assert_true(is_file($fallback),
    'the relative fallback path in Captcha.php resolves to a real font file');

test_summary();
