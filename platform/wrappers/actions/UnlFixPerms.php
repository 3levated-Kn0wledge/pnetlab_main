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
 * SYMLINKS ARE SKIPPED, NOT FOLLOWED
 *
 * `chown -R` on a tree the web user can already write is a hazard in one
 * direction: a symlink planted inside the tree pointing at, say, /etc/shadow.
 * GNU chown -R does not dereference by default, but PHP's chown() DOES, and PHP
 * has no lchown(). So this walk never touches a symlink at all — it does not
 * chown it and does not descend through it. The effect is that a planted link
 * is inert instead of being a way to take ownership of a file outside the root.
 */

class UnlFixPerms
{
    /** The user every one of these trees is handed to. Not a parameter. */
    const OWNER = 'www-data';

    /**
     * Deeper than any of these trees goes. A cap rather than an unbounded walk,
     * because the trees are writable by the web user.
     */
    const MAX_DEPTH = 32;

    private $prefix;
    private $owner;
    private $runCommands;

    /** Recorded rather than run when run_commands is false. */
    public $commands = array();

    public function __construct(array $options = array())
    {
        $this->prefix = isset($options['prefix']) ? rtrim($options['prefix'], '/') : '';
        $this->owner  = isset($options['owner']) ? $options['owner'] : self::OWNER;
        $this->runCommands = array_key_exists('run_commands', $options)
            ? (bool) $options['run_commands'] : true;
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
        foreach ($scopes[$scopeRaw] as $root) {
            // A root that is a symlink, or is not there at all, is skipped and
            // said so. Neither is an error: an appliance without the IOL addons
            // has no addons tree, and refusing the whole call for that would
            // break a download that had otherwise succeeded.
            if (is_link($root) || !is_dir($root)) {
                $skipped[] = $root;
                continue;
            }
            $this->own($root, 0, $ids, $changed, $failed);
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
     * chown one path and, if it is a directory, everything beneath it.
     *
     * Recursion is bounded by MAX_DEPTH, and symlinks are neither chowned nor
     * descended — see the header. Nothing here is built into a string.
     */
    private function own($path, $depth, array $ids, &$changed, &$failed)
    {
        if ($depth > self::MAX_DEPTH) {
            $failed++;
            return;
        }

        if (!$this->runCommands) {
            $this->commands[] = array('chown', $path, $this->owner);
            $changed++;
        } elseif (@chown($path, $ids['uid']) && @chgrp($path, $ids['gid'])) {
            $changed++;
        } else {
            $failed++;
        }

        if (!is_dir($path)) return;
        $entries = @scandir($path);
        if ($entries === false) {
            $failed++;
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . '/' . $entry;
            if (is_link($child)) continue;
            $this->own($child, $depth + 1, $ids, $changed, $failed);
        }
    }
}
