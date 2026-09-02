<?php
/**
 * Pins the two call sites that used to fetch a shell script from pnetlab.com
 * and run it as root.
 *
 *   store/app/Http/Controllers/Admin/DevicesController.php
 *       $script = htmlspecialchars_decode($device[DEVICE_SCRIPT], ENT_QUOTES);
 *       file_put_contents('/tmp/pnet_device_factory_'.$deviceId, "#!/bin/bash\n".$script);
 *       chmod($excutefile, '0755');
 *       exec('sudo dos2unix '. $excutefile);
 *       exec('sudo '. $excutefile. ' > '. $logfile.' 2>&1 &');
 *
 *   store/app/Helpers/Admin/Upgrade.php
 *       $zip->extractTo($folder);
 *       exec("sudo chmod 755 -R $folder");
 *       exec("sudo $folder/upgrade 2>&1");
 *
 * That last one was the only `sudo $variable` in the tree, which is exactly the
 * shape SudoersPolicyTest cannot see: it matches a literal binary name after
 * `sudo`, and there is no literal there to match.
 *
 * WHAT IS ASSERTED
 *
 *   1. Neither file executes anything it downloaded. Every exec-family call
 *      that survives is examined token by token, and any variable reaching one
 *      must be inside escapeshellarg().
 *   2. Neither file still names the machinery of the old path — the upstream
 *      script fields, the /tmp script, dos2unix, ZipArchive::extractTo.
 *   3. The privileged call goes through the wrapper action, and through
 *      nothing else.
 *
 * Comments are stripped first, the same as RoutingTest and the shell sweep do,
 * so the quoted history at the top of each rewritten file cannot satisfy or
 * trip an assertion — and cannot fail one either. A commented-out exec() is not
 * an exec().
 *
 * NEGATIVE CONTROL
 *
 * Pass the pre-change files as argv[1] and argv[2] to confirm the assertions
 * actually bite:
 *
 *   git show HEAD:store/app/Http/Controllers/Admin/DevicesController.php > /tmp/old-dc.php
 *   git show HEAD:store/app/Helpers/Admin/Upgrade.php                    > /tmp/old-up.php
 *   php tests/Security/PackageCallSitesTest.php /tmp/old-dc.php /tmp/old-up.php
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$devicesPath = $argv[1] ?? $root . '/store/app/Http/Controllers/Admin/DevicesController.php';
$upgradePath = $argv[2] ?? $root . '/store/app/Helpers/Admin/Upgrade.php';
$clientPath  = $root . '/store/app/Helpers/Packages/PackageClient.php';
$wrapperPath = $root . '/platform/wrappers/unl_wrapper';

const EXEC_FUNCTIONS = array('exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open');

/** Tokens with comments dropped, so only code can decide an assertion. */
function tokens_without_comments($path)
{
    $out = array();
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
        }
        $out[] = $token;
    }
    return $out;
}

function code_text(array $tokens)
{
    $out = '';
    foreach ($tokens as $token) {
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

/**
 * Every exec-family call in $tokens, as ['name' => , 'line' => , 'args' => tokens].
 *
 * Only the FIRST argument is captured. That is the expression the shell
 * parses; exec()'s second and third arguments are output and status
 * by-reference and never reach a shell, and treating them as if they did would
 * make the test noisy enough to be ignored.
 */
function exec_call_sites(array $tokens)
{
    $sites = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_string($token) && $token === '`') {
            $sites[] = array('name' => 'backticks', 'line' => 0, 'args' => array());
            continue;
        }
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        if (!in_array(strtolower($token[1]), EXEC_FUNCTIONS, true)) {
            continue;
        }
        // A method call or a declaration of the same name is not the function.
        for ($b = $i - 1; $b >= 0; $b--) {
            $prev = $tokens[$b];
            if (is_array($prev) && $prev[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
                continue 2;
            }
            break;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }
        $depth = 0;
        $args = array();
        for ($k = $j; $k < $count; $k++) {
            $t = $tokens[$k];
            if ($t === '(' || $t === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if ($t === ',' && $depth === 1) {
                break; // first argument only
            }
            $args[] = $t;
        }
        $sites[] = array(
            'name' => strtolower($token[1]),
            'line' => $token[2],
            'args' => $args,
        );
    }
    return $sites;
}

/**
 * Every `$v = ...` and `$v .= ...` in the file, as name => list of token lists.
 *
 * File scope rather than function scope, which is deliberately coarse: it can
 * only ever consider MORE assignments than a per-function version would, so a
 * value that looks safe here is safe under any narrower reading too.
 */
function assignments(array $tokens)
{
    $out = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
            continue;
        }
        $name = $tokens[$i][1];
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count) {
            break;
        }
        $isAssign = ($tokens[$j] === '=')
            || (is_array($tokens[$j]) && $tokens[$j][0] === T_CONCAT_EQUAL);
        if (!$isAssign) {
            continue;
        }
        $depth = 0;
        $value = array();
        for ($k = $j + 1; $k < $count; $k++) {
            $t = $tokens[$k];
            if ($t === '(' || $t === '[') {
                $depth++;
            }
            if ($t === ')' || $t === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            if ($t === ';' && $depth === 0) {
                break;
            }
            $value[] = $t;
        }
        $out[$name][] = $value;
        $i = $j;
    }
    return $out;
}

/**
 * Variables in $args that are NOT inside escapeshellarg() or a numeric cast,
 * after substituting every assignment made to them elsewhere in the file.
 *
 * The substitution is what makes `exec($cmd)` answerable: the interesting
 * question is never the name of the variable, it is what was concatenated into
 * it three lines earlier.
 */
function unescaped_variables(array $args, array $assignments, array $seen = array(), $depth = 0)
{
    $bad = array();
    $parenDepth = 0;
    $safeUntil = array();
    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        $token = $args[$i];
        if ($token === '(') {
            $parenDepth++;
        } elseif ($token === ')') {
            while (count($safeUntil) > 0 && end($safeUntil) >= $parenDepth) {
                array_pop($safeUntil);
            }
            $parenDepth--;
        } elseif (is_array($token) && $token[0] === T_STRING
            && in_array(strtolower($token[1]), array('escapeshellarg', 'intval', 'floatval'), true)) {
            $safeUntil[] = $parenDepth + 1;
        } elseif (is_array($token) && in_array($token[0], array(T_INT_CAST, T_DOUBLE_CAST), true)) {
            // (int)$x reaches a shell as a number.
            $i++;
            while ($i < $count && is_array($args[$i]) && $args[$i][0] === T_WHITESPACE) {
                $i++;
            }
        } elseif (is_array($token) && $token[0] === T_VARIABLE) {
            if (count($safeUntil) > 0) {
                continue;
            }
            $name = $token[1];
            if (isset($seen[$name]) || $depth > 4 || !isset($assignments[$name])) {
                $bad[] = $name;
                continue;
            }
            $seen[$name] = true;
            foreach ($assignments[$name] as $value) {
                foreach (unescaped_variables($value, $assignments, $seen, $depth + 1) as $inner) {
                    $bad[] = $name . ' <- ' . $inner;
                }
            }
        }
    }
    return $bad;
}

/*
|--------------------------------------------------------------------------
| 1. Nothing the box downloaded is executed
|--------------------------------------------------------------------------
*/

foreach (array('DevicesController' => $devicesPath, 'Upgrade' => $upgradePath) as $label => $path) {
    assert_true(is_file($path), "$label exists at $path");
    $tokens = tokens_without_comments($path);
    $sites = exec_call_sites($tokens);
    $assigned = assignments($tokens);

    $offending = array();
    foreach ($sites as $site) {
        if ($site['name'] === 'backticks') {
            $offending[] = 'backtick operator';
            continue;
        }
        foreach (unescaped_variables($site['args'], $assigned) as $var) {
            $offending[] = $site['name'] . '() line ' . $site['line'] . ': ' . $var;
        }
    }
    assert_same(array(), $offending,
        "$label reaches a shell with no unescaped value");
    foreach ($offending as $o) {
        echo "        unescaped into a shell: $o\n";
    }
}

// DevicesController keeps exactly one exec: spawning the fork's own background
// worker, whose entire command line is PHP_BINARY, artisan, a command name and
// a device id that has already been checked against a strict pattern.
$deviceTokens = tokens_without_comments($devicesPath);
$deviceCode = code_text($deviceTokens);
$deviceSites = exec_call_sites($deviceTokens);
assert_same(1, count($deviceSites), 'DevicesController makes exactly one exec-family call');
assert_true(strpos($deviceCode, 'pnet:package-run') !== false,
    'the one call in DevicesController starts the package worker');

// Upgrade.php runs nothing at all any more. Applying the update is the
// wrapper's job, reached through the client helper.
$upgradeTokens = tokens_without_comments($upgradePath);
$upgradeCode = code_text($upgradeTokens);
assert_same(array(), exec_call_sites($upgradeTokens),
    'Upgrade.php makes no exec-family call at all');

/*
|--------------------------------------------------------------------------
| 2. The old machinery is gone, not merely bypassed
|--------------------------------------------------------------------------
*/

$banned = array(
    'pnet_device_factory' => 'the /tmp script the installer used to write and run',
    'dos2unix'            => 'the sudo dos2unix on that script',
    'DEVICE_SCRIPT'       => 'the upstream install script field',
    'DEVICE_DELETE'       => 'the upstream delete script field',
    'DEVICE_CHECK'        => 'the upstream shell snippet run to test for a device',
    'extractTo'           => 'ZipArchive extraction, which writes whatever names the archive carries',
    'ZipArchive'          => 'the zip container the upgrade used',
    'sudo'                => 'a direct sudo invocation',
);
foreach (array('DevicesController' => $deviceCode, 'Upgrade' => $upgradeCode) as $label => $code) {
    foreach ($banned as $needle => $what) {
        assert_true(strpos($code, $needle) === false,
            "$label no longer contains \"$needle\" — $what");
    }
}

/*
|--------------------------------------------------------------------------
| 3. The privileged path is the wrapper action, and only that
|--------------------------------------------------------------------------
*/

$clientTokens = tokens_without_comments($clientPath);
$clientCode = code_text($clientTokens);

assert_true(strpos($clientCode, "-a package -P ") !== false,
    'PackageClient invokes the wrapper package action');
assert_true(strpos($clientCode, "-a packageremove -I ") !== false,
    'PackageClient invokes the wrapper package removal action');

$clientSites = exec_call_sites($clientTokens);
$clientAssigned = assignments($clientTokens);
assert_same(2, count($clientSites), 'PackageClient makes exactly two exec calls: apply and remove');
foreach ($clientSites as $site) {
    assert_same(array(), unescaped_variables($site['args'], $clientAssigned),
        'the wrapper invocation on line ' . $site['line'] . ' escapes everything variable');
}

// Every sudo in the client names the wrapper constant. If a second sudo target
// ever appears here it needs a policy line, and this is where that shows up.
preg_match_all('/sudo/', $clientCode, $sudos);
assert_same(2, count($sudos[0]), 'PackageClient contains exactly two sudo invocations');
assert_same(2, substr_count($clientCode, "'sudo ' . PACKAGE_WRAPPER"),
    'both of them are the wrapper, named by constant rather than by string');

/*
|--------------------------------------------------------------------------
| 4. The wrapper action exists and is reachable
|--------------------------------------------------------------------------
*/

$wrapperCode = code_text(tokens_without_comments($wrapperPath));
assert_true(strpos($wrapperCode, "case 'package':") !== false,
    'unl_wrapper implements the package action');
assert_true(strpos($wrapperCode, "case 'packageremove':") !== false,
    'unl_wrapper implements the package removal action');
assert_true(strpos($wrapperCode, 'PnetPackageApplier') !== false,
    'the package action goes through the applier');
assert_true(preg_match('/getopt\(\s*\'[^\']*P:/', $wrapperCode) === 1,
    'the wrapper accepts -P, so the package path can be passed');

// Unsigned mode needs the flag AND a root-owned marker. The flag alone is one
// bug in the web layer away from being reachable, so it cannot be the gate.
assert_true(strpos($wrapperCode, 'ALLOW_UNSIGNED') !== false,
    'unsigned mode is gated on a marker file, not only on a command-line flag');
assert_true(strpos($wrapperCode, "\$stat['uid'] === 0") !== false,
    'that marker file must be owned by root');

test_summary();
