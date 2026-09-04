<?php
/**
 * The users directory is not for every account that can log in.
 *
 * routes/web.php dispatches /admin/{controller}/{method} behind the `auth`
 * middleware and nothing else: Checker::action() decides POST-versus-GET, and
 * the ROLE is each method's own business. Most of UsersController says so with
 * Role::checkRoot() on its first line. filter() and read() did not, and between
 * them they handed the complete active-user directory -- usernames, emails,
 * IP addresses, licence keys, lab-session and pod ids, admin notes, workspace
 * paths, activity and expiry times, resource limits -- to any ordinary account
 * that POSTed `{"data":[]}`.
 *
 * read() is root-only now. filter() cannot be, because the lab-sharing dialog
 * lists accounts through it, so a non-root caller gets a fixed projection
 * (UsersController::PEER_COLUMNS) and has its filter and sort keys cut to the
 * same columns -- otherwise a `contain` condition on a column that is not
 * returned would still let its contents be guessed out one character at a time.
 *
 * Source-level, in the style of RootRoleTest: the controller needs the
 * framework, and asserting the shape of the guard is what stops it being lost
 * in the next edit. Comments are stripped so this explanation cannot satisfy
 * the assertions.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$path = $root . '/store/app/Http/Controllers/Admin/UsersController.php';

echo "the users directory\n";

assert_true(is_file($path), 'UsersController.php exists');
$code = code_only($path);

/** The body of one public method, comments already stripped. */
function method_body($code, $name)
{
    if (!preg_match('/public\s+function\s+' . $name . '\s*\([^)]*\)\s*\{/', $code, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    for ($i = $start, $n = strlen($code); $i < $n; $i++) {
        if ($code[$i] === '{') $depth++;
        elseif ($code[$i] === '}' && --$depth === 0) return substr($code, $start, $i - $start);
    }
    return null;
}

// -------------------------------------------------------------------- read()

$read = method_body($code, 'read');
assert_true($read !== null, 'read() exists');
assert_true(preg_match('/^\s*Checker::method\(\'post\'\);\s*if\s*\(\s*!\s*Role::checkRoot\(\)\s*\)\s*Reply::finish\(false,\s*ERROR_PERMISSION\)/', (string) $read) === 1,
    'read() refuses a non-root caller before it touches the model');

// ------------------------------------------------------------------ filter()

$filter = method_body($code, 'filter');
assert_true($filter !== null, 'filter() exists');
assert_true(strpos((string) $filter, 'Role::checkRoot()') !== false,
    'filter() distinguishes root from everyone else');

// The projection, and that it is the one a peer needs and no more.
assert_true(preg_match('/const\s+PEER_COLUMNS\s*=\s*\[([^\]]*)\]/', $code, $m) === 1,
    'PEER_COLUMNS is declared');
$peer = array_map('trim', explode(',', $m[1]));
sort($peer);
assert_same(['USER_EMAIL', 'USER_OFFLINE', 'USER_POD', 'USER_ROLE', 'USER_USERNAME'], $peer,
    'a peer sees username, email, pod, role and the offline marker -- what the sharing dialog renders');
foreach (['USER_LICENSE', 'USER_IP', 'USER_SESSION', 'USER_LAB_SESSION', 'USER_NOTE',
          'USER_WORKSPACE', 'USER_FOLDER', 'USER_EXPIRED_TIME', 'USER_MAX_NODE'] as $secret) {
    assert_true(!in_array($secret, $peer, true), "$secret is not in the peer projection");
}

// The non-root branch selects the projection, and cuts the caller's filter and
// sort keys to it, before the model runs.
$pos = strpos($filter, 'Role::checkRoot()');
$branch = substr($filter, $pos);
$posSelect = strpos($branch, 'false, self::PEER_COLUMNS)');
$posFilters = strpos($branch, 'array_intersect_key((array) get($datas[DATA_FILTERS]');
$posSort = strpos($branch, 'array_intersect_key((array) get($datas[DATA_SORT]');
assert_true($posSelect !== false, 'the non-root branch selects PEER_COLUMNS');
assert_true($posFilters !== false && $posFilters < $posSelect,
    'and cuts the filter keys to the same columns first');
assert_true($posSort !== false && $posSort < $posSelect,
    'and the sort keys');
assert_true(preg_match('/return\s+\$responseData;/', $branch) === 1,
    'and returns from that branch, so the full projection below is root-only');

// -------------------------------------------- the ones that were already right

// apply(), view(), getKeys(), activeKey() and deleteKey() were on this list
// until Phase 05 removed them with the multi-access licences.
foreach (['offAdd', 'offDrop', 'offEdit', 'offFilter'] as $m) {
    $body = method_body($code, $m);
    assert_true($body !== null && strpos($body, 'Role::checkRoot()') !== false,
        "$m() is still root-only");
}

// ------------------------------------------------ the dispatcher, for context

// If the /admin namespace ever grows a root middleware this test can shrink.
// Until then, the guard has to be in the method, and that is what is asserted
// above. This just records the reason.
$routes = code_only($root . '/store/routes/web.php');
assert_true(preg_match("/'\\/admin\\/\\{controller\\}\\/\\{method\\}'.*?->middleware\\('auth'\\)/s", $routes) === 1,
    'the /admin dispatcher is behind `auth` only, so each method owns its role check');

test_summary();
