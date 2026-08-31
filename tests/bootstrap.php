<?php
/**
 * A deliberately tiny assertion helper.
 *
 * The application has no test suite and no dev dependencies installed, so these
 * tests must run against a bare PHP binary. Bringing in PHPUnit would mean a
 * composer install before anything can be checked, which is a worse trade at
 * this stage than fifteen lines of helper.
 */

$GLOBALS['tests_run'] = 0;
$GLOBALS['tests_failed'] = 0;

function assert_true($condition, $description)
{
    $GLOBALS['tests_run']++;
    if ($condition) {
        echo "  ok    $description\n";
        return;
    }
    $GLOBALS['tests_failed']++;
    echo "  FAIL  $description\n";
}

function assert_same($expected, $actual, $description)
{
    $GLOBALS['tests_run']++;
    if ($expected === $actual) {
        echo "  ok    $description\n";
        return;
    }
    $GLOBALS['tests_failed']++;
    echo "  FAIL  $description\n";
    echo "        expected: " . var_export($expected, true) . "\n";
    echo "        actual:   " . var_export($actual, true) . "\n";
}

function test_summary()
{
    printf("  %d assertions, %d failed\n", $GLOBALS['tests_run'], $GLOBALS['tests_failed']);
    exit($GLOBALS['tests_failed'] === 0 ? 0 : 1);
}
