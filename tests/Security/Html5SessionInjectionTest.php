<?php
/**
 * html5AddSession() builds the Guacamole connection rows. Its $name argument is
 * derived from the node name, which any authenticated user controls.
 *
 * Before this was fixed, the query was assembled by string concatenation and
 * handed to prepare() already complete — which offers no protection. Naming a
 * node PC'X and opening its console returned, to the client:
 *
 *   SQLSTATE[42000]: Syntax error ... near 'X_4_admin','telnet')' at line 1
 *
 * These tests run the real function against an in-memory SQLite database.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../bootstrap.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE guacamole_connection (
    connection_id INTEGER PRIMARY KEY, connection_name TEXT, protocol TEXT)');
$db->exec('CREATE TABLE guacamole_connection_permission (
    entity_id INTEGER, connection_id INTEGER, permission TEXT)');
$db->exec('CREATE TABLE guacamole_connection_parameter (
    connection_id INTEGER, parameter_name TEXT, parameter_value TEXT)');

// --- 1. The exact name that used to break the query --------------------------
html5AddSession($db, "PC'X_4_admin", 'telnet', 30004, 2);

assert_same("PC'X_4_admin",
    $db->query('SELECT connection_name FROM guacamole_connection')->fetchColumn(),
    'a single quote in the node name is stored verbatim');

// --- 2. A payload that would drop the table if it were interpolated ----------
$payload = "x'); DROP TABLE guacamole_connection; --";
html5AddSession($db, $payload, 'vnc', 30005, 2);

assert_true(
    (bool) $db->query("SELECT name FROM sqlite_master WHERE type='table'
                       AND name='guacamole_connection'")->fetchColumn(),
    'the connection table survives a statement-terminating payload');

assert_same($payload,
    $db->query('SELECT connection_name FROM guacamole_connection
                WHERE connection_id = 300052')->fetchColumn(),
    'the payload is stored as data, not executed');

// --- 3. Hostile values in the parameter rows too -----------------------------
// hostname, username and password all reach guacamole_connection_parameter.
html5AddSession($db, 'node', 'rdp', 30006, 2, "127.0.0.1'); DROP TABLE x; --",
                3389, "user'--", "pw'); DROP TABLE guacamole_connection_parameter; --", 'reconnect');

assert_true(
    (bool) $db->query("SELECT name FROM sqlite_master WHERE type='table'
                       AND name='guacamole_connection_parameter'")->fetchColumn(),
    'the parameter table survives hostile hostname/username/password');

assert_same("pw'); DROP TABLE guacamole_connection_parameter; --",
    $db->query("SELECT parameter_value FROM guacamole_connection_parameter
                WHERE connection_id = 300062 AND parameter_name = 'password'")->fetchColumn(),
    'the password parameter is stored verbatim');

// --- 4. The function still does its actual job -------------------------------
assert_same('rdp',
    $db->query('SELECT protocol FROM guacamole_connection WHERE connection_id = 300062')->fetchColumn(),
    'protocol is recorded');

assert_same('3389',
    (string) $db->query("SELECT parameter_value FROM guacamole_connection_parameter
                         WHERE connection_id = 300062 AND parameter_name = 'port'")->fetchColumn(),
    'service port is recorded');

assert_same('1',
    (string) $db->query("SELECT COUNT(*) FROM guacamole_connection_permission
                         WHERE connection_id = 300062 AND entity_id = 1002")->fetchColumn(),
    'read permission is granted to the owning entity');

// rdp connections get the glyph-caching workaround; telnet ones do not.
assert_same('1',
    (string) $db->query("SELECT COUNT(*) FROM guacamole_connection_parameter
                         WHERE connection_id = 300062
                         AND parameter_name = 'disable-glyph-caching'")->fetchColumn(),
    'rdp-specific parameter is present');

assert_same('0',
    (string) $db->query("SELECT COUNT(*) FROM guacamole_connection_parameter
                         WHERE connection_id = 300042
                         AND parameter_name = 'disable-glyph-caching'")->fetchColumn(),
    'rdp-specific parameter is absent for telnet');

test_summary();
