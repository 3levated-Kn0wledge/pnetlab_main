<?php
/**
 * Hand one enumerated tree back to the web user.
 *
 * WHAT THIS REPLACES
 *
 * Six `sudo chown` call sites, which between them were the last consumers of
 * the chown grant:
 *
 *     User/DependenceController.php:61-64
 *         exec('sudo chown www-data:www-data -R /opt/unetlab/addons');
 *         exec('sudo chown www-data:www-data -R /opt/unetlab/html/templates');
 *         exec('sudo chown www-data:www-data -R /opt/unetlab/html/images/icons');
 *         exec('sudo chown www-data:www-data -R /opt/unetlab/scripts');
 *     User/VersionsController.php:78
 *         exec('sudo chown www-data:www-data -R /opt/unetlab/labs');
 *     Admin/DefaultController.php:254
 *         exec('sudo chown www-data:www-data '. $file);
 *
 * The first five are constants, and moving them behind the wrapper is only
 * about retiring the grant. The sixth is the one that mattered: $file is
 *     '/opt/unetlab/html/templates/' . secureCmd($req->input('template')) . '.yml'
 * and secureCmd() is a blocklist of [#;|&] and '..' that passes backticks,
 * $( ), spaces, quotes and newlines straight through — pinned in
 * tests/Security/SecureCmdTest.php. It is the call site that group 8 of the
 * shell-escaping baseline names by hand.
 *
 * THE SHAPE THAT MAKES THIS SAFE
 *
 * The caller sends a SCOPE WORD. It never sends a path. Every path in this file
 * is a constant, an unknown scope is refused outright, and there is no scope
 * that reaches a leaf: a scope names a root, and the root's whole tree gets the
 * same ownership. That is what the six call sites were doing anyway — five of
 * them literally, and the sixth by chowning one file inside a root that should
 * have been www-data's all along.
 *
 * WHY OWNERSHIP AND NOT MODE
 *
 * There is exactly one operation here, applied to whole trees. A scope cannot
 * ask for a mode, cannot ask for an owner, and cannot ask for a single file, so
 * there is nothing to get wrong per call site. `unl_wrapper -a fixpermissions`
 * remains the blunt everything-at-once repair the admin button runs; this is
 * the narrow, named half that application code is allowed to call.
 *
 * SYMLINKS ARE NOT FOLLOWED, AND THE WALK IS NOT OURS
 *
 * `chown -R` on a tree the web user can already write is a hazard in one
 * direction: a symlink planted inside the tree pointing at, say, /etc/shadow.
 * An earlier revision of this file walked the tree itself in PHP, testing
 * is_link() and then calling chown(). That is a time-of-check/time-of-use
 * race, and on a www-data-writable tree it is a live one: between the
 * is_link() and the chown() the entry can be swapped for a symlink, and PHP's
 * chown() dereferences; between the is_dir() and the scandir() a directory can
 * be swapped for a symlink to /etc, and every path built beneath it resolves
 * through the link. PHP has lchown() but no openat()/fchownat(), so a walk
 * written in PHP cannot be made race-free.
 *
 * GNU chown can. `chown -R -h -P` traverses with openat() relative to a held
 * directory descriptor, changes ownership with fchownat(AT_SYMLINK_NOFOLLOW),
 * refuses to descend through any symlink, and re-checks each directory's
 * dev/ino after entering it -- a directory swapped mid-walk is reported as
 * changed, not followed. That is the traversal this file now delegates to,
 * as an argv array through proc_open(): no shell, and the only variable
 * argument is a root chosen from the enumeration below.
 *
 * A root that is itself a symlink is still refused here first, and would be
 * handled by -h -P anyway (the link is changed, its target is not).
 */

class UnlFixPerms
{
    /** The user every one of these trees is handed to. Not a parameter. */
    const OWNER = 'www-data';

    /** The one binary this action runs. Fixed path; not a parameter of run(). */
    const CHOWN = '/bin/chown';

    private $prefix;
    private $owner;
    private $runCommands;
    private $chown;

    /** Recorded rather than run when run_commands is false. One argv per root. */
    public $commands = array();

    /**
     * Every path chown reported on the last live run, changed or retained,
     * in the order it visited them. For diagnostics and for the test, which
     * uses it to prove a planted symlink's target was never visited.
     */
    public $visited = array();

    public function __construct(array $options = array())
    {
        $this->prefix = isset($options['prefix']) ? rtrim($options['prefix'], '/') : '';
        $this->owner  = isset($options['owner']) ? $options['owner'] : self::OWNER;
        $this->runCommands = array_key_exists('run_commands', $options)
            ? (bool) $options['run_commands'] : true;
        $this->chown = isset($options['chown']) ? $options['chown'] : self::CHOWN;
    }

    /**
     * The enumeration. Scope word => the roots it owns.
     *
     * 'dependencies' is the union of the four roots a marketplace dependency
     * unpacks into, so DependenceController spawns the wrapper once instead of
     * four times. It is a convenience over the same four constants, not a
     * seventh root.
     */
    public function scopes()
    {
        $p = $this->prefix;
        $addons    = $p . '/opt/unetlab/addons';
        $templates = $p . '/opt/unetlab/html/templates';
        $icons     = $p . '/opt/unetlab/html/images/icons';
        $scripts   = $p . '/opt/unetlab/scripts';
        $labs      = $p . '/opt/unetlab/labs';

        return array(
            'addons'       => array($addons),
            'templates'    => array($templates),
            'icons'        => array($icons),
            'scripts'      => array($scripts),
            'labs'         => array($labs),
            'dependencies' => array($addons, $templates, $icons, $scripts),
        );
    }

    /** The scope words, for a usage message and for the callers' own guard. */
    public function names()
    {
        return array_keys($this->scopes());
    }

    /**
     * @return array ['ok'=>bool,'error'=>string|null,'scope'=>string|null,
     *                'changed'=>int,'failed'=>int,'skipped'=>string[]]
     */
    public function run($scopeRaw)
    {
        $fail = function ($why) {
            return array('ok' => false, 'error' => $why, 'scope' => null,
                         'changed' => 0, 'failed' => 0, 'skipped' => array());
        };

        $scopes = $this->scopes();
        // in_array with strict comparison: without it, PHP would compare a
        // non-string scope loosely against the keys and 0 == 'addons' has been
        // true in this language's history.
        if (!is_string($scopeRaw) || !in_array($scopeRaw, array_keys($scopes), true)) {
            return $fail('scope must be one of: ' . implode(', ', array_keys($scopes)));
        }

        $ids = $this->ownerIds();
        if ($ids === null) return $fail('no such user: ' . $this->owner);

        $changed = 0;
        $failed  = 0;
        $skipped = array();
        $this->visited = array();
        foreach ($scopes[$scopeRaw] as $root) {
            // A root that is a symlink, or is not there at all, is skipped and
            // said so. Neither is an error: an appliance without the IOL addons
            // has no addons tree, and refusing the whole call for that would
            // break a download that had otherwise succeeded.
            if (is_link($root) || !is_dir($root)) {
                $skipped[] = $root;
                continue;
            }
            $this->own($root, $ids, $changed, $failed);
        }

        return array('ok' => $failed === 0, 'scope' => $scopeRaw,
                     'error' => $failed === 0 ? null
                         : $failed . ' path(s) could not be given to ' . $this->owner,
                     'changed' => $changed, 'failed' => $failed, 'skipped' => $skipped);
    }

    /** uid and gid for the owner, or null. */
    private function ownerIds()
    {
        if (!$this->runCommands) return array('uid' => -1, 'gid' => -1);
        if (!function_exists('posix_getpwnam')) return null;
        $pw = posix_getpwnam($this->owner);
        if ($pw === false) return null;
        $gid = $pw['gid'];
        if (function_exists('posix_getgrnam')) {
            $gr = posix_getgrnam($this->owner);
            if ($gr !== false) $gid = $gr['gid'];
        }
        return array('uid' => $pw['uid'], 'gid' => $gid);
    }

    /**
     * The argv for one root. Every flag is load-bearing:
     *
     *   -R              the whole tree
     *   -h              change a symlink itself, never what it points at
     *   -P              never traverse a symlink, including one given as the root
     *   --preserve-root a defence against a root of '/' that the enumeration
     *                   above already makes unreachable
     *   -v              one line per path, which is how `changed` is counted
     *                   and how the test proves what was NOT visited
     *   --              the root can never be read as an option
     *
     * The owner is numeric on a live run (uid:gid as resolved above) so that
     * the account named in the enumeration and the account chown acts on are
     * the same lookup. When recording, the name stands in.
     */
    private function argv($root, array $ids)
    {
        $owner = $this->runCommands
            ? $ids['uid'] . ':' . $ids['gid']
            : $this->owner . ':' . $this->owner;
        return array($this->chown, '-R', '-h', '-P', '--preserve-root', '-v', '--', $owner, $root);
    }

    /**
     * proc_open() with an ARRAY execs the binary directly: no shell. The
     * parameter is array-typed because that is the shape the tokenizer sweep
     * in tests/Security/ShellEscapingTest.php can prove is not a shell.
     *
     * Both pipes are read together with stream_select(), never one to EOF and
     * then the other: chown -v writes a line per path to stdout and a line per
     * failure to stderr, and a tree with many unreadable entries could fill
     * the stderr pipe while this side was still waiting on stdout -- the
     * two-pipe deadlock, which holds the caller's fpm worker until timeout.
     */
    private function spawn(array $argv)
    {
        $desc = array(0 => array('file', '/dev/null', 'r'),
                      1 => array('pipe', 'w'),
                      2 => array('pipe', 'w'));
        $proc = @proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) return array('rc' => 127, 'out' => '', 'err' => '');
        $buf = array(1 => '', 2 => '');
        $open = array(1 => $pipes[1], 2 => $pipes[2]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        while (count($open)) {
            $r = array_values($open);
            $w = null;
            $e = null;
            if (@stream_select($r, $w, $e, 60) === false) break;
            foreach ($r as $stream) {
                $k = ($stream === $pipes[1]) ? 1 : 2;
                $chunk = fread($stream, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $buf[$k] .= $chunk;
                } elseif (feof($stream)) {
                    unset($open[$k]);
                }
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        return array('rc' => $rc, 'out' => $buf[1], 'err' => $buf[2]);
    }

    /** Hand one root and everything beneath it to the owner. */
    private function own($root, array $ids, &$changed, &$failed)
    {
        $argv = $this->argv($root, $ids);
        if (!$this->runCommands) {
            $this->commands[] = $argv;
            $changed++;
            return;
        }

        $r = $this->spawn($argv);
        $rc = $r['rc'];
        $err = $r['err'];

        foreach (explode("\n", $r['out']) as $line) {
            // GNU chown -v: "changed ownership of 'P' from A to B" and
            // "ownership of 'P' retained as B". The path is quoted with the
            // locale's quotes; both ASCII and the UTF-8 pair are stripped.
            if (preg_match("/^(changed ownership of|ownership of) (['\x{2018}])(.*)(['\x{2019}]) (from |retained)/u", $line, $m)) {
                $this->visited[] = $m[3];
                if ($m[1] === 'changed ownership of') $changed++;
            }
        }
        if ($rc !== 0) {
            $failed++;
            error_log(date('M d H:i:s ') . 'ERROR: chown -R on ' . $root . ' exited ' . $rc
                . ($err !== '' ? ': ' . trim($err) : ''));
        }
    }
}
