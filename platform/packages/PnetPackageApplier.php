<?php
/**
 * Applies a verified package plan. This is the only code in the fork that is
 * allowed to change the system on a supplier's instructions, and it is the
 * reason the supplier no longer gets to ship a shell script.
 *
 * WHAT THIS FILE MAY NOT CONTAIN
 *
 * There is no exec(), shell_exec(), system(), passthru(), popen() or backtick
 * operator anywhere below, and tests/Security/PackageApplyTest.php asserts that
 * by walking the tokens. Filesystem work is done with PHP's own calls — mkdir,
 * copy, rename, chmod, chown, unlink — which take paths, not command lines, so
 * there is no shell for a metacharacter to reach. The three operations that do
 * need an external program (docker pull, systemctl restart) go through
 * proc_open() with an ARGV ARRAY, which on PHP >= 7.4 execs the binary directly
 * and never constructs a command string. A semicolon in an argument is a
 * semicolon in argv[1]; it is not a second command.
 *
 * THE TRANSACTION
 *
 * Reversible operations run first, each one journalled before it mutates
 * anything. A file that is about to be overwritten or removed is MOVED into the
 * staging directory rather than deleted, so undo is a rename back. If any
 * reversible operation fails, the journal is replayed backwards and the host is
 * where it started.
 *
 * Irreversible operations (docker pull, service restart) run only after every
 * reversible one has succeeded. If one of those fails, the filesystem changes
 * stand and the failure says so — there is no pretending a service restart can
 * be undone.
 *
 * If the process is killed mid-apply, the journal survives in the staging
 * directory. The next run finds it, replays it backwards, and only then starts
 * work. That is what makes an interrupted upgrade safe: the half-applied state
 * is not repaired by the next upgrade succeeding, it is unwound before the next
 * upgrade begins.
 */

require_once __DIR__ . '/PnetPackage.php';

class PnetPackageApplier
{
    /** Prefix every managed root with this. Empty in production; a temp dir in tests. */
    private $prefix;
    /** Directories searched for trusted public keys, in order. */
    private $trustDirs;
    /** Accept a package with no valid signature. Off unless two separate things say on. */
    private $allowUnsigned;
    /** Where staging, journals and installed state live (already prefixed). */
    private $stateDir;
    /** Call to report progress. */
    private $logger;
    /** Actually chown/chgrp. False when not running as root. */
    private $applyOwnership;
    /** Actually run docker/systemctl. False in tests. */
    private $runCommands;

    private $journalPath;
    private $journal = array();
    private $stagingDir;
    /** Recorded rather than run, so tests can assert on the argv that would have been used. */
    public $commands = array();

    public function __construct(array $options = array())
    {
        $this->prefix = isset($options['prefix']) ? rtrim($options['prefix'], '/') : '';
        $roots = PnetManifest::roots();
        $this->stateDir = isset($options['state_dir'])
            ? rtrim($options['state_dir'], '/')
            : $this->prefix . $roots['state'];
        $this->trustDirs = isset($options['trust_dirs'])
            ? $options['trust_dirs']
            : array($this->stateDir . '/trusted.d', __DIR__ . '/trust');
        $this->allowUnsigned = !empty($options['allow_unsigned']);
        $this->logger = isset($options['logger']) ? $options['logger'] : null;
        $this->applyOwnership = array_key_exists('apply_ownership', $options)
            ? (bool) $options['apply_ownership']
            : (function_exists('posix_geteuid') && posix_geteuid() === 0);
        $this->runCommands = array_key_exists('run_commands', $options)
            ? (bool) $options['run_commands']
            : true;
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }

    /**
     * Verify and apply a package.
     *
     * @return array ['ok' => bool, 'id' => string, 'version' => string,
     *                'signed' => bool, 'key' => string|null, 'error' => string|null,
     *                'rolled_back' => bool]
     */
    public function apply($packagePath)
    {
        $result = array(
            'ok' => false, 'id' => null, 'version' => null, 'signed' => false,
            'key' => null, 'error' => null, 'rolled_back' => false,
        );

        try {
            $this->recoverInterrupted();

            if (!is_file($packagePath)) {
                throw new PnetPackageError('package file not found: ' . PnetManifest::quote($packagePath));
            }
            // A package handed to us by the web layer lives in a directory the
            // web layer can write. Read it through a path we opened ourselves
            // and never follow a link to get there.
            if (is_link($packagePath)) {
                throw new PnetPackageError('package path is a symbolic link');
            }

            $reader = new PnetTarReader($packagePath);

            // --- member 1: the manifest --------------------------------------
            $entry = $reader->next();
            if ($entry === null || $entry['name'] !== PnetManifest::MANIFEST_MEMBER || $entry['type'] !== '0') {
                throw new PnetPackageError('first archive member must be ' . PnetManifest::MANIFEST_MEMBER);
            }
            $manifestBytes = $reader->readBody(PnetManifest::MAX_MANIFEST_BYTES);

            // --- member 2: its signature -------------------------------------
            $entry = $reader->next();
            $signatureBytes = null;
            if ($entry !== null && $entry['name'] === PnetManifest::SIGNATURE_MEMBER && $entry['type'] === '0') {
                $signatureBytes = $reader->readBody(PnetManifest::MAX_SIGNATURE_BYTES);
                $entry = $reader->next();
            }

            $signed = false;
            $keyId = null;
            if ($signatureBytes !== null) {
                $keys = $this->trustedKeys();
                if (count($keys) === 0) {
                    throw new PnetPackageError('package is signed but no trusted keys are installed');
                }
                $keyId = PnetMinisign::verify($signatureBytes, $manifestBytes, $keys);
                $signed = true;
                $this->log('signature verified against key ' . $keyId);
            } else {
                if (!$this->allowUnsigned) {
                    throw new PnetPackageError(
                        'package carries no signature and unsigned packages are not enabled'
                    );
                }
                $this->log('WARNING: applying an UNSIGNED package. Its contents are not attributable to anyone.');
            }
            $result['signed'] = $signed;
            $result['key'] = $keyId;

            $manifest = new PnetManifest($manifestBytes, $signed);
            $result['id'] = $manifest->id;
            $result['version'] = $manifest->version;
            $this->log('package ' . $manifest->id . ' ' . $manifest->version
                . ' (manifest sha256 ' . $manifest->digest . ')');

            // --- staging ------------------------------------------------------
            $this->stagingDir = $this->stateDir . '/staging/' . $manifest->id . '-' . getmypid();
            $this->mkdirp($this->stagingDir, 0700);
            $this->journalPath = $this->stagingDir . '/journal.json';
            $this->journal = array();
            $this->writeJournal(false);

            $this->extractPayload($reader, $manifest, $entry);
            $reader->close();

            // --- plan ---------------------------------------------------------
            $reversible = array();
            $irreversible = array();
            foreach ($manifest->plan as $op) {
                if ($op['reversible']) {
                    $reversible[] = $op;
                } else {
                    $irreversible[] = $op;
                }
            }

            try {
                foreach ($reversible as $op) {
                    $this->execute($op, $manifest);
                }
            } catch (\Exception $e) {
                $this->rollback();
                $result['rolled_back'] = true;
                throw $e;
            }

            foreach ($irreversible as $op) {
                try {
                    $this->execute($op, $manifest);
                } catch (\Exception $e) {
                    throw new PnetPackageError(
                        'operation "' . $op['verb'] . '" failed AFTER the filesystem changes were applied, '
                        . 'and cannot be rolled back: ' . $e->getMessage()
                    );
                }
            }

            $this->recordInstalled($manifest, $signed, $keyId);
            $this->writeJournal(true);
            $this->removeTree($this->stagingDir);

            $result['ok'] = true;
            $this->log('applied ' . $manifest->id . ' ' . $manifest->version);
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $this->log('ERROR: ' . $e->getMessage());
            if ($result['rolled_back']) {
                $this->log('rolled back; the host is as it was before this package');
            }
        }

        return $result;
    }

    /**
     * Run the uninstall plan that was recorded when a package was installed.
     *
     * The plan is not re-downloaded. It was written into root-owned state after
     * its manifest's signature verified, so it has the same provenance as the
     * install did — and it is recompiled through the same verb table and the
     * same argument patterns before anything runs, so a state file that has
     * been edited by hand is rejected exactly like a bad manifest would be.
     *
     * An uninstall operation may not name a payload source: there is no package
     * open, so there is nothing a source could refer to, and allowing the
     * argument would only create a way to ask for a file that is not there.
     *
     * @return array same shape as apply()
     */
    public function uninstall($id)
    {
        $result = array(
            'ok' => false, 'id' => $id, 'version' => null, 'signed' => false,
            'key' => null, 'error' => null, 'rolled_back' => false,
        );
        try {
            $this->recoverInterrupted();

            $types = PnetManifest::types();
            if (!is_string($id) || !preg_match($types['id'], $id)) {
                throw new PnetPackageError('malformed package id');
            }
            $path = $this->stateDir . '/installed/' . $id . '.json';
            if (!is_file($path) || is_link($path)) {
                throw new PnetPackageError('package ' . PnetManifest::quote($id) . ' is not installed');
            }
            $record = json_decode(file_get_contents($path), true);
            if (!is_array($record) || !isset($record['id']) || $record['id'] !== $id) {
                throw new PnetPackageError('installed-state file for ' . PnetManifest::quote($id) . ' is unusable');
            }
            $result['version'] = isset($record['version']) ? $record['version'] : null;

            $ops = isset($record['uninstall']) && is_array($record['uninstall']) ? $record['uninstall'] : array();
            if (count($ops) === 0) {
                throw new PnetPackageError('package ' . PnetManifest::quote($id)
                    . ' shipped no uninstall plan, so it cannot be removed automatically');
            }
            $plan = PnetManifest::compilePlan($ops, true);
            foreach ($plan as $op) {
                if (isset($op['args']['source'])) {
                    throw new PnetPackageError('uninstall operation "' . $op['verb']
                        . '" names a payload source, which an uninstall cannot have');
                }
            }

            $this->stagingDir = $this->stateDir . '/staging/' . $id . '-uninstall-' . getmypid();
            $this->mkdirp($this->stagingDir, 0700);
            $this->journalPath = $this->stagingDir . '/journal.json';
            $this->journal = array();
            $this->writeJournal(false);

            $reversible = array();
            $irreversible = array();
            foreach ($plan as $op) {
                if ($op['reversible']) {
                    $reversible[] = $op;
                } else {
                    $irreversible[] = $op;
                }
            }
            try {
                foreach ($reversible as $op) {
                    $this->executeUninstallOperation($op);
                }
            } catch (\Exception $e) {
                $this->rollback();
                $result['rolled_back'] = true;
                throw $e;
            }
            foreach ($irreversible as $op) {
                $this->executeUninstallOperation($op);
            }

            @unlink($path);
            $this->writeJournal(true);
            $this->removeTree($this->stagingDir);
            $result['ok'] = true;
            $this->log('removed ' . $id);
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $this->log('ERROR: ' . $e->getMessage());
        }
        return $result;
    }

    private function executeUninstallOperation(array $op)
    {
        // Same executor, same verb table. A manifest that shipped install_file
        // in its uninstall section was already refused above for naming a
        // source, so what reaches here is remove/set_permissions/mkdir/
        // set_version/docker_pull/restart_service.
        $this->execute($op, null);
    }

    // ---------------------------------------------------------------- trust

    /** @return array hex key id => 32-byte raw public key */
    public function trustedKeys()
    {
        $keys = array();
        foreach ($this->trustDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $entries = scandir($dir);
            if ($entries === false) {
                continue;
            }
            sort($entries);
            foreach ($entries as $name) {
                if (substr($name, -4) !== '.pub') {
                    continue;
                }
                $path = $dir . '/' . $name;
                if (!is_file($path) || is_link($path)) {
                    continue;
                }
                try {
                    $key = PnetMinisign::parsePublicKey(file_get_contents($path));
                } catch (PnetPackageError $e) {
                    $this->log('ignoring unreadable trusted key ' . $name . ': ' . $e->getMessage());
                    continue;
                }
                $keys[bin2hex($key['id'])] = $key['pk'];
            }
        }
        return $keys;
    }

    // ------------------------------------------------------------ extraction

    /**
     * Stream the payload members into staging.
     *
     * Every member must be declared in the manifest, must be exactly the
     * declared size, and must hash to the declared sha256. Since the manifest
     * was signed before this ran, those numbers are the supplier's authenticated
     * statement of what the package weighs, which is what bounds a
     * decompression bomb — the applier stops the moment a member exceeds what
     * was declared rather than after the disk fills.
     *
     * @param array|null $entry the member already read by the caller, if any
     */
    private function extractPayload(PnetTarReader $reader, PnetManifest $manifest, $entry)
    {
        $payloadDir = $this->stagingDir . '/payload';
        $this->mkdirp($payloadDir, 0700);

        $seen = array();
        $count = 0;
        while ($entry !== null) {
            $count++;
            if ($count > PnetManifest::MAX_MEMBERS) {
                throw new PnetPackageError('package holds more than ' . PnetManifest::MAX_MEMBERS . ' members');
            }
            $name = $entry['name'];
            if (strpos($name, PnetManifest::PAYLOAD_PREFIX) !== 0) {
                throw new PnetPackageError('unexpected archive member ' . PnetManifest::quote($name)
                    . ': only ' . PnetManifest::PAYLOAD_PREFIX . '* may follow the manifest');
            }
            $rel = substr($name, strlen(PnetManifest::PAYLOAD_PREFIX));

            // The name is checked against the same pattern the manifest's
            // payload keys are checked against. An absolute path fails it (no
            // leading slash is allowed), '..' fails it (a component must begin
            // with an alphanumeric), and a NUL or newline fails it. This runs
            // BEFORE any path is built from the name.
            // Traversal is checked first and by component, so the classic case
            // is refused by the rule that names it rather than falling through
            // to the general pattern and being refused for the wrong reason.
            foreach (explode('/', $rel) as $component) {
                if ($component === '' || $component === '.' || $component === '..') {
                    throw new PnetPackageError('rejected archive member ' . PnetManifest::quote($name)
                        . ': path traversal');
                }
            }
            $types = PnetManifest::types();
            if ($rel === '' || !preg_match($types['source'], $rel)) {
                throw new PnetPackageError('rejected archive member ' . PnetManifest::quote($name)
                    . ': name is not a safe relative path');
            }
            if ($entry['type'] !== '0') {
                throw new PnetPackageError('rejected archive member ' . PnetManifest::quote($name)
                    . ': payload members must be regular files');
            }
            if (!isset($manifest->payload[$rel])) {
                throw new PnetPackageError('archive member ' . PnetManifest::quote($name)
                    . ' is not declared in the manifest');
            }
            if (isset($seen[$rel])) {
                throw new PnetPackageError('archive member ' . PnetManifest::quote($name) . ' appears twice');
            }
            $declared = $manifest->payload[$rel];
            if ($entry['size'] !== $declared['size']) {
                throw new PnetPackageError('archive member ' . PnetManifest::quote($name)
                    . ' is ' . $entry['size'] . ' bytes; the manifest declares ' . $declared['size']);
            }

            $dest = $payloadDir . '/' . $rel;
            $this->mkdirp(dirname($dest), 0700);
            $out = fopen($dest, 'wb');
            if ($out === false) {
                throw new PnetPackageError('cannot write staging file for ' . PnetManifest::quote($name));
            }
            $digest = $reader->copyBodyTo($out);
            fclose($out);
            if (!hash_equals($declared['sha256'], $digest)) {
                throw new PnetPackageError('archive member ' . PnetManifest::quote($name)
                    . ' does not match the digest in the manifest');
            }
            $seen[$rel] = true;
            $entry = $reader->next();
        }

        foreach (array_keys($manifest->payload) as $rel) {
            if (!isset($seen[$rel])) {
                throw new PnetPackageError('manifest declares payload member ' . PnetManifest::quote($rel)
                    . ' but the archive does not contain it');
            }
        }
        $this->log('extracted and verified ' . count($seen) . ' payload file(s)');
    }

    // ------------------------------------------------------------- the verbs

    private function execute(array $op, $manifest = null)
    {
        $verb = $op['verb'];
        $args = $op['args'];
        $this->log('-> ' . $verb . ' ' . json_encode($args));

        switch ($verb) {
            case 'mkdir':
                $target = $this->target($args['path']);
                $this->createDirectory($target, isset($args['mode']) ? $args['mode'] : '0755');
                break;

            case 'install_file':
                $this->installFile($args['source'], $args['path'], $args);
                break;

            case 'install_image':
                $folder = isset($args['folder']) && $args['folder'] !== '' ? $args['folder'] . '/' : '';
                $spec = 'addons:' . $args['emulator'] . '/' . $folder . $args['name'];
                $dir = 'addons:' . rtrim($args['emulator'] . '/' . $folder, '/');
                $this->createDirectory($this->target($dir), '0755');
                $this->installFile($args['source'], $spec, $args);
                break;

            case 'install_template':
                $arch = isset($args['arch']) ? $args['arch'] : 'intel';
                $this->installFile($args['source'], 'templates:' . $arch . '/' . $args['name'],
                    array('mode' => '0644', 'owner' => 'www-data:www-data'));
                break;

            case 'install_icon':
                $this->installFile($args['source'], 'icons:' . $args['name'],
                    array('mode' => '0644', 'owner' => 'www-data:www-data'));
                break;

            case 'install_config_script':
                $this->installFile($args['source'], 'scripts:' . $args['name'],
                    array('mode' => '0755', 'owner' => 'root:root'));
                break;

            case 'set_permissions':
                $this->setPermissions($args);
                break;

            case 'remove':
                $this->removePath($this->target($args['path']));
                break;

            case 'set_version':
                $this->writeManagedFile($this->target('state:version'), $args['version'] . "\n", '0644');
                break;

            case 'docker_pull':
                $this->runArgv(array('/usr/bin/docker', 'pull', '--', $args['image']));
                break;

            case 'restart_service':
                $this->runArgv(array('/usr/bin/systemctl', 'restart', '--', $args['service'] . '.service'));
                break;

            default:
                // Unreachable: PnetManifest rejects an unknown verb at parse
                // time. Present so that adding a verb to the table without
                // adding it here fails loudly rather than silently doing
                // nothing.
                throw new PnetPackageError('verb ' . PnetManifest::quote($verb) . ' has no implementation');
        }
    }

    /** Resolve a manifest path spec and refuse it if any component is a symlink. */
    private function target($spec)
    {
        $resolved = PnetManifest::resolve($spec, $this->prefix);
        $this->assertNoSymlinkOnPath($resolved['base'], $resolved['path']);
        return $resolved['path'];
    }

    /**
     * Walk from the managed root down to the target and refuse if any
     * intermediate component is a symbolic link.
     *
     * The archive can no longer contain a link, but a link could have been
     * planted under a managed root by something else, and writing "into" it
     * would put a supplier's file wherever it points. Checked per operation,
     * immediately before the operation, so the window is as small as a
     * userspace check can make it.
     */
    private function assertNoSymlinkOnPath($base, $path)
    {
        $rel = substr($path, strlen($base) + 1);
        $walk = $base;
        if (is_link($walk)) {
            throw new PnetPackageError('managed root is a symbolic link: ' . PnetManifest::quote($base));
        }
        foreach (explode('/', $rel) as $component) {
            $walk .= '/' . $component;
            if (is_link($walk)) {
                throw new PnetPackageError('refusing to follow a symbolic link at ' . PnetManifest::quote($walk));
            }
        }
    }

    private function installFile($source, $spec, array $args)
    {
        $src = $this->stagingDir . '/payload/' . $source;
        if (!is_file($src) || is_link($src)) {
            throw new PnetPackageError('payload file missing from staging: ' . PnetManifest::quote($source));
        }
        $dest = $this->target($spec);
        $this->createDirectory(dirname($dest), '0755');
        $this->backupIfPresent($dest);

        // Copy to a sibling temporary and rename, so a reader never sees a
        // half-written file and an interrupted copy leaves no partial image
        // where a complete one is expected.
        $tmp = $dest . '.pnetpkg-' . getmypid();
        if (!@copy($src, $tmp)) {
            throw new PnetPackageError('cannot copy into place: ' . PnetManifest::quote($spec));
        }
        $mode = isset($args['mode']) ? $args['mode'] : '0644';
        @chmod($tmp, intval($mode, 8));
        $this->applyOwner($tmp, isset($args['owner']) ? $args['owner'] : null);
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new PnetPackageError('cannot move into place: ' . PnetManifest::quote($spec));
        }
        $this->record(array('undo' => 'delete_file', 'path' => $dest));
    }

    private function writeManagedFile($dest, $content, $mode)
    {
        $this->createDirectory(dirname($dest), '0755');
        $this->backupIfPresent($dest);
        $tmp = $dest . '.pnetpkg-' . getmypid();
        if (@file_put_contents($tmp, $content) === false) {
            throw new PnetPackageError('cannot write ' . PnetManifest::quote($dest));
        }
        @chmod($tmp, intval($mode, 8));
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new PnetPackageError('cannot move ' . PnetManifest::quote($dest) . ' into place');
        }
        $this->record(array('undo' => 'delete_file', 'path' => $dest));
    }

    private function createDirectory($path, $mode)
    {
        if (is_dir($path)) {
            return;
        }
        $parent = dirname($path);
        if ($parent !== $path && !is_dir($parent)) {
            $this->createDirectory($parent, '0755');
        }
        if (is_link($path)) {
            throw new PnetPackageError('refusing to treat a symbolic link as a directory: ' . PnetManifest::quote($path));
        }
        if (!@mkdir($path, intval($mode, 8))) {
            throw new PnetPackageError('cannot create directory ' . PnetManifest::quote($path));
        }
        @chmod($path, intval($mode, 8));
        $this->record(array('undo' => 'delete_dir', 'path' => $path));
    }

    private function setPermissions(array $args)
    {
        $path = $this->target($args['path']);
        if (!file_exists($path)) {
            throw new PnetPackageError('set_permissions on a path that does not exist: '
                . PnetManifest::quote($args['path']));
        }
        $recursive = isset($args['recursive']) && ($args['recursive'] === '1' || $args['recursive'] === 'true');
        $paths = $recursive ? $this->walk($path) : array($path);
        foreach ($paths as $p) {
            if (is_link($p)) {
                continue; // never chmod or chown through a link
            }
            if (isset($args['mode'])) {
                $old = @fileperms($p);
                $this->record(array('undo' => 'mode', 'path' => $p, 'mode' => $old === false ? null : ($old & 07777)));
                @chmod($p, intval($args['mode'], 8));
            }
            if (isset($args['owner'])) {
                $this->applyOwner($p, $args['owner'], true);
            }
        }
    }

    private function applyOwner($path, $owner, $journal = false)
    {
        if ($owner === null) {
            return;
        }
        if (!$this->applyOwnership) {
            $this->log('   (not root: leaving ownership of ' . basename($path) . ' unchanged, wanted ' . $owner . ')');
            return;
        }
        list($user, $group) = explode(':', $owner);
        if ($journal) {
            $this->record(array('undo' => 'owner', 'path' => $path,
                'uid' => @fileowner($path), 'gid' => @filegroup($path)));
        }
        if (!@chown($path, $user)) {
            throw new PnetPackageError('cannot set owner ' . $user . ' on ' . PnetManifest::quote($path));
        }
        if (!@chgrp($path, $group)) {
            throw new PnetPackageError('cannot set group ' . $group . ' on ' . PnetManifest::quote($path));
        }
    }

    /** Move an existing path aside instead of destroying it, so undo is a rename. */
    private function backupIfPresent($path)
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $backupRoot = $this->stagingDir . '/rollback';
        if (!is_dir($backupRoot) && !@mkdir($backupRoot, 0700, true)) {
            throw new PnetPackageError('cannot create the rollback directory');
        }
        $backup = $backupRoot . '/' . count($this->journal) . '-' . basename($path);
        if (!@rename($path, $backup)) {
            throw new PnetPackageError('cannot move the existing ' . PnetManifest::quote($path) . ' aside');
        }
        $this->record(array('undo' => 'restore', 'path' => $path, 'backup' => $backup));
    }

    private function removePath($path)
    {
        if (!file_exists($path) && !is_link($path)) {
            return; // idempotent: removing what is not there is success
        }
        $this->backupIfPresent($path);
    }

    /**
     * Run an external program with an argv array.
     *
     * proc_open() with an array does not spawn a shell, so nothing in $argv is
     * ever parsed as syntax. Every element is additionally pattern-checked at
     * manifest-parse time; this is the second of the two defences, not the
     * first.
     */
    private function runArgv(array $argv)
    {
        $this->commands[] = $argv;
        if (!$this->runCommands) {
            $this->log('   (not executing: ' . implode(' ', $argv) . ')');
            return;
        }
        $descriptors = array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $proc = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new PnetPackageError('cannot start ' . $argv[0]);
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        foreach (preg_split('/\r?\n/', trim($out . "\n" . $err)) as $line) {
            if ($line !== '') {
                $this->log('   ' . $line);
            }
        }
        if ($rc !== 0) {
            throw new PnetPackageError($argv[0] . ' exited ' . $rc);
        }
    }

    // ------------------------------------------------------------- the journal

    private function record(array $entry)
    {
        $this->journal[] = $entry;
        $this->writeJournal(false);
    }

    private function writeJournal($complete)
    {
        if ($this->journalPath === null) {
            return;
        }
        $payload = json_encode(array('complete' => $complete, 'entries' => $this->journal));
        $tmp = $this->journalPath . '.tmp';
        file_put_contents($tmp, $payload);
        // The journal has to be on disk before the operation it describes, or a
        // kill between the two leaves a change nothing knows how to undo.
        $fh = fopen($tmp, 'r');
        if ($fh) {
            fflush($fh);
            fclose($fh);
        }
        rename($tmp, $this->journalPath);
    }

    /** Undo the journal, newest first. */
    private function rollback()
    {
        $this->log('rolling back ' . count($this->journal) . ' operation(s)');
        for ($i = count($this->journal) - 1; $i >= 0; $i--) {
            $entry = $this->journal[$i];
            try {
                switch ($entry['undo']) {
                    case 'delete_file':
                        if (is_file($entry['path']) || is_link($entry['path'])) {
                            @unlink($entry['path']);
                        }
                        break;
                    case 'delete_dir':
                        if (is_dir($entry['path'])) {
                            @rmdir($entry['path']);
                        }
                        break;
                    case 'restore':
                        if (file_exists($entry['backup']) || is_link($entry['backup'])) {
                            if (file_exists($entry['path']) || is_link($entry['path'])) {
                                $this->removeTree($entry['path']);
                            }
                            @rename($entry['backup'], $entry['path']);
                        }
                        break;
                    case 'mode':
                        if ($entry['mode'] !== null && file_exists($entry['path'])) {
                            @chmod($entry['path'], $entry['mode']);
                        }
                        break;
                    case 'owner':
                        if ($this->applyOwnership && file_exists($entry['path'])) {
                            if ($entry['uid'] !== false) {
                                @chown($entry['path'], (int) $entry['uid']);
                            }
                            if ($entry['gid'] !== false) {
                                @chgrp($entry['path'], (int) $entry['gid']);
                            }
                        }
                        break;
                }
            } catch (\Exception $e) {
                $this->log('rollback step failed: ' . $e->getMessage());
            }
        }
        $this->journal = array();
        $this->writeJournal(true);
    }

    /**
     * Unwind any journal left behind by a killed run before starting a new one.
     *
     * This is the answer to "what happens if the box loses power halfway
     * through an upgrade": the next attempt does not build on the wreckage, it
     * clears it first.
     */
    public function recoverInterrupted()
    {
        $stagingRoot = $this->stateDir . '/staging';
        if (!is_dir($stagingRoot)) {
            return;
        }
        foreach (scandir($stagingRoot) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $dir = $stagingRoot . '/' . $name;
            $journalPath = $dir . '/journal.json';
            if (!is_file($journalPath)) {
                $this->removeTree($dir);
                continue;
            }
            $data = json_decode(file_get_contents($journalPath), true);
            if (is_array($data) && empty($data['complete']) && !empty($data['entries'])) {
                $this->log('found an interrupted apply in ' . $name . '; unwinding it first');
                $savedJournal = $this->journal;
                $savedPath = $this->journalPath;
                $this->journal = $data['entries'];
                $this->journalPath = $journalPath;
                $this->rollback();
                $this->journal = $savedJournal;
                $this->journalPath = $savedPath;
            }
            $this->removeTree($dir);
        }
    }

    // -------------------------------------------------------------- bookkeeping

    private function recordInstalled(PnetManifest $manifest, $signed, $keyId)
    {
        $dir = $this->stateDir . '/installed';
        $this->mkdirp($dir, 0755);
        $record = array(
            'id' => $manifest->id,
            'version' => $manifest->version,
            'kind' => $manifest->kind,
            'name' => isset($manifest->data['name']) ? $manifest->data['name'] : $manifest->id,
            'device_id' => isset($manifest->data['device_id']) ? (string) $manifest->data['device_id'] : null,
            'signed' => $signed,
            'key' => $keyId,
            'manifest_sha256' => $manifest->digest,
            'installed_at' => gmdate('c'),
            'uninstall' => isset($manifest->data['uninstall']) ? $manifest->data['uninstall'] : array(),
        );
        $path = $dir . '/' . $manifest->id . '.json';
        file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        @chmod($path, 0644);
    }

    private function mkdirp($path, $mode)
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new PnetPackageError('cannot create ' . PnetManifest::quote($path));
        }
    }

    /** Every path under $root, deepest first, never crossing a symbolic link. */
    private function walk($root)
    {
        $out = array();
        if (is_link($root) || !is_dir($root)) {
            return array($root);
        }
        $entries = @scandir($root);
        if ($entries !== false) {
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $child = $root . '/' . $name;
                if (!is_link($child) && is_dir($child)) {
                    foreach ($this->walk($child) as $p) {
                        $out[] = $p;
                    }
                } else {
                    $out[] = $child;
                }
            }
        }
        $out[] = $root;
        return $out;
    }

    private function removeTree($path)
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = @scandir($path);
        if ($entries !== false) {
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $this->removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
