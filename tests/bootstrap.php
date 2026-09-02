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

/**
 * A PHP file with its comments removed.
 *
 * Several tests here assert that a path, a function or an idiom is GONE from a
 * file. Those same files usually explain at length why it went, quoting it —
 * and a plain strpos() then fails on the explanation, which would mean the
 * house style of writing down what was removed is in tension with the tests
 * that check it was. token_get_all() is the same tokenizer ShellEscapingTest
 * uses to find call sites, for the same reason.
 *
 * @param   string  $path               File to read
 * @return  string  The source with every comment stripped
 */
function code_only($path)
{
    $out = '';
    foreach (token_get_all(file_get_contents($path)) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

function test_summary()
{
    printf("  %d assertions, %d failed\n", $GLOBALS['tests_run'], $GLOBALS['tests_failed']);
    exit($GLOBALS['tests_failed'] === 0 ? 0 : 1);
}
