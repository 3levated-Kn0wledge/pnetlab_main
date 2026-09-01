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
            // VerifyCsrfToken is disabled, and this sweep deliberately left it
            // that way. Turning it on is a one-line change with an app-wide
            // blast radius -- every POST to a web route -- and it could not be
            // shown to be safe from the source alone. What was checked:
            //
            //  - The React frontend posts through axios, which does attach
            //    X-XSRF-TOKEN from the XSRF-TOKEN cookie on same-origin
            //    requests, so the ordinary SPA POSTs would most likely
            //    survive. resources/react/bootstrap.js has its explicit
            //    X-CSRF-TOKEN wiring commented out, and views/reactjs/
            //    document.blade.php has its $.ajaxSetup header commented out,
            //    so nothing sets the token by hand -- it would rest entirely
            //    on axios's implicit behaviour.
            //  - The legacy theme's jQuery POSTs (themes/default/js/*.js and
            //    the bundles in store/public/react/js) all target /api/...,
            //    which the root .htaccess routes to api.php, outside Laravel
            //    entirely. Those are unaffected either way.
            //  - But the shipped bundle store/public/react/js/lab.js also
            //    contains CKEditor's raw-XMLHttpRequest upload adapter, which
            //    sets no CSRF header, and the editor upload targets are
            //    Laravel web routes (/store/public/admin/*/uploader). That
            //    path would break.
            //  - There is no vendor/ in this tree and no way to run the app
            //    here, so none of the above could be confirmed against a live
            //    request. The built bundles may also lag the sources.
            //
            // The exclusion list in App\Http\Middleware\VerifyCsrfToken
            // ('admin/box/*', 'auth/login/license') suggests this was live at
            // some point and was switched off rather than fixed, which is a
            // reason to be careful about switching it straight back on.
            //
            // To re-enable safely: uncomment the line below, then exercise (1)
            // offline login, (2) the online/license return leg, (3) a file
            // upload through each *_uploader route, and (4) a CKEditor image
            // upload; anything that 419s needs its token wired up (or, for a
            // genuinely cross-site callback, an entry in $except).
            //
            // \App\Http\Middleware\VerifyCsrfToken::class,
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
