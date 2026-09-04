<?php

/*
|--------------------------------------------------------------------------
| Actions reachable by GET
|--------------------------------------------------------------------------
|
| The three dynamic dispatchers in routes/web.php -- /admin/{controller}/{method},
| /user/... and /notice/... -- accepted BOTH verbs for every method on every
| controller. 157 dispatchable methods, of which 39 called Checker::method('post')
| and 118 did not.
|
| That is the hole SameSite=Lax does not cover and VerifyCsrfToken cannot see.
| Lax withholds the token cookie from a cross-site form POST, fetch, XHR, <img>
| and <iframe>, but it SENDS it on top-level GET navigation -- a link, a
| window.open, a `location =`, a 302. And VerifyCsrfToken only verifies
| POST/PUT/PATCH/DELETE. So before this list existed,
|
|     location = 'http://box/store/public/admin/system/reboot'
|
| from any page on the internet, in a browser logged into the appliance, ran.
| shutdown/reboot/stopAllNodes happened to be among the 39 guarded, which was
| luck; admin/status/apiSetKsm, admin/labs/drop, admin/mode/setOffline,
| admin/node_sessions/commitDevice and about a hundred others were not.
|
| So the router now defaults to POST-only and this file is the exception list:
| the actions that a browser is genuinely expected to reach by GET. Default-deny
| is the point. A controller method added tomorrow is POST-only without anyone
| remembering to guard it, and publishing it to GET is a deliberate edit here.
|
| Enforced by App\Helpers\Request\Checker::action(), called by each dispatcher.
| Keys are 'group/controller/method', matched case-insensitively (PHP method
| names are case-insensitive, so the URL segment is too).
|
| Every entry below was established from a caller, not from the method name:
| store/resources/react (source) and store/public/react (the bundles that
| actually run) were both read for <a href>, window.open, history.push,
| <img src> and axios method:'get'; themes/ was read for the same. Anything the
| front end reaches with POST is absent from this list on purpose.
|
| Pinned by tests/Security/CsrfTest.php, which fails if an action on this list
| can change state.
*/

return [

    // --- The SPA's page renders. Each of these is a URL the browser navigates
    // to, so GET is not optional; each returns view('reactjs.reactjs') (or
    // view('main.main')) and nothing else. The page names come from
    // store/resources/react/pages/, which app.js maps to
    // /store/public/{folder}/{page}/{func} -- so this list and that directory
    // have to agree or a refresh on an open page 'Not Support's.
    'admin/main/view',              // the root redirect target, from Route::redirect('/')
    'admin/labs/workbook',          // window.open from Wb_bar.js:50
    'admin/labs/workbookview',      // <a target=_blank> Wb_bar.js:31, Wb_Modal.js:92
    'admin/labs/terminal',          // window.open from HTMLConsoleModal.js:168
    'admin/lab_sessions/view',      // <a href> RunningLabButton.js:20
    'admin/devices/store',          // menu, and WiresharkModal.js:126
    'admin/mode/view',              // menu
    'admin/profile/view',           // user menu, UserName.js:38
    'admin/status/view',            // menu, and window.open from StatusModal.js:75
    'admin/sync/view',              // pages/admin/SyncView.js
    'admin/system/view',            // menu
    'admin/users/offline',          // menu, offline mode
    'admin/user_roles/view',        // menu

    // --- Not page renders, but genuinely fetched with GET.

    // Loaded as <script src> by themes/default/index.html:40, so it cannot be
    // a POST. Emits the server-side bootstrap object as JavaScript; reads only.
    'admin/default/initial',

    // app.js:59 fetches the language pack with axios method:'get' before the
    // router is even mounted. loadLanguage() reads files; nothing is written.
    'admin/default/language',

    // store/public/assets/js/default.js:169,171 -- $.get on window.onload and
    // every 20 minutes after, from every page of both UIs. It re-issues the
    // caller's own 60-minute token cookie and stamps the caller's own row
    // with the time. This was missing when the list was first written, and
    // the effect was invisible: the refusal came back with a 200-class
    // status to a call with no .fail handler, so nothing complained and the
    // cookie simply expired -- every admin was bounced to login after about
    // an hour of continuous work. What a cross-origin GET could do with it
    // is extend the victim's OWN session by the same amount the victim's own
    // page load would; it reads no data and writes nobody else's.
    'admin/default/refreshToken',

];
