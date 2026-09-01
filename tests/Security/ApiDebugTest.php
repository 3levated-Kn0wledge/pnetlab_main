<?php
/**
 * Guards the legacy API's error verbosity.
 *
 * api.php shipped 'debug' => True underneath 'mode' => 'production', with the
 * comment "Change to False for production" sitting next to it. Slim 2.6's
 * debug handler answers an uncaught exception with an HTML page carrying the
 * exception message, the stack trace and the absolute path of every file in
 * it. The API serves unauthenticated requests, so provoking an error was
 * enough to read back the install layout.
 *
 * The flag is now off unless PNETLAB_API_DEBUG=1 is set in the environment.
 * That shape is the point of this test: a constant True can be committed by
 * accident, an environment lookup cannot.
 *
 * Comments are stripped before the source is examined, so the explanation
 * above the flag -- which necessarily contains the word it warns about --
 * cannot satisfy or break an assertion. A commented-out setting is not a
 * setting.
 *
 * Pass a path as argv[1] to point it at a different api.php; used to confirm
 * this test fails against the pre-fix file.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$apiPath = $argv[1] ?? $root . '/api.php';

/**
 * The file with every comment removed, so the assertions below only ever see
 * code that runs.
 */
function code_without_comments($path)
{
    $out = '';
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $out .= "\n";
                continue;
            }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
}

echo "api.php debug flag\n";

$code = code_without_comments($apiPath);

// The Slim constructor array is the only place the flag is set.
assert_true(
    preg_match('/[\'"]debug[\'"]\s*=>/', $code) === 1,
    "api.php sets 'debug' exactly once"
);

// The failure this test exists to prevent: a literal truthy constant. Slim
// treats any truthy value as debug, so 1 and '1' are as bad as True.
assert_true(
    preg_match('/[\'"]debug[\'"]\s*=>\s*(True|true|TRUE|1|[\'"]1[\'"])\s*,/', $code) !== 1,
    "'debug' is not a hardcoded truthy constant"
);

// And the shape that replaced it: the value comes from the environment, so the
// default is off and enabling it leaves no diff to commit by mistake.
assert_true(
    preg_match('/[\'"]debug[\'"]\s*=>\s*getenv\(\s*[\'"]PNETLAB_API_DEBUG[\'"]\s*\)\s*===\s*[\'"]1[\'"]/', $code) === 1,
    "'debug' is read from PNETLAB_API_DEBUG"
);

// 'mode' is what selects Slim's error handler in the first place. If a future
// change sets it to 'development', Slim turns debug on by itself and the
// assertions above stop meaning anything.
assert_true(
    preg_match('/[\'"]mode[\'"]\s*=>\s*[\'"]production[\'"]/', $code) === 1,
    "'mode' is still 'production', which is what makes the debug flag decisive"
);

// The log writer appends to a file under /opt/unetlab/data/Logs. Left at DEBUG
// it records every request path and response status for every caller, which is
// a slower version of the same leak.
assert_true(
    preg_match('/[\'"]log\.level[\'"]\s*=>\s*\\\\Slim\\\\Log::(WARN|ERROR|FATAL)/', $code) === 1,
    "'log.level' is WARN or quieter"
);

test_summary();
