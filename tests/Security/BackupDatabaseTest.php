<?php
/**
 * Exercises `unl_wrapper -a backupdb` and `-a restoredb`, the actions that
 * replaced three shell_exec() lines nothing had ever run.
 *
 * WHAT WAS THERE BEFORE
 *
 *     case "backupdb":
 *         shell_exec("mysqldump -uroot -ppnetlab ... --databases pnetlab_db > .../pnetlab_db.sql
 *                   ; mysqldump -uroot -ppnetlab ... --databases guacdb     > .../guacdb.sql");
 *     case "restoredb":
 *         shell_exec("cat .../pnetlab_db.sql | /usr/bin/mysql --password=pnetlab pnetlab_db
 *                   ; cat .../guacdb.sql     | /usr/bin/mysql --password=pnetlab guacdb");
 *
 * old_backup_argv() below is that call site, used as a NEGATIVE CONTROL.
 *
 * The tests run against a fake mysqldump and a fake mysql client — shell
 * scripts that record their argv and can be told to fail — because the
 * properties that matter here are not "does mysqldump work". They are:
 *
 *   - a dump that FAILS must not overwrite the good backup that was there. That
 *     is the property the old code got exactly backwards: `>` truncated the
 *     destination before mysqldump had produced a byte, so a failure destroyed
 *     the last good backup on its way to reporting nothing.
 *   - a restore must REFUSE while a lab session is live, and refuse when it
 *     cannot tell.
 *   - no argument, on any path, is ever a password.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/platform/wrappers/actions/UnlBackupDatabase.php';

// ---------------------------------------------------------------- scaffolding

$ws     = sys_get_temp_dir() . '/backupdb-test-' . getmypid();
$backup = $ws . '/opt/unetlab/backup_database';
$bin    = $ws . '/bin';

mkdir($backup . '/remote', 0700, true);
mkdir($bin, 0755, true);

register_shutdown_function(function () use ($ws) {
    $rm = function ($p) use (&$rm) {
        if (is_link($p) || is_file($p)) { @unlink($p); return; }
        if (!is_dir($p)) return;
        foreach (scandir($p) as $n) if ($n !== '.' && $n !== '..') $rm($p . '/' . $n);
        @rmdir($p);
    };
    $rm($ws);
});

/**
 * A stand-in for mysqldump. It writes a dump-shaped file for whatever schema
 * `--databases <x>` names, records its own argv, and fails for the schema named
 * in bin/fail so a mid-backup failure can be driven.
 */
$fakeDump = $bin . '/mysqldump';
file_put_contents($fakeDump, "#!/bin/bash\n"
    . "d=\"\$(dirname \"\$0\")\"\n"
    . "printf '%s\\n' \"\$@\" >> \"\$d/argv.log\"\n"
    . "s=''\n"
    . "while [ \$# -gt 0 ]; do [ \"\$1\" = '--databases' ] && s=\"\$2\"; shift; done\n"
    . "if [ -f \"\$d/fail\" ] && grep -qx \"\$s\" \"\$d/fail\"; then\n"
    . "  echo \"mysqldump: Got error 1049 on \$s\" >&2; exit 2\n"
    . "fi\n"
    . "echo '-- MariaDB dump (fake)'\n"
    . "echo \"CREATE DATABASE /*!32312 IF NOT EXISTS*/ \\`\$s\\`;\"\n"
    . "echo \"USE \\`\$s\\`;\"\n"
    . "if [ -f \"\$d/payload\" ]; then cat \"\$d/payload\"; fi\n"
    . "head -c 900 /dev/zero | tr '\\0' '-' | sed 's/^/-- /'\n"
    . "echo\n"
    . "if [ -f \"\$d/truncate\" ] && grep -qx \"\$s\" \"\$d/truncate\"; then exit 0; fi\n"
    . "echo '-- Dump completed on 2026-09-01 00:00:00'\n");
chmod($fakeDump, 0755);

/**
 * A stand-in for the mysql client. Records argv, keeps what arrived on stdin,
 * and fails when that stdin mentions the schema named in bin/clientfail — the
 * client takes no database argument (the dump's own USE says where it goes), so
 * stdin is the only thing that distinguishes one invocation from the other.
 */
$fakeClient = $bin . '/mysql';
file_put_contents($fakeClient, "#!/bin/bash\n"
    . "d=\"\$(dirname \"\$0\")\"\n"
    . "printf '%s\\n' \"\$@\" >> \"\$d/argv.log\"\n"
    . "cat > \"\$d/in.sql\"\n"
    . "cat \"\$d/in.sql\" >> \"\$d/restored.sql\"\n"
    . "if [ -f \"\$d/clientfail\" ] && grep -qF \"\$(cat \"\$d/clientfail\")\" \"\$d/in.sql\"; then\n"
    . "  echo 'ERROR 1064 (42000) at line 42' >&2; exit 1\n"
    . "fi\n"
    . "exit 0\n");
chmod($fakeClient, 0755);

function bd(array $extra = [])
{
    global $backup, $fakeDump, $fakeClient;
    return new UnlBackupDatabase(array_merge([
        'backup_root' => $backup,
        'mysqldump'   => $fakeDump,
        'mysql'       => $fakeClient,
        'sessions'    => function () { return ['labs' => 0, 'nodes' => 0]; },
    ], $extra));
}

function argv_log()
{
    global $bin;
    $f = $bin . '/argv.log';
    return is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [];
}

/** NEGATIVE CONTROL — the pre-change call site's command string. */
function old_backup_argv()
{
    return ['/bin/sh', '-c',
        'mysqldump -uroot -ppnetlab --add-drop-database --skip-comments  --databases pnetlab_db '
        . '> /opt/unetlab/backup_database/pnetlab_db.sql ; mysqldump -uroot -ppnetlab '
        . '--add-drop-database --skip-comments  --databases guacdb '
        . '> /opt/unetlab/backup_database/guacdb.sql'];
}

// ------------------------------------------------------------- a clean backup

echo "  -- a backup writes both schemas, or neither\n";

$r = bd()->backup();
assert_true($r['ok'], 'backup succeeds');
assert_same('backup', $r['action'], 'and says which action it was');
assert_same(2, count($r['files']), 'and reports one file per schema');
assert_true(is_file($backup . '/pnetlab_db.sql'), 'pnetlab_db.sql exists');
assert_true(is_file($backup . '/guacdb.sql'), 'guacdb.sql exists');

// guacdb holds the Guacamole console connections, keyed to node session ids in
// pnetlab_db. A backup that takes one and not the other is not a backup of this
// product, it is half of one.
$schemas = [];
foreach ($r['files'] as $f) $schemas[] = $f['schema'];
assert_same(['pnetlab_db', 'guacdb'], $schemas, 'both schemas are covered, guacdb included');

assert_same('0600', substr(sprintf('%o', fileperms($backup . '/pnetlab_db.sql')), -4),
    'the dump is mode 0600 — it carries every password digest in the users table');
assert_same('0700', substr(sprintf('%o', fileperms($backup)), -4),
    'and the directory holding it is 0700');

$good = file_get_contents($backup . '/pnetlab_db.sql');
assert_true(strpos($good, 'USE `pnetlab_db`;') !== false, 'the dump names the schema it dumped');

// ------------------------------------------- a failed dump keeps the good one

echo "  -- a FAILED dump does not overwrite a good backup\n";

// Fail the SECOND schema. The old code chained the two dumps with `;` and
// redirected with `>`, so this exact case truncated guacdb.sql to nothing and
// reported success. Here neither file may move.
file_put_contents($bin . '/fail', "guacdb\n");
file_put_contents($bin . '/payload', "-- second generation\n");
$r = bd()->backup();
assert_true(!$r['ok'], 'a dump that exits non-zero fails the action');
assert_true(strpos($r['error'], 'guacdb') !== false, 'and names the schema that failed');
assert_same($good, file_get_contents($backup . '/pnetlab_db.sql'),
    'the previous pnetlab_db.sql is byte-identical afterwards');
assert_true(strpos(file_get_contents($backup . '/guacdb.sql'), 'second generation') === false,
    'and guacdb.sql was not replaced by a partial one');

// Nor may it leave its scratch files lying about in a 0700 directory whose
// contents an operator is meant to be able to trust.
$strays = array_values(array_diff(scandir($backup), ['.', '..', 'remote', 'pnetlab_db.sql', 'guacdb.sql']));
assert_same([], $strays, 'and left no temporary file behind');

// A dump that exits 0 but is truncated is also a failure. mysqldump's own
// "-- Dump completed" trailer is the only thing in the file that says so.
unlink($bin . '/fail');
file_put_contents($bin . '/truncate', "pnetlab_db\n");
$r = bd()->backup();
assert_true(!$r['ok'], 'a dump that exits 0 but has no completion trailer fails the action');
assert_same($good, file_get_contents($backup . '/pnetlab_db.sql'),
    'and still leaves the good backup in place');
unlink($bin . '/truncate');
unlink($bin . '/payload');

// -------------------------------------------------------- the source argument

echo "  -- the restore source is a closed enumeration\n";

$badSources = ['', 'LOCAL', 'local ', '../remote', '/etc', 'remote;id', 'both', 0, false,
               ['local'], "local\n"];
$rejected = 0;
foreach ($badSources as $bad) {
    $x = bd()->restore($bad);
    if (!$x['ok']) $rejected++;
    else echo "        ACCEPTED a bad source: " . var_export($bad, true) . "\n";
}
assert_same(count($badSources), $rejected,
    sprintf('rejects every source outside {local,remote} (%d of %d)', $rejected, count($badSources)));

// ------------------------------------------------ restore refuses under a lab

echo "  -- restore refuses while a lab session is live\n";

$live = bd(['sessions' => function () { return ['labs' => 1, 'nodes' => 3]; }])->restore('local');
assert_true(!$live['ok'], 'refuses while a lab session and node sessions are open');
assert_true(strpos($live['error'], '1 lab session') !== false
         && strpos($live['error'], '3 node session') !== false,
    'and says how many of each, rather than a bare refusal');
assert_same([], $live['restored'], 'and restored nothing');

$nodesOnly = bd(['sessions' => function () { return ['labs' => 0, 'nodes' => 1]; }])->restore('local');
assert_true(!$nodesOnly['ok'], 'refuses on a node session even with no lab session row');

// FAIL CLOSED. "I could not read the session tables" is not "nothing is
// running", and DROP DATABASE is not a thing to do on an assumption.
foreach ([
    'the lookup is missing'    => null,
    'the lookup returns null'  => function () { return null; },
    'the lookup returns junk'  => function () { return ['labs' => 0]; },
] as $label => $lookup) {
    $x = $lookup === null ? bd(['sessions' => null])->restore('local')
                          : bd(['sessions' => $lookup])->restore('local');
    assert_true(!$x['ok'], "refuses when $label — this fails closed");
}

// -------------------------------------------- restore checks the files first

echo "  -- restore validates every file before it touches a database\n";

file_put_contents($bin . '/restored.sql', '');

$x = bd()->restore('remote');
assert_true(!$x['ok'], 'refuses when the remote directory holds no dump');
assert_same('', file_get_contents($bin . '/restored.sql'), 'and fed the client nothing');

// The wrong schema in the right filename. This is the check that stops
// --source remote replacing pnetlab_db with a dump of something else, and it is
// why the appliance migration script's combined remotedb.sql is refused rather
// than half-applied.
copy($backup . '/guacdb.sql', $backup . '/remote/pnetlab_db.sql');
copy($backup . '/guacdb.sql', $backup . '/remote/guacdb.sql');
$x = bd()->restore('remote');
assert_true(!$x['ok'], 'refuses a file whose USE statement names a different schema');
assert_true(strpos($x['error'], 'is a dump of guacdb, not of pnetlab_db') !== false,
    'and says exactly what it found');
assert_same('', file_get_contents($bin . '/restored.sql'), 'and still fed the client nothing');

// A truncated file, which is what a dump interrupted halfway leaves behind.
copy($backup . '/pnetlab_db.sql', $backup . '/remote/pnetlab_db.sql');
$whole = file_get_contents($backup . '/remote/pnetlab_db.sql');
file_put_contents($backup . '/remote/pnetlab_db.sql', substr($whole, 0, 700));
$x = bd()->restore('remote');
assert_true(!$x['ok'], 'refuses a truncated dump');
assert_true(strpos($x['error'], 'truncated') !== false, 'and says it is truncated');
file_put_contents($backup . '/remote/pnetlab_db.sql', $whole);

// ------------------------------------------------------- the happy restore

echo "  -- a restore that is allowed to proceed\n";

$x = bd()->restore('local');
assert_true($x['ok'], 'restore succeeds from the local directory');
assert_same(['pnetlab_db', 'guacdb'], $x['restored'], 'and restores BOTH schemas, guacdb included');
assert_same($backup . '/pre-restore', $x['safety'], 'and reports where the safety dump went');
assert_true(is_file($backup . '/pre-restore/pnetlab_db.sql')
         && is_file($backup . '/pre-restore/guacdb.sql'),
    'the safety dump of the CURRENT databases exists, both schemas');
assert_same('0700', substr(sprintf('%o', fileperms($backup . '/pre-restore')), -4),
    'and the safety directory is 0700 too');

$fed = file_get_contents($bin . '/restored.sql');
assert_true(strpos($fed, 'USE `pnetlab_db`;') !== false
         && strpos($fed, 'USE `guacdb`;') !== false,
    'the client was fed both dumps on stdin');

// A safety dump that cannot be taken means no restore at all: overwriting a
// live database with no way back is the whole failure this action exists to
// prevent.
file_put_contents($bin . '/restored.sql', '');
file_put_contents($bin . '/fail', "guacdb\n");
$x = bd()->restore('local');
assert_true(!$x['ok'], 'refuses to restore when the safety dump fails');
assert_true(strpos($x['error'], 'no way back') !== false, 'and says why');
assert_same('', file_get_contents($bin . '/restored.sql'), 'and fed the client nothing');
unlink($bin . '/fail');

// A client that fails on the FIRST schema fails the action and says that
// nothing changed.
file_put_contents($bin . '/clientfail', 'pnetlab_db');
$x = bd()->restore('local');
assert_true(!$x['ok'], 'a client that exits non-zero fails the action');
assert_same([], $x['restored'], 'and reports that no schema was restored');
assert_true(strpos($x['error'], 'Nothing was restored') !== false,
    'and says the databases are as they were');

// A client that fails on the SECOND must say something different, because the
// first HAS been replaced: two schemas cannot be swapped in one transaction, and
// pretending otherwise is how an operator ends up with consoles pointing at
// nodes from a different generation of pnetlab_db.
file_put_contents($bin . '/clientfail', 'guacdb');
$x = bd()->restore('local');
assert_true(!$x['ok'], 'a client that fails on the second schema also fails the action');
assert_same(['pnetlab_db'], $x['restored'], 'and reports which schema had already gone in');
assert_true(strpos($x['error'], 'HAD ALREADY BEEN REPLACED') !== false,
    'and says the two schemas are now inconsistent');
assert_true(strpos($x['error'], 'pre-restore') !== false,
    'and names the safety dump that undoes it');
unlink($bin . '/clientfail');

// ------------------------------------------ no password on any command line

echo "  -- no code path puts a password on a command line\n";

$log = argv_log();
assert_true(count($log) > 0, 'the fakes were actually executed');
foreach ($log as $arg) {
    assert_true(!preg_match('/^(-p|--password)/', $arg),
        'no argument is a password option: ' . $arg);
    assert_true(strpos($arg, 'pnetlab') === false || $arg === 'pnetlab_db',
        'no argument carries the appliance password: ' . $arg);
}
assert_true(in_array('--protocol=socket', $log, true) && in_array('-uroot', $log, true),
    'every invocation authenticates as unix-socket root, as install/lib/database.sh does');

// The same, statically, over the source — because an argument the fixtures
// never reach would not appear in the log above.
$source = file_get_contents($root . '/platform/wrappers/actions/UnlBackupDatabase.php');
$code = '';
$backticks = 0;
foreach (token_get_all($source) as $t) {
    // Comments are dropped, so the header quoting the OLD command string
    // cannot satisfy or trip this. That is the same property the rest of the
    // suite relies on.
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    if (!is_array($t) && $t === '`') $backticks++;
    $code .= is_array($t) ? $t[1] : $t;
}
foreach (['-ppnetlab', '--password', 'password=', 'shell_exec', 'passthru', 'system(',
          'popen', 'escapeshellarg'] as $forbidden) {
    assert_true(strpos($code, $forbidden) === false,
        "the action's code contains no '$forbidden'");
}
// Counted as an operator, not as a character: the schema check matches a
// backticked identifier, so `USE `pnetlab_db`;` appears inside a string literal
// and a substring test would report a shell that is not there.
assert_same(0, $backticks, 'the action uses no backtick operator');
assert_true(strpos($code, 'proc_open') !== false, 'and does spawn through proc_open');

// The wrapper's own case blocks, likewise.
$wrapper = file_get_contents($root . '/platform/wrappers/unl_wrapper');
$wcode = '';
foreach (token_get_all($wrapper) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $wcode .= is_array($t) ? $t[1] : $t;
}
assert_true(strpos($wcode, 'ppnetlab') === false,
    'unl_wrapper no longer carries the appliance root password anywhere in its code');
assert_true(strpos($wcode, 'mysqldump') === false && strpos($wcode, 'backup_database') === false,
    'and no longer builds the dump command itself');
assert_true(strpos($wcode, "'restoredb_remote'") !== false,
    'restoredb_remote is still recognised, so it fails with a message rather than "no such action"');

// -------------------------------------- negative control: the old shape

echo "  -- negative control: what the same operation did before\n";

$old = old_backup_argv();
assert_true($old[0] === '/bin/sh' && $old[1] === '-c',
    'the old call site handed a STRING to a root shell');
assert_true(strpos($old[2], '-ppnetlab') !== false,
    'with the database password in argv, visible in ps to every local account');
assert_true(strpos($old[2], ' ; ') !== false,
    'and the two dumps chained with ";", so the second ran whatever the first did');
assert_true(strpos($old[2], '> /opt/unetlab/backup_database/') !== false,
    'and a shell redirect that truncated the destination before mysqldump ran');

$k = bd(['run_commands' => false]);
$k->backup();
$k->restore('local');
assert_true(count($k->commands) > 0, 'the recorded run produced commands');
foreach ($k->commands as $c) {
    assert_true(is_array($c), 'every recorded operation is an argv array, not a string');
    foreach ($c as $arg) {
        assert_true(strpos($arg, ';') === false && strpos($arg, '|') === false
                 && strpos($arg, '>') === false,
            'no argument carries a shell metacharacter: ' . $arg);
    }
}

// ------------------------------------------- the installer creates the dir

echo "  -- the installer creates the backup directory, root-only\n";

$deploy = file_get_contents($root . '/install/lib/deploy.sh');
foreach (['backup_database', 'backup_database/remote', 'backup_database/pre-restore'] as $d) {
    assert_true(preg_match('#ensure_dir\s+"\$\{BASE_DIR\}/' . preg_quote($d, '#') . '"\s+root:root\s+0700#', $deploy) === 1,
        "install/lib/deploy.sh creates ${d} as root:root 0700");
}

test_summary();
