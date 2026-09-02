
///window._ = require('lodash');

/**
 * We'll load jQuery and the Bootstrap jQuery plugin which provides support
 * for JavaScript based Bootstrap features such as modals and tabs. This
 * code may be modified to fit the specific needs of your application.
 */

//try {
//    //window.$ = window.jQuery = require('jquery');
//
//    //require('bootstrap-sass');
//} catch (e) {}

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

// The `.default` is load-bearing, and it is the whole SPA.
//
// 0.19 was CommonJS: its index.js was `module.exports = require('./lib/axios')`,
// so a bare require() handed back the callable instance and this line read
// `window.axios = require('axios')`. 1.x ships an ES module entry -- webpack 4
// predates the package.json `exports` map and so resolves `main`, which is
// ESM -- and require()ing an ES module through webpack yields the namespace
// OBJECT. The instance is one level down, on `default`.
//
// It breaks loudly but in the wrong place. `window.axios.VERSION` is a named
// export and reads fine, so the module looks present; `window.axios.request` is
// undefined, and app.js calls it at module scope to fetch the language table.
// The page dies with "axios.request is not a function" before React mounts, and
// #app stays empty -- a blank login screen, which reads as a broken deploy
// rather than as a dependency upgrade.
//
// This global is not a convenience. 107 of the 109 front-end files that use
// axios reach it this way; only app.js and components/uploader/
// ckeditorUploadAdapter.js import the module themselves.
const axiosModule = require('axios');
window.axios = axiosModule.default || axiosModule;

// XSRF is still automatic, and deliberately still not configured here.
//
// axios 1.x gained a `withXSRFToken` option when it fixed the token-leak
// advisory (GHSA-wf5p-g6vw-rhxx), and resolveConfig() now decides with
// `withXSRFToken === true || (withXSRFToken == null && isURLSameOrigin(url))`.
// Left unset -- which is what this file does -- the second clause applies, so
// the XSRF-TOKEN cookie is still copied into X-XSRF-TOKEN on same-origin
// requests exactly as 0.19 did. Every URL the front end asks for is
// root-relative, so every request qualifies.
//
// Setting `withXSRFToken: true` would be the wrong "explicit" fix: it takes the
// first clause, which sends the token to ANY origin and reintroduces precisely
// the leak the advisory is about. The default is the safer of the two and it is
// the one this application needs.
//
//window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Next we will register the CSRF Token as a common header with Axios so that
 * all outgoing HTTP requests automatically have it attached. This is just
 * a simple convenience so we don't have to attach every token manually.
 */

//let token = document.head.querySelector('meta[name="csrf-token"]');
//
//if (token) {
//    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
//} else {
//    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
//} 

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

// import Echo from 'laravel-echo'

// window.Pusher = require('pusher-js');

// window.Echo = new Echo({
//     broadcaster: 'pusher',
//     key: 'your-pusher-key',
//     cluster: 'mt1',
//     encrypted: true
// });
var constantLoader = require.context('./constants/', true, /\.js$/);
constantLoader.keys().forEach((key)=>{constantLoader(key)});

var helperLoader = require.context('./helpers/', true, /\.js$/);
helperLoader.keys().forEach((key)=>{helperLoader(key)});

//require('./lang/lang'); 

