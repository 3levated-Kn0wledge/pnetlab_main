<?php
/**
 * Slim - a micro PHP 5 framework
 *
 * @author      Josh Lockhart <info@slimframework.com>
 * @copyright   2011 Josh Lockhart
 * @link        http://www.slimframework.com
 * @license     http://www.slimframework.com/license
 * @version     2.6.1
 * @package     Slim
 *
 * MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
namespace Slim\Middleware;

/**
 * Session Cookie
 *
 * This class provides an HTTP cookie storage mechanism
 * for session data. This class avoids using a PHP session
 * and instead serializes/unserializes the $_SESSION global
 * variable to/from an HTTP cookie.
 *
 * You should NEVER store sensitive data in a client-side cookie
 * in any format, encrypted (with cookies.encrypt) or not. If you
 * need to store sensitive user information in a session, you should
 * rely on PHP's native session implementation, or use other middleware
 * to store session data in a database or alternative server-side cache.
 *
 * Because this class stores serialized session data in an HTTP cookie,
 * you are inherently limited to 4 Kb. If you attempt to store
 * more than this amount, serialization will fail.
 *
 * @package     Slim
 * @author     Josh Lockhart
 * @since      1.6.0
 */
class SessionCookie extends \Slim\Middleware
{
    /**
     * @var array
     */
    protected $settings;

    /**
     * Constructor
     *
     * @param array $settings
     */
    public function __construct($settings = array())
    {
        $defaults = array(
            'expires' => '20 minutes',
            'path' => '/',
            'domain' => null,
            // Only mark the cookie Secure when the request actually arrived over
            // TLS. The appliance is reachable on plain HTTP as well, and a
            // hardcoded true would make the browser withhold the cookie there,
            // silently breaking every session on the HTTP listener.
            'secure' => self::isRequestSecure(),
            // No script has any reason to read the session cookie, so keep it
            // out of document.cookie and away from XSS. Unconditional: HttpOnly
            // costs nothing on either listener.
            'httponly' => true,
            'name' => 'slim_session',
            // No SameSite. This is Slim 2.6.1 (see \Slim\Slim::VERSION), whose
            // cookie writer -- \Slim\Http\Util::setCookieHeader() -- only
            // understands domain, path, expires, secure and httponly, and whose
            // \Slim\Slim::setCookie() has no parameter to carry anything else.
            // A 'samesite' key here would be silently dropped on the floor and
            // read as protection that is not actually being applied. Adding it
            // for real means editing Util.php and Slim.php, which is out of
            // scope here; see the report.
        );
        $this->settings = array_merge($defaults, $settings);
        if (is_string($this->settings['expires'])) {
            $this->settings['expires'] = strtotime($this->settings['expires']);
        }

        /**
         * Session
         *
         * We must start a native PHP session to initialize the $_SESSION superglobal.
         * However, we won't be using the native session store for persistence, so we
         * disable the session cookie and cache limiter. We also set the session
         * handler to this class instance to avoid PHP's native session file locking.
         */
        ini_set('session.use_cookies', 0);
        session_cache_limiter(false);
        session_set_save_handler(
            array($this, 'open'),
            array($this, 'close'),
            array($this, 'read'),
            array($this, 'write'),
            array($this, 'destroy'),
            array($this, 'gc')
        );
    }

    /**
     * Is the current request being served over TLS?
     *
     * The equivalent of Laravel's request()->isSecure(), which is what
     * store/app/Http/Controllers/Auth/LoginController.php uses for the same
     * decision. Slim 2 has no such helper, so read the server environment
     * directly: $_SERVER['HTTPS'] for a direct TLS connection, plus the port and
     * the forwarding headers a reverse proxy sets when it terminates TLS itself.
     *
     * Fails closed to false, which yields a cookie without Secure -- usable over
     * both schemes. Guessing true on a plain-HTTP appliance would lock users out.
     *
     * @return bool
     */
    protected static function isRequestSecure()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_SSL'])
            && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }

        return false;
    }

    /**
     * Call
     */
    public function call()
    {
        $this->loadSession();
        $this->next->call();
        $this->saveSession();
    }

    /**
     * Load session
     */
    protected function loadSession()
    {
        if (session_id() === '') {
            session_start();
        }
        $value = $this->app->getCookie($this->settings['name']);
        if ($value) {
            $value = json_decode($value, true);
            $_SESSION = is_array($value) ? $value : array();
        } else {
            $_SESSION = array();
        }
    }

    /**
     * Save session
     */
    protected function saveSession()
    {
        $value = json_encode($_SESSION);

        if (strlen($value) > 4096) {
            $this->app->getLog()->error('WARNING! Slim\Middleware\SessionCookie data size is larger than 4KB. Content save failed.');
        } else {
            $this->app->setCookie(
                $this->settings['name'],
                $value,
                $this->settings['expires'],
                $this->settings['path'],
                $this->settings['domain'],
                $this->settings['secure'],
                $this->settings['httponly']
            );
        }
        // session_destroy();
    }

    /********************************************************************************
    * Session Handler
    *******************************************************************************/

    /**
     * @codeCoverageIgnore
     */
    public function open($savePath, $sessionName)
    {
        return true;
    }

    /**
     * @codeCoverageIgnore
     */
    public function close()
    {
        return true;
    }

    /**
     * @codeCoverageIgnore
     */
    public function read($id)
    {
        return '';
    }

    /**
     * @codeCoverageIgnore
     */
    public function write($id, $data)
    {
        return true;
    }

    /**
     * @codeCoverageIgnore
     */
    public function destroy($id)
    {
        return true;
    }

    /**
     * @codeCoverageIgnore
     */
    public function gc($maxlifetime)
    {
        return true;
    }
}
