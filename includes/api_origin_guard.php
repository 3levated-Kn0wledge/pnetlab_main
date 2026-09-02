<?php
/**
 * A CSRF guard for the legacy API, applied at the one place every request to it
 * passes through.
 *
 * WHY THIS EXISTS
 *
 * The root .htaccess rewrites /api/* to api.php, a standalone Slim 2.6.1
 * application. It never enters Laravel's kernel, so Laravel's VerifyCsrfToken
 * cannot protect it however that middleware is configured. Every one of the 18
 * routes authenticates by handing $app->getCookie('token') to
 * indentify::authorization() -- ambient cookie authority, no header, no nonce,
 * no origin check. And every POST handler calls
 * json_decode($app->request()->getBody(), true) on the raw body without looking
 * at Content-Type, so a cross-site <form> POST -- a "simple request", no
 * preflight -- reaches the handler and dispatches. That is roughly fifty
 * mutating actions behind /api/labs/session/(:object)/(:action) alone, many of
 * which (nodes/start, nodes/stop, nodes/wipe, factory/leave) need no parameters
 * at all and so execute happily on an empty body.
 *
 * Until now the only thing standing in the way was the SameSite=Lax attribute
 * on the token cookie -- a browser-enforced control, with nothing checked
 * server side, that had already been dropped by accident once (six of the eight
 * issuance sites omitted it; see App\Helpers\Auth\AuthCookie).
 *
 * WHERE IT IS APPLIED
 *
 * On the 'slim.before.router' hook, registered before the route table is built.
 * That is the only choke point in api.php that runs for every request without
 * touching the eighteen handlers: Slim\Slim::call() fires it once, inside the
 * output buffer, before getMatchedRoutes(), so $app->halt() from here unwinds
 * cleanly through the Stop exception the same way it does from a route. A new
 * route added tomorrow is covered without anyone remembering to cover it, which
 * is the property that editing eighteen handlers would not have.
 *
 * HOW IT DECIDES
 *
 * Only POST, PUT, PATCH and DELETE are policed. GET and HEAD are left alone --
 * the GET state-changer that did exist, /api/auth/logout, was moved to POST
 * rather than exempted.
 *
 * 1. Origin, else Referer. Whichever of the two is present is authoritative,
 *    and its hostname must equal the hostname this request was addressed to.
 *
 *    A request carrying NEITHER header is allowed. This is deliberate and it is
 *    the part that is easy to get wrong: curl, scripts and the project's own
 *    tools/integration/lab-functional.sh send no Origin, and rejecting them
 *    would break every non-browser client to defend against a threat that does
 *    not exist there. A missing Origin is not a CSRF signal. A mismatched one
 *    is. Every browser that can be made to issue a cross-site POST sends one of
 *    the two -- including the Referrer-Policy: no-referrer and sandboxed-iframe
 *    cases, which send the literal "Origin: null" and are rejected here as the
 *    mismatch they are.
 *
 *    Hostname only: scheme and port are not compared. An appliance is commonly
 *    reached over both listeners or fronted by a TLS-terminating proxy, where
 *    the browser's Origin says :443 and HTTP_HOST does not, and a port
 *    comparison would 403 legitimate traffic. Exact-hostname matching is still
 *    strictly stronger than the SameSite attribute it backs up, which compares
 *    registrable domains and would let a sibling subdomain through.
 *
 * 2. The body encoding must be one this API actually consumes: JSON, or
 *    multipart/form-data for the two genuine upload paths (/api/import and
 *    /api/labs/session/pictures/add, which read $_FILES). A request with no
 *    body, or with no Content-Type at all, is fine.
 *
 *    This rejects application/x-www-form-urlencoded and text/plain, which are
 *    the encodings an HTML form can produce and no first-party client sends.
 *    Defence in depth behind rule 1, for a browser too old to send Origin.
 *    BEWARE: it is a real behaviour change for third-party scripts. `curl -d`
 *    defaults to application/x-www-form-urlencoded, and json_decode() used to
 *    ignore that; such a caller must now add
 *    -H 'Content-Type: application/json'. lab-functional.sh already does.
 *
 * Rules are pure functions so tests/Security/LegacyApiOriginTest.php can
 * exercise the decision directly rather than asserting that some string appears
 * in a file.
 */

/**
 * The verbs that can change state, and so must be policed.
 *
 * @return string[] upper case, no aliases
 */
function apiGuardedMethods()
{
	return array('POST', 'PUT', 'PATCH', 'DELETE');
}

/**
 * Media types a mutating request may declare.
 *
 * @return string[] lower case, no parameters
 */
function apiAcceptedMediaTypes()
{
	return array('application/json', 'multipart/form-data');
}

/**
 * The hostname a Host header names.
 *
 * Accepts "box", "box:8080", "[::1]:80". Returns '' when there is nothing
 * usable, which the caller must treat as a failure to identify the host rather
 * than as a match.
 *
 * @param  string $host
 * @return string lower case, no trailing dot, no port
 */
function apiHostHostname($host)
{
	$host = trim((string) $host);
	if ($host === '') return '';
	// parse_url wants a scheme or at least an authority marker before it will
	// read an authority; '//' is enough and cannot change the hostname.
	$parsed = parse_url('//' . $host);
	if (!is_array($parsed) || !isset($parsed['host']) || $parsed['host'] === '') return '';
	return strtolower(rtrim($parsed['host'], '.'));
}

/**
 * The hostname an Origin or Referer header names.
 *
 * A scheme is required, so the opaque origin -- the literal string "null" that
 * browsers send from a sandboxed iframe, a data: document or under
 * Referrer-Policy: no-referrer -- yields '' and is rejected by the caller.
 *
 * @param  string $value
 * @return string lower case, no trailing dot, no port; '' if unusable
 */
function apiOriginHostname($value)
{
	$value = trim((string) $value);
	if ($value === '') return '';
	if (!preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*://~', $value)) return '';
	$parsed = parse_url($value);
	if (!is_array($parsed) || !isset($parsed['host']) || $parsed['host'] === '') return '';
	return strtolower(rtrim($parsed['host'], '.'));
}

/**
 * The media type of a Content-Type header, without its parameters.
 *
 * @param  string $contentType
 * @return string lower case; '' when the header is absent or empty
 */
function apiMediaType($contentType)
{
	$contentType = trim((string) $contentType);
	if ($contentType === '') return '';
	$parts = explode(';', $contentType);
	return strtolower(trim($parts[0]));
}

/**
 * The whole decision, as a pure function.
 *
 * @param  string $method      the request verb
 * @param  string $origin      the Origin header, '' if absent
 * @param  string $referer     the Referer header, '' if absent
 * @param  string $host        the Host header the request was addressed to
 * @param  string $contentType the Content-Type header, '' if absent
 * @param  int    $bodyLength  length of the request body in bytes
 * @return string|null null to allow; otherwise the reason for the refusal
 */
function apiCsrfVerdict($method, $origin, $referer, $host, $contentType, $bodyLength)
{
	if (!in_array(strtoupper(trim((string) $method)), apiGuardedMethods(), true)) {
		return null;
	}

	// 1. Origin, else Referer. Neither present means "not a browser".
	$declared = '';
	if (trim((string) $origin) !== '') {
		$declared = $origin;
	} elseif (trim((string) $referer) !== '') {
		$declared = $referer;
	}

	if ($declared !== '') {
		$expected = apiHostHostname($host);
		$actual = apiOriginHostname($declared);
		if ($expected === '' || $actual === '' || $actual !== $expected) {
			return 'Cross-origin request refused';
		}
	}

	// 2. The body encoding must be one the handlers actually read.
	if ((int) $bodyLength > 0) {
		$media = apiMediaType($contentType);
		if ($media !== '' && !in_array($media, apiAcceptedMediaTypes(), true)
			&& substr($media, -5) !== '+json') {
			return 'Unsupported Content-Type for a state-changing request';
		}
	}

	return null;
}

/**
 * Read the decision's inputs off a Slim request and apply it.
 *
 * Split out from the verdict so the rules stay testable without Slim, and so
 * the header names are named in exactly one place.
 *
 * @param  \Slim\Slim $app
 * @return string|null null to allow; otherwise the reason for the refusal
 */
function apiCsrfVerdictFor($app)
{
	$request = $app->request();
	$body = $request->getBody();

	return apiCsrfVerdict(
		$request->getMethod(),
		// Slim lower-cases header names into HTTP_ form; headers() handles both.
		(string) $request->headers('ORIGIN', ''),
		(string) $request->getReferer(),
		// HTTP_HOST, not SERVER_NAME: the browser computes Origin from the URL
		// it was given, which is the same value it put in Host. X-Forwarded-Host
		// is deliberately not consulted -- it is attacker-settable.
		(string) $request->headers('HOST', ''),
		(string) $request->getContentType(),
		is_string($body) ? strlen($body) : 0
	);
}

/**
 * Register the guard on the application.
 *
 * Call this once, before the route table. It halts with 403 and the same JSON
 * envelope the rest of the API uses, so an existing client's error handling
 * (getJsonMessage() in themes/default/js/functions.js, error_helper.js in the
 * SPA) reports it rather than choking.
 *
 * @param  \Slim\Slim $app
 * @return void
 */
function apiRegisterOriginGuard($app)
{
	$app->hook('slim.before.router', function () use ($app) {
		$reason = apiCsrfVerdictFor($app);
		if ($reason === null) return;

		$app->log->warn(sprintf(
			'CSRF guard refused %s %s: %s',
			$app->request()->getMethod(),
			$app->request()->getPathInfo(),
			$reason
		));

		$app->halt(403, json_encode(array(
			'code' => 403,
			'status' => 'forbidden',
			'message' => $reason
		)));
	});
}
