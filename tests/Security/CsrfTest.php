<?php
/**
 * Guards the CSRF defence on the Laravel half of the application.
 *
 * VerifyCsrfToken was commented out of the 'web' middleware group, with the
 * reason recorded in store/app/Http/Kernel.php. Switching it back on is one
 * line, and on its own it would have been a partial fix that read as a complete
 * one -- which is the specific failure this file exists to prevent recurring.
 *
 * The reason is a verb problem, not a token problem. The three dispatchers in
 * store/routes/web.php take the controller AND the method out of the URL and
 * accepted both verbs for all of it: 157 dispatchable controller methods, of
 * which 39 called Checker::method('post') and 118 did not. VerifyCsrfToken only
 * verifies POST/PUT/PATCH/DELETE, and SameSite=Lax -- the only thing standing
 * in front of any of this -- explicitly DOES send the cookie on top-level GET
 * navigation. So `location = 'http://box/store/public/admin/labs/drop?id=...'`
 * worked, and enabling the middleware would not have changed that by one byte
 * while making a test suite look green.
 *
 * The fix is default-deny at the router: config/readonly_actions.php names the
 * actions a browser may reach with GET, Checker::action() refuses every other
 * GET with the same reply Checker::method('post') has always produced, and the
 * dispatchers call it before App::call().
 *
 * The assertions, and what each one would catch:
 *
 *   1. VerifyCsrfToken is in the 'web' group, uncommented, before
 *      SubstituteBindings.                     -- catches it being switched off again
 *   2. $except contains exactly 'auth/login/license', and every entry carries a
 *      comment.                                -- catches $except as an escape hatch
 *   3. All three dispatchers call Checker::action() before App::call(), and
 *      Checker::action() still fails closed.   -- catches the guard being lifted
 *   4. No action reachable by GET can change state.  -- THE ONE THAT MATTERS
 *   5. The front end still carries the token, and CKEditor is still dormant.
 *
 * Assertion 4 is deliberately NOT a pinned count of methods. A count says
 * "someone added a method" and gets updated without thought. This says "an
 * action you can reach with GET calls something that writes", which is the
 * property, and it is satisfied for free by any new method -- because the
 * router defaults to POST-only, a method added tomorrow is safe without anyone
 * remembering anything. What it catches is the deliberate act: putting a
 * mutating action on the read-only list.
 *
 * Comments are stripped with token_get_all() before any PHP source is examined,
 * so commented-out code cannot satisfy an assertion. web.php, Kernel.php and
 * VerifyCsrfToken.php all quote code in their explanatory blocks.
 *
 * Pass a tree root as argv[1] to point it at a different checkout -- used to
 * confirm it fails against the pre-change files.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = isset($argv[1]) ? realpath($argv[1]) : realpath(__DIR__ . '/../..');

$kernelPath     = $root . '/store/app/Http/Kernel.php';
$csrfPath       = $root . '/store/app/Http/Middleware/VerifyCsrfToken.php';
$webPath        = $root . '/store/routes/web.php';
$checkerPath    = $root . '/store/app/Helpers/Request/Checker.php';
$readOnlyPath   = $root . '/store/config/readonly_actions.php';
$controllersDir = $root . '/store/app/Http/Controllers';
$reactDir       = $root . '/store/resources/react';

/**
 * The file with every comment removed, so the assertions below only ever see
 * code that runs.
 */
function code_without_comments($path)
{
    if (!is_file($path)) {
        return '';
    }
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
| 1. The middleware is enabled
|--------------------------------------------------------------------------
*/

$kernel = code_without_comments($kernelPath);

$webGroup = '';
if (preg_match("/'web'\s*=>\s*\[(.*?)\]\s*,/s", $kernel, $m)) {
    $webGroup = $m[1];
}
assert_true($webGroup !== '', "the 'web' middleware group is readable in Kernel.php");

assert_true(strpos($webGroup, 'VerifyCsrfToken::class') !== false,
    "VerifyCsrfToken is in the 'web' middleware group as live code, not a comment");

$csrfPos = strpos($webGroup, 'VerifyCsrfToken::class');
$bindPos = strpos($webGroup, 'SubstituteBindings::class');
assert_true($csrfPos !== false && $bindPos !== false && $csrfPos < $bindPos,
    'VerifyCsrfToken runs before SubstituteBindings, where Laravel ships it');

/*
|--------------------------------------------------------------------------
| 2. $except is minimal, and every survivor is explained
|--------------------------------------------------------------------------
|
| Each entry would be an unauthenticated write. 'auth/login/license' used to
| earn its place: APP_AUTHEN redirected the browser back to it from a server
| this box did not control, so no token this box issued could be on that
| request. Phase 05 removed the online login, and with it the last entry.
| Everything else is same-origin JavaScript, and axios already sends the
| token -- so the list is empty, and a new entry is a hole to justify.
*/

$csrfCode = code_without_comments($csrfPath);
$exceptEntries = [];
if (preg_match('/\$except\s*=\s*\[(.*?)\]\s*;/s', $csrfCode, $m)) {
    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $m[1], $e);
    $exceptEntries = $e[1];
}
assert_same([], $exceptEntries,
    'VerifyCsrfToken::$except is empty: the online login that needed an exemption is gone');

// The removal of 'admin/box/*' rests on there being no such controller. Assert
// the fact, not the absence of the string, so that adding an Admin\BoxController
// re-opens the question here rather than silently inheriting an exemption.
assert_true(!is_file($controllersDir . '/Admin/BoxController.php'),
    'there is still no Admin\\BoxController (the class admin/box/* was exempting)');

// Every entry has a written reason immediately above it.
$csrfLines = is_file($csrfPath) ? file($csrfPath) : [];
foreach ($exceptEntries as $entry) {
    $explained = false;
    foreach ($csrfLines as $n => $line) {
        if (strpos($line, "'" . $entry . "'") === false) continue;
        $explained = isset($csrfLines[$n - 1]) && strpos(ltrim($csrfLines[$n - 1]), '//') === 0;
    }
    assert_true($explained, "the \$except entry '$entry' carries a comment saying why");
}

/*
|--------------------------------------------------------------------------
| 3. The verb split is wired into all three dispatchers
|--------------------------------------------------------------------------
*/

$web = code_without_comments($webPath);

assert_true(strpos($web, 'use App\Helpers\Request\Checker;') !== false,
    'web.php imports Checker');

foreach (['admin', 'user', 'notice'] as $group) {
    // The guard has to be inside the closure and before App::call, or the
    // controller runs first and the guard is decoration.
    $pattern = '/Route::match\(\s*\[[^\]]*\]\s*,\s*[\'"]\/' . $group . '\/\{controller\}\/\{method\}[\'"].*?'
             . 'Checker::action\(\s*[\'"]' . $group . '[\'"]\s*,\s*\$controller\s*,\s*\$method\s*\)\s*;.*?'
             . 'App::call/s';
    assert_true(preg_match($pattern, $web) === 1,
        "the /$group dispatcher calls Checker::action() before App::call()");
}

$checker = code_without_comments($checkerPath);
assert_true(strpos($checker, 'function action(') !== false,
    'Checker::action() exists');

// Fails closed: the only ways out are POST, or membership of the list. Anything
// else falls through to method('post'), which is the refusal.
if (preg_match('/function action\((.*?)\n    \}/s', $checker, $m)) {
    $body = $m[1];
    assert_true(preg_match("/isMethod\(\s*'post'\s*\)/", $body) === 1,
        'Checker::action() lets POST through (VerifyCsrfToken verifies it)');
    assert_true(strpos($body, 'readOnlyActions()') !== false,
        'Checker::action() decides from the read-only list');
    assert_true(preg_match("/return self::method\(\s*'post'\s*\)/", $body) === 1,
        'Checker::action() refuses anything else with the existing method() guard');
    assert_same(2, substr_count($body, 'return true'),
        'Checker::action() has exactly two ways to pass: POST, and the list');
} else {
    assert_true(false, 'Checker::action() body is readable');
}

/*
|--------------------------------------------------------------------------
| 4. Nothing reachable by GET can change state
|--------------------------------------------------------------------------
*/

assert_true(is_file($readOnlyPath), 'store/config/readonly_actions.php exists');
$readOnly = is_file($readOnlyPath) ? (array) (include $readOnlyPath) : [];
$readOnly = array_map('strtolower', array_values($readOnly));
assert_true(count($readOnly) > 0,
    'the read-only list is populated (an empty list would make every check below vacuous)');

/**
 * Every method a dispatcher can reach, as
 * 'group/controller/method' => body-with-comments-stripped.
 *
 * Private and protected methods are excluded: App::call() cannot reach them.
 * Methods declared with no visibility keyword ARE included -- PHP defaults them
 * to public, and Admin\StatusController::apiSetKsm(), which shells out to
 * unl_wrapper, is written that way. An enumeration that only looked for
 * 'public function' would have missed it.
 */
function dispatchable_methods($controllersDir)
{
    $found = [];
    foreach (['Admin' => 'admin', 'User' => 'user', 'Notice' => 'notice'] as $dir => $group) {
        foreach ((array) glob($controllersDir . '/' . $dir . '/*Controller.php') as $file) {
            $src = code_without_comments($file);
            $controller = strtolower(basename($file, 'Controller.php'));
            $n = preg_match_all(
                '/^[ \t]*((?:(?:public|protected|private|static|final|abstract)[ \t]+)*)function[ \t]+&?[ \t]*(\w+)[ \t]*\(/m',
                $src, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            for ($i = 0; $i < $n; $i++) {
                $modifiers = $matches[$i][1][0];
                $name = $matches[$i][2][0];
                $start = $matches[$i][0][1];
                $end = ($i + 1 < $n) ? $matches[$i + 1][0][1] : strlen($src);
                if ($name === '__construct') continue;
                if (preg_match('/\b(private|protected)\b/', $modifiers)) continue;
                $found[$group . '/' . $controller . '/' . strtolower($name)] = substr($src, $start, $end - $start);
            }
        }
    }
    return $found;
}

$methods = dispatchable_methods($controllersDir);
assert_true(count($methods) > 100,
    sprintf('the controller sweep found the dispatchable methods (%d)', count($methods)));

/**
 * Calls that change something: the database, the filesystem, the host, the
 * store, or the caller's identity. Not exhaustive by construction -- it does
 * not need to be. It only has to cover what the ~20 actions on the read-only
 * list actually do, and it fails loudly when one of them starts doing more.
 */
$mutators = [
    '->add(', '->edit(', '->drop(', '->update(', '->delete(', '->save(',
    '->create(', '->insert(', '->destroy(',
    'exec(', 'shell_exec', 'passthru(', 'proc_open(', 'system(', 'popen(',
    'unlink(', 'rmdir(', 'mkdir(', 'chmod(', 'chown(', 'rename(', 'touch(',
    'file_put_contents(', 'move_uploaded_file(', 'fwrite(', 'fopen(',
    'Ctrl::set(', 'Cookie::', 'AuthCookie::', 'DB::', 'Auth::login', 'Auth::logout',
    'Query::center(', 'Query::boxCenter(', 'Query::make(', 'Query::setProxy(',
    'License::',
];

/**
 * The state changes that are accepted on a GET, with the reason, and pinned to
 * the exact calls that are accepted. Anything else in these methods, or any new
 * entry here, is a decision someone has to make on purpose.
 *
 * An earlier revision of this list accepted 'License::' on three page renders,
 * for a ?relicense=1 query parameter. That was wrong, and this test is what
 * let it through: License::relicense() POSTs to the central server and writes
 * USER_LICENSE to the user row, which is state, and SameSite=Lax made it
 * reachable from a bare `location =` on any site. The parameter is gone from
 * all three; the trigger is admin/default/relicense, POST-only. Nothing that
 * reaches an external service or writes a row belongs on this list again.
 *
 * What remains is the session keep-alive: it re-issues the CALLER's token
 * cookie and stamps the CALLER's online time. Triggered cross-origin it
 * extends the victim's own session by exactly what the victim's own page load
 * would. No data is read back and no other row is written.
 */
$acceptedGetSideEffects = [
    'admin/default/refreshtoken' => ['->edit(', 'Cookie::', 'AuthCookie::'],
];
$neverAcceptedOnGet = ['License::', 'Query::center(', 'Query::boxCenter(', 'Query::make(',
    'exec(', 'shell_exec', 'passthru(', 'proc_open(', 'system(', 'popen('];

foreach ($readOnly as $action) {
    assert_true(isset($methods[$action]),
        "read-only list entry '$action' names a real controller method");
    if (!isset($methods[$action])) continue;

    $body = $methods[$action];

    // A method that guards itself has already made the decision per action --
    // admin/labs/uploader serves <img src="...?action=Read"> on GET and calls
    // Checker::method('post') for Upload, Delete and History.
    if (preg_match("/Checker::method\(\s*'post'\s*\)/", $body)) {
        assert_true(true, "GET-reachable '$action' guards its mutating branches itself");
        continue;
    }

    $hits = [];
    foreach ($mutators as $needle) {
        if (strpos($body, $needle) !== false) $hits[] = $needle;
    }

    if (isset($acceptedGetSideEffects[$action])) {
        $accepted = $acceptedGetSideEffects[$action];
        sort($accepted); sort($hits);
        assert_same($accepted, $hits,
            "GET-reachable '$action' still does only its accepted side effects");
        assert_same([], array_values(array_intersect($hits, $neverAcceptedOnGet)),
            "and none of them reaches a shell or an external service");
        continue;
    }

    assert_same([], $hits, "GET-reachable '$action' changes no state");
}

// The parameter itself must not come back under another name: no allowlisted
// method reads a request input and branches to a mutator on it.
foreach ($readOnly as $action) {
    if (!isset($methods[$action])) continue;
    assert_true(strpos($methods[$action], "'relicense'") === false,
        "GET-reachable '$action' no longer looks for ?relicense");
}

// A route that serves GET straight into a controller, outside the three guarded
// dispatchers, would bypass Checker::action() entirely. /admin/default/initial
// and /admin/default/language are registered that way (a <script src> and the
// language fetch), so they are held to the same list.
preg_match_all(
    '/Route::(match\(\s*(\[[^\]]*\])\s*,|get\(|post\(|any\()\s*[\'"]([^\'"]+)[\'"]/',
    $web, $routeMatches, PREG_SET_ORDER);
foreach ($routeMatches as $r) {
    $verbs = isset($r[2]) && $r[2] !== '' ? strtolower($r[2]) : strtolower($r[1]);
    $path = ltrim($r[3], '/');
    if (!preg_match('#^(admin|user|notice)/#', $path)) continue;
    if (strpos($path, '{controller}') !== false) continue;   // the guarded dispatchers
    if (strpos($verbs, 'get') === false && strpos($verbs, 'any') === false) continue;
    assert_true(in_array(strtolower($path), $readOnly, true),
        "the standalone GET route '$path' is on the read-only list");
}

/*
|--------------------------------------------------------------------------
| 5. The front end carries the token, and CKEditor is still dormant
|--------------------------------------------------------------------------
|
| No front-end change was needed for any of this, which is a claim worth
| pinning rather than repeating. axios attaches X-XSRF-TOKEN from the
| XSRF-TOKEN cookie on same-origin requests, with no configuration; the theme's
| jQuery never touches a Laravel route; and a missed token surfaces as a bounce
| to the login page rather than a silent failure.
|
| That claim survived the 0.19 -> 1.20 upgrade, but the assertion that used to
| carry it did not. It looked for the strings xsrfCookieName:"XSRF-TOKEN" and
| xsrfHeaderName:"X-XSRF-TOKEN", which are still the defaults in 1.x and are
| still in the bundle -- and which no longer decide anything on their own. 1.x
| gained a `withXSRFToken` option when it fixed GHSA-wf5p-g6vw-rhxx, and it is
| that option, not the cookie name, that says whether the header is attached.
| So the old assertion would have passed against a bundle that sends no token
| at all. What is pinned below is the DECISION, not the names it decides with.
*/

$bundles = (array) glob($root . '/store/public/react/js/*.js');
assert_true(count($bundles) > 0, 'the built bundles are present to check');

/**
 * The three things that have to be true of a shipped bundle for a POST from
 * this application to carry a token, each keyed to the minified shape the
 * pinned toolchain (webpack 4 + terser) emits.
 *
 * A miss here is not a style nit. If the first fails the SPA does not render
 * at all; if the second fails every POST in the admin UI is refused with 419
 * and it reads as a CSRF bug rather than as a dependency change.
 */
$bundleChecks = [
    // window.axios = axiosModule.default || axiosModule
    //
    // 107 of the 109 front-end files reach axios through this global. 1.x
    // resolves to an ES module under webpack 4 (which predates the exports
    // map), so a bare require() yields the namespace object and .request is
    // undefined -- app.js then dies at module scope and #app stays empty.
    'the global axios is the callable instance, not the ES module namespace'
        => '/window\.axios=(\w+)\.default\|\|\1/',

    // resolveConfig(): !0===w || null==w && isURLSameOrigin(config.url)
    //
    // Unset (the second clause) is what this application relies on and is what
    // 0.19 did too. `!0===w` alone would mean someone set withXSRFToken:true,
    // which sends the token to ANY origin and reopens the advisory.
    'the bundle still gates the XSRF header on withXSRFToken-or-same-origin'
        => '/!0===(\w+)\|\|null==\1&&\w+\(\w+\.url\)/',

    // ...and having decided, it reads the cookie and sets the header.
    'the bundle reads the XSRF cookie and sets it as a request header'
        => '/\.read\(\w+\);\w+&&\w+\.set\(/',
];

foreach ($bundleChecks as $what => $pattern) {
    $hits = 0;
    foreach ($bundles as $b) {
        if (preg_match($pattern, (string) file_get_contents($b))) $hits++;
    }
    assert_same(count($bundles), $hits, $what);
}

// The names are still asserted -- they are what the middleware reads -- but as
// a supporting fact rather than as the proof.
$withXsrf = 0;
foreach ($bundles as $b) {
    $s = (string) file_get_contents($b);
    if (strpos($s, 'xsrfCookieName:"XSRF-TOKEN"') !== false
        && strpos($s, 'xsrfHeaderName:"X-XSRF-TOKEN"') !== false) {
        $withXsrf++;
    }
}
assert_same(count($bundles), $withXsrf,
    'every shipped bundle carries axios with the XSRF-TOKEN -> X-XSRF-TOKEN defaults');

/**
 * The committed bundles are what a deployed install serves -- the installer
 * never builds a frontend (docs/LICENSING.md, gap G2) -- so a package.json
 * that has moved on from the bundles is a real divergence, not bookkeeping.
 * Pin the line rather than the exact version: 0.19 predates every fix below,
 * and `withXSRFToken` does not exist before 1.6.2 on the 1.x line.
 */
$pkg = json_decode((string) @file_get_contents($root . '/package.json'), true);
$axiosRange = isset($pkg['devDependencies']['axios'])
    ? $pkg['devDependencies']['axios']
    : (isset($pkg['dependencies']['axios']) ? $pkg['dependencies']['axios'] : '');
assert_true($axiosRange !== '' && preg_match('/^\^?[1-9]/', $axiosRange) === 1,
    "package.json pins axios off the 0.19 line (got '$axiosRange')");

foreach ($bundles as $b) {
    assert_true(strpos((string) file_get_contents($b), 'withXSRFToken') !== false,
        basename($b) . ' was built from an axios that has withXSRFToken (>= 1.6.2)');
}


// Nothing may override those defaults; doing so is how the implicit path breaks.
$overrides = [];
$walk = function ($dir) use (&$walk) {
    $out = [];
    foreach ((array) scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        if (is_dir($p)) { $out = array_merge($out, $walk($p)); continue; }
        if (substr($e, -3) === '.js') $out[] = $p;
    }
    return $out;
};
$reactFiles = is_dir($reactDir) ? $walk($reactDir) : [];
assert_true(count($reactFiles) > 50,
    sprintf('the React source sweep found the front end (%d files)', count($reactFiles)));

foreach ($reactFiles as $p) {
    $s = (string) file_get_contents($p);
    foreach (explode("\n", $s) as $line) {
        if (strpos(ltrim($line), '//') === 0) continue;
        if (strpos($line, 'xsrfCookieName') !== false || strpos($line, 'xsrfHeaderName') !== false) {
            $overrides[] = $p;
        }
    }
}
assert_same([], array_values(array_unique($overrides)),
    'nothing in the front end overrides axios\'s XSRF cookie/header names');

// Setting withXSRFToken:true attaches the token to EVERY origin, which is the
// leak GHSA-wf5p-g6vw-rhxx is about; setting it false turns the mechanism off
// and every POST becomes a 419. Neither belongs here -- leaving it unset is
// what gives the same-origin behaviour 0.19 had, and it is what was measured.
$xsrfOptOverrides = [];
foreach ($reactFiles as $p) {
    foreach (explode("\n", (string) file_get_contents($p)) as $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '*' || strpos($t, '//') === 0 || strpos($t, '/*') === 0) continue;
        if (strpos($line, 'withXSRFToken') !== false) $xsrfOptOverrides[] = $p;
    }
}
assert_same([], array_values(array_unique($xsrfOptOverrides)),
    'nothing in the front end sets withXSRFToken (unset is the same-origin default)');

/**
 * The JavaScript equivalent of code_without_comments(), and it is needed for
 * the same reason: this house writes down what it removed, and the note in
 * error_helper.js explaining why `error.name == 'Error'` had to go would
 * otherwise satisfy the assertion that it is gone. (Handover trap 6.)
 *
 * Line-oriented, matching the sweeps above -- enough for a file whose comments
 * are all whole lines, and it does not pretend to tokenize JavaScript.
 */
function js_code_only($src)
{
    $out = [];
    $inBlock = false;
    foreach (explode("\n", $src) as $line) {
        $t = ltrim($line);
        if ($inBlock) {
            if (strpos($t, '*/') !== false) $inBlock = false;
            continue;
        }
        if (strpos($t, '/*') === 0) {
            if (strpos($t, '*/') === false) $inBlock = true;
            continue;
        }
        if ($t === '' || $t[0] === '*' || strpos($t, '//') === 0) continue;
        $out[] = $line;
    }
    return implode("\n", $out);
}

// A 419 has to be recoverable, not a dead page.
$errorHelper = js_code_only((string) @file_get_contents($reactDir . '/helpers/error_helper.js'));
assert_true(strpos($errorHelper, '419') !== false && strpos($errorHelper, 'auth/login/manager') !== false,
    'error_helper.js still turns a 419 into a bounce to the login page');

/**
 * ...and it has to still REACH that 419.
 *
 * error_handle() reads the status off the error axios rejects with. It used to
 * find it by testing `error.name == 'Error'`, which held only because 0.x built
 * its rejection with enhanceError(new Error(...)). 1.x rejects with an
 * AxiosError, name 'AxiosError', so that test stopped matching and a 419 fell
 * through to a toast -- measured, on this host, against a bundle built with the
 * upgrade but without this fix: the browser stayed on the admin page instead of
 * bouncing. Nothing throws, so only an assertion catches it.
 *
 * `response` is the discriminator axios documents and it is the same on both
 * lines. Assert against the SOURCE and the BUNDLE: the bundles are committed
 * output that a deployed install serves directly, so a fixed source with a
 * stale bundle is still a broken box.
 */
assert_true(preg_match('/isset\(\s*error\.response\s*\)/', $errorHelper) === 1,
    'error_helper.js keys the status off error.response, not off the error\'s name');
assert_true(preg_match('/\berror\.name\s*==/', $errorHelper) === 0,
    'error_helper.js no longer discriminates on the axios error\'s name');

foreach ($bundles as $b) {
    $s = (string) file_get_contents($b);
    assert_true(preg_match('/isset\((\w+)\.response\)&&isset\(\1\.response\.status\)/', $s) === 1,
        basename($b) . ' carries the response-shaped error test, so a 419 still bounces');
}

// The theme's jQuery is the code path axios does not cover. It may reach the
// legacy API, which has its own guard (includes/api_origin_guard.php), but it
// must not reach a Laravel route -- there is no $.ajaxSetup wiring a token.
// The one exception is a <script src>, which is a GET.
$themeHits = [];
$themeFiles = is_dir($root . '/themes') ? $walk($root . '/themes') : [];
foreach (array_merge($themeFiles, (array) glob($root . '/themes/*/*.html')) as $p) {
    $s = (string) file_get_contents($p);
    if (preg_match_all('#[\'"](?:/store/public)?/(?:admin|user|notice)/[A-Za-z_0-9]+/[A-Za-z_0-9]+[^\'"]*[\'"]#', $s, $mm)) {
        foreach ($mm[0] as $hit) $themeHits[] = trim($hit, '\'"');
    }
}
assert_same(['/store/public/admin/default/initial'], array_values(array_unique($themeHits)),
    'the theme reaches exactly one Laravel route, and does it with a <script src>');

// CKEditor. The recorded blocker was that lab.js ships a raw-XMLHttpRequest
// upload adapter posting to /store/public/admin/*/uploader without a token.
// It does ship CKEditor 5's stock CKFinderUploadAdapter, whose init() is
// `const url = config.get('ckfinder.uploadUrl'); url && (...)`; nothing
// configures that key, so createUploadAdapter is never replaced. Assert the
// premises, so that setting the key, or un-commenting an extraPlugins block,
// re-opens the question loudly instead of quietly shipping an untokened upload.
$assignsUploadUrl = [];
foreach (array_merge($reactFiles, $bundles, (array) glob($root . '/store/public/react/pages/*.js')) as $p) {
    foreach (explode("\n", (string) file_get_contents($p)) as $line) {
        // JSDoc, not code. ckeditorUploadAdapter.js carries CKSource's original
        // docblock, which shows the very configuration this asserts is absent --
        // and a comment is not code.
        $t = ltrim($line);
        if ($t === '' || $t[0] === '*' || strpos($t, '//') === 0 || strpos($t, '/*') === 0) continue;
        if (preg_match('/ckfinder\s*:\s*\{/', $line) || preg_match('/uploadUrl\s*:/', $line)
            || preg_match('/simpleUpload\s*:\s*\{/', $line)) {
            $assignsUploadUrl[] = str_replace($root . '/', '', $p);
            break;
        }
    }
}
assert_same([], $assignsUploadUrl,
    'nothing assigns ckfinder.uploadUrl / simpleUpload.uploadUrl anywhere');

// The editor call sites register no upload adapter. (They used to keep one
// commented out, pointing at admin/labs/uploader; Phase 05 removed that
// uploader with the lab marketplace, and the dead comment with it. The third
// site, components/admin/product/Step_03.js, went with the marketplace too.)
foreach ([
    'components/lab/text/TextEditor.js',
    'components/lab/workbook/editor/HTMLEditor.js',
] as $rel) {
    $lines = @file($reactDir . '/' . $rel);
    $live = 0;
    foreach ((array) $lines as $line) {
        if (strpos($line, 'createUploadAdapter') === false) continue;
        if (strpos(ltrim($line), '//') !== 0) $live++;
    }
    assert_same(0, $live, "no live createUploadAdapter registration in $rel");
}

// components/policy/Uploader.js is CKEditor's documentation sample, copied
// verbatim, and it is the only raw XMLHttpRequest in the tree. It is imported
// by nothing and appears in no bundle. If it is ever wired up it needs a token
// of its own -- it does not go through axios.
$xhrFiles = [];
foreach ($reactFiles as $p) {
    if (strpos((string) file_get_contents($p), 'new XMLHttpRequest') !== false) {
        $xhrFiles[] = str_replace($reactDir . '/', '', $p);
    }
}
assert_same(['components/policy/Uploader.js'], $xhrFiles,
    'the raw-XHR uploader sample is still the only XMLHttpRequest in the front end');

$importsSample = [];
foreach ($reactFiles as $p) {
    if (basename($p) === 'Uploader.js' && strpos($p, '/policy/') !== false) continue;
    $s = (string) file_get_contents($p);
    if (preg_match('#(import|require)[^\n]*policy/Uploader#', $s)) {
        $importsSample[] = str_replace($reactDir . '/', '', $p);
    }
}
assert_same([], $importsSample, 'nothing imports the raw-XHR uploader sample');

foreach (array_merge($bundles, (array) glob($root . '/store/public/react/pages/*.js')) as $p) {
    assert_true(strpos((string) file_get_contents($p), 'example.com/image/upload/path') === false,
        'the raw-XHR uploader sample is not in ' . basename($p));
}

test_summary();
