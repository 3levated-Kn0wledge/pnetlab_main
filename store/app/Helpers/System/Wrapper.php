<?php
namespace App\Helpers\System;

/**
 * The web layer's side of the enumerated wrapper actions.
 *
 * Everything here runs as www-data and says one of a handful of fixed things to
 * unl_wrapper. It exists so the three controllers that used to run
 * `sudo chown ...` and `... | sudo tee ...` share one boundary crossing instead
 * of open-coding three of them.
 *
 * THE SHAPE
 *
 * proc_open() with an argv ARRAY. There is no shell on this path, so no value
 * needs escaping and none is escaped — escapeshellarg() on an argv element
 * would corrupt the argument while making the code look safer. The argv is
 * assembled here and every element is either a literal or a value the wrapper
 * revalidates on the far side; this side's validation is a courtesy that lets
 * the caller get a sensible message, never the boundary.
 *
 * The secret goes in on STDIN. A proxy password passed as an argument would be
 * visible in /proc/<pid>/cmdline to every local account for as long as the
 * wrapper runs, and the web user is granted `sudo ps`.
 */
class Wrapper
{
    const SUDO    = '/usr/bin/sudo';
    const WRAPPER = '/opt/unetlab/wrappers/unl_wrapper';

    /**
     * The scope words `-a fixperms` accepts.
     *
     * Repeated here so a typo in a controller is a local failure with a clear
     * message rather than a wrapper exit code. The wrapper holds the real
     * enumeration and the paths; this list cannot widen what it accepts.
     */
    const SCOPES = array('addons', 'templates', 'icons', 'scripts', 'labs', 'dependencies');

    /**
     * Hand one enumerated tree back to www-data.
     *
     * @param  string $scope one of self::SCOPES
     * @return array ['ok'=>bool,'error'=>string|null,...]
     */
    public static function fixperms($scope)
    {
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            return array('ok' => false, 'error' => 'unknown fixperms scope');
        }
        return self::run(array(self::SUDO, self::WRAPPER, '-a', 'fixperms', '--scope', $scope),
            null, 'FIXPERMS-RESULT ');
    }

    /**
     * Write or clear /etc/apt/apt.conf.d/00proxy.
     *
     * Empty settings clear it. That is what the admin screen means by saving an
     * empty form, and it is the one case the old code got right in intent — it
     * wrote an empty string, leaving a zero-byte file; the wrapper removes it.
     *
     * @param  array $p proxy_ip, proxy_port, proxy_username, proxy_password
     * @return array ['ok'=>bool,'error'=>string|null,...]
     */
    public static function setProxy(array $p)
    {
        $get = function ($k) use ($p) {
            if (!isset($p[$k]) || is_array($p[$k]) || is_object($p[$k])) return '';
            return trim((string) $p[$k]);
        };

        $host = $get('proxy_ip');
        if ($host === '') {
            return self::run(array(self::SUDO, self::WRAPPER, '-a', 'set-proxy', '--clear'),
                null, 'SET-PROXY-RESULT ');
        }

        $port     = $get('proxy_port');
        $user     = $get('proxy_username');
        $password = $get('proxy_password');

        // Built branch by branch rather than merged, so every proc_open() below
        // is handed a plain array literal. The tokenizer sweep in
        // tests/Security/ShellEscapingTest.php can prove that shape spawns no
        // shell; it cannot prove it of an array whose contents were computed
        // elsewhere, and it is right not to.
        if ($user === '' && $password === '') {
            return self::run(array(self::SUDO, self::WRAPPER, '-a', 'set-proxy',
                '--proxy-host', $host, '--proxy-port', $port), null, 'SET-PROXY-RESULT ');
        }

        return self::run(array(self::SUDO, self::WRAPPER, '-a', 'set-proxy',
            '--proxy-host', $host, '--proxy-port', $port,
            '--proxy-user', $user, '--proxy-pass-stdin'), $password, 'SET-PROXY-RESULT ');
    }

    /**
     * Exec the wrapper and pull its one result line out of stdout.
     *
     * @param  array       $argv   program and arguments; no shell is involved
     * @param  string|null $stdin  written and closed before output is read
     * @param  string      $marker the prefix of the JSON result line
     * @return array
     */
    private static function run(array $argv, $stdin, $marker)
    {
        $desc = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $proc = @proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) {
            return array('ok' => false, 'error' => 'could not run the platform wrapper');
        }

        if ($stdin !== null && $stdin !== '') fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        foreach (explode("\n", $out) as $line) {
            if (strpos($line, $marker) !== 0) continue;
            $decoded = json_decode(substr($line, strlen($marker)), true);
            if (is_array($decoded) && isset($decoded['ok'])) {
                return $decoded + array('error' => null);
            }
        }
        return array('ok' => false, 'code' => $code,
            'error' => $code === 0 ? 'the wrapper returned no result' : trim($err));
    }
}
