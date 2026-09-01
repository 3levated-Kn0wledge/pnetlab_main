<?php

namespace App\Helpers\Auth;

use Illuminate\Support\Facades\Cookie;

/**
 * The one place the `token` cookie's attributes are written down.
 *
 * `token` is the whole authenticator for both halves of this application. The
 * Laravel side reads it through JwtGuard; the legacy Slim API in api.php reads
 * the same plaintext cookie (it is listed in EncryptCookies::$except) and
 * authenticates from it alone -- no header, no nonce. api.php is reached by a
 * rewrite in the root .htaccess and never enters Laravel's kernel, so
 * VerifyCsrfToken cannot protect it however it is configured.
 *
 * That made SameSite=Lax on this cookie a load-bearing control rather than a
 * nicety, and it was being applied inconsistently: two call sites passed 'Lax'
 * and six did not. Cookie::make() defaults $sameSite to null and emits no
 * attribute, so the first token refresh after login silently downgraded the
 * cookie and re-opened roughly fifty mutating legacy endpoints to a cross-site
 * form POST. Nothing failed; nothing logged; the attribute just went away.
 *
 * Hence this class. It is deliberately the only place in store/app that calls
 * Cookie::make('token', ...), and tests/Security/CookieAttributesTest.php
 * fails if a second one appears. Add a new issuance site by calling issue() or
 * forget(), not by copying the argument list.
 *
 * Deletion matters as much as issuance. A browser removes a cookie only when
 * the clearing Set-Cookie matches its name, domain and path, so a clear scoped
 * to APP_DOMAIN cannot remove a cookie that was set for the served host -- and
 * that was exactly the shape of the three logout paths here. forget() therefore
 * clears every scope this application has ever issued the cookie on.
 */
class AuthCookie
{
    /**
     * Lax, not Strict. Strict would drop the cookie on the top-level navigation
     * back from APP_AUTHEN into /auth/login/license, and on any bookmark or
     * external link into the UI, logging the user out for no security gain that
     * the origin guard in includes/api_origin_guard.php does not already give.
     */
    const SAME_SITE = 'Lax';

    /** The cookie name both layers agree on. */
    const NAME = 'token';

    /** Both layers serve from the document root; a narrower path breaks /api. */
    const PATH = '/';

    /**
     * The host the appliance is actually being served from.
     *
     * SERVER_NAME, not HTTP_HOST: this is the value the offline login path has
     * always used, and it comes from the vhost rather than from the request.
     */
    public static function host()
    {
        return isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : null;
    }

    /**
     * Queue the authentication cookie.
     *
     * @param string      $value   the session token
     * @param int         $minutes lifetime
     * @param string|null $domain  scope; defaults to the served host. The online
     *                             path passes APP_DOMAIN explicitly -- see the
     *                             note on scopes() -- and keeping that a caller
     *                             decision means this change does not quietly
     *                             alter where the online cookie lands.
     */
    public static function issue($value, $minutes, $domain = null)
    {
        Cookie::queue(self::build($value, $minutes, $domain === null ? self::host() : $domain));
    }

    /**
     * Queue a clearing cookie for every scope the token has ever been issued on.
     *
     * Sending more than one is not tidiness: the offline path scopes the cookie
     * to SERVER_NAME and the online path to APP_DOMAIN, and a clear for one has
     * no effect on the other. Logging out used to clear only APP_DOMAIN, which
     * on an appliance served from anything else was a no-op.
     */
    public static function forget()
    {
        foreach (self::scopes() as $domain) {
            Cookie::queue(self::build(null, -3600, $domain));
        }
    }

    /**
     * Every domain scope a `token` cookie may exist under.
     *
     * APP_DOMAIN is user.pnetlab.com, which is not a domain the appliance is
     * served from, so the browser rejects the online path's cookie outright.
     * That is a separate problem, tracked in docs/OFFLINE-FIRST.md; clearing it
     * anyway costs one header and stops this method being wrong if the online
     * path is ever repaired rather than removed.
     */
    private static function scopes()
    {
        $scopes = array(self::host());
        if (defined('APP_DOMAIN') && APP_DOMAIN !== '' && !in_array(APP_DOMAIN, $scopes, true)) {
            $scopes[] = APP_DOMAIN;
        }
        return $scopes;
    }

    /**
     * The attribute list. Written down once, on purpose.
     */
    private static function build($value, $minutes, $domain)
    {
        return Cookie::make(
            self::NAME,
            $value,
            $minutes,
            self::PATH,
            $domain,
            request()->isSecure(),  // Secure only when actually served over TLS
            true,                   // HttpOnly
            false,                  // not raw
            self::SAME_SITE         // SameSite
        );
    }
}
