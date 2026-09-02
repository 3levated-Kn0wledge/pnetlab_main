<?php
/**
 * Commit a running QEMU node's disk back into a template.
 *
 * WHAT THIS REPLACES
 *
 * store/app/Http/Controllers/Admin/Node_sessionsController.php held the largest
 * unquoted-path cluster in the tree: fifteen sudo call sites running qemu-img,
 * cp, mv, mkdir, rm and chown on paths built by concatenation from request
 * input. The centre of it was
 *
 *     $newName   = $imageQemu[0] . '-' . $deviceName;
 *     $newFolder = '/opt/unetlab/addons/qemu/' . $newName;
 *     exec('sudo mkdir ' . $newFolder);
 *     exec('sudo cp -f ' . $qcowFile . ' ' . $newFolder . '/' . basename($qcowFile));
 *     exec('sudo chown -R www-data:www-data ' . $newFolder);
 *
 * where $imageQemu came from explode('-', $request->input('node_image')). Not
 * one of those was quoted, and $newFolder reached a root shell.
 *
 * The 'new' path was worse again. It copied every member of the backing chain
 * into /tmp/commit/<session><absolute path of the member>, rebased and
 * committed each level, and moved the result out — a dozen root-side path
 * concatenations, a predictable /tmp directory, and the chain itself taken from
 * the output of a previous command with no check on where those paths pointed.
 *
 * WHAT THIS DOES INSTEAD
 *
 *   - the caller sends a node session id, one of four type words, and (for the
 *     two types that create something) a name. Nothing else crosses.
 *   - the name must match ^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$, so it cannot hold a
 *     separator, cannot be '..', and cannot begin with a dash. The destination
 *     is that name joined to a root this file owns; no path is ever received.
 *   - THE BACKING CHAIN IS VALIDATED BEFORE ANYTHING IS WRITTEN. The workspace
 *     lives under /opt/unetlab/tmp, which is mode 777, so the qcow2 header that
 *     names the backing file is writable by anyone who can write a node
 *     workspace. `qemu-img commit` follows that pointer and WRITES to it, and
 *     `qemu-img convert` follows it and reads it into an image the web user can
 *     then download. Every member of the chain must resolve inside the addons
 *     root or the node's own workspace, or nothing runs.
 *   - qemu-img is executed through proc_open() with an argv ARRAY. There is no
 *     shell, so an image name containing a semicolon is an image name.
 *   - 'new' is one `qemu-img convert -O qcow2`, which flattens the whole chain
 *     into a standalone image. That is what the old copy/rebase/commit/move
 *     dance produced, without the /tmp staging area, without mutating anything,
 *     and without a dozen path concatenations to get wrong.
 */

class UnlImageCommit
{
    /** Names of the four things this action will do. Closed set. */
    const TYPES = array('check', 'existed', 'snapshot', 'new');
    /** Session ids are database auto-increments; this bounds them. */
    const MAX_SESSION = 1000000;
    /** A template folder name. No separator, no '..', no leading dash. */
    const NAME_RE = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/';
    /** A disk image inside a workspace. */
    const QCOW_RE = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\.qcow2\z/';
    /** Refuse a chain longer than this rather than walking one built to be walked. */
    const MAX_CHAIN = 16;

    private $addonsRoot;
    private $tmpRoot;
    private $qemuImg;
    private $owner;
    private $lookup;
    private $runCommands;

    /** Recorded rather than run when run_commands is false. */
    public $commands = array();

    public function __construct(array $options = array())
    {
        $prefix = isset($options['prefix']) ? rtrim($options['prefix'], '/') : '';
        $this->addonsRoot = isset($options['addons_root'])
            ? rtrim($options['addons_root'], '/') : $prefix . '/opt/unetlab/addons/qemu';
        $this->tmpRoot = isset($options['tmp_root'])
            ? rtrim($options['tmp_root'], '/') : $prefix . '/opt/unetlab/tmp';
        $this->qemuImg = isset($options['qemu_img']) ? $options['qemu_img'] : '/usr/bin/qemu-img';
        $this->owner   = isset($options['owner'])    ? $options['owner']    : 'www-data';
        $this->lookup  = isset($options['lookup'])   ? $options['lookup']   : null;
        $this->runCommands = array_key_exists('run_commands', $options)
            ? (bool) $options['run_commands'] : true;
    }

    // ------------------------------------------------------------- validation

    /** An id is digits and nothing else. \z, not $: '$' also matches before "\n". */
    private static function id($value, $min, $max)
    {
        if (is_array($value) || is_object($value) || is_bool($value) || $value === null) return null;
        $value = (string) $value;
        if ($value === '' || !preg_match('/^[0-9]+\z/', $value)) return null;
        $n = (int) $value;
        return ($n < $min || $n > $max) ? null : $n;
    }

    private static function name($value)
    {
        if (!is_string($value) || !preg_match(self::NAME_RE, $value)) return null;
        if ($value === '.' || $value === '..') return null;
        return $value;
    }

    /** True if $path resolves inside $root. Both must already exist. */
    private static function inside($path, $root)
    {
        $real = realpath($path);
        $rootReal = realpath($root);
        if ($real === false || $rootReal === false) return false;
        return $real === $rootReal || strpos($real, $rootReal . '/') === 0;
    }

    // ------------------------------------------------------------------ qemu

    /** Run qemu-img with an argv ARRAY. No shell is involved at any point. */
    private function qemu(array $args)
    {
        array_unshift($args, $this->qemuImg);
        if (!$this->runCommands) {
            $this->commands[] = $args;
            return array('rc' => 0, 'out' => '');
        }
        return $this->spawn($args);
    }

    /**
     * proc_open() with an ARRAY execs the binary directly and never builds a
     * command string, so a semicolon in an argument is a semicolon in argv[n].
     *
     * The argv arrives as an array-TYPED parameter deliberately. That is the
     * shape the tokenizer sweep in tests/Security/ShellEscapingTest.php can
     * prove is not a shell; handing proc_open() the result of array_merge()
     * does the same thing at runtime but reads, to the sweep, as a value of
     * unknown type reaching an exec-family call, and it is right to say so.
     */
    private function spawn(array $argv)
    {
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

    /**
     * Every image in the backing chain of $file, deepest last, or null.
     *
     * Each member is required to resolve inside the addons root or inside the
     * node's own workspace. That check is the point of this method: the header
     * that names a backing file is inside a file the tenant (and, because
     * /opt/unetlab/tmp is mode 777, anyone who can write a workspace) controls,
     * and both `commit` and `convert` follow it.
     *
     * The format of each member is kept alongside it (see formats()), because
     * the check alone is not enough -- see spec() for why.
     */
    private function chain($file, $workspace)
    {
        $result = $this->qemu(array('info', '--backing-chain', $file));
        if ($result['rc'] !== 0) return null;
        if (!$this->runCommands) return array($file);

        $chain = array();
        $this->formats = array();
        foreach (explode("\n", $result['out']) as $line) {
            $line = trim($line);
            if (preg_match('/^file format:\s+(\S+)$/', $line, $m) && count($chain)) {
                $this->formats[$chain[count($chain) - 1]] = $m[1];
                continue;
            }
            if (!preg_match('/^image:\s+(.+)$/', $line, $m)) continue;
            $member = $m[1];
            if (count($chain) >= self::MAX_CHAIN) return null;
            if (is_link($member)) return null;
            if (!is_file($member)) return null;
            if (!self::inside($member, $this->addonsRoot) && !self::inside($member, $workspace)) {
                return null;
            }
            $chain[] = realpath($member);
        }
        return count($chain) ? $chain : null;
    }

    /** Format of a chain member as `qemu-img info` reported it; qcow2 if it did not say. */
    private $formats = array();

    private function format($member)
    {
        return isset($this->formats[$member]) && preg_match('/^[a-z0-9_-]+$/', $this->formats[$member])
            ? $this->formats[$member] : 'qcow2';
    }

    /**
     * The image, as an explicit block-graph spec rather than a filename.
     *
     * WHY A FILENAME IS NOT ENOUGH. chain() reads the backing pointer out of
     * the qcow2 header and checks where it points. `qemu-img commit <file>`
     * then reads the SAME header again, and writes into whatever it names by
     * then. The header lives in a file the tenant owns, in a directory that is
     * mode 777. Between the check and the commit, one `qemu-img rebase -u`
     * repoints it at any file on the host, and root's qemu-img commits the
     * node's delta into it -- a write to an attacker-chosen path, with the
     * check having passed. That was measured, not inferred: a header rebased
     * to a copy of /etc/hostname after the check was committed into.
     *
     * So the pointer the header carries is never used for a write or a read.
     * The chain that was checked is what is handed to qemu-img, as a `json:`
     * pseudo-filename whose "backing" is spelled out: the verified member for
     * a chain with one, and null -- open with NO backing file -- for an image
     * that had none when it was checked. qemu opens exactly what the spec
     * says and ignores the header's pointer. Verified against qemu-img 8.2:
     * a hostile header is committed into the verified template and the file
     * the header names is untouched; `"backing": null` flattens a standalone
     * image without opening anything else.
     *
     * The spec is JSON, not the key=value --image-opts form, because a comma
     * in a path would have to be doubled there, and because `backing=null`
     * is only expressible in JSON.
     *
     * The backing member's own backing pointer (a chain of three or more) is
     * still read from that member's header, and that is intended: those are
     * templates under the addons root, which the tenant cannot write. What is
     * closed here is the one hop that crosses the tenant boundary.
     */
    private function spec($top, array $chain)
    {
        $node = array(
            'driver' => $this->format($top),
            'file'   => array('driver' => 'file', 'filename' => $top),
        );
        if (count($chain) >= 2 && $chain[0] === $top) {
            $node['backing'] = array(
                'driver' => $this->format($chain[1]),
                'file'   => array('driver' => 'file', 'filename' => $chain[1]),
            );
        } else {
            $node['backing'] = null;
        }
        return 'json:' . json_encode($node, JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ entry

    /**
     * @return array ['ok'=>bool,'error'=>string|null,'name'=>string|null,
     *                'size'=>int,'files'=>int]
     */
    public function run($sessionRaw, $typeRaw, $nameRaw = null)
    {
        $fail = function ($why) {
            return array('ok' => false, 'error' => $why, 'name' => null, 'size' => 0, 'files' => 0);
        };

        $session = self::id($sessionRaw, 1, self::MAX_SESSION);
        if ($session === null) return $fail('session id is not a bounded integer');

        if (!is_string($typeRaw) || !in_array($typeRaw, self::TYPES, true)) {
            return $fail('type must be one of: ' . implode(', ', self::TYPES));
        }
        $type = $typeRaw;

        $name = null;
        if ($type === 'snapshot' || $type === 'new') {
            $name = self::name($nameRaw);
            if ($name === null) return $fail('name must match ' . self::NAME_RE);
        }

        if ($this->lookup === null) return $fail('no node session lookup was configured');
        $row = call_user_func($this->lookup, $session);
        if (!is_array($row)) return $fail('no such node session');
        if (!isset($row['type']) || $row['type'] !== 'qemu') return $fail('node session is not a QEMU node');
        $labSession = self::id(isset($row['lab']) ? $row['lab'] : null, 1, self::MAX_SESSION);
        if ($labSession === null) return $fail('node session has no usable lab session');

        $workspace = $this->tmpRoot . '/' . $labSession . '/' . $session;
        if (!is_dir($workspace)) return $fail('node workspace does not exist');
        if (realpath($workspace) !== $workspace) return $fail('node workspace is not where it should be');

        $qcows = array();
        foreach (scandir($workspace) as $entry) {
            if (!preg_match(self::QCOW_RE, $entry)) continue;
            $path = $workspace . '/' . $entry;
            if (is_link($path) || !is_file($path)) continue;
            $qcows[] = $path;
        }
        sort($qcows);
        if (!count($qcows)) return $fail('the node workspace holds no disk image');

        // Every chain is resolved and checked BEFORE anything is created or
        // written. A hostile backing pointer must not get as far as a partly
        // built template folder.
        $chains = array();
        foreach ($qcows as $qcow) {
            $chain = $this->chain($qcow, $workspace);
            if ($chain === null) {
                return $fail('the backing chain of ' . basename($qcow)
                    . ' is unreadable, too long, or points outside the image roots');
            }
            $chains[$qcow] = $chain;
        }

        if ($type === 'check') {
            $size = 0;
            $seen = array();
            foreach ($chains as $chain) {
                foreach ($chain as $member) {
                    if (isset($seen[$member])) continue;
                    $seen[$member] = true;
                    $size += (int) @filesize($member);
                }
            }
            return array('ok' => true, 'error' => null, 'name' => null,
                         'size' => $size, 'files' => count($seen));
        }

        if ($type === 'existed') {
            // Merge each node disk down into the template it was cloned from.
            // That mutates a shared template, which is what the feature is; the
            // chain check above is what stops it mutating something else -- and
            // spec() is what makes the check stick, by naming the template
            // explicitly instead of trusting the header a second time.
            foreach ($qcows as $qcow) {
                $chain = $chains[$qcow];
                if (count($chain) < 2 || $chain[0] !== realpath($qcow)) {
                    return $fail(basename($qcow) . ' has no backing file to commit into');
                }
                $r = $this->qemu(array('commit', $this->spec($chain[0], $chain)));
                if ($r['rc'] !== 0) return $fail('qemu-img commit failed: ' . trim($r['out']));
            }
            return array('ok' => true, 'error' => null, 'name' => null,
                         'size' => 0, 'files' => count($qcows));
        }

        // snapshot and new both create a template folder.
        $newFolder = $this->addonsRoot . '/' . $name;
        if (file_exists($newFolder)) return $fail('a template called ' . $name . ' already exists');
        if (!is_dir($this->addonsRoot)) return $fail('the QEMU addons root does not exist');
        if (!$this->runCommands) {
            $this->commands[] = array('mkdir', $newFolder);
        } elseif (!@mkdir($newFolder, 0755)) {
            return $fail('could not create ' . $newFolder);
        }

        $made = 0;
        foreach ($qcows as $qcow) {
            $target = $newFolder . '/' . basename($qcow);
            if ($type === 'snapshot') {
                // Keep the delta. The copy still references its backing file by
                // absolute path, exactly as the original did.
                if (!$this->runCommands) {
                    $this->commands[] = array('copy', $qcow, $target);
                } elseif (!@copy($qcow, $target)) {
                    $this->cleanup($newFolder);
                    return $fail('could not copy ' . basename($qcow));
                }
            } else {
                // Flatten the whole chain into one standalone image. The
                // source is the checked chain, not the header (see spec()).
                $chain = $chains[$qcow];
                $r = $this->qemu(array('convert', '-O', 'qcow2', $this->spec($chain[0], $chain), $target));
                if ($r['rc'] !== 0) {
                    $this->cleanup($newFolder);
                    return $fail('qemu-img convert failed: ' . trim($r['out']));
                }
            }
            $made++;
        }

        $this->chownTree($newFolder);
        return array('ok' => true, 'error' => null, 'name' => $name,
                     'size' => 0, 'files' => $made);
    }

    /** Hand the finished template to the web user, as the old chown -R did. */
    private function chownTree($path)
    {
        if (!$this->runCommands) { $this->commands[] = array('chown', $path, $this->owner); return; }
        if (!function_exists('posix_getpwnam')) return;
        $entry = posix_getpwnam($this->owner);
        if ($entry === false) return;
        @chown($path, $entry['uid']);
        @chgrp($path, $entry['gid']);
        foreach (scandir($path) as $n) {
            if ($n === '.' || $n === '..') continue;
            $child = $path . '/' . $n;
            if (is_link($child)) continue;
            @chown($child, $entry['uid']);
            @chgrp($child, $entry['gid']);
        }
    }

    /**
     * Remove a half-built template. Only ever the folder this call created, and
     * only its own direct children — it is one level deep by construction, and
     * a recursive delete driven by anything else is how this file would become
     * the problem it replaced.
     */
    private function cleanup($folder)
    {
        if (!$this->runCommands) return;
        if (!is_dir($folder) || !self::inside($folder, $this->addonsRoot)) return;
        if (realpath($folder) === realpath($this->addonsRoot)) return;
        foreach (scandir($folder) as $n) {
            if ($n === '.' || $n === '..') continue;
            $child = $folder . '/' . $n;
            if (is_dir($child) && !is_link($child)) continue;   // never created here
            @unlink($child);
        }
        @rmdir($folder);
    }
}
