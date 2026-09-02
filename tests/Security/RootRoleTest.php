<?php
/**
 * Who counts as root, and who does not.
 *
 * Two values have always meant the root role. install/sql/seed-admin.sql writes
 * the string 'admin' for the built-in account; App\Helpers\Box\License maps that
 * same 'admin' onto 0 when it provisions an online account. The column is a
 * `text` holding either.
 *
 * Role::checkRoot() tested `== 0` alone, which caught both on PHP 7 only because
 * 'admin' == 0 was true there -- a non-numeric string converted to 0. PHP 8
 * compares a number against a non-numeric string as strings, so that became
 * false and the account that ships with the product stopped being root: every
 * root-only screen in the Laravel admin UI answered ERROR_PERMISSION.
 *
 * The same comparison in LoginController decided the admin's Guacamole
 * permission, so it silently downgraded UPDATE to READ.
 *
 * This test pins the behaviour in both directions, because the PHP 7 version was
 * also wrong the other way: 'anything' == 0 was true, so ANY non-numeric role
 * was root. Fixing one direction and reintroducing the other would still be a
 * privilege bug.
 *
 * Role.php is loaded directly rather than booting Laravel: isRootRole() is a
 * pure function of its argument, and requiring the framework to test it would
 * mean this only runs on a deployed host.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$rolePath = $root . '/store/app/Helpers/Auth/Role.php';

echo "root role\n";

assert_true(is_file($rolePath), 'store/app/Helpers/Auth/Role.php exists');

$src = file_get_contents($rolePath);

// The regression this file exists to prevent: checkRoot() going back to a bare
// loose comparison against 0. Comments are stripped so the explanation above
// isRootRole(), which necessarily quotes the old code, cannot satisfy or defeat
// the assertion.
$code = '';
foreach (token_get_all($src) as $token) {
    if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { $code .= "\n"; continue; }
        $code .= $token[1];
        continue;
    }
    $code .= $token;
}

assert_true(strpos($code, 'function isRootRole') !== false,
    'Role::isRootRole() exists');
assert_true(preg_match('/checkRoot\s*\(\s*\)\s*\{\s*return\s+self::isRootRole/', $code) === 1,
    'checkRoot() delegates to isRootRole() rather than comparing inline');
assert_true(preg_match('/\{USER_ROLE\}\s*==\s*0/', $code) !== 1,
    'no bare `role == 0` comparison survives in Role.php');

// LoginController decided a Guacamole permission with the same comparison.
$login = $root . '/store/app/Http/Controllers/Auth/LoginController.php';
$loginCode = '';
foreach (token_get_all((string) @file_get_contents($login)) as $token) {
    if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { $loginCode .= "\n"; continue; }
        $loginCode .= $token[1];
        continue;
    }
    $loginCode .= $token;
}
assert_true(preg_match('/\{USER_ROLE\}\s*==\s*0/', $loginCode) !== 1,
    'LoginController does not compare the role to 0 directly either');

// ---------------------------------------------------------------- behaviour

// Evaluate isRootRole() itself. The class needs USER_ROLE and the framework, so
// the method body is extracted and evaluated on its own -- it is a pure
// function of its argument and depends on neither.
if (!preg_match('/public static function isRootRole\(\$role\)\{(.*?)\n    \}/s', $code, $m)) {
    assert_true(false, 'isRootRole() body could be extracted for evaluation');
    test_summary();
}
$body = $m[1];
eval('function test_is_root_role($role){' . $body . '}');

$rootValues = [
    "'admin'"      => 'admin',
    "'ADMIN'"      => 'ADMIN',
    "' admin '"    => ' admin ',
    '0 (int)'      => 0,
    "'0' (string)" => '0',
];
foreach ($rootValues as $label => $value) {
    assert_true(test_is_root_role($value) === true, "root: $label");
}

// The other half, and the half PHP 7 got wrong. A role that is neither the
// 'admin' sentinel nor numeric zero must not be root -- on PHP 7 every one of
// these was root, because any non-numeric string == 0.
$notRootValues = [
    "'user'"        => 'user',
    "'administrator'" => 'administrator',
    "'root'"        => 'root',
    "'' (empty)"    => '',
    '1 (int)'       => 1,
    "'2' (string)"  => '2',
    'null'          => null,
    'false'         => false,
];
foreach ($notRootValues as $label => $value) {
    assert_true(test_is_root_role($value) === false, "NOT root: $label");
}

// The seeded account must satisfy it, or the product ships locked out of its own
// admin screens -- which is exactly what happened.
$seed = (string) @file_get_contents($root . '/install/sql/seed-admin.sql');
if (preg_match("/'admin'\s*,\s*SHA2/", $seed) === 1 || strpos($seed, "'admin'") !== false) {
    assert_true(test_is_root_role('admin') === true,
        "the role seed-admin.sql writes is accepted as root");
} else {
    assert_true(false, 'seed-admin.sql still seeds a recognisable admin role');
}

test_summary();
