<?php
/**
 * Dump and restore the two schemas this product keeps its state in.
 *
 * WHAT THIS REPLACES
 *
 * unl_wrapper carried three cases — backupdb, restoredb and restoredb_remote —
 * that between them were four separate defects in nine lines:
 *
 *     case "backupdb":
 *         shell_exec("mysqldump -uroot -ppnetlab --add-drop-database --skip-comments
 *                     --databases pnetlab_db > /opt/unetlab/backup_database/pnetlab_db.sql
 *                   ; mysqldump -uroot -ppnetlab ... --databases guacdb > .../guacdb.sql");
 *     case "restoredb":
 *         shell_exec("cat .../pnetlab_db.sql | /usr/bin/mysql --password=pnetlab pnetlab_db
 *                   ; cat .../guacdb.sql     | /usr/bin/mysql --password=pnetlab guacdb");
 *
 * 1. IT COULD NOT WORK, AND NOT FOR THE REASON IT LOOKS LIKE. The destination
 *    /opt/unetlab/backup_database DOES NOT EXIST. Nothing in this tree created
 *    it — not the installer, not the wrapper — and a stock 5.3.13 appliance does
 *    not have it either. Measured, running that exact string as root with the
 *    directory absent:
 *        sh: 1: cannot create /opt/unetlab/backup_database/pnetlab_db.sql:
 *              Directory nonexistent            (twice, once per schema)
 *        shell_exec() returned NULL
 *    and the case then fell through to `break` and exit 0. So `-a backupdb`
 *    reported success, wrote nothing, and `-a restoredb` had nothing to read.
 *    Nobody found out, because nothing calls these actions: there is no caller
 *    in store/, none in includes/, none in the crontab, and none on the
 *    appliance either. The installer now creates the directory (0700 root:root,
 *    install/lib/deploy.sh) and this action creates it if it is still missing.
 * 2. THE PASSWORD WAS ON THE COMMAND LINE, and bought nothing. `-ppnetlab` is
 *    the appliance's MariaDB root password; install/lib/database.sh deliberately
 *    does not set one, because the appliance's choice handed the database to
 *    every local account on the box. BE CAREFUL HOW YOU TEST THIS: as root the
 *    old command still authenticates, because the unix_socket plugin
 *    authenticates by peer uid and IGNORES whatever password was supplied — so
 *    checking it as root shows it "working". As any other local account it is
 *    ERROR 1698, measured both ways. What the argument therefore is here is pure
 *    cost: it authenticates nothing and publishes the appliance credential to
 *    /proc/<pid>/cmdline — readable by every local account, and the web layer's
 *    own sudo policy grants `ps` — for as long as the dump runs. On a host where
 *    root does have that password, which is every appliance, it is the real one.
 * 3. NO ERROR CHECKING WHATSOEVER. shell_exec() discards the return, the two
 *    dumps were chained with `;` so the second ran regardless of the first, and
 *    the redirection truncated the destination before mysqldump had produced a
 *    byte. A failed backup was indistinguishable from a good one and had already
 *    destroyed the previous good one — the single worst property a backup can
 *    have.
 * 4. A SHELL RAN ALL OF IT, with redirection and a pipe.
 *
 * WHAT THIS DOES INSTEAD
 *
 *   - AUTHENTICATION IS THE INSTALLER'S. `--protocol=socket -uroot`, matching
 *     mysql_root() in install/lib/database.sh. unl_wrapper has already refused to
 *     run as anything but uid 0 by the time this class is loaded, and uid 0 is
 *     exactly what the unix_socket plugin authenticates. NO PASSWORD IS PASSED,
 *     ON A COMMAND LINE OR ANYWHERE ELSE, AND NO DEFAULTS FILE IS NEEDED —
 *     socket authentication has no credential to keep. There is deliberately no
 *     option on this class through which one could be supplied.
 *   - proc_open() with an argv ARRAY. No shell, no `;`, no `|`, no `>`. The dump
 *     file is opened by PHP and its descriptor handed to the child as fd 1; on
 *     restore the same is done with fd 0.
 *   - EVERY EXIT STATUS IS CHECKED, and so is the shape of what came out. A dump
 *     is written to a mode-0600 temporary file in the same directory and renamed
 *     over the live backup only after mysqldump exited 0, the file is non-empty,
 *     it names the schema it claims to, and it carries mysqldump's own
 *     "-- Dump completed" trailer. A failure leaves the previous backup exactly
 *     where it was.
 *   - BOTH SCHEMAS MOVE TOGETHER. Both dumps are produced into temporary files
 *     and both are renamed only if both succeeded, so guacdb failing cannot
 *     leave a new pnetlab_db.sql beside a stale guacdb.sql. That pairing is the
 *     point: guacdb holds the Guacamole connection rows for the HTML5 consoles,
 *     keyed to node session ids in pnetlab_db, and a restore that misses it
 *     leaves every console pointing at a node that no longer exists.
 *   - `--skip-comments` is gone. It stripped the header naming the server
 *     version and the trailer saying the dump finished, which are the only two
 *     things in the file that let a later reader tell a complete dump from a
 *     truncated one. Six lines of comments is a cheap integrity marker.
 *   - `--single-transaction` is new. Both schemas are entirely InnoDB, so this
 *     gives a consistent snapshot without taking a read lock the running
 *     application would block on.
 *
 * RESTORE IS DESTRUCTIVE, AND IS TREATED THAT WAY
 *
 *   - It REFUSES while any lab session or node session exists. The dump carries
 *     `DROP DATABASE IF EXISTS`, so restoring under a running lab would delete
 *     the lab_sessions and node_sessions rows out from under processes that are
 *     still running, leaving orphaned emulators, taps and tenant accounts that
 *     nothing can now find to reap. If the session count cannot be established
 *     at all, it refuses then too — this fails closed.
 *   - It takes a SAFETY DUMP of the live databases first, into
 *     <backup root>/pre-restore/, and refuses to proceed if that dump fails.
 *     One generation, overwritten by each restore, so it cannot grow without
 *     bound. If a restore goes wrong, that directory is what you put back.
 *
 * WHAT restoredb_remote WAS, AND WHAT HAPPENED TO IT
 *
 * It read /opt/unetlab/backup_database/remote/, which nothing in this tree
 * writes. It is not, however, imaginary: on a 5.3.13 appliance
 * /opt/unetlab/scripts/migrate_new_host.sh — an appliance-to-appliance migration
 * helper, not shipped in this repository — does
 *
 *     sshpass -e ssh root@$SRC "mysqldump --password=pnetlab --databases pnetlab_db guacdb > /tmp/remotedb.sql"
 *     sshpass -e rsync -e ssh root@$SRC:/tmp/remotedb.sql /opt/unetlab/backup_database/remote
 *     cat /opt/unetlab/backup_database/remote/remotedb.sql | /usr/bin/mysql --password=pnetlab
 *
 * So the directory is a migration staging area — and note that even there
 * `restoredb_remote` was dead code twice over: the script writes ONE file called
 * remotedb.sql holding both schemas, while restoredb_remote reads two files
 * called pnetlab_db.sql and guacdb.sql that nothing ever creates, and the script
 * does its own restore inline rather than calling the wrapper at all.
 *
 * The capability is kept, because "restore from a dump someone put here from
 * another host" is a real thing to want and dropping it silently would be worse
 * than keeping it. It is now `-a restoredb --source remote`, which reads the
 * same directory, applies every check the local path applies, and — unlike the
 * original — says so out loud when the files are not there. It is NOT a separate
 * action, because a separate action is how it came to be dead: one word of
 * argument on the action people actually use cannot rot in the same way.
 */

class UnlBackupDatabase
{
    /** Both schemas, always, in restore order. Closed set. */
    const SCHEMAS = array('pnetlab_db', 'guacdb');
    /** Where a restore may read from. A word, never a path. */
    const SOURCES = array('local', 'remote');
    /** mysqldump's own end-of-file marker. Absent means truncated. */
    const TRAILER = '-- Dump completed';
    /** A dump smaller than this is not a schema, whatever it exited with. */
    const MIN_BYTES = 512;

    private $backupRoot;
    private $mysqldump;
    private $mysql;
    private $sessions;
    private $runCommands;

    /** Recorded rather than run when run_commands is false. */
    public $commands = array();

    public function __construct(array $options = array())
    {
        $prefix = isset($options['prefix']) ? rtrim($options['prefix'], '/') : '';
        $this->backupRoot = isset($options['backup_root'])
            ? rtrim($options['backup_root'], '/') : $prefix . '/opt/unetlab/backup_database';
        $this->mysqldump = isset($options['mysqldump']) ? $options['mysqldump'] : null;
        $this->mysql     = isset($options['mysql'])     ? $options['mysql']     : null;
        $this->sessions  = isset($options['sessions'])  ? $options['sessions']  : null;
        $this->runCommands = array_key_exists('run_commands', $options)
            ? (bool) $options['run_commands'] : true;
    }

    /** Where the backups live, so a caller can report it without guessing. */
    public function root()
    {
        return $this->backupRoot;
    }

    // ------------------------------------------------------------- the client

    /**
     * The dump and client binaries, by absolute path.
     *
     * install/lib/database.sh resolves `mariadb` then `mysql` and this follows
     * it, one name at a time, from a fixed list of directories. There is no PATH
     * lookup, because PATH is inherited from whoever invoked sudo.
     */
    private static function which(array $names)
    {
        foreach ($names as $name) {
            foreach (array('/usr/bin/', '/usr/local/bin/', '/bin/') as $dir) {
                $path = $dir . $name;
                if (is_file($path) && is_executable($path)) return $path;
            }
        }
        return null;
    }

    private function dumpBinary()
    {
        if ($this->mysqldump === null) {
            $this->mysqldump = self::which(array('mariadb-dump', 'mysqldump'));
        }
        return $this->mysqldump;
    }

    private function clientBinary()
    {
        if ($this->mysql === null) {
            $this->mysql = self::which(array('mariadb', 'mysql'));
        }
        return $this->mysql;
    }

    /**
     * The arguments that authenticate. Socket, root, and nothing else.
     *
     * Kept as one method so there is exactly one place to read when the question
     * is "how does this authenticate", and exactly one place that would have to
     * change to introduce a credential — which would then be visible in a diff.
     */
    private static function authArgs()
    {
        return array('--protocol=socket', '-uroot');
    }

    // --------------------------------------------------------------- spawning

    /**
     * proc_open() with an ARRAY execs the binary directly and never builds a
     * command string.
     *
     * The argv arrives as an array-TYPED parameter deliberately; that is the
     * shape tests/Security/ShellEscapingTest.php's tokenizer sweep can prove is
     * not a shell. $stdin and $stdout are open PHP stream resources or null, so
     * the redirection the old code did with `>` and `|` happens in the process
     * table rather than in a shell.
     */
    private function spawn(array $argv, $stdin, $stdout)
    {
        $desc = array(
            0 => $stdin  === null ? array('file', '/dev/null', 'r') : $stdin,
            1 => $stdout === null ? array('pipe', 'w')              : $stdout,
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $proc = proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) return array('rc' => 127, 'out' => 'could not execute ' . $argv[0]);
        $out = '';
        if (isset($pipes[1])) { $out .= stream_get_contents($pipes[1]); fclose($pipes[1]); }
        if (isset($pipes[2])) { $out .= stream_get_contents($pipes[2]); fclose($pipes[2]); }
        $rc = proc_close($proc);
        return array('rc' => $rc, 'out' => $out);
    }

    // ------------------------------------------------------------- filesystem

    /**
     * The backup root, created 0700 root-owned if it is not there.
     *
     * 0700 is not tidiness. These dumps hold the whole `users` table, including
     * every password digest, and guacdb holds the console connection parameters.
     * A world-readable backup directory is a database leak that survives every
     * other control in this tree.
     */
    private function ensureDir($path)
    {
        if (is_dir($path)) {
            @chmod($path, 0700);
            return true;
        }
        if (file_exists($path)) return false;
        if (!@mkdir($path, 0700, true)) return false;
        @chmod($path, 0700);
        return true;
    }

    /**
     * Why $path is not a complete dump of $schema, or null if it is.
     *
     * Three separate questions, because each catches a different failure: a
     * truncated file (no trailer), an empty one (size), and the wrong file in
     * the right place (the schema its own USE statement names). The last is what
     * stops `--source remote` restoring a dump of something else over
     * pnetlab_db, and it is the reason the combined remotedb.sql the appliance's
     * migration script produces is refused rather than half-applied.
     */
    private static function inspect($path, $schema)
    {
        if (is_link($path)) return 'is a symlink, and a backup that can be redirected is not a backup';
        if (!is_file($path)) return 'does not exist';
        $size = filesize($path);
        if ($size === false || $size < self::MIN_BYTES) return 'is empty or far too small to be a schema dump';

        $head = @file_get_contents($path, false, null, 0, 65536);
        if ($head === false) return 'cannot be read';
        if (!preg_match_all('/^USE `([A-Za-z0-9_$]+)`;/m', $head, $ms)) {
            return 'names no database: it was not produced with --databases, so a restore '
                 . 'would have nowhere to put it';
        }
        $named = array_values(array_unique($ms[1]));
        if (count($named) !== 1 || $named[0] !== $schema) {
            return 'is a dump of ' . implode(', ', $named) . ', not of ' . $schema;
        }

        // The trailer is the last thing mysqldump writes. Read the tail rather
        // than the whole file: these are megabytes on a used install.
        $fh = @fopen($path, 'rb');
        if ($fh === false) return 'cannot be read';
        fseek($fh, max(0, $size - 4096));
        $tail = stream_get_contents($fh);
        fclose($fh);
        if (strpos($tail, self::TRAILER) === false) {
            return 'has no "' . self::TRAILER . '" trailer, so it is truncated';
        }
        return null;
    }

    // ------------------------------------------------------------------- dump

    /**
     * Dump every schema into $dir, atomically, or change nothing.
     *
     * Each dump goes to a 0600 temporary file created by tempnam() in the target
     * directory, so the rename at the end is within one filesystem and therefore
     * atomic. Nothing is renamed until every schema has succeeded.
     *
     * @return array ['ok'=>bool,'error'=>string|null,'files'=>array]
     */
    private function dumpAll($dir)
    {
        $binary = $this->dumpBinary();
        if ($binary === null && $this->runCommands) {
            return array('ok' => false, 'files' => array(),
                'error' => 'no mariadb-dump or mysqldump binary was found');
        }
        if (!$this->ensureDir($dir)) {
            return array('ok' => false, 'files' => array(),
                'error' => 'could not create the backup directory ' . $dir);
        }

        $staged = array();
        $files  = array();
        $abort  = function ($why) use (&$staged) {
            foreach ($staged as $tmp) @unlink($tmp);
            return array('ok' => false, 'error' => $why, 'files' => array());
        };

        foreach (self::SCHEMAS as $schema) {
            $argv = array_merge(array($binary === null ? 'mysqldump' : $binary), self::authArgs(),
                array('--add-drop-database', '--single-transaction', '--databases', $schema));

            if (!$this->runCommands) {
                $this->commands[] = $argv;
                $files[] = array('schema' => $schema, 'path' => $dir . '/' . $schema . '.sql', 'bytes' => 0);
                continue;
            }

            $tmp = @tempnam($dir, '.dump-');
            if ($tmp === false) return $abort('could not create a temporary file in ' . $dir);
            $staged[$schema] = $tmp;
            @chmod($tmp, 0600);

            $fh = @fopen($tmp, 'wb');
            if ($fh === false) return $abort('could not open ' . $tmp . ' for writing');
            $result = $this->spawn($argv, null, $fh);
            fclose($fh);

            if ($result['rc'] !== 0) {
                return $abort('dumping ' . $schema . ' failed (exit ' . $result['rc'] . '): '
                    . trim($result['out']));
            }
            $why = self::inspect($tmp, $schema);
            if ($why !== null) {
                return $abort('the dump of ' . $schema . ' ' . $why
                    . ' — the previous backup has been left untouched');
            }
            $files[] = array('schema' => $schema, 'path' => $dir . '/' . $schema . '.sql',
                             'bytes' => (int) filesize($tmp));
        }

        // Every schema dumped cleanly. Publish them together.
        foreach ($staged as $schema => $tmp) {
            $target = $dir . '/' . $schema . '.sql';
            if (!@rename($tmp, $target)) {
                return $abort('could not move the new dump of ' . $schema . ' into place');
            }
            @chmod($target, 0600);
        }
        return array('ok' => true, 'error' => null, 'files' => $files);
    }

    // ------------------------------------------------------------------ entry

    /**
     * -a backupdb
     *
     * @return array ['ok'=>bool,'error'=>string|null,'action'=>'backup',
     *                'directory'=>string,'files'=>array]
     */
    public function backup()
    {
        $result = $this->dumpAll($this->backupRoot);
        return array(
            'ok'        => $result['ok'],
            'error'     => $result['error'],
            'action'    => 'backup',
            'directory' => $this->backupRoot,
            'files'     => $result['files'],
        );
    }

    /**
     * -a restoredb [--source local|remote]
     *
     * @return array ['ok'=>bool,'error'=>string|null,'action'=>'restore',
     *                'source'=>string|null,'directory'=>string|null,
     *                'safety'=>string|null,'restored'=>array]
     */
    public function restore($sourceRaw = null)
    {
        $out = function ($ok, $error, $source, $dir, $safety, $restored) {
            return array('ok' => $ok, 'error' => $error, 'action' => 'restore',
                'source' => $source, 'directory' => $dir, 'safety' => $safety,
                'restored' => $restored);
        };

        // --- the argument ---------------------------------------------------
        $source = $sourceRaw === null ? 'local' : $sourceRaw;
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            return $out(false, 'source must be one of: ' . implode(', ', self::SOURCES),
                null, null, null, array());
        }
        $dir = $source === 'remote' ? $this->backupRoot . '/remote' : $this->backupRoot;

        // --- refuse while anything is running -------------------------------
        //
        // FAIL CLOSED. A restore that cannot see the session tables is a restore
        // that cannot know whether a lab is running, and "probably nothing" is
        // not a basis for DROP DATABASE.
        if ($this->sessions === null) {
            return $out(false, 'no lab session lookup was configured, so this cannot tell '
                . 'whether a lab is running; refusing', $source, $dir, null, array());
        }
        $live = call_user_func($this->sessions);
        if (!is_array($live) || !isset($live['labs']) || !isset($live['nodes'])) {
            return $out(false, 'could not read lab_sessions/node_sessions, so this cannot tell '
                . 'whether a lab is running; refusing', $source, $dir, null, array());
        }
        if ((int) $live['labs'] > 0 || (int) $live['nodes'] > 0) {
            return $out(false, sprintf('refusing: %d lab session(s) and %d node session(s) are '
                . 'open. A restore drops and recreates both databases, which would delete the '
                . 'rows describing running emulators, taps and tenant accounts and leave them '
                . 'orphaned. Stop every lab first.',
                (int) $live['labs'], (int) $live['nodes']), $source, $dir, null, array());
        }

        // --- check every file BEFORE touching the databases ------------------
        $client = $this->clientBinary();
        if ($client === null && $this->runCommands) {
            return $out(false, 'no mariadb or mysql client binary was found', $source, $dir, null, array());
        }
        // What to say when the files are not there. The remote directory is
        // populated by a human, by definition — nothing in this tree writes it —
        // so an empty one is the expected first encounter with it and deserves
        // an explanation rather than "does not exist".
        $hint = $source === 'remote'
            ? ' Nothing in this tree writes ' . $dir . '; it is a staging area for a dump '
              . 'copied from another host. Put pnetlab_db.sql and guacdb.sql there first.'
            : ' Run -a backupdb first.';

        if (!is_dir($dir)) {
            return $out(false, 'there is no backup directory at ' . $dir . '.' . $hint,
                $source, $dir, null, array());
        }
        if ($this->runCommands) {
            foreach (self::SCHEMAS as $schema) {
                $why = self::inspect($dir . '/' . $schema . '.sql', $schema);
                if ($why !== null) {
                    return $out(false, $dir . '/' . $schema . '.sql ' . $why
                        . '; nothing has been restored.' . $hint, $source, $dir, null, array());
                }
            }
        }

        // --- the safety dump --------------------------------------------------
        //
        // One generation, overwritten by each restore. If this fails the restore
        // does not happen: overwriting a live database with no way back is the
        // failure mode this whole action exists to stop.
        $safetyDir = $this->backupRoot . '/pre-restore';
        $safety = $this->dumpAll($safetyDir);
        if (!$safety['ok']) {
            return $out(false, 'refusing to restore: the safety dump of the CURRENT databases '
                . 'failed (' . $safety['error'] . '), so there would be no way back',
                $source, $dir, null, array());
        }

        // --- restore ----------------------------------------------------------
        //
        // No database name is passed. The dumps were produced with --databases,
        // so each carries its own DROP DATABASE / CREATE DATABASE / USE, and
        // inspect() has already confirmed which schema that USE names. Passing a
        // name as well, as the old code did, would have been overridden by the
        // USE anyway — it only ever looked like a safeguard.
        $restored = array();
        foreach (self::SCHEMAS as $schema) {
            $path = $dir . '/' . $schema . '.sql';
            $argv = array_merge(array($client === null ? 'mysql' : $client), self::authArgs());

            if (!$this->runCommands) {
                $this->commands[] = $argv;
                $restored[] = $schema;
                continue;
            }

            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                return $out(false, 'could not open ' . $path . ' for reading' . $this->wayBack($restored),
                    $source, $dir, $safetyDir, $restored);
            }
            $result = $this->spawn($argv, $fh, null);
            fclose($fh);
            if ($result['rc'] !== 0) {
                return $out(false, 'restoring ' . $schema . ' failed (exit ' . $result['rc'] . '): '
                    . trim($result['out']) . $this->wayBack($restored),
                    $source, $dir, $safetyDir, $restored);
            }
            $restored[] = $schema;
        }

        return $out(true, null, $source, $dir, $safetyDir, $restored);
    }

    /**
     * The sentence appended to a mid-restore failure.
     *
     * Two schemas cannot be replaced in one transaction, so a failure on the
     * second leaves the first replaced. Say that plainly and name the directory
     * that undoes it, rather than returning a bare error and leaving the
     * operator to discover the state by exploring it.
     */
    private function wayBack(array $restored)
    {
        if (!count($restored)) {
            return '. Nothing was restored; the databases are as they were.';
        }
        return '. ' . implode(' and ', $restored) . ' HAD ALREADY BEEN REPLACED when this failed, '
            . 'so the two schemas are now inconsistent. The state from before this restore is in '
            . $this->backupRoot . '/pre-restore/; put it back by copying those two files over '
            . $this->backupRoot . '/ and running unl_wrapper -a restoredb again.';
    }
}
