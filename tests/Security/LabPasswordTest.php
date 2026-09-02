<?php
/**
 * Lab-level passwords were stored as a bare md5() digest and checked in
 * Lab::unlockLab() with:
 *
 *     if (md5($pass) == $this->password)
 *
 * Two problems. The digest is unsalted md5, so it is a rainbow-table lookup.
 * And == on two strings still triggers PHP's numeric-string juggling, so any
 * two passwords whose md5 digests both look like /^0e[0-9]+$/ compare equal --
 * the "magic hash" collision. md5('240610708') and md5('QNKCDZO') are the
 * textbook pair, and both are 0e-prefixed.
 *
 * The fix has to keep opening labs that already hold md5 in their .unl file,
 * because those labs are on disk right now and nobody is going to re-set them.
 * So the legacy digest still verifies -- via hash_equals(), which is constant
 * time and does no juggling -- while newly set passwords get password_hash().
 *
 * These assertions exercise unl_lab_password_*(), which is what __lab.php now
 * calls; Lab itself is not instantiated here because __lab.php needs the whole
 * init.php constant and database environment to load.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/functions.php';

$plain  = 'labsecret';
$legacy = md5($plain);                      // exactly what is in existing .unl files

// --- an existing md5 lab password still opens the lab ---------------------
assert_true(unl_lab_password_verify($plain, $legacy),
    'an existing md5 lab password still opens the lab');
assert_true(!unl_lab_password_verify('wrong', $legacy),
    'a wrong password does not open a lab holding an md5 digest');
assert_true(unl_lab_password_needs_rehash($legacy),
    'an md5 lab password is flagged as needing a re-hash');

// --- a newly set password uses the modern hash ----------------------------
$modern = unl_lab_password_hash($plain);
assert_true($modern !== $legacy, 'a newly set lab password is not the md5 digest');
assert_true(!preg_match('/^[0-9a-f]{32}$/i', $modern),
    'a newly set lab password is not a bare 32-hex digest');
assert_true(strlen($modern) >= 60, 'a newly set lab password looks like a real password hash');
assert_true(unl_lab_password_verify($plain, $modern),
    'a newly set lab password opens the lab');
assert_true(!unl_lab_password_verify('wrong', $modern),
    'a wrong password does not open a lab with a modern hash');
assert_true(!unl_lab_password_needs_rehash($modern),
    'a freshly hashed lab password is not flagged for re-hashing');
assert_true(unl_lab_password_hash($plain) !== unl_lab_password_hash($plain),
    'the same lab password hashes differently each time, so it is salted');

// --- the magic-hash collision ---------------------------------------------
// Both digests are 0e followed by digits only, which is what makes PHP's ==
// treat them as equal floating-point zero.
$magicA = '240610708';
$magicB = 'QNKCDZO';
assert_true((bool) preg_match('/^0e[0-9]+$/', md5($magicA)),
    'md5(240610708) really is a 0e-prefixed digest: ' . md5($magicA));
assert_true((bool) preg_match('/^0e[0-9]+$/', md5($magicB)),
    'md5(QNKCDZO) really is a 0e-prefixed digest: ' . md5($magicB));
assert_true(md5($magicA) == md5($magicB),
    'the two digests are still == to each other, so the collision is live in this PHP');
assert_true(md5($magicA) !== md5($magicB),
    'the two digests are not actually the same string');

// A lab locked with one of the pair must not open with the other. This is the
// assertion that fails against the original code.
assert_true(!unl_lab_password_verify($magicB, md5($magicA)),
    'the magic-hash partner does not open a lab locked with a legacy md5 digest');
assert_true(!unl_lab_password_verify($magicA, md5($magicB)),
    'the collision does not work in the other direction either');
assert_true(unl_lab_password_verify($magicA, md5($magicA)),
    'the real password still opens that lab');

// And with a modern hash there is no digest to collide with at all.
assert_true(!unl_lab_password_verify($magicB, unl_lab_password_hash($magicA)),
    'the magic-hash partner does not open a lab locked with a modern hash');

// --- rubbish input --------------------------------------------------------
foreach ([null, '', 'not-a-hash', 0, false, array()] as $junk) {
    assert_true(!unl_lab_password_verify($plain, $junk),
        'nothing opens a lab whose stored password is ' . var_export($junk, true));
}
assert_true(!unl_lab_password_verify(null, $legacy),
    'a null password does not open a lab');
assert_true(unl_lab_password_needs_rehash(''),
    'an empty stored lab password is flagged for re-hashing');

// --- the user-account helpers are left alone ------------------------------
// unl_password_verify() must not have learned to accept 32-hex md5 as well;
// widening the legacy formats accepted for user login would be a regression.
assert_true(!unl_password_verify($plain, $legacy),
    'the user-account verifier still rejects an md5 digest');

test_summary();
