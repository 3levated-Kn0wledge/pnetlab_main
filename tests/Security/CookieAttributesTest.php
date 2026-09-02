<?php
/**
 * Guards the attributes on the `token` cookie.
 *
 * `token` authenticates both halves of this application. Laravel reads it
 * through JwtGuard; the legacy Slim API in api.php reads the same plaintext
 * value (EncryptCookies::$except lists it) and authenticates from it alone.
 * api.php is reached by a rewrite in the root .htaccess and never enters
 * Laravel's kernel, so VerifyCsrfToken cannot protect it. That made
 * SameSite=Lax on this cookie a load-bearing control rather than a nicety.
 *
 * It had already failed silently once. Two call sites passed 'Lax' as the ninth
 * argument to Cookie::make(); six did not, and Cookie::make() defaults
 * $sameSite to null and emits no attribute at all. The first token refresh
 * after login therefore re-issued the cookie without SameSite and re-opened the
 * whole legacy API to a cross-site form POST. Nothing failed. Nothing logged.
 *
 * So this test does not check that the conforming call sites are still
 * conforming -- that would have passed happily the whole time the cookie was
 * broken. It checks that NO NON-CONFORMING CALL SITE EXISTS: the only place in
 * store/app permitted to construct a `token` cookie at all is
 * App\Helpers\Auth\AuthCookie. A ninth issuance site fails this test on the
 * line it is written, whatever attributes it passes.
 *
 * Comments are stripped before the source is examined. DefaultController still
 * quotes an old Cookie::make('token', ...) call in a commented-out block, and a
 * comment is not code -- neither to excuse it, nor to condemn it.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$appDir = $root . '/store/app';
$helperPath = $appDir . '/Helpers/Auth/AuthCookie.php';
$sessionConfig = $root . '/store/config/session.php';

/**
 * The file with every comment removed, so the assertions below only ever see
 * code that runs.
 */
function code_without_comments($path)
{
    $out = '';
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $out .= "\n";
                continue;
            }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
}

/** Every first-party PHP file under store/app. */
function php_files($dir)
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

/*
|--------------------------------------------------------------------------
| 1. Nothing outside the helper constructs a `token` cookie
|--------------------------------------------------------------------------
|
| Every way this codebase has of putting a Set-Cookie on the wire, checked for
| a first argument naming the auth cookie. Cookie::queue() is listed as well as
| Cookie::make() because queue() accepts the same argument list directly.
*/

$constructors = [
    'Cookie::make(',
    'Cookie::queue(',
    'Cookie::forever(',
    'setcookie(',
    'setrawcookie(',
    '->withCookie(',
    '->cookie(',
];

$offenders = [];
$helperReal = realpath($helperPath);
assert_true($helperReal !== false, 'the AuthCookie helper exists at store/app/Helpers/Auth/AuthCookie.php');

foreach (php_files($appDir) as $path) {
    $code = code_without_comments($path);
    foreach ($constructors as $call) {
        $offset = 0;
        while (($pos = strpos($code, $call, $offset)) !== false) {
            $offset = $pos + strlen($call);
            // The first argument, as written. A literal 'token', or a constant
            // that resolves to it, both count.
            $arg = substr($code, $offset, 40);
            $namesToken = preg_match('/^\s*([\'"])token\1/', $arg)
                || preg_match('/^\s*(self|static|AuthCookie|App\\\\Helpers\\\\Auth\\\\AuthCookie)::NAME/', $arg);
            if (!$namesToken) {
                continue;
            }
            if (realpath($path) === $helperReal) {
                continue;
            }
            $line = substr_count(substr($code, 0, $pos), "\n") + 1;
            $offenders[] = str_replace($root . '/', '', $path) . ':' . $line . ' (' . $call . ')';
        }
    }
}

assert_same([], $offenders,
    'the token cookie is constructed nowhere but App\Helpers\Auth\AuthCookie '
    . '(route the new call site through AuthCookie::issue() or ::forget())');

/*
|--------------------------------------------------------------------------
| 2. The helper writes the attributes down correctly
|--------------------------------------------------------------------------
|
| One Cookie::make() call, with SameSite, HttpOnly, and Secure derived from the
| request rather than hardcoded -- the appliance serves plain HTTP by default
| and a literal `true` for Secure would make the cookie undeliverable and lock
| every user out.
*/

$helper = code_without_comments($helperPath);

assert_same(1, substr_count($helper, 'Cookie::make('),
    'the helper builds the cookie in exactly one place');

preg_match('/Cookie::make\((.*?)\);/s', $helper, $m);
$args = array_map('trim', explode(',', $m[1] ?? ''));
assert_same(9, count($args), 'Cookie::make is called with all nine arguments');
assert_same('self::PATH', $args[3] ?? null, 'path is the shared constant');
assert_same('request()->isSecure()', $args[5] ?? null,
    'Secure follows the request, so plain-HTTP appliances still receive the cookie');
assert_same('true', $args[6] ?? null, 'HttpOnly is set');
assert_same('false', $args[7] ?? null, 'the cookie is not raw');
assert_same('self::SAME_SITE', $args[8] ?? null, 'SameSite comes from the constant');

// Behaviour, not text: load the class and read what it will actually pass.
require_once $helperPath;
assert_true(class_exists('App\Helpers\Auth\AuthCookie', false),
    'the helper class is in the namespace its callers import');
assert_same('Lax', \App\Helpers\Auth\AuthCookie::SAME_SITE, 'SameSite is Lax');
assert_same('token', \App\Helpers\Auth\AuthCookie::NAME, 'the helper names the auth cookie');
assert_same('/', \App\Helpers\Auth\AuthCookie::PATH,
    'the cookie is scoped to the document root, which /api and /store both live under');

// forget() must clear more than one scope. The offline path scopes the cookie
// to SERVER_NAME and the online path to APP_DOMAIN; a clearing cookie only
// removes a cookie whose domain matches, so clearing one has no effect on the
// other. Logging out used to clear APP_DOMAIN only, which was a no-op.
assert_true(preg_match('/function\s+forget\s*\(\s*\)\s*\{\s*foreach/', $helper) === 1,
    'forget() clears every scope the token has been issued on, not just one');

/*
|--------------------------------------------------------------------------
| 3. Every path that used to issue the cookie still does, through the helper
|--------------------------------------------------------------------------
|
| Section 1 fails if a call site is added. This fails if one is deleted --
| which would leave a user logged in with no cookie, or logged out with one.
*/

$callers = [
    'store/app/Services/Auth/JwtGuard.php'                 => 3, // refresh, logout, login
    'store/app/Exceptions/Handler.php'                     => 1, // unauthenticated
    'store/app/Helpers/Auth/AuthenticatesUsers.php'        => 1, // logout
    'store/app/Http/Controllers/Admin/DefaultController.php' => 1, // refreshToken
    'store/app/Http/Controllers/Auth/LoginController.php'  => 1, // apiLogin
];

foreach ($callers as $rel => $expected) {
    $code = code_without_comments($root . '/' . $rel);
    $count = preg_match_all('/AuthCookie::(issue|forget)\(/', $code);
    assert_same($expected, $count, "$rel still issues or clears the token cookie, via the helper");
}

/*
|--------------------------------------------------------------------------
| 4. The session configuration carries the attribute too
|--------------------------------------------------------------------------
|
| 'same_site' was null, which emits no attribute. It governs the session cookie
| and -- once VerifyCsrfToken is enabled -- the XSRF-TOKEN cookie that
| addCookieToResponse() issues, so it has to be right before that switch is
| thrown, not after.
|
| The config file is evaluated rather than grepped, with env() stubbed to return
| its default, so this asserts what the application will actually be handed.
*/

if (!function_exists('env')) {
    function env($key, $default = null) { return $default; }
}
if (!function_exists('storage_path')) {
    function storage_path($path = '') { return sys_get_temp_dir() . '/' . $path; }
}
// The 'cookie' entry reads env('SESSION_COOKIE', Str::slug(...)), and PHP
// evaluates that default argument whether or not env() uses it, so the class
// has to exist before the file is required. eval() because a namespaced class
// cannot be declared beside global code in one file, and the house style is one
// self-contained script per test.
if (!class_exists('Illuminate\\Support\\Str')) {
    eval('namespace Illuminate\\Support; class Str {'
        . ' public static function slug($title, $separator = "-") {'
        . '   return trim(strtolower(preg_replace("/[^A-Za-z0-9]++/", $separator, $title)), $separator);'
        . ' } }');
}

$session = require $sessionConfig;

assert_true(is_array($session), 'the session config evaluates to an array');
assert_same('lax', $session['same_site'] ?? null,
    "session.same_site is 'lax' (null emits no attribute at all)");
assert_same(true, $session['http_only'] ?? null,
    'the session cookie is HttpOnly');
assert_same(false, $session['secure'] ?? null,
    'Secure defaults off, because the appliance serves plain HTTP; a TLS-only '
    . 'deployment sets SESSION_SECURE_COOKIE=true in .env');

test_summary();
