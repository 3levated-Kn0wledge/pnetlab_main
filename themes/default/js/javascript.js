
/**
 * themes/default/js/javascript.js
 *
 * Startup scripts for the legacy UI.
 *
 * Derived from UNetLab html/themes/default/js/javascript.js.
 * Its BSD-3-Clause notice was absent from the copy this fork inherited
 * and is restored below. See docs/LICENSING.md section 2.2.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 *
 * Substantially modified by PNETLab and by the pnetlab_main fork. Those
 * modifications are licensed under the terms in this repository's LICENSE;
 * the notice above must be retained regardless.
 */

// Custom vars
var DEBUG = 5;
var TIMEOUT = 30000;
var LONGTIMEOUT = 600000;
var STATUSINTERVAL = 5000;

// Global vars
var EMAIL;
var FOLDER;
var LAB;
var LANG;
var NAME;
var ROLE;
var TENANT;
var USERNAME;
var ATTACHMENTS;
var UPDATEID;
var HTML5;
var LOCK = 0;
var isIE = getInternetExplorerVersion() > -1;
var FOLLOW_WRAPPER_IMG_STATE = 'resized'
var EVE_VERSION = "PNET";

$(document).ready(function () {
	if ($.cookie('privacy') != 'true') {
		// Cookie is not set, show a modal with privacy policy
		console.log('DEBUG: need to accept privacy.');
		$.cookie('privacy', 'true', {
			expires: 90,
			path: '/'
		});
		if ($.cookie('privacy') == 'true') {
			window.location.reload();
		}
	} else {
		// Privacy policy already been accepted, check if user is already authenticated
		$.when(getUserInfo()).done(function () {
			postLogin();
		}).fail(function (data) {
			location.href = "/";
		});
	}
	var timer;
	$(document).on('click', '#alert_container', function (e) {
		if (timer) {
			clearTimeout(timer);
		}

		var container = $(this).next().first();
		container.slideToggle(300);
		setTimeout(function () {
			container.slideUp(300);
		}, 2700);

	});
});


$.ajaxPrefilter("json", function (options, originalOptions) {

	if (originalOptions.type.toLowerCase() == 'post') {
		if (typeof (originalOptions.contentType) == 'undefined') {
			options.data = JSON.stringify(originalOptions.data || null);
			options.contentType = "application/json"
		}
	}

});


