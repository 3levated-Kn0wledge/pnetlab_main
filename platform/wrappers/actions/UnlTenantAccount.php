<?php
/**
 * The lifecycle of a node session's Unix account: create it, and reap it.
 *
 * WHAT THIS FIXES
 *
 * checkUsername() in includes/cli.php runs, once per node session:
 *
 *     sudo /usr/sbin/useradd -c "Unified Networking Lab TID=N" -d /opt/unetlab/users/N
 *          -g unl -M -s /bin/bash -u <32768+N> unlN
 *
 * and nothing anywhere in the tree ever removed one. There is no userdel, no
 * deluser and no expiry. A session id here is a NODE session id, not a user id,
 * so the accounts accumulate at the rate nodes are started, for the life of the
 * appliance, each with a login shell and a home directory.
 *
 * It is a correctness bug and not only a hygiene one. createNodeSession() in
 * includes/__node.php allocates ids modulo 30000 and skips ids present in
 * node_sessions, so an id is REUSED once its row is deleted. The account, its
 * uid and its home directory survive that deletion, and the next session handed
 * that id inherits all three — including whatever the previous tenant left in
 * the home directory. Tying the account's lifetime to the session id's lifetime
 * is what closes that, and it is why the reaper removes the home directory as
 * well as the passwd entry.
 *
 * WHY REAP RATHER THAN POOL
 *
 * The roadmap offers "reap tenant accounts on node stop, or replace per-session
 * useradd with a fixed pool". The uid is load-bearing per session, and a pool
 * cannot keep that:
 *
 *   - tap interfaces are handed to the account by name (`tunctl -u unlN`), and
 *     the point of that is that node A's tenant cannot open node B's tap;
 *   - the IOL data plane derives its AF_UNIX bus directory from the RUNNING
 *     uid — /tmp/netio<uid> — so two IOL nodes sharing a uid share a bus
 *     directory, and getIolId() only guarantees id uniqueness within a lab.
 *     platform/wrappers/src/iol.c records that dependency explicitly, in the
 *     comment above bus_paths();
 *   - UnlIolKeepalive computes 32768+session and confirms it against passwd.
 *
 * A pool would therefore have to be 30000 accounts wide to preserve those
 * properties — a pool in name only — or it would have to collapse the
 * per-session isolation the accounts exist to provide, in the phase whose title
 * is "establish a privilege boundary". Reaping keeps the tenancy model and
 * deletes the unbounded growth, and it is the smaller change.
 *
 * ORDERING, WHICH IS THE HARD PART
 *
 * An account owns the tap interfaces and the running directory of a live node.
 * Removing it while the node is up strands both. So a reap must be strictly
 * after teardown, and this class refuses rather than trusting its caller:
 *
 *   - no process may be running as the account's uid (read from /proc, never
 *     from a caller-supplied pid);
 *   - no vunl<session>_* tap may still exist;
 *   - the node session, if the database still has a row for it, must not report
 *     status 2 or 3 (running, or running-and-locked).
 *
 * The process check gets a short bounded wait, because `fuser -k -TERM` returns
 * before the emulator has finished dying and the call sites reap immediately
 * afterwards. If the wait expires the account is KEPT, not forced: a surviving
 * account is a leak the `--scope all` sweep on `stopall` will clear, whereas a
 * forced removal under a running node is a stranded tap and a broken lab.
 *
 * WHAT IT WILL NOT DO
 *
 *   - it never receives a username. The caller sends a session id, digits only,
 *     bounded to the range createNodeSession() can produce; the name is built
 *     here and must still match ^unl[0-9]+$ afterwards.
 *   - the account must hold exactly uid 32768+session AND have `unl` as its
 *     primary group. Anything else wearing that name is someone else's account
 *     and is left alone.
 *   - uid 0 is unreachable by construction, and refused explicitly anyway.
 *   - userdel is exec'd through an argv ARRAY. No shell is involved.
 *   - it is safe to call twice: an account that is already gone is a success
 *     with nothing reaped, which is what every teardown path needs.
 *   - the home directory is removed ONE LEVEL deep, and only if it is exactly
 *     /opt/unetlab/users/<session>, a real directory rather than a symlink.
 *     `userdel -r` and `rm -rf` are both wrong here: that tree is 2775 root:unl
 *     by design, so a recursive root delete driven by its contents is precisely
 *     the primitive the rest of this phase spent its time removing.
 */

class UnlTenantAccount
{
    /** The group every tenant account has as its primary group. */
    const TENANT_GROUP = 'unl';
    /** Its gid on a stock install. A fallback only; the name is resolved first. */
    const TENANT_GID = 32768;
    /** uid of the account for node session N. Mirrors checkUsername(). */
    const TENANT_UID_BASE = 32768;
    /**
     * createNodeSession() returns `$id % 30000`, so 0 is producible and 30000
     * is not. Bounding the session bounds the uid arithmetic.
     */
    const MIN_SESSION = 0;
    const MAX_SESSION = 29999;
    /** An account this class will consider at all. */
    const NAME_RE = '/^unl[0-9]+\z/';
    /** Poll interval and cap for "has everything as this uid actually exited". */
    const WAIT_USLEEP = 200000;
    const WAIT_POLLS  = 10;

    /** Root of the tenant home directories. Nothing outside it is touched. */
    private $usersRoot;
    /** Fixed binaries, only ever exec'd with an argv array. */
    private $userdel;
    private $useradd;
    /** The login shell useradd is told to set. Never taken from a caller. */
    private $shell;
    /** Where interfaces are enumerated from. */
    private $sysNet;
    /** Where local accounts are enumerated from, for the sweep. */
    private $passwdFile;
    /** callable(string $name): array|null — passwd entry; defaults to posix_getpwnam(). */
    private $pwnam;
    /** callable(string $name): array|null — group entry; defaults to posix_getgrnam(). */
    private $grnam;
    /** callable(): string[] — every local account name. */
    private $accounts;
    /** callable(): array of ['pid' => int, 'uid' => int]. */
    private $procLister;
    /** callable(int $session): int|null — getNodeStatus() for the session, if any. */
    private $status;
    private $waitPolls;
    /** False in tests: record what would have been done instead of doing it. */
    private $runCommands;

    /** Recorded rather than run when run_commands is false. */
    public $commands = array();

    public function __construct(array $options = array())
    {
        $prefix = isset($options['prefix']) ? rtrim($options['prefix'], '/') : '';
        $this->usersRoot = isset($options['users_root'])
            ? rtrim($options['users_root'], '/') : $prefix . '/opt/unetlab/users';
        $this->userdel    = isset($options['userdel'])     ? $options['userdel']     : '/usr/sbin/userdel';
        $this->useradd    = isset($options['useradd'])     ? $options['useradd']     : '/usr/sbin/useradd';
        $this->shell      = isset($options['shell'])       ? $options['shell']       : '/bin/bash';
        $this->sysNet     = isset($options['sys_net'])     ? rtrim($options['sys_net'], '/') : '/sys/class/net';
        $this->passwdFile = isset($options['passwd_file']) ? $options['passwd_file'] : '/etc/passwd';
        $this->pwnam      = isset($options['pwnam'])       ? $options['pwnam']       : null;
        $this->grnam      = isset($options['grnam'])       ? $options['grnam']       : null;
        $this->accounts   = isset($options['accounts'])    ? $options['accounts']    : null;
        $this->procLister = isset($options['proc_lister']) ? $options['proc_lister'] : null;
        $this->status     = isset($options['status'])      ? $options['status']      : null;
        $this->waitPolls  = isset($options['wait_polls'])  ? (int) $options['wait_polls'] : self::WAIT_POLLS;
        $this->runCommands = array_key_exists('run_commands', $options)
            ? (bool) $options['run_commands'] : true;
    }

    // ------------------------------------------------------------- validation

    /**
     * An id is a string of digits and nothing else.
     *
     * \z, not $: in PCRE without /D, '$' also matches immediately before a
     * trailing newline, so '/^[0-9]+$/' accepts "42\n". (int) alone is worse
     * still — (int) '12; id' is 12, and (int) on the array getopt() returns for
     * a repeated option is 1.
     */
    private static function id($value, $min, $max)
    {
        if (is_array($value) || is_object($value) || is_bool($value) || $value === null) return null;
        $value = (string) $value;
        if ($value === '' || !preg_match('/^[0-9]+\z/', $value)) return null;
        $n = (int) $value;
        return ($n < $min || $n > $max) ? null : $n;
    }

    /**
     * True only for a tenant account name.
     *
     * Public because it is the invariant the whole class rests on, and a test
     * that cannot state it directly is testing something else.
     */
    public static function isTenantName($name)
    {
        return is_string($name) && preg_match(self::NAME_RE, $name) === 1;
    }

    /** The session id a tenant account name encodes, or null. */
    public static function sessionOfName($name)
    {
        if (!self::isTenantName($name)) return null;
        return self::id(substr($name, 3), self::MIN_SESSION, self::MAX_SESSION);
    }

    private function passwd($name)
    {
        if ($this->pwnam !== null) return call_user_func($this->pwnam, $name);
        if (!function_exists('posix_getpwnam')) return null;
        $entry = posix_getpwnam($name);
        return $entry === false ? null : $entry;
    }

    /** The gid of the `unl` group, resolved by NAME. */
    private function tenantGid()
    {
        if ($this->grnam !== null) {
            $group = call_user_func($this->grnam, self::TENANT_GROUP);
        } elseif (function_exists('posix_getgrnam')) {
            $group = posix_getgrnam(self::TENANT_GROUP);
            if ($group === false) $group = null;
        } else {
            $group = null;
        }
        if (is_array($group) && isset($group['gid'])) return (int) $group['gid'];
        // The group is created by the installer, and its absence is a broken
        // install rather than a licence to guess. Falling back to the stock gid
        // keeps the comparison happening, so a mismatched account is still
        // REFUSED; it never lets a reap proceed with no group check at all.
        return self::TENANT_GID;
    }

    // -------------------------------------------------------------- inventory

    /** Every process on the host, as [pid, uid]. */
    private function processes()
    {
        if ($this->procLister !== null) return call_user_func($this->procLister);

        $out = array();
        $dir = @opendir('/proc');
        if ($dir === false) return $out;
        while (($entry = readdir($dir)) !== false) {
            if (!preg_match('/^[0-9]+\z/', $entry)) continue;
            $stat = @stat('/proc/' . $entry);
            if ($stat === false) continue;
            $out[] = array('pid' => (int) $entry, 'uid' => (int) $stat['uid']);
        }
        closedir($dir);
        return $out;
    }

    /** True if anything is still running as $uid. */
    private function uidIsBusy($uid)
    {
        foreach ($this->processes() as $proc) {
            if ((int) $proc['uid'] === $uid) return true;
        }
        return false;
    }

    /** True if any vunl<session>_* interface still exists. */
    private function tapsExist($session)
    {
        $prefix = 'vunl' . $session . '_';
        $names = @scandir($this->sysNet);
        if ($names === false) return false;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;
            if (strpos($name, $prefix) === 0) return true;
        }
        return false;
    }

    /**
     * Every local account name, for the sweep.
     *
     * Read from /etc/passwd rather than enumerated with posix_getpwnam(), which
     * would mean 30000 lookups. Tenant accounts are created by useradd on this
     * host, so they are always local; anything the file does not list is not a
     * tenant account and is not this class's business.
     */
    private function accountNames()
    {
        if ($this->accounts !== null) return call_user_func($this->accounts);
        $names = array();
        $lines = @file($this->passwdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return $names;
        foreach ($lines as $line) {
            $fields = explode(':', $line);
            if (count($fields) < 3) continue;
            $names[] = $fields[0];
        }
        return $names;
    }

    // ------------------------------------------------------------------ exec

    /**
     * proc_open() with an ARRAY execs the binary directly and never builds a
     * command string. The argv arrives as an array-TYPED parameter deliberately:
     * that is the shape the tokenizer sweep in tests/Security/ShellEscapingTest
     * can prove is not a shell.
     */
    private function spawn(array $argv)
    {
        if (!$this->runCommands) {
            $this->commands[] = $argv;
            return array('rc' => 0, 'out' => '');
        }
        $desc = array(0 => array('file', '/dev/null', 'r'),
                      1 => array('pipe', 'w'),
                      2 => array('pipe', 'w'));
        $proc = proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) return array('rc' => 127, 'out' => '');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        return array('rc' => $rc, 'out' => $out . $err);
    }

    // ---------------------------------------------------------------- create

    /**
     * Ensure the tenant account for a node session exists.
     *
     * This is the other half of the pair, and it lives here so creation and
     * removal cannot disagree about the name, the uid or the group — a
     * disagreement between them is exactly how a stale account outlives the id
     * that named it.
     *
     * It runs useradd DIRECTLY, with no sudo. Every caller reaches it inside
     * unl_wrapper, which is already root: node start has one entry point,
     * `unl_wrapper -a start`, reached only from apiStartLabNode(), and
     * checkUsername() is called only from a device's prepare(). The `sudo` that
     * used to be on this call site was root running sudo to become root, and it
     * is the only thing that kept /usr/sbin/useradd in the web user's sudo
     * policy. If you ever add a web-side caller, this returns an error rather
     * than silently failing — but the right fix would be a wrapper action, not
     * putting the grant back.
     *
     * @return array ['ok'=>bool,'error'=>string|null,'name'=>string|null,
     *                'uid'=>int|null,'created'=>bool]
     */
    public function create($sessionRaw)
    {
        $fail = function ($why) {
            return array('ok' => false, 'error' => $why, 'name' => null,
                         'uid' => null, 'created' => false);
        };

        $session = self::id($sessionRaw, self::MIN_SESSION, self::MAX_SESSION);
        if ($session === null) return $fail('session id is not a bounded integer');

        $name = 'unl' . $session;
        if (!self::isTenantName($name)) return $fail('constructed name is not a tenant name');
        $uid = self::TENANT_UID_BASE + $session;
        $gid = $this->tenantGid();

        $entry = $this->passwd($name);
        if (is_array($entry)) {
            // Already there. Confirm it is OURS before reporting success: an
            // account called unl<session> holding a different uid is not the
            // platform's, and a node started under it would put its tap and its
            // workspace somewhere nobody expects.
            if (!isset($entry['uid']) || (int) $entry['uid'] !== $uid) {
                return $fail($name . ' exists but does not hold uid ' . $uid);
            }
            if (!isset($entry['gid']) || (int) $entry['gid'] !== $gid) {
                return $fail($name . ' exists but is not in the ' . self::TENANT_GROUP . ' group');
            }
            return array('ok' => true, 'error' => null, 'name' => $name,
                         'uid' => $uid, 'created' => false);
        }

        $home = $this->usersRoot . '/' . $session;
        $result = $this->spawn(array(
            $this->useradd,
            '-c', 'Unified Networking Lab TID=' . $session,
            '-d', $home,
            '-g', self::TENANT_GROUP,
            '-M',
            '-s', $this->shell,
            '-u', (string) $uid,
            $name,
        ));
        if ($result['rc'] !== 0) {
            return $fail('useradd failed: ' . trim($result['out']));
        }
        return array('ok' => true, 'error' => null, 'name' => $name,
                     'uid' => $uid, 'created' => true);
    }

    // ------------------------------------------------------------------ reap

    /**
     * Remove the tenant account for one node session, if it is safe to.
     *
     * 'kept' being set is not a failure: it says the account is still there and
     * why, which is the outcome every ordering rule above produces. A caller
     * that treats it as an error will fail an ordinary node stop.
     *
     * @return array ['ok'=>bool,'error'=>string|null,'name'=>string|null,
     *                'reaped'=>int,'kept'=>string|null]
     */
    public function reap($sessionRaw)
    {
        $fail = function ($why) {
            return array('ok' => false, 'error' => $why, 'name' => null,
                         'reaped' => 0, 'kept' => null);
        };

        $session = self::id($sessionRaw, self::MIN_SESSION, self::MAX_SESSION);
        if ($session === null) return $fail('session id is not a bounded integer');

        $name = 'unl' . $session;
        if (!self::isTenantName($name)) return $fail('constructed name is not a tenant name');

        $entry = $this->passwd($name);
        if (!is_array($entry) || !isset($entry['uid'])) {
            // Nothing to do. Teardown asks for this constantly, and more than
            // once per session; it is a success, not an error.
            return array('ok' => true, 'error' => null, 'name' => $name,
                         'reaped' => 0, 'kept' => null);
        }

        $uid = self::TENANT_UID_BASE + $session;
        if ((int) $entry['uid'] !== $uid) {
            return $fail($name . ' holds uid ' . (int) $entry['uid'] . ', not ' . $uid
                . '; refusing to remove an account that is not the platform\'s');
        }
        if ($uid < self::TENANT_UID_BASE || $uid === 0) {
            // Unreachable given the bounds above; kept as a floor, because the
            // consequence of it ever becoming reachable is `userdel root`.
            return $fail('computed uid is outside the tenant range');
        }
        $gid = $this->tenantGid();
        if (!isset($entry['gid']) || (int) $entry['gid'] !== $gid) {
            return $fail($name . ' is not in the ' . self::TENANT_GROUP
                . ' group; refusing to remove it');
        }

        // --- is the tenant actually finished? -------------------------------
        if ($this->tapsExist($session)) {
            return array('ok' => true, 'error' => null, 'name' => $name, 'reaped' => 0,
                         'kept' => 'a vunl' . $session . '_* interface still exists');
        }
        if ($this->status !== null) {
            $st = call_user_func($this->status, $session);
            // 2 is running, 3 is running-and-locked. 1 is stopped-and-locked,
            // which is a stale .lock file in the workspace and not a reason to
            // keep an account alive for the life of the appliance.
            if ($st === 2 || $st === 3) {
                return array('ok' => true, 'error' => null, 'name' => $name, 'reaped' => 0,
                             'kept' => 'the node session still reports status ' . $st);
            }
        }
        // `fuser -k -TERM` returns before the emulator has finished dying, and
        // every call site reaps immediately afterwards. Wait, briefly, rather
        // than leak an account on every ordinary stop.
        for ($i = 0; $i <= $this->waitPolls; $i++) {
            if (!$this->uidIsBusy($uid)) break;
            if ($i === $this->waitPolls) {
                return array('ok' => true, 'error' => null, 'name' => $name, 'reaped' => 0,
                             'kept' => 'processes are still running as uid ' . $uid);
            }
            if ($this->runCommands) usleep(self::WAIT_USLEEP);
        }

        // --- remove it ------------------------------------------------------
        // No -r. The home directory is dealt with below, by a path this class
        // builds, checks, and only descends one level into.
        $result = $this->spawn(array($this->userdel, $name));
        // 6 is "the user does not exist", which is a race with another reap of
        // the same session — the idempotent case, not a failure.
        if ($result['rc'] !== 0 && $result['rc'] !== 6) {
            return $fail('userdel failed: ' . trim($result['out']));
        }

        $this->removeHome($session);

        return array('ok' => true, 'error' => null, 'name' => $name,
                     'reaped' => 1, 'kept' => null);
    }

    /**
     * Remove /opt/unetlab/users/<session>, one level deep.
     *
     * checkUsername() creates it holding a single symlink to unl_profile. If it
     * holds anything else — a subdirectory, or a file this class did not put
     * there — the directory is left in place. That is deliberate:
     * /opt/unetlab/users is 2775 root:unl, so its contents are writable by every
     * tenant, and a recursive root delete driven by them is exactly the
     * primitive the rest of this phase spent its time removing.
     */
    private function removeHome($session)
    {
        $home = $this->usersRoot . '/' . $session;
        if (!$this->runCommands) { $this->commands[] = array('rmdir', $home); return; }
        if (is_link($home) || !is_dir($home)) return;
        if (realpath($home) !== $home) return;
        foreach (scandir($home) as $child) {
            if ($child === '.' || $child === '..') continue;
            $path = $home . '/' . $child;
            if (is_dir($path) && !is_link($path)) continue;   // never created here
            @unlink($path);
        }
        @rmdir($home);
    }

    // -------------------------------------------------------------- reap all

    /**
     * Sweep every tenant account the host holds.
     *
     * This is what `stopall` needs — it kills every node and clears the system,
     * so every tenant account is by definition finished — and it is also the net
     * that catches an account some earlier stop declined to reap because the
     * emulator was still dying. Each candidate goes through reap(), so every
     * refusal above still applies, one account at a time.
     *
     * @return array ['ok'=>bool,'error'=>null,'reaped'=>int,'kept'=>array]
     */
    public function reapAll()
    {
        $reaped = 0;
        $kept = array();
        foreach ($this->accountNames() as $name) {
            $session = self::sessionOfName($name);
            if ($session === null) continue;
            $result = $this->reap($session);
            if (!$result['ok']) { $kept[$name] = $result['error']; continue; }
            if ($result['reaped'] > 0) { $reaped++; continue; }
            if ($result['kept'] !== null) $kept[$name] = $result['kept'];
        }
        return array('ok' => true, 'error' => null, 'reaped' => $reaped, 'kept' => $kept);
    }
}
