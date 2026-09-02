<?php
/**
 * Passwords were stored as unsalted, single-round sha256. The default admin
 * account's stored digest matched `echo -n pnet | sha256sum` byte for byte, and
 * GET /api/auth returned that digest to the client on every call.
 *
 * The fix has to accept the legacy format, because existing users cannot reset a
 * password they are unable to log in to change. These tests cover that both
 * directions work and that the upgrade actually triggers.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/functions.php';

$plain  = 'pnet';
$legacy = hash('sha256', $plain);           // exactly what is in the database today

// --- the legacy format still authenticates -------------------------------
assert_true(unl_password_verify($plain, $legacy),
    'a legacy sha256 digest still verifies, so existing users can log in');
assert_true(!unl_password_verify('wrong', $legacy),
    'a wrong password does not verify against a legacy digest');

// --- and is flagged for replacement --------------------------------------
assert_true(unl_password_needs_rehash($legacy),
    'a legacy digest is flagged for re-hashing');

// --- the modern format ----------------------------------------------------
$modern = unl_password_hash($plain);
assert_true($modern !== $legacy, 'the new hash is not the old digest');
assert_true(strlen($modern) >= 60, 'the new hash looks like a real password hash');
assert_true(unl_password_verify($plain, $modern), 'the new hash verifies');
assert_true(!unl_password_verify('wrong', $modern), 'a wrong password does not verify');
assert_true(!unl_password_needs_rehash($modern), 'a fresh hash is not flagged for re-hashing');

// --- salted: the same password hashes differently every time --------------
assert_true(unl_password_hash($plain) !== unl_password_hash($plain),
    'the same password produces a different hash each time, so it is salted');

// --- rubbish input --------------------------------------------------------
foreach ([null, '', 'not-a-hash'] as $junk) {
    assert_true(!unl_password_verify($plain, $junk),
        'nothing verifies against ' . var_export($junk, true));
}
assert_true(unl_password_needs_rehash(''), 'an empty stored hash is flagged for re-hashing');

// --- Guacamole no longer holds anything derived from the password ---------
$secret = unl_guacamole_secret('admin');
assert_true($secret !== $legacy && $secret !== $modern,
    'the Guacamole credential is not the user password hash');
assert_true(strpos($secret, $plain) === false,
    'the Guacamole credential does not contain the password');
assert_same($secret, unl_guacamole_secret('admin'),
    'the Guacamole credential is stable for a given user');
assert_true(unl_guacamole_secret('admin') !== unl_guacamole_secret('other'),
    'the Guacamole credential differs per user');

test_summary();
