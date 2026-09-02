<?php
/**
 * Keeps install/sudoers.d/pnetlab honest against the tree.
 *
 * The policy is an allowlist. That only works if it matches what the code
 * actually invokes, and it will not stay matched on its own:
 *
 *   - a binary the code sudo-invokes but the policy omits breaks the product at
 *     runtime, silently, on whichever code path happens to reach it
 *   - a binary the policy grants but the code no longer invokes is a standing
 *     privilege nobody needs
 *
 * This test fails on either. It is the thing that makes tightening the policy
 * safe to attempt.
 */

require_once __DIR__ . '/../bootstrap.php';

$root   = realpath(__DIR__ . '/../..');
$policy = $root . '/install/sudoers.d/pnetlab';

assert_true(is_file($policy), 'the sudo policy exists');

// --- what the policy grants ------------------------------------------------
$granted = [];
foreach (file($policy) as $line) {
    if (!preg_match('/^www-data\s+ALL=\(root\)\s+NOPASSWD:\s*(\S+)/', $line, $m)) continue;
    $granted[] = basename($m[1]);
}
$granted = array_unique($granted);

// --- what the code invokes -------------------------------------------------
$invoked = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $file->getPathname());
    foreach (['/.git/', '/.claude/', '/store/vendor/', '/node_modules/', '/tests/', '/tools/'] as $skip) {
        if (strpos($path, $skip) !== false) continue 2;
    }
    foreach (file($path) as $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '//') === 0 || strpos($t, '*') === 0) continue;
        if (preg_match_all('/sudo\s+(?:-\S+\s+)*(\/[\w\/.-]+|[a-z_][\w.-]*)/', $line, $ms)) {
            foreach ($ms[1] as $bin) $invoked[basename($bin)] = true;
        }
    }
}
$invoked = array_keys($invoked);

// --- compare ---------------------------------------------------------------
$missing = array_values(array_diff($invoked, $granted));
$unused  = array_values(array_diff($granted, $invoked));

sort($missing); sort($unused);

assert_same([], $missing,
    'every binary the code sudo-invokes is granted by the policy');
foreach ($missing as $m) echo "        code invokes but policy omits: $m\n";

assert_same([], $unused,
    'the policy grants nothing the code no longer invokes');
foreach ($unused as $u) echo "        policy grants but code never invokes: $u\n";

// --- the grants that must never come back ----------------------------------
$text = file_get_contents($policy);

/**
 * A blanket grant, in any spelling: user or group, any host, any runas (or
 * none), NOPASSWD or not, a command list that is ALL or contains a glob.
 * The first version of this check matched only `ALL=(ALL) NOPASSWD: ALL`
 * literally, so `ALL=(root) NOPASSWD: ALL` -- the shape every real grant in
 * this file takes -- would have passed it. It passed vacuously.
 */
function blanket_grants($text)
{
    $hits = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') continue;
        if (!preg_match('/^%?(?:www-data|unl)\s+\S+\s*=\s*(?:\([^)]*\)\s*)?(?:[A-Z]+:\s*)*(.*)$/i', $t, $m)) continue;
        $cmds = trim($m[1]);
        if (preg_match('/^ALL\b/i', $cmds) || preg_match('/[*?]/', $cmds)) $hits[] = $t;
    }
    return $hits;
}
assert_same([], blanket_grants($text), 'the policy carries no blanket or wildcard grant for www-data or unl');

// Negative control: the shapes the appliance shipped, and the one the first
// version of this check could not see, are all caught.
$control = "www-data ALL=(ALL) NOPASSWD: ALL\n%www-data ALL=(ALL:ALL) NOPASSWD: ALL\n%unl ALL=(ALL) NOPASSWD:ALL\n"
    . "www-data ALL=(root) NOPASSWD: ALL\nwww-data ALL=(root) NOPASSWD: /usr/bin/*\nwww-data ALL=(root) NOPASSWD: /opt/unetlab/wrappers/unl_wrapper\n";
assert_same(5, count(blanket_grants($control)), 'the blanket-grant check can fail: five of six control lines are caught');

assert_true(strpos($text, '/opt/unetlab/wrappers/unl_wrapper') !== false,
    'the platform wrapper is still permitted');

test_summary();
