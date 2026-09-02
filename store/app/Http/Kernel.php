<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        \App\Http\Middleware\TrustProxies::class,
        
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            // VerifyCsrfToken was disabled here, with a written record of why.
            // It is on now. What the record said, and what each claim turned
            // out to be worth once it could be checked against a running app:
            //
            //  - "The React frontend posts through axios, which attaches
            //    X-XSRF-TOKEN from the XSRF-TOKEN cookie on same-origin
            //    requests." Correct, and it is the whole front-end story.
            //    axios reads xsrfCookieName 'XSRF-TOKEN' and writes
            //    xsrfHeaderName 'X-XSRF-TOKEN'; this middleware issues that
            //    cookie on every response; EncryptCookies encrypts it on the
            //    way out and getTokenFromRequest() decrypts the header on the
            //    way back. Nothing has to be wired by hand, and the commented
            //    -out blocks in resources/react/bootstrap.js and
            //    views/reactjs/document.blade.php must STAY commented out --
            //    both depend on a <meta name="csrf-token"> tag that no Blade
            //    view in this application emits.
            //
            //  - "The legacy theme's jQuery POSTs all target /api/..., outside
            //    Laravel entirely." Correct. Rechecked: themes/ contains no URL
            //    under /store/public at all except the <script src> for
            //    /admin/default/initial, which is a GET. The legacy layer has
            //    its own defence now, in includes/api_origin_guard.php.
            //
            //  - "store/public/react/js/lab.js contains CKEditor's raw-XHR
            //    upload adapter and the editor uploads target Laravel routes,
            //    so that path would break." WRONG, and it was the whole
            //    blocker. The adapter in the bundle is CKEditor 5's stock
            //    CKFinderUploadAdapter, whose init() is
            //    `const e = config.get('ckfinder.uploadUrl'); e && (...)` --
            //    nothing in this application sets ckfinder.uploadUrl, so
            //    createUploadAdapter is never replaced and the raw XHR never
            //    runs. All three editor call sites (Step_03.js:204,
            //    TextEditor.js:221, HTMLEditor.js:559) have their
            //    createUploadAdapter line commented out, and the only file that
            //    registers one for real, components/policy/Uploader.js, is
            //    imported by nothing. There is NO $except entry for CKEditor
            //    and there must not be one; tests/Security/CsrfTest.php fails
            //    if any of those facts changes.
            //
            //  - "There is no vendor/ in this tree and no way to run the app
            //    here." No longer true: tests/Laravel/LaravelBootTest.php boots
            //    the real application and dispatches real requests.
            //
            // The thing the record did not say, and the reason enabling this
            // alone would have been a partial fix that read as a complete one:
            // this middleware never sees a GET, and the three dynamic
            // dispatchers in routes/web.php accepted GET for all 157 controller
            // methods, of which 118 had no verb guard. SameSite=Lax does send
            // the cookie on top-level GET navigation. So the verb split in
            // config/readonly_actions.php + Checker::action() had to land with
            // this line, not after it.
            //
            // 'admin/box/*' has been removed from VerifyCsrfToken::$except --
            // there is no Admin\BoxController and there never was one in this
            // tree, so it was a wildcard reserving an exemption for a class
            // that does not exist.
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            'throttle:60,1',
            'bindings',
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'multilang' => \App\Http\Middleware\Multilang::class,
    ];
}
