<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * Patterns are matched against Request::path(). The application is served
     * from store/public/, so the path Laravel sees is 'auth/login/license',
     * not 'store/public/auth/login/license'.
     *
     * Every entry here is a hole. One survives, and it carries its reason. If
     * you are about to add another, the question to answer first is whether the
     * caller is genuinely a different site -- because if it is same-origin
     * JavaScript, axios already sends the token and the entry buys nothing but
     * the hole. tests/Security/CsrfTest.php asserts this list stays exactly as
     * it is, so adding to it is a deliberate act with a test to update.
     *
     * REMOVED: 'admin/box/*'. There is no App\Http\Controllers\Admin\BoxController
     * in this tree -- `find store/app -iname '*box*'` returns only
     * store/app/Helpers/Box -- so the entry exempted nothing today while
     * standing ready to exempt every method of any Admin\BoxController added
     * tomorrow. A wildcard exemption for a class that does not exist is the
     * worst shape an $except entry can have. It is also the clearest evidence
     * that this middleware was live once and was switched off rather than
     * fixed.
     *
     * @var array
     */
    protected $except = [
        // The return leg of the online login. LoginController::online() builds
        // $box_link and redirects the browser to APP_AUTHEN; APP_AUTHEN sends
        // it back here carrying the license. That is a genuinely cross-site
        // request from a server this box does not control, so it cannot carry
        // a token this box issued. routes/web.php registers it for both verbs
        // for the same reason -- the remote end chooses the method.
        'auth/login/license',
    ];
}
