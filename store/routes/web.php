<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

/*Admin*/

use App\Helpers\Encrypt\Encrypt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

// Auth::routes();

Route::match(['post'], '/captcha', function() {
    return App::call('\App\Http\Controllers\Auth\LoginController@captcha');
});

Route::redirect('/', '/store/public/admin/main/view', 301);

/*route for admin*/

// Route::match(['post', 'get'], '/test', function () {
//     die;
//     print_r($_SERVER);
// });

Route::match(['post', 'get'], '/admin/default/initial', function () {
    return App::call('\App\Http\Controllers\Admin\DefaultController@initial');
});

Route::match(['post', 'get'], '/admin/default/language', function () {
    return App::call('\App\Http\Controllers\Admin\DefaultController@language');
});

/*
|--------------------------------------------------------------------------
| Auth (pre-authentication surface)
|--------------------------------------------------------------------------
|
| This used to be a single dynamic dispatcher:
|
|     Route::match(['post', 'get'], '/auth/{controller}/{method}', function ($controller, $method) {
|         return App::call('\App\Http\Controllers\Auth\\'.ucfirst($controller).'Controller@' . $method);
|     });
|
| with no 'auth' middleware, unlike its /admin, /user and /notice siblings.
| Both the class and the method came out of the URL, so every public method
| on every Auth\*Controller was callable by an anonymous request: not just
| the login flow but logout(), showLoginForm(), the inherited
| Controller::getMapData() and BaseController::callAction(), and the two
| that mutate state -- initialOffline(), which creates or promotes the
| default 'admin' account, and license(), which creates a local account
| from a caller-supplied license string.
|
| LoginController's own constructor calls $this->middleware('guest'), but
| that never ran: App::call() invokes the method directly and does not
| apply controller middleware. Nothing was gating this.
|
| Each endpoint the login flow actually uses is now listed explicitly.
| Callers were checked in store/resources/react, in the built bundles under
| store/public/react/js, and in themes/. The dispatch shape is kept exactly
| as it was (App::call from a closure) so behaviour is unchanged for the
| endpoints that remain -- in particular controller middleware still does
| not run, which is what the current login flow expects.
|
| Deliberately not routed: logout() and showLoginForm() (inherited from the
| AuthenticatesUsers trait), getMapData() (from the base Controller), and
| the framework's own callAction()/middleware()/getMiddleware(). Adding a
| public method to an Auth controller no longer publishes it; a route has to
| be added here.
|
| captcha() is reachable, but through the dedicated POST /captcha route
| above -- that is the URL components/auth/Captcha.js posts to. No caller
| anywhere uses /auth/login/captcha, so it is not re-published here.
*/

// First-run mode chooser. Necessarily anonymous: on a fresh install neither
// mode is set and no account exists yet, so there is nobody who could be
// authenticated. Both initial* methods no-op once a mode has been chosen.
// pages/auth/LoginInitial.js links to these.
Route::get('/auth/login/initial', function () {
    return App::call('\App\Http\Controllers\Auth\LoginController@initial');
});

Route::get('/auth/login/initialOnline', function () {
    return App::call('\App\Http\Controllers\Auth\LoginController@initialOnline');
});

Route::get('/auth/login/initialOffline', function () {
    return App::call('\App\Http\Controllers\Auth\LoginController@initialOffline');
});

// Entry point after an expired session or an auth error; redirects to
// whichever login page is configured. helpers/error_helper.js sends the
// browser here.
Route::get('/auth/login/manager', function () {
    return App::call('\App\Http\Controllers\Auth\LoginController@manager');
});

// The two login pages themselves. offline() renders the React login view;
// online() redirects to APP_AUTHEN.
Route::get('/auth/login/offline', function () {
    return App::call('\App\Http\Controllers\Auth\LoginController@offline');
});

Route::get('/auth/login/online', function () {
    return App::call('\App\Http\Controllers\Auth\LoginController@online');
});

// The offline login POST. pages/auth/LoginOffline.js posts here.
Route::post('/auth/login/login', function () {
    return App::call('\App\Http\Controllers\Auth\LoginController@login');
});

// Return leg of the online login: APP_AUTHEN sends the browser back to this
// URL (built in LoginController::online() as $box_link) carrying the
// license. Cross-site by design, which is why it is already listed in
// VerifyCsrfToken::$except. Both verbs are accepted because the remote end
// chooses the method.
Route::match(['post', 'get'], '/auth/login/license', function () {
    return App::call('\App\Http\Controllers\Auth\LoginController@license');
});

Route::match(['post', 'get'], '/admin/{controller}/{method}', function ($controller, $method) {
    return App::call('\App\Http\Controllers\Admin\\'.ucfirst($controller).'Controller@' . $method);
})->middleware('auth');

Route::match(['post', 'get'], '/user/{controller}/{method}', function ($controller, $method) {
    return App::call('\App\Http\Controllers\User\\'.ucfirst($controller).'Controller@' . $method);
})->middleware('auth');

Route::match(['post', 'get'], '/notice/{controller}/{method}', function ($controller, $method) {
    return App::call('\App\Http\Controllers\Notice\\'.ucfirst($controller).'Controller@' . $method);
})->middleware('auth');

// Route::match(['get'], '/redirect', function (Request $request) {
    
//     return Redirect::away($request->input('blod'));
// });







