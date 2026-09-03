<?php
/**
 * No PHP file in the tree uses the backtick operator.
 *
 * api.php's P2P branch built a network name as
 *
 *     `Net-` . get($variables['name'])
 *
 * with backticks where quotes were meant. In PHP a backtick string is
 * shell_exec(): every P2P request ran a command called `Net-`, and because a
 * command that does not exist has no stdout, the prefix was silently dropped
 * and the network got the bare id as its name. An executable called `Net-` on
 * PHP-FPM's PATH would have chosen the prefix instead.
 *
 * ShellEscapingTest treats a backtick as an exec root and walks its operand for
 * unescaped interpolation, so it saw this site — and passed it, correctly, as a
 * constant. That test answers "is the argument escaped"; this one answers "is
 * there a backtick at all", which for this codebase should be no: every
 * legitimate shell call goes through exec() with escapeshellarg() or through
 * spawnAsTenant(), where the sweep can see it. A backtick is never the
 * intended way to run something here, and a typo that runs one is exactly what
 * a tokenizer scan catches and a reader does not.
 *
 * Same tree as tools/php-lint.sh: .git, store/vendor and store/node_modules
 * are pruned. .claude is pruned too, because worktrees under it hold whole
 * copies of the tree.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

echo "the backtick operator\n";

$prune = ['.git', 'vendor', 'node_modules', '.claude'];
$iter = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($file) use ($prune) {
            return !in_array($file->getFilename(), $prune, true);
        }
    )
);

$scanned = 0;
$hits = [];
foreach ($iter as $file) {
    if ($file->getExtension() !== 'php') continue;
    $scanned++;
    $src = file_get_contents($file->getPathname());
    $line = 0;
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { $line = $t[2]; continue; }
        // token_get_all() emits the backtick operator as a bare '`' character
        // token, on both sides of the string. Nothing else produces one.
        if ($t === '`') {
            $hits[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $line;
            break;
        }
    }
}

assert_true($scanned > 300, "the sweep found the tree ($scanned PHP files)");
assert_same([], $hits, 'no file uses the backtick operator' . ($hits ? ' — ' . implode(', ', $hits) : ''));

// The site itself, as it should read now.
$api = code_only($root . '/api.php');
assert_true(strpos($api, "'Net-' . get(\$variables['name'], '')") !== false,
    "api.php builds the P2P network name with a quoted 'Net-' and a default");

test_summary();
