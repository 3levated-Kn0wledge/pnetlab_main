<?php
/**
 * Guards the CSRF defence on the legacy API.
 *
 * The root .htaccess rewrites /api/* to api.php, a standalone Slim 2.6.1
 * application that never enters Laravel's kernel. VerifyCsrfToken therefore
 * cannot protect any of its eighteen routes however it is configured, and each
 * one authenticates from the `token` cookie and nothing else -- no header, no
 * nonce. Every POST handler json_decode()s the raw request body without looking
 * at Content-Type, so a cross-site <form> POST is a "simple request" that
 * reaches the handler and dispatches; roughly fifty mutating actions sit behind
 * /api/labs/session/(:object)/(:action) alone. Until includes/api_origin_guard.php
 * the only thing in the way was a cookie attribute.
 *
 * The rules are pure functions precisely so this test can exercise the decision
 * rather than assert that a string appears in a file. The table below is the
 * specification: read it, not the implementation.
 *
 * The one case that is easy to get backwards, and that the table pins from both
 * sides: a request with NO Origin and NO Referer is ALLOWED. curl, scripts and
 * tools/integration/lab-functional.sh send neither, and a missing Origin is not
 * a CSRF signal -- only a mismatched one is. Every browser that can be made to
 * issue a cross-site POST sends one of the two headers, including the
 * no-referrer and sandboxed-iframe cases, which send the literal "null".
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$guardPath = $root . '/includes/api_origin_guard.php';
$apiPath = $root . '/api.php';

assert_true(is_file($guardPath), 'the origin guard exists at includes/api_origin_guard.php');
require_once $guardPath;

/**
 * The file with every comment removed, so the assertions below only ever see
 * code that runs. api.php quotes URLs and verbs in its explanatory comments,
 * and a comment is not code.
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

/*
|--------------------------------------------------------------------------
| 1. The decision
|--------------------------------------------------------------------------
|
| [expect, method, Origin, Referer, Host, Content-Type, body length, why]
| 'allow' means apiCsrfVerdict() returns null; 'deny' means it returns a reason.
*/

$host = 'pnetlab.test';
$cases = [
    // -- safe verbs are never policed ------------------------------------
    ['allow', 'GET',    'http://evil.example', '', $host, '', 0,
     'GET is not policed; the one mutating GET was moved to POST instead'],
    ['allow', 'HEAD',   'http://evil.example', '', $host, '', 0, 'HEAD is not policed'],
    ['allow', 'OPTIONS','http://evil.example', '', $host, '', 0, 'OPTIONS is not policed'],

    // -- no browsing context declared: not a browser, not CSRF -----------
    ['allow', 'POST',   '', '', $host, 'application/json', 42,
     'a POST with neither Origin nor Referer is a script, and is allowed'],
    ['allow', 'DELETE', '', '', $host, 'application/json', 20,
     'DELETE from a script is allowed'],
    ['allow', 'POST',   '', '', $host, '', 0,
     'a bare POST with no headers at all is allowed'],

    // -- Origin present: it must be us ----------------------------------
    ['allow', 'POST', 'http://pnetlab.test',      '', $host, 'application/json', 42, 'same origin'],
    ['allow', 'POST', 'https://pnetlab.test',     '', $host, 'application/json', 42,
     'scheme is not compared: the appliance is commonly fronted by TLS'],
    ['allow', 'POST', 'http://pnetlab.test:8080', '', $host, 'application/json', 42,
     'port is not compared: HTTP_HOST and Origin disagree behind a proxy'],
    ['allow', 'POST', 'http://PNETLAB.TEST',      '', $host, 'application/json', 42,
     'hostnames compare case-insensitively'],
    ['allow', 'POST', 'http://pnetlab.test.',     '', $host, 'application/json', 42,
     'a fully qualified trailing dot is the same host'],
    ['deny',  'POST', 'http://evil.example',      '', $host, 'application/json', 42,
     'a foreign Origin is refused'],
    ['deny',  'POST', 'http://pnetlab.test.evil.example', '', $host, 'application/json', 42,
     'a suffix that merely contains our name is refused'],
    ['deny',  'POST', 'http://evil.pnetlab.test', '', $host, 'application/json', 42,
     'a sibling subdomain is refused -- stricter than SameSite, which compares '
     . 'registrable domains and would let this through'],
    ['deny',  'POST', 'null',                     '', $host, 'application/json', 42,
     'the opaque origin (no-referrer, sandboxed iframe, data: document) is refused'],

    // -- Referer is the fallback, and Origin wins when both are present --
    ['allow', 'POST', '', 'http://pnetlab.test/lab_edit.php?path=/a.unl', $host, 'application/json', 42,
     'a matching Referer is accepted when Origin is absent'],
    ['deny',  'POST', '', 'http://evil.example/attack.html', $host, 'application/json', 42,
     'a foreign Referer is refused when Origin is absent'],
    ['allow', 'POST', 'http://pnetlab.test', 'http://evil.example/x', $host, 'application/json', 42,
     'Origin is authoritative when both are present'],
    ['deny',  'POST', 'http://evil.example', 'http://pnetlab.test/x', $host, 'application/json', 42,
     'a bad Origin is not rescued by a good Referer'],

    // -- every mutating verb, not just POST ------------------------------
    ['deny',  'PUT',    'http://evil.example', '', $host, 'application/json', 10, 'PUT is policed'],
    ['deny',  'PATCH',  'http://evil.example', '', $host, 'application/json', 10, 'PATCH is policed'],
    ['deny',  'DELETE', 'http://evil.example', '', $host, 'application/json', 10, 'DELETE is policed'],
    ['deny',  'post',   'http://evil.example', '', $host, 'application/json', 10,
     'the verb comparison is case-insensitive'],

    // -- the host must be identifiable -----------------------------------
    ['deny',  'POST', 'http://pnetlab.test', '', '', 'application/json', 42,
     'if the request names no Host there is nothing to match against, so refuse'],

    // -- body encoding ----------------------------------------------------
    ['allow', 'POST', '', '', $host, 'application/json; charset=utf-8', 42,
     'Content-Type parameters are ignored'],
    ['allow', 'POST', '', '', $host, 'APPLICATION/JSON', 42, 'media types compare case-insensitively'],
    ['allow', 'POST', '', '', $host, 'application/vnd.api+json', 42, 'a +json suffix is JSON'],
    ['allow', 'POST', '', '', $host, 'multipart/form-data; boundary=x', 900,
     '/api/import and /api/labs/session/pictures/add read $_FILES and must keep working'],
    ['allow', 'POST', '', '', $host, '', 42, 'no Content-Type at all is allowed; a form always sets one'],
    ['allow', 'POST', '', '', $host, 'application/x-www-form-urlencoded', 0,
     'an empty body carries no encoding to object to'],
    ['deny',  'POST', '', '', $host, 'application/x-www-form-urlencoded', 42,
     'the form encoding is refused: no first-party client sends it and a '
     . 'cross-site form can'],
    ['deny',  'POST', '', '', $host, 'text/plain', 42,
     'text/plain is refused: it is the JSON-smuggling enctype'],
    ['deny',  'POST', 'http://pnetlab.test', '', $host, 'text/plain', 42,
     'a same-origin request is still held to the encoding rule'],
];

foreach ($cases as $case) {
    list($expect, $method, $origin, $referer, $reqHost, $contentType, $length, $why) = $case;
    $verdict = apiCsrfVerdict($method, $origin, $referer, $reqHost, $contentType, $length);
    $actual = $verdict === null ? 'allow' : 'deny';
    assert_same($expect, $actual, "$method: $why");
}

/*
|--------------------------------------------------------------------------
| 2. The guard is wired in, once, ahead of the routes
|--------------------------------------------------------------------------
|
| A hook on 'slim.before.router' is the only choke point in api.php that runs
| for every request without touching the eighteen handlers -- which is what
| makes a route added tomorrow guarded by default.
*/

$api = code_without_comments($apiPath);

assert_same(1, preg_match_all('/apiRegisterOriginGuard\(\$app\)\s*;/', $api),
    'api.php registers the guard exactly once');
assert_true(strpos($api, "require_once(BASE_DIR . '/html/includes/api_origin_guard.php');") !== false,
    'api.php requires the guard alongside the other api_* includes');

$registered = strpos($api, 'apiRegisterOriginGuard($app)');
preg_match('/\$app->(get|post|put|patch|delete|map)\(/', $api, $rm, PREG_OFFSET_CAPTURE);
assert_true($registered !== false && isset($rm[0][1]) && $registered < $rm[0][1],
    'the guard is registered before the first route, so no route can be added above it');

// The hook name is the load-bearing detail: 'slim.before' fires outside the
// output buffer Slim\Slim::call() opens, and halt() from there does not unwind
// cleanly.
$guard = code_without_comments($guardPath);
assert_true(strpos($guard, "'slim.before.router'") !== false,
    "the guard hooks 'slim.before.router', inside the buffer call() opens");
assert_true(preg_match('/halt\(\s*403/', $guard) === 1,
    'a refused request gets 403, in the JSON envelope the rest of the API uses');

/*
|--------------------------------------------------------------------------
| 3. Every mutating verb api.php actually registers is policed
|--------------------------------------------------------------------------
|
| Not a fixed list: the verbs are read back out of api.php. Adding
| $app->put(...) to a guard that does not know about PUT fails here.
*/

preg_match_all('/\$app->(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]/', $api, $routes, PREG_SET_ORDER);
assert_true(count($routes) > 0, 'the route table parsed');

$guarded = array_map('strtoupper', apiGuardedMethods());
$safe = ['GET', 'HEAD'];
$mutating = 0;
foreach ($routes as $r) {
    $verb = strtoupper($r[1]);
    if (in_array($verb, $safe, true)) {
        continue;
    }
    $mutating++;
    assert_true(in_array($verb, $guarded, true),
        "the guard policies $verb, used by $r[2]");
}

// Pinned so that a new mutating route is a deliberate act with a test to
// update, and so a reviewer is told how much surface is behind the guard.
assert_same(11, $mutating, 'api.php registers eleven mutating routes (ten POST, one DELETE)');
assert_same(18, count($routes), 'api.php registers eighteen routes in total');

/*
|--------------------------------------------------------------------------
| 4. /api/auth/logout is POST, and every caller agrees
|--------------------------------------------------------------------------
|
| It mutates -- apiLogout() invalidates the server-side session -- and a
| SameSite=Lax cookie rides along on a top-level GET navigation, so while this
| was a GET any page on the internet could log the user out with a link. The
| built bundles are checked as well as their sources: store/public/react/js is
| what is actually served, and rebuilding it needs a node toolchain that a fresh
| clone does not have.
*/

$logoutVerbs = [];
foreach ($routes as $r) {
    if ($r[2] === '/api/auth/logout') {
        $logoutVerbs[] = strtolower($r[1]);
    }
}
assert_same(['post'], $logoutVerbs, '/api/auth/logout is registered POST only');

$callers = [
    'store/resources/react/components/menu/Logout.js',
    'themes/default/js/functions.js',
    'store/public/react/js/app.js',
    'store/public/react/js/main.js',
];
foreach ($callers as $rel) {
    $src = file_get_contents($root . '/' . $rel);
    assert_true(strpos($src, '/api/auth/logout') !== false, "$rel still calls the logout endpoint");
    // The verb sits within a short distance of the URL in every one of these,
    // minified or not. Any surviving 'get' beside it means a caller was missed.
    preg_match_all('~/api/auth/logout(.{0,60})~s', $src, $near, PREG_SET_ORDER);
    foreach ($near as $n) {
        assert_true(preg_match('/["\']get["\']|=\s*[\'"]GET[\'"]/i', $n[1]) === 0,
            "$rel does not ask for the logout endpoint by GET");
    }
}

test_summary();
