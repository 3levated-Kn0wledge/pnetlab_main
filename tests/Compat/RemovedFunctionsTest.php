<?php
/**
 * Functions removed in PHP 8 are a runtime fatal, not a syntax error, so
 * `php -l` passes a file that dies on every request.
 *
 * This was not hypothetical: includes/Slim/Http/Util.php called
 * get_magic_quotes_gpc() on every request through the legacy API. The tree
 * linted clean on 303 files while the entire API returned 500 on PHP 8.4.
 *
 * An earlier assessment checked five removed functions (each, create_function,
 * ereg, split, mysql_*) and reported "zero". get_magic_quotes_gpc was not on
 * that list. This test carries the full list so the gap cannot reopen.
 */

require_once __DIR__ . '/../bootstrap.php';

$removed = [
    // PHP 8.0
    'get_magic_quotes_gpc', 'get_magic_quotes_runtime', 'each', 'create_function',
    'ereg', 'eregi', 'ereg_replace', 'eregi_replace', 'split', 'spliti', 'sql_regcase',
    'money_format', 'restore_include_path', 'convert_cyr_string', 'hebrevc',
    'image2wbmp', 'png2wbmp', 'jpeg2wbmp', 'read_exif_data', 'gmp_random',
    'ldap_sort', 'fgetss', 'gzgetss', 'find_zend_extension_path',
    // mysql_* extension, removed in PHP 7.0
    'mysql_connect', 'mysql_query', 'mysql_fetch_array', 'mysql_real_escape_string',
    'mysql_select_db', 'mysql_close', 'mysql_error', 'mysql_num_rows',
    // wddx extension, removed in PHP 7.4
    'wddx_serialize_value', 'wddx_deserialize', 'wddx_packet_start', 'wddx_packet_end',
];

$root = dirname(__DIR__, 1) . '/..';
$root = realpath(__DIR__ . '/../..');

// Generated code is not this project's source, which is why vendor/ and
// node_modules/ are here. store/storage/framework/views/ belongs in the same
// category and was missing: Blade compiles into it at runtime, so a checkout
// that has ever booted the application in place carries compiled views, and one
// of them is Laravel 11's own debug exception page -- which inlines a minified
// highlight.js containing `e.split(a)`. The lookbehind below excludes
// `[\w$>:]` but not `.`, so a JS method call reads as a call to PHP's removed
// split(), and the suite fails pointing at a file no one wrote. Found during
// the Laravel 11 upgrade; the residue was a boot test run, not a code change.
$skip = ['/.git/', '/store/vendor/', '/store/node_modules/', '/node_modules/', '/tests/',
         '/store/storage/framework/views/'];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$found = [];
$scanned = 0;

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skip as $s) {
        if (strpos($path, $s) !== false) continue 2;
    }
    $scanned++;
    $src = file_get_contents($path);
    // Strip comments so documentation of these names does not trip the check.
    $stripped = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $stripped .= is_array($t) ? $t[1] : $t;
    }
    foreach ($removed as $fn) {
        if (preg_match('/(?<![\w$>:])' . preg_quote($fn, '/') . '\s*\(/i', $stripped)) {
            $found[] = substr($path, strlen($root) + 1) . ' -> ' . $fn . '()';
        }
    }
}

assert_true($scanned > 200, "scanned the tree ($scanned php files)");
assert_same([], $found, 'no functions removed in PHP 7/8 are called');

if ($found) {
    foreach ($found as $f) echo "        $f\n";
}

test_summary();
