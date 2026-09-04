<?php
/**
 * Evidence that the Laravel application boots. There was none.
 *
 * Laravel 5.5 -> 10.50.3 is the largest change on this branch, and until this
 * file nothing automated exercised it beyond `php -l`. CI never runs
 * `composer install` (store/vendor is gitignored), so no Laravel class has ever
 * been loaded in automation; tests/Security/RoutingTest.php is a regex read of
 * store/routes/web.php that never instantiates anything; and
 * install/lib/verify.sh reports a non-200 on GET / as `[info]`, with a comment
 * saying it is expected to fail — so the installer cannot fail because the admin
 * UI does not boot. A broken lockfile, a service provider removed in L6, a
 * middleware alias that only resolves at runtime, or a facade root that is never
 * bound would all pass every gate this project has.
 *
 * WHY IT IS HERE AND NOT IN store/tests/Feature/ UNDER PHPUnit.
 *
 * PHPUnit is already a dev dependency, store/phpunit.xml exists and runs,
 * and a Feature test would be the natural home. It is not the right home here,
 * for one decisive reason:
 *
 *     store/bootstrap/app.php:14
 *         require_once('/opt/unetlab/html/includes/init.php');
 *
 * The application cannot be constructed anywhere but a host where the tree is
 * deployed to that absolute path. The require is before the Application is even
 * built, it ignores SRC_DIR, and includes/init.php in turn requires six constant
 * files back out of store/app/Constants/ — the two layers require each other in
 * both directions. So the boot half of any such test runs on a deployed host or
 * it does not run at all, and moving to PHPUnit would buy a composer install, a
 * test database and a CI symlink without buying a single assertion this file
 * cannot make.
 *
 * Meanwhile tools/run-tests.sh is the suite that actually executes in CI and on
 * the reference VM today. Putting the test here means it runs in both places:
 * the static half everywhere, the boot half wherever the app is installed —
 * including from install/lib/verify.sh, which is where it belongs long-term.
 *
 * Revisit if CI ever gains a composer install and a deployed layout; at that
 * point the boot half should move to store/tests/Feature/ and this file should
 * keep only the lockfile assertions.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

// --------------------------------------------------- runs anywhere: the lockfile

$lock = json_decode((string) @file_get_contents($root . '/store/composer.lock'), true);
$framework = null;
foreach ((isset($lock['packages']) ? $lock['packages'] : []) as $p) {
    if ($p['name'] === 'laravel/framework') { $framework = $p['version']; break; }
}
assert_true($framework !== null, 'store/composer.lock pins laravel/framework');

// The pinned major line. This is asserted, rather than merely read, because the
// lockfile is the only place the framework version is written down that is
// under version control -- store/vendor is gitignored -- so a lockfile that has
// drifted off the line composer.json asks for is the one upgrade failure no
// other assertion in this file can see. Bump it deliberately, as part of an
// upgrade, and never to make a red run green.
$LARAVEL_LINE = '12.';
assert_true($framework !== null && strpos(ltrim($framework, 'v'), $LARAVEL_LINE) === 0,
    "laravel/framework is on {$LARAVEL_LINE}x (locked: " . var_export($framework, true) . ")");

// The absolute require above is the reason this test lives here. If it is ever
// removed, this assertion fails and the comment at the top needs rewriting —
// which is the point.
$appBootstrap = (string) @file_get_contents($root . '/store/bootstrap/app.php');
assert_true(strpos($appBootstrap, "require_once('/opt/unetlab/html/includes/init.php')") !== false,
    'store/bootstrap/app.php still hard-requires the deployed includes/init.php');

// ------------------------------------------------ runs on a deployed host: boot

/**
 * Prefer this checkout if it happens to be the deployed tree; otherwise use the
 * deployment, because app.php will require its init.php regardless.
 */
$store = null;
foreach ([$root . '/store', '/opt/unetlab/html/store'] as $candidate) {
    if (is_file($candidate . '/vendor/autoload.php') && is_file($candidate . '/bootstrap/app.php')) {
        $store = $candidate;
        break;
    }
}

if ($store === null || !is_file('/opt/unetlab/html/includes/init.php')) {
    echo "  note  boot not attempted: no deployed Laravel tree (needs store/vendor and\n";
    echo "        /opt/unetlab/html/includes/init.php). The assertions above are the only\n";
    echo "        ones this run made — nothing here claims the application boots.\n";
    test_summary();
}

// The deployed .env is 0640 root:www-data — install/lib/store.sh sets that
// deliberately, because it holds the per-installation APP_KEY and verify.sh
// separately asserts the web server will not serve it as text.
//
// So a run as an ordinary user cannot read it, Encrypter has no key, and every
// request below returns 500 "No application encryption key has been specified".
// That is a fact about who is running the test, not about whether the
// application boots — and reporting it as four failures is worse than useless,
// because it looks exactly like the breakage this file exists to detect.
//
// So the request assertions are skipped, loudly, rather than reported as
// failures. Run the suite as www-data or root — php-fpm serves as www-data, so
// that is the view of the application worth asserting — and they execute.
//
// A .env that is *absent* has to be treated the same way, and for a reason that
// cost this session an hour during the Laravel 11 hop. The candidate loop above
// prefers this checkout whenever it has a store/vendor, and building one there
// is exactly what a `composer update` during an upgrade does. The checkout has
// no .env -- it is gitignored, and only the deployed tree ever gets one -- so
// the loop then boots a tree with a framework but no APP_KEY, and the four
// request assertions fail with 500s that read precisely like a framework
// upgrade having broken the application. They had not. Skip loudly instead, and
// say which of the two cases it is, so the message names the cause.
$envPath = $store . '/.env';
$envMissing    = !is_file($envPath);
$envUnreadable = !$envMissing && !is_readable($envPath);
$skipRequests  = $envMissing || $envUnreadable;
if ($envUnreadable) {
    $who = function_exists('posix_getpwuid')
        ? posix_getpwuid(posix_geteuid())['name'] : 'this user';
    echo "  note  request assertions skipped: $envPath is not readable\n";
    echo "        by $who, so APP_KEY is absent and every request below would return\n";
    echo "        500 on that alone. Run the suite as www-data or root to exercise them.\n";
} elseif ($envMissing) {
    echo "  note  request assertions skipped: $envPath does not exist, so APP_KEY is\n";
    echo "        absent and every request below would return 500 on that alone. This is\n";
    echo "        the tree the boot half chose ($store); a checkout that has\n";
    echo "        acquired a store/vendor is preferred over the deployment, and a checkout\n";
    echo "        never has a .env. Remove that vendor directory, or run against the\n";
    echo "        deployed tree, to exercise them.\n";
}

require_once $store . '/vendor/autoload.php';

$app = require $store . '/bootstrap/app.php';
assert_true($app instanceof \Illuminate\Foundation\Application,
    'bootstrap/app.php returns an Illuminate\\Foundation\\Application');

$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
assert_true($kernel instanceof \Illuminate\Contracts\Http\Kernel,
    'the container resolves the HTTP kernel');

// Bootstrapping is what actually runs the providers, loads config and registers
// the facades. Everything below depends on it, so a failure here is the failure
// this whole file exists to catch.
//
// The request instance has to be bound first. Kernel::handle() does that before
// it bootstraps; calling bootstrap() directly does not, and RoutingServiceProvider
// then constructs a UrlGenerator with a null request and dies with a TypeError
// that looks like a framework fault and is not one.
$app->instance('request', \Illuminate\Http\Request::create('/', 'GET'));
$kernel->bootstrap();
assert_true($app->isBooted() || $app->bound('config'), 'the application bootstraps');

// The suite must neither depend on being able to write the application's log nor
// pollute it. store/config/logging.php does not exist (it arrived in Laravel 5.6
// and this config directory is still the 5.5 set), so LogManager falls through to
// its emergency handler on storage/logs/laravel.log — which this process cannot
// open, and the write failure then masks whatever it was trying to report.
//
// Configure a discard channel rather than replacing the 'log' binding. Binding
// an Illuminate\Log\Logger directly looks equivalent and is not: the
// application calls Log::channel(), which exists on LogManager and not on
// Logger, so the substitution turns a working page into a 500 that reads like
// an application fault.
$config = $app->make('config');
$config->set('logging.default', 'pnetlab-boot-test');
$config->set('logging.channels.pnetlab-boot-test', [
    'driver'  => 'monolog',
    'handler' => \Monolog\Handler\NullHandler::class,
]);

assert_true($config->get('app.providers') !== null, 'config/app.php loads');

$missing = [];
foreach ((array) $config->get('app.providers') as $provider) {
    if (!class_exists($provider)) $missing[] = $provider;
}
assert_same([], $missing, 'every registered service provider class exists');

$missingAliases = [];
foreach ((array) $config->get('app.aliases') as $alias => $class) {
    if (!class_exists($class) && !interface_exists($class)) $missingAliases[] = "$alias => $class";
}
assert_same([], $missingAliases, 'every facade alias resolves to a real class');

// Middleware aliases are the classic thing that only fails at request time: the
// property was renamed $routeMiddleware -> $middlewareAliases in L10 and is
// still honoured through a deprecated fallback.
$httpKernel = new ReflectionClass(\App\Http\Kernel::class);
$missingMw = [];
foreach (['middlewareAliases', 'routeMiddleware', 'middleware', 'middlewareGroups'] as $prop) {
    if (!$httpKernel->hasProperty($prop)) continue;
    $p = $httpKernel->getProperty($prop);
    $p->setAccessible(true);
    foreach ((array) $p->getValue($app->make(\App\Http\Kernel::class)) as $entry) {
        foreach ((array) $entry as $class) {
            if (is_string($class) && strpos($class, '\\') !== false && !class_exists($class)) {
                $missingMw[] = $class;
            }
        }
    }
}
assert_same([], array_values(array_unique($missingMw)),
    'every middleware named by App\\Http\\Kernel exists');

$routes = $app->make('router')->getRoutes();
// 13 since Phase 05 removed the four online-login routes (initial,
// initialOnline, online, license); it was 17 before that.
assert_true(count($routes) >= 13,
    sprintf('the router resolves the full route table (%d routes)', count($routes)));

$uris = [];
foreach ($routes as $r) $uris[$r->uri()] = true;
foreach (['/', 'auth/login/login', 'auth/login/offline', 'admin/{controller}/{method}',
          'user/{controller}/{method}', 'notice/{controller}/{method}'] as $uri) {
    assert_true(isset($uris[$uri]), "route is registered: $uri");
}

// A real request through the real stack. GET / is a redirect route, so it needs
// no database and no session, and it is exactly what install/lib/verify.sh
// checks and then declines to fail on.
/** Handle a request and surface what actually happened rather than a stack trace. */
function boot_get($kernel, $path)
{
    try {
        $request = \Illuminate\Http\Request::create($path, 'GET');

        // Publish the request into $_SERVER before dispatching. Request::create()
        // populates the Request object only, and parts of this application read
        // the superglobal directly -- $_SERVER['HTTP_HOST'] and SERVER_NAME among
        // them. Under Apache those are always set, so the difference never shows
        // in production; in-process it surfaces as a 500 that looks like a broken
        // page and is really a missing environment.
        //
        // Reading the superglobal instead of the Request is worth fixing on its
        // own account -- it is why this app cannot be exercised from the CLI --
        // but that is a change to the application, not to this test.
        $_SERVER = array_merge($_SERVER, $request->server->all());

        return $kernel->handle($request);
    } catch (Throwable $e) {
        echo "        $path threw " . get_class($e) . ': ' . $e->getMessage() . "\n";
        return null;
    }
}

if ($skipRequests) test_summary();

$response = boot_get($kernel, '/');
assert_true($response !== null, 'GET / is handled without an uncaught exception');
if ($response === null) test_summary();
assert_same(301, $response->getStatusCode(), 'GET / returns 301');
assert_same('/store/public/admin/main/view', $response->headers->get('Location'),
    'GET / redirects to the admin entry point');

// An auth-gated page must send an anonymous caller to the login form rather than
// erroring. 5xx here means the app booted but cannot serve.
$guarded = boot_get($kernel, '/admin/main/view');
assert_true($guarded !== null && $guarded->getStatusCode() < 500,
    sprintf('an unauthenticated admin request does not 5xx (got %s)',
        $guarded === null ? 'an exception' : $guarded->getStatusCode()));

$login = boot_get($kernel, '/auth/login/offline');
assert_true($login !== null && $login->getStatusCode() < 500,
    sprintf('the login page renders without a server error (got %s)',
        $login === null ? 'an exception' : $login->getStatusCode()));

test_summary();
