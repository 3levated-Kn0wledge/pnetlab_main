<?php
/**
 * Write /etc/apt/apt.conf.d/00proxy, as root, without a shell.
 *
 * WHAT THIS REPLACES
 *
 * store/app/Helpers/Request/Query.php::setProxy() was the shortest path from an
 * HTTP request to root on this appliance:
 *
 *     $file = '/etc/apt/apt.conf.d/00proxy';
 *     if(!is_file($file)) exec('sudo touch '.$file);
 *     $proxyAddr = $p['proxy_username'].':'.$p['proxy_password'].'@'
 *                . $p['proxy_ip'].':'.$p['proxy_port'];
 *     ...
 *     $result = exec("echo '".$proxyAddr."' | sudo tee ".$file);
 *
 * All four components came off the request body. They were interpolated into a
 * SINGLE-QUOTED shell string, so one apostrophe in any of them closes the quote
 * and the rest of the value is parsed as shell — running as www-data, but the
 * pipeline's right-hand side is `sudo tee`, and the same apostrophe also lets
 * the attacker choose what `tee` writes.
 *
 * The second half is the worse half. The destination is an apt configuration
 * file, and apt configuration is not data: a 00proxy containing
 *
 *     APT::Update::Pre-Invoke {"/bin/sh -c '...'";};
 *
 * executes that string as root at the next `apt update` — which this box runs,
 * from the installer and from unattended-upgrades. So the write was a deferred
 * root shell even without the quoting bug.
 *
 * A third thing was wrong, and it is why nobody noticed: the file it produced
 * never worked. The template string is SINGLE-quoted in PHP, so its '\n' were
 * two literal characters, and `echo` without -e passed them through. The file
 * was one long line containing backslash-n, which apt cannot parse and which
 * getProxy()'s own /m regex could never match. The feature had been broken since
 * it was written; only the vulnerability worked.
 *
 * WHAT THIS DOES INSTEAD
 *
 *   - the four components arrive as four separately validated options. Nothing
 *     is concatenated before it is checked, and no path is ever received: the
 *     destination is a constant in this file.
 *   - the host must be an IPv4 literal, an IPv6 literal, or a hostname; the
 *     port must be 1-65535; the username is a conservative slug. None of those
 *     classes can hold a quote, a backslash, a newline, an '@' or a ':'.
 *   - the password is allowed the printable ASCII range, because a proxy
 *     password is not ours to restrict, and is then PERCENT-ENCODED into the
 *     URL. rawurlencode() leaves only [A-Za-z0-9._~-], so the richer class
 *     cannot reach the file as syntax. That is the trade the alternative — a
 *     wider character class written raw — never makes safely.
 *   - the file is written atomically: a temp file in the same directory, chmod,
 *     chown, rename. A half-written apt.conf.d entry is a broken apt.
 *   - clearing removes the file. The old code "cleared" by writing an empty
 *     string, which left a zero-byte file behind; unlinking is what the caller
 *     actually means and what apt actually wants.
 *
 * There is no exec-family call anywhere in this file. Nothing here builds a
 * command line, so there is nothing to escape.
 */

class UnlSetProxy
{
    /** The only file this action will ever write. Not a parameter. */
    const FILE = '/etc/apt/apt.conf.d/00proxy';

    /** RFC 1035-shaped hostname, or an IP literal. */
    const HOST_MAX = 253;

    /** A proxy username. No quote, no backslash, no newline, no '@', no ':'. */
    const USER_RE = '/^[A-Za-z0-9._-]{1,64}\z/';

    /**
     * A proxy password: printable ASCII, no space. Wider than the username
     * class ON PURPOSE and safe only because every byte of it is percent-encoded
     * before it reaches the file. Control characters, including the newline that
     * would split one apt directive into two, are outside the class.
     */
    const PASS_RE = '/^[\x21-\x7E]{1,128}\z/';

    const PORT_MIN = 1;
    const PORT_MAX = 65535;

    /**
     * The web user reads this file back — getProxy() renders it into the admin
     * form, and Query::make() takes curl's proxy from it — so www-data has to
     * be able to open it. When it holds a password it is therefore 0640
     * root:www-data rather than the 0644 root:root that `sudo touch` produced,
     * which put a proxy password in a world-readable file.
     *
     * The cost was measured rather than assumed: apt run by an account that
     * cannot read the file prints
     *     W: Unable to read /etc/apt/apt.conf.d/00proxy - open (13: Permission denied)
     * and continues, exit status 0. Root apt, which is the only apt this
     * appliance runs, is unaffected. A warning for unprivileged `apt-cache` is
     * the right side of that trade; a credential-free file stays 0644 so the
     * common case is silent.
     */
    const MODE_PLAIN = 0644;
    const MODE_AUTH  = 0640;

    private $file;

    public function __construct(array $options = array())
    {
        // 'file' exists for the tests, which must not write into /etc. Nothing
        // on the command line can set it: the wrapper does not pass it through.
        $this->file = isset($options['file']) ? $options['file'] : self::FILE;
    }

    // ------------------------------------------------------------- validation

    /**
     * A host, ready to place in a URL, or null.
     *
     * IPv6 comes back bracketed, because that is what a URL needs and because
     * bracketing it here means the caller never sends brackets — a value that
     * arrives already bracketed is refused by the pre-check below.
     */
    public static function host($value)
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::HOST_MAX) return null;

        // One pre-check, so the branches below can never be handed a byte that
        // matters to a URL, a shell or an apt config. \z, not $: '$' also
        // matches immediately before a trailing newline.
        if (!preg_match('/^[A-Za-z0-9.:-]{1,253}\z/', $value)) return null;

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $value;
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) return '[' . $value . ']';

        // A hostname: dot-separated labels, each 1-63 characters, starting and
        // ending alphanumeric. A trailing dot is refused rather than tolerated.
        $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        if (preg_match('/^' . $label . '(?:\.' . $label . ')*\z/', $value)) return $value;

        return null;
    }

    /** A port as an int, or null. */
    public static function port($value)
    {
        if (is_array($value) || is_object($value) || is_bool($value) || $value === null) return null;
        $value = (string) $value;
        if (!preg_match('/^[0-9]{1,5}\z/', $value)) return null;
        $n = (int) $value;
        return ($n < self::PORT_MIN || $n > self::PORT_MAX) ? null : $n;
    }

    public static function user($value)
    {
        if (!is_string($value) || !preg_match(self::USER_RE, $value)) return null;
        return $value;
    }

    public static function password($value)
    {
        if (!is_string($value) || !preg_match(self::PASS_RE, $value)) return null;
        return $value;
    }

    // ------------------------------------------------------------------ entry

    /**
     * @param  mixed $host      IPv4/IPv6 literal or hostname; '' or null clears
     * @param  mixed $port      1-65535
     * @param  mixed $user      null when the proxy needs no credentials
     * @param  mixed $password  null when the proxy needs no credentials
     * @return array ['ok'=>bool,'error'=>string|null,'action'=>'set'|'clear',
     *                'host'=>string|null,'port'=>int|null,'auth'=>bool]
     *
     * The result deliberately carries no credential. It is echoed as JSON on
     * stdout and the web layer logs what it reads.
     */
    public function run($host, $port, $user = null, $password = null)
    {
        $fail = function ($why) {
            return array('ok' => false, 'error' => $why, 'action' => null,
                         'host' => null, 'port' => null, 'auth' => false);
        };

        $blank = function ($v) { return $v === null || $v === false || $v === ''; };

        if ($blank($host)) {
            // Clearing is a whole-settings operation: a port or a credential
            // with no host is a mistake, not a clear.
            if (!$blank($port) || !$blank($user) || !$blank($password)) {
                return $fail('a proxy needs a host');
            }
            return $this->clear();
        }

        $h = self::host($host);
        if ($h === null) return $fail('host must be an IPv4 literal, an IPv6 literal or a hostname');

        $p = self::port($port);
        if ($p === null) return $fail('port must be an integer between '
            . self::PORT_MIN . ' and ' . self::PORT_MAX);

        $auth = '';
        if (!$blank($user) || !$blank($password)) {
            // Both or neither. Half a credential silently became "username with
            // an empty password" in the old code, which is a different proxy.
            if ($blank($user) || $blank($password)) {
                return $fail('a proxy credential needs both a username and a password');
            }
            $u = self::user($user);
            if ($u === null) return $fail('username must match ' . self::USER_RE);
            // The password is never quoted back in an error. It is a secret and
            // this string reaches a log.
            if (self::password($password) === null) {
                return $fail('password must be 1-128 printable ASCII characters with no space');
            }
            $auth = rawurlencode($u) . ':' . rawurlencode($password) . '@';
        }

        $url = 'http://' . $auth . $h . ':' . $p . '/';

        // Belt and braces. Every component above is already constrained, and
        // rawurlencode() leaves only [A-Za-z0-9._~-]; this says so out loud so
        // that widening any class later trips here instead of in apt.
        if (preg_match('/[^\x21-\x7E]/', $url) || strpos($url, '"') !== false
            || strpos($url, '\\') !== false) {
            return $fail('refusing to write a proxy URL with unexpected characters');
        }

        $content = "// Written by unl_wrapper -a set-proxy. Edits are overwritten.\n"
            . 'Acquire::http::Proxy "' . $url . "\";\n"
            . 'Acquire::https::Proxy "' . $url . "\";\n"
            . 'Acquire::ftp::Proxy "' . $url . "\";\n";

        if (!$this->write($content, $auth !== '')) return $fail('could not write ' . $this->file);

        return array('ok' => true, 'error' => null, 'action' => 'set',
                     'host' => $h, 'port' => $p, 'auth' => $auth !== '');
    }

    /** Remove the file. Absent is the state being asked for, so absent is success. */
    private function clear()
    {
        $ok = true;
        if (is_link($this->file) || file_exists($this->file)) {
            $ok = @unlink($this->file);
        }
        return array('ok' => (bool) $ok,
                     'error' => $ok ? null : 'could not remove ' . $this->file,
                     'action' => 'clear', 'host' => null, 'port' => null, 'auth' => false);
    }

    /**
     * Atomic replace: temp file in the SAME directory, permissions set while
     * nothing can read it under its final name, then rename().
     *
     * Same directory matters twice — rename() is only atomic within a
     * filesystem, and a temp file in /tmp would be a temp file in a directory
     * every local account can write.
     */
    private function write($content, $hasAuth)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) return false;

        $tmp = $dir . '/.' . basename($this->file) . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $content) !== strlen($content)) {
            @unlink($tmp);
            return false;
        }
        // Mode and ownership are set on the temp file, so the name callers read
        // never exists with the wrong permissions for an instant. chown is a
        // no-op anywhere but root, which is the tests and nowhere else.
        @chmod($tmp, $hasAuth ? self::MODE_AUTH : self::MODE_PLAIN);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($tmp, 0);
            $group = $hasAuth && function_exists('posix_getgrnam')
                ? posix_getgrnam('www-data') : false;
            @chgrp($tmp, $group === false ? 0 : $group['gid']);
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
