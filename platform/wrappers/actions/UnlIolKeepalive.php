<?php
/**
 * The IOL L1 keepalive helper, and the gadget it replaces.
 *
 * WHAT THIS REPLACES
 *
 * An IOL node with keepalive enabled needs a small perl helper running on each
 * link, and that helper has to run as the tenant account that owns the tap —
 * not as root. devices/interfc.php arranged that like this:
 *
 *     $cmd = 'sudo perl <runningPath>/keepalive.pl -i .. -p .. -n .. > .. 2>&1 &';
 *     exec("sudo php .../store/app/Console/Commands/wrapper 32768 $uid '$cmd'");
 *
 * and store/app/Console/Commands/wrapper was thirteen lines:
 *
 *     posix_setsid();
 *     if ($gid > 0) posix_setgid($argv[1]);
 *     if ($uid > 0) posix_setuid($argv[2]);
 *     if ($cmd != '') return shell_exec($argv[3]);
 *
 * That is not a keepalive launcher. It is "run this string as root, or first
 * drop to any uid you name, then run it" — a general-purpose root-exec
 * primitive, reachable from an ordinary link-state change, with the string
 * assembled by string concatenation in the web layer. Passing 0 for the uid
 * kept it running as root. Nothing in it was specific to keepalive at all.
 *
 * WHAT THIS DOES INSTEAD
 *
 * The caller says which node session and which interface, and whether the link
 * is up or down. Everything else is decided here:
 *
 *   - the uid is COMPUTED (32768 + session) and then CONFIRMED against the
 *     passwd database: the account must be named unl<session> and must already
 *     hold exactly that uid, or nothing runs. The caller cannot name a uid, and
 *     0 is not reachable by any input.
 *   - the script is the fixed path /opt/unetlab/addons/iol/bin/keepalive.pl.
 *     NOT <runningPath>/keepalive.pl, which is where the old call site read it
 *     from: /opt/unetlab/tmp is mode 777, so the symlink the node's prepare()
 *     creates there can be replaced by anyone who can write a node workspace.
 *   - the argv is an ARRAY handed to pcntl_exec(). There is no shell anywhere on
 *     this path, so there is nothing for a metacharacter to mean.
 *   - bringing a link down resolves pids from /proc by the tenant uid and the
 *     script path, never from a caller-supplied pid and never from the output of
 *     `ps -aux | grep`, which is what the old code fed to `sudo kill -9`.
 *
 * The argv the helper receives is byte-identical to what the old path produced.
 * keepalive.pl ships with the licensed IOL addon and is not in this repository,
 * so its behaviour cannot be tested here and must not be guessed at.
 */

class UnlIolKeepalive
{
    /** The group every tenant account belongs to. */
    const TENANT_GID = 32768;
    /** uid of the account for node session N. Mirrors checkUsername() in includes/cli.php. */
    const TENANT_UID_BASE = 32768;
    /** Session ids are database auto-increments; this bounds the uid arithmetic. */
    const MAX_SESSION = 1000000;
    /** IOL nodes have at most 64 interfaces; 255 is generous and still bounded. */
    const MAX_IFACE = 255;
    /** IOL instance ids are small and per-pod. */
    const MAX_IOL_ID = 1024;

    /** The one script this action is allowed to run. Never taken from the caller. */
    private $script;
    /** The interpreter. Fixed, and only ever exec'd with an argv array. */
    private $perl;
    /** Root of the node workspaces. Nothing outside it is touched. */
    private $tmpRoot;
    /** callable(int $session): array|null — ['lab' => int, 'type' => string, 'iol_id' => int] */
    private $lookup;
    /** callable(): array of ['pid' => int, 'uid' => int, 'argv' => string[]] */
    private $procLister;
    /** callable(string $name): array|null — passwd entry, defaults to posix_getpwnam(). */
    private $pwnam;
    /** False in tests: record what would have been done instead of doing it. */
    private $runCommands;

    /** Recorded rather than run when run_commands is false. */
    public $commands = array();
    /** Recorded rather than sent when run_commands is false. */
    public $signals = array();

    public function __construct(array $options = array())
    {
        $prefix = isset($options['prefix']) ? rtrim($options['prefix'], '/') : '';
        $this->script  = isset($options['script'])   ? $options['script']   : $prefix . '/opt/unetlab/addons/iol/bin/keepalive.pl';
        $this->perl    = isset($options['perl'])     ? $options['perl']     : '/usr/bin/perl';
        $this->tmpRoot = isset($options['tmp_root']) ? rtrim($options['tmp_root'], '/') : $prefix . '/opt/unetlab/tmp';
        $this->lookup  = isset($options['lookup'])   ? $options['lookup']   : null;
        $this->procLister = isset($options['proc_lister']) ? $options['proc_lister'] : null;
        $this->pwnam   = isset($options['pwnam'])    ? $options['pwnam']    : null;
        $this->runCommands = array_key_exists('run_commands', $options)
            ? (bool) $options['run_commands']
            : true;
    }

    // ------------------------------------------------------------- validation

    /**
     * An id is a string of digits and nothing else.
     *
     * (int) is not enough on its own: (int) '12; id' is 12, and (int) on an
     * array — which getopt() returns the moment an option is repeated — is 1.
     * Both of those used to be how a value got past a numeric-looking check.
     */
    private static function id($value, $min, $max)
    {
        if (is_array($value) || is_object($value) || is_bool($value) || $value === null) return null;
        $value = (string) $value;
        // \z, not $. In PCRE without /D, `$` also matches immediately before a
        // trailing newline, so '/^[0-9]+$/' accepts "42\n" — and a value that
        // ends in a newline is exactly the one that would have been interesting
        // had anything downstream still built a command line. The test that
        // caught this is in tests/Security/IolKeepaliveTest.php; do not
        // "simplify" this back.
        if ($value === '' || !preg_match('/^[0-9]+\z/', $value)) return null;
        $n = (int) $value;
        if ($n < $min || $n > $max) return null;
        return $n;
    }

    private function passwd($name)
    {
        if ($this->pwnam !== null) return call_user_func($this->pwnam, $name);
        if (!function_exists('posix_getpwnam')) return null;
        $entry = posix_getpwnam($name);
        return $entry === false ? null : $entry;
    }

    /**
     * The uid to drop to, or null.
     *
     * Computed, then confirmed. An account called unl<session> that does NOT
     * hold uid 32768+session is not the platform's tenant account and is
     * refused rather than used — that is the case where someone has managed to
     * create a same-named account pointing somewhere more interesting.
     */
    private function tenantUid($session)
    {
        $expected = self::TENANT_UID_BASE + $session;
        $entry = $this->passwd('unl' . $session);
        if (!is_array($entry) || !isset($entry['uid'])) return null;
        if ((int) $entry['uid'] !== $expected) return null;
        if ($expected < self::TENANT_UID_BASE) return null;   // unreachable; kept as a floor
        return $expected;
    }

    /**
     * The tenant's primary gid, read from its passwd entry, and refused if it
     * is not the platform group. useradd -g unl is what creates these, so a
     * different primary group means the account is not the platform's -- the
     * same reasoning UnlTenantAccount applies before it will remove one.
     * Returned rather than hard-coded so the drop below and the check here
     * cannot disagree.
     */
    private function tenantGid($session)
    {
        $entry = $this->passwd('unl' . $session);
        if (!is_array($entry) || !isset($entry['gid'])) return null;
        if ((int) $entry['gid'] !== self::TENANT_GID) return null;
        return (int) $entry['gid'];
    }

    /** The node workspace, or null if it is not exactly where it should be. */
    private function runningPath($session, $labSession)
    {
        $expected = $this->tmpRoot . '/' . $labSession . '/' . $session;
        if (!is_dir($expected)) return null;
        $real = realpath($expected);
        // A symlinked workspace resolves somewhere else and is refused. The tmp
        // tree is mode 777, so this is a live concern rather than a formality.
        if ($real === false || $real !== $expected) return null;
        return $real;
    }

    // -------------------------------------------------------------------- up

    /**
     * Start the keepalive helper for one interface of one node session.
     *
     * @return array ['ok' => bool, 'error' => string|null, 'pid' => int|null]
     */
    public function up($sessionRaw, $ifaceRaw)
    {
        $fail = function ($why) { return array('ok' => false, 'error' => $why, 'pid' => null); };

        $session = self::id($sessionRaw, 1, self::MAX_SESSION);
        if ($session === null) return $fail('session id is not a bounded integer');
        $iface = self::id($ifaceRaw, 0, self::MAX_IFACE);
        if ($iface === null) return $fail('interface id is not a bounded integer');

        if ($this->lookup === null) return $fail('no node session lookup was configured');
        $row = call_user_func($this->lookup, $session);
        if (!is_array($row)) return $fail('no such node session');
        if (!isset($row['type']) || $row['type'] !== 'iol') return $fail('node session is not an IOL node');

        $labSession = self::id(isset($row['lab']) ? $row['lab'] : null, 1, self::MAX_SESSION);
        if ($labSession === null) return $fail('node session has no usable lab session');
        $iolId = self::id(isset($row['iol_id']) ? $row['iol_id'] : null, 1, self::MAX_IOL_ID);
        if ($iolId === null) return $fail('node session has no usable IOL id');

        $uid = $this->tenantUid($session);
        if ($uid === null) return $fail('tenant account unl' . $session . ' is missing or holds the wrong uid');
        $gid = $this->tenantGid($session);
        if ($gid === null) return $fail('tenant account unl' . $session . ' is not in the platform group');

        $cwd = $this->runningPath($session, $labSession);
        if ($cwd === null) return $fail('node workspace is missing, or is not where it should be');

        // The script is a fixed path, and it is checked here rather than left to
        // exec to discover — a dangling symlink under the addons tree should be
        // an error message, not a silent no-op in a forked child.
        if (is_link($this->script) || !is_file($this->script)) {
            return $fail('keepalive.pl is not installed at ' . $this->script);
        }

        $argv = array($this->script, '-i', (string) $iolId, '-p', (string) $iface,
                      '-n', $session . '_' . $iface);
        $log = $cwd . '/keepalive.log';

        if (!$this->runCommands) {
            $this->commands[] = array(
                'bin' => $this->perl, 'argv' => $argv, 'uid' => $uid,
                'gid' => $gid, 'initgroups' => true, 'cwd' => $cwd, 'log' => $log,
            );
            return array('ok' => true, 'error' => null, 'pid' => null);
        }

        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')
            || !function_exists('posix_setuid')) {
            return $fail('ext-pcntl and ext-posix are required to drop privileges');
        }

        $pid = pcntl_fork();
        if ($pid < 0) return $fail('fork failed');

        if ($pid === 0) {
            // --- child ---------------------------------------------------
            // Order matters. Privileges go first, and the log file is opened
            // AFTER the drop, so a symlink planted in the (mode 777) workspace
            // can only reach what the tenant account could reach anyway.
            posix_setsid();
            // Supplementary groups first, exactly as device::spawnAsTenant()
            // does it: without initgroups the child keeps ROOT's supplementary
            // groups across the uid drop, and after setuid() there is no way
            // back to fix that. This was the one drop site that skipped it.
            if (function_exists('posix_initgroups')) posix_initgroups('unl' . $session, $gid);
            if (!posix_setgid($gid) || !posix_setuid($uid)) {
                exit(126);
            }
            @chdir($cwd);
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            @fopen('/dev/null', 'r');
            @fopen($log, 'a');
            @fopen($log, 'a');
            pcntl_exec($this->perl, $argv);
            exit(127);   // only reached if exec failed
        }

        // --- parent ------------------------------------------------------
        // Deliberately not waited on. The child is a long-lived daemon; the
        // wrapper returns immediately and init reaps it. Its stdio was replaced
        // above, so the exec() in the web layer does not block on it either.
        return array('ok' => true, 'error' => null, 'pid' => $pid);
    }

    // ------------------------------------------------------------------ down

    /**
     * Stop the keepalive helper(s) for a node session, optionally one interface.
     *
     * Needs no database: the only thing that identifies the processes is the
     * tenant uid and the script path, both derived from the session id. That
     * makes it safe to call during teardown, when the row may already be gone.
     *
     * @return array ['ok' => bool, 'error' => string|null, 'killed' => int]
     */
    public function down($sessionRaw, $ifaceRaw = null)
    {
        $fail = function ($why) { return array('ok' => false, 'error' => $why, 'killed' => 0); };

        $session = self::id($sessionRaw, 1, self::MAX_SESSION);
        if ($session === null) return $fail('session id is not a bounded integer');

        $iface = null;
        if ($ifaceRaw !== null && $ifaceRaw !== false && $ifaceRaw !== '') {
            $iface = self::id($ifaceRaw, 0, self::MAX_IFACE);
            if ($iface === null) return $fail('interface id is not a bounded integer');
        }

        $uid = $this->tenantUid($session);
        if ($uid === null) {
            // The account is gone, so nothing can be running as it. Teardown
            // asks for this constantly; it is not an error.
            return array('ok' => true, 'error' => null, 'killed' => 0);
        }

        $tag = $iface === null ? null : $session . '_' . $iface;
        $killed = 0;
        foreach ($this->processes() as $proc) {
            if ((int) $proc['uid'] !== $uid) continue;
            if (!in_array($this->script, $proc['argv'], true)) continue;
            if ($tag !== null && $this->nameArg($proc['argv']) !== $tag) continue;
            $pid = (int) $proc['pid'];
            if ($pid <= 1) continue;             // never signal init or a bad parse
            if ($this->runCommands) {
                @posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
            } else {
                $this->signals[] = array('pid' => $pid, 'signal' => 9);
            }
            $killed++;
        }

        return array('ok' => true, 'error' => null, 'killed' => $killed);
    }

    /** The value of the helper's -n argument, or null. */
    private function nameArg(array $argv)
    {
        for ($i = 0, $n = count($argv) - 1; $i < $n; $i++) {
            if ($argv[$i] === '-n') return $argv[$i + 1];
        }
        return null;
    }

    /** Every process on the host, as [pid, uid, argv]. */
    private function processes()
    {
        if ($this->procLister !== null) return call_user_func($this->procLister);

        $out = array();
        $dir = @opendir('/proc');
        if ($dir === false) return $out;
        while (($entry = readdir($dir)) !== false) {
            if (!preg_match('/^[0-9]+\z/', $entry)) continue;
            $stat = @stat('/proc/' . $entry);
            $raw = @file_get_contents('/proc/' . $entry . '/cmdline');
            if ($stat === false || $raw === false || $raw === '') continue;
            $argv = explode("\0", $raw);
            while (count($argv) && end($argv) === '') array_pop($argv);
            $out[] = array('pid' => (int) $entry, 'uid' => (int) $stat['uid'], 'argv' => $argv);
        }
        closedir($dir);
        return $out;
    }
}
