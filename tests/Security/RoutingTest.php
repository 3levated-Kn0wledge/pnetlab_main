<?php
/**
 * Guards the auth routing table.
 *
 * store/routes/web.php used to dispatch /auth/{controller}/{method} straight
 * from the URL with no 'auth' middleware, so every public method on every
 * Auth\*Controller -- including inherited ones, and the ones that create
 * accounts -- was callable by an anonymous request. The fix is an explicit
 * list of the endpoints the login flow actually needs.
 *
 * This test asserts three things:
 *   1. no route under /auth takes the controller or the method from the URL;
 *   2. the endpoints the login flow depends on are still published, with the
 *      right verbs -- getting this wrong locks every user out;
 *   3. every public method on LoginController is accounted for, so adding one
 *      cannot silently publish it.
 *
 * Comments are stripped before the source is examined: web.php quotes the old
 * dispatcher in its explanatory header, and a comment is not code.
 *
 * Pass a path as argv[1] to point it at a different web.php (used to confirm
 * it fails against the pre-fix file).
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$webPath = $argv[1] ?? $root . '/store/routes/web.php';
$controllerPath = $root . '/store/app/Http/Controllers/Auth/LoginController.php';
$kernelPath = $root . '/store/app/Http/Kernel.php';

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

$code = code_without_comments($webPath);

/*
|--------------------------------------------------------------------------
| 1. Nothing under /auth is dispatched dynamically
|--------------------------------------------------------------------------
*/

// Every mention of the Auth controller namespace must be a complete literal
// target. If a name is built by concatenation -- '...Auth\\' . ucfirst($x) --
// the literal pattern will not match it and the counts diverge.
$literal = preg_match_all('/Controllers\\\\Auth\\\\(\w+)Controller@(\w+)/', $code, $targets, PREG_SET_ORDER);
$mentions = substr_count($code, 'Controllers\\Auth\\');
assert_same($mentions, $literal, 'every Auth controller target is a literal class@method');

/** @var string[] $registrations Every Route:: call, as (verbs, path). */
$routes = [];
preg_match_all(
    '/Route::(\w+)\(\s*(\[[^\]]*\]\s*,\s*)?[\'"]([^\'"]*)[\'"]/',
    $code,
    $matches,
    PREG_SET_ORDER
);
foreach ($matches as $m) {
    $method = strtolower($m[1]);
    $path = $m[3];
    if ($method === 'redirect') {
        continue; // Route::redirect's second argument is a destination, not a verb.
    }
    if (in_array($method, ['match', 'any'], true)) {
        $verbs = [];
        preg_match_all('/[\'"](\w+)[\'"]/', $m[2], $vm);
        foreach ($vm[1] as $v) {
            $verbs[] = strtolower($v);
        }
        if ($method === 'any') {
            $verbs = ['get', 'post'];
        }
    } else {
        $verbs = [$method];
    }
    sort($verbs);
    $routes[$path] = $verbs;
}

assert_true(count($routes) > 0, 'the route file parsed into routes');

$authRoutes = [];
foreach ($routes as $path => $verbs) {
    if (strpos($path, '/auth/') === 0) {
        $authRoutes[$path] = $verbs;
    }
}

$dynamic = [];
foreach ($authRoutes as $path => $verbs) {
    if (strpos($path, '{') !== false) {
        $dynamic[] = $path;
    }
}
assert_same([], $dynamic, 'no /auth route takes a URL segment as controller or method');

/*
|--------------------------------------------------------------------------
| 2. The login flow is still reachable
|--------------------------------------------------------------------------
|
| Callers were traced in store/resources/react, in the built bundles under
| store/public/react/js, and in LoginController's own redirect() targets.
| Dropping any of these locks users out of the box.
*/

$mustBePublic = [
    // path                        => verbs           // caller
    '/auth/login/initialOffline'   => ['get'],        // LoginController redirects (first boot)
    '/auth/login/manager'          => ['get'],        // helpers/error_helper.js
    '/auth/login/offline'          => ['get'],        // login page
    '/auth/login/login'            => ['post'],       // pages/auth/LoginOffline.js
];
// Phase 05 removed the online login: /auth/login/initial (the mode chooser),
// /auth/login/initialOnline, /auth/login/online (the redirect to
// authen.pnetlab.com) and /auth/login/license (its return leg, and the one
// CSRF exemption). They must not come back; docs/OFFLINE-FIRST.md says why.
foreach (['/auth/login/initial', '/auth/login/initialOnline', '/auth/login/online', '/auth/login/license'] as $gone) {
    assert_true(!isset($authRoutes[$gone]), "the online login endpoint stays unpublished: $gone");
}

foreach ($mustBePublic as $path => $verbs) {
    assert_true(isset($authRoutes[$path]), "login flow endpoint is published: $path");
    if (isset($authRoutes[$path])) {
        assert_same($verbs, $authRoutes[$path], "verbs for $path");
    }
}

// The captcha image the offline login page fetches. It has its own top-level
// route (components/auth/Captcha.js posts to /captcha, not /auth/...), so it
// has to survive the /auth clean-up untouched.
assert_true(isset($routes['/captcha']), 'the captcha endpoint is still published');
assert_same(['post'], $routes['/captcha'] ?? [], 'captcha is POST only');

// Nothing beyond the login flow may appear under /auth.
assert_same([], array_diff(array_keys($authRoutes), array_keys($mustBePublic)),
    'no /auth route beyond the login flow');

// The sibling dispatchers are still dynamic, which is tolerable only because
// they are authenticated. If that middleware is ever dropped they become the
// same hole in a different namespace.
foreach (array_slice(explode('Route::', $code), 1) as $chunk) {
    if (strpos($chunk, '{controller}') === false) {
        continue;
    }
    preg_match('/[\'"]([^\'"]*\{controller\}[^\'"]*)[\'"]/', $chunk, $pm);
    $where = $pm[1] ?? '(unknown)';
    assert_true(strpos($chunk, "middleware('auth')") !== false,
        "dynamic dispatcher is authenticated: $where");
}

/*
|--------------------------------------------------------------------------
| 3. Every public method on LoginController is accounted for
|--------------------------------------------------------------------------
|
| A new public method must be classified here before it can be published --
| which is the whole point of replacing the dynamic dispatcher.
*/

preg_match_all('/^\s*public\s+function\s+(\w+)\s*\(/m', file_get_contents($controllerPath), $pm);
$publicMethods = array_values(array_diff($pm[1], ['__construct']));
sort($publicMethods);

$routedMethods = [];
foreach ($targets as $t) {
    if ($t[1] === 'Login') {
        $routedMethods[] = $t[2];
    }
}
$routedMethods = array_values(array_unique($routedMethods));
sort($routedMethods);

// Methods reachable through the routes above, including captcha via /captcha.
$expectedRouted = ['captcha', 'initialOffline', 'login', 'manager', 'offline'];
sort($expectedRouted);
assert_same($expectedRouted, $routedMethods, 'exactly the intended LoginController methods are routed');

assert_same([], array_diff($publicMethods, $expectedRouted),
    'no public method on LoginController is unclassified (classify it here, then route it or leave it unrouted)');

// Inherited public methods the old dispatcher also exposed. These are not
// declared in LoginController, so the check above cannot see them; name them
// explicitly.
foreach (['logout', 'showLoginForm', 'getMapData', 'callAction', 'getMiddleware',
          'redirectPath'] as $inherited) {
    assert_true(!in_array($inherited, $routedMethods, true),
        "inherited method is not published: $inherited");
}

/*
|--------------------------------------------------------------------------
| 4. CSRF verification is on
|--------------------------------------------------------------------------
|
| This block used to assert only that a *disabled* VerifyCsrfToken carried at
| least five lines of written justification -- the right assertion while the
| middleware was off, and a vacuous one the moment it was switched on. It is
| now the plain fact: the middleware is in the 'web' group as live code.
|
| It stays here as well as in tests/Security/CsrfTest.php because this file is
| the one that reads the routing table, and the two are a pair: the middleware
| never sees a GET, so enabling it is only half a defence without the verb split
| on the dynamic dispatchers. CsrfTest.php asserts the other half, and the
| reasoning is recorded in store/app/Http/Kernel.php.
*/

$kernelLines = file($kernelPath);
$csrfEnabled = false;
foreach ($kernelLines as $line) {
    if (preg_match('/^\s*\\\\App\\\\Http\\\\Middleware\\\\VerifyCsrfToken::class,/', $line)) {
        $csrfEnabled = true;
    }
}

assert_true($csrfEnabled,
    "VerifyCsrfToken is enabled in the 'web' middleware group");

test_summary();
