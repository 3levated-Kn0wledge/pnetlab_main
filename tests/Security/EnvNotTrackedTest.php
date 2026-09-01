<?php
/**
 * No environment file with secrets in it is tracked in this repository.
 *
 * store/.env was tracked from the first commit, carrying a live APP_KEY,
 * APP_DEBUG=true and APP_LOG_LEVEL=debug. .gitignore listed `.env` the whole
 * time, which does exactly nothing for a file git is already tracking — the
 * pattern is consulted for untracked files only. So the ignore rule read as
 * protection while the key sat in every clone.
 *
 * The key must be treated as burned regardless of what happens next: it is in
 * this repository's history and in upstream's, and history is not being
 * rewritten for it because 22 of these commits are already published.
 * install/lib/store.sh generates a per-installation key and refuses to keep the
 * committed one, which is what actually protects a deployment.
 *
 * What this test protects is the future: that store/.env stays untracked, that
 * store/.env.example never acquires a value, and that the template does not
 * quietly go back to shipping developer settings.
 *
 * It reads git's index rather than the filesystem, because a developer's own
 * untracked store/.env is perfectly normal and must not fail the suite.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

echo "environment files\n";

// `git ls-files` is the question being asked: what does the repository carry?
$tracked = [];
$out = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $out, $rc);
if ($rc !== 0 || !$out) {
    echo "  note  not a git checkout, or git is unavailable; skipping the tracking\n";
    echo "        assertions. The template assertions below still run.\n";
} else {
    foreach ($out as $line) $tracked[trim($line)] = true;

    assert_true(!isset($tracked['store/.env']),
        'store/.env is not tracked');

    // Any other environment file carrying secrets. .env.example is the one
    // deliberate exception and is asserted separately below.
    $leaked = [];
    foreach (array_keys($tracked) as $path) {
        $base = basename($path);
        if ($base === '.env.example') continue;
        if ($base === '.env' || strpos($base, '.env.') === 0) $leaked[] = $path;
    }
    assert_same([], $leaked, 'no tracked .env file anywhere in the tree');
    foreach ($leaked as $l) echo "        tracked environment file: $l\n";

    assert_true(isset($tracked['store/.env.example']),
        'store/.env.example IS tracked — the installer needs it as a template');
}

// ---------------------------------------------------------------- the template

$examplePath = $root . '/store/.env.example';
assert_true(is_file($examplePath), 'store/.env.example exists');

$example = (string) @file_get_contents($examplePath);

/** The value of a key in an env file, or null. Comments are not settings. */
function env_value($text, $key)
{
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, $key . '=') === 0) return trim(substr($line, strlen($key) + 1));
    }
    return null;
}

// The assertion this file exists for: the template must never carry a key.
assert_same('', env_value($example, 'APP_KEY'),
    'APP_KEY in the template is empty');

// A template that ships developer settings puts a debug appliance on the
// internet for anyone who deploys without the installer. The installer forces
// these too, belt and braces.
assert_same('production', env_value($example, 'APP_ENV'), "APP_ENV is 'production'");
assert_same('false', env_value($example, 'APP_DEBUG'), "APP_DEBUG is 'false'");

$level = env_value($example, 'APP_LOG_LEVEL');
assert_true(in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency'], true),
    'APP_LOG_LEVEL is warning or quieter (got ' . var_export($level, true) . ')');

// Nothing that looks like a credential should have a value in a template.
$secretish = ['REDIS_PASSWORD', 'DB_PASSWORD', 'MAIL_PASSWORD', 'PUSHER_APP_SECRET'];
$withValues = [];
foreach ($secretish as $key) {
    $v = env_value($example, $key);
    if ($v !== null && $v !== '' && $v !== 'null') $withValues[] = "$key=$v";
}
assert_same([], $withValues, 'no credential in the template carries a value');
foreach ($withValues as $w) echo "        template carries a secret: $w\n";

// And the installer must actually consume the template, or untracking the real
// file leaves a clean install with no .env at all.
$storeSh = (string) @file_get_contents($root . '/install/lib/store.sh');
assert_true(strpos($storeSh, 'store/.env.example') !== false,
    'install/lib/store.sh sources the template');

test_summary();
