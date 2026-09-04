<?php
/**
 * Phase 05: the upstream dependency is severed, and stays severed.
 *
 * docs/OFFLINE-FIRST.md is the decision; this file is the part a machine can
 * check. It grew one section per commit of the phase, in the order the work
 * was done, so a reader can follow what was removed and why each piece must
 * not come back:
 *
 *   1. the online login -- the redirect to authen.pnetlab.com, the return leg
 *      carrying a licence, and the mode chooser in front of both
 *   2. the licence machinery -- relicense, keepalive, the alive key, and the
 *      cron jobs and artisan commands that drove them
 *   3. the lab marketplace -- selling labs, downloading bought labs and their
 *      dependencies, versioning them, and the image uploader behind it
 *   4. the notices -- the bell in the menu bar, which asked the licensing
 *      server for news on every page load
 *   5. the multi-access licences -- the online accounts page, the "apply"
 *      that registered accounts upstream, the account limit, the offline
 *      licence keys, and the machine UUID the box showed as its identity
 *   6. the device store -- listed from the repository's own index now, not
 *      from user.pnetlab.com
 *   7. the update check and the upgrade worker -- the same index, and no
 *      more `sudo php`
 *
 * Every assertion below is source-level and comment-stripped, in the style of
 * RoutingTest: the files that lost this code explain at length what they
 * lost, and an explanation is not a call.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

/** Public method names declared in a controller, comments already stripped. */
function public_methods($code)
{
    preg_match_all('/public\s+function\s+(\w+)\s*\(/', $code, $m);
    return $m[1];
}

/** Every .php file under a directory, minus vendor and the like. */
function php_files($dir)
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $p = str_replace('\\', '/', $f->getPathname());
        if (strpos($p, '/vendor/') !== false || strpos($p, '/node_modules/') !== false) continue;
        $out[] = $p;
    }
    sort($out);
    return $out;
}

/** Every source file of the React front end (the bundles are built from these). */
function react_files($root)
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/store/resources/react', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'js') $out[] = $f->getPathname();
    }
    sort($out);
    return $out;
}

/** Files under store/app whose code (not comments) contains a needle. */
function app_files_mentioning($root, $needle)
{
    $hits = [];
    foreach (php_files($root . '/store/app') as $p) {
        if (strpos(code_only($p), $needle) !== false) $hits[] = substr($p, strlen($root) + 1);
    }
    return $hits;
}

echo "1. the online login is gone\n";

$login = code_only($root . '/store/app/Http/Controllers/Auth/LoginController.php');
$methods = public_methods($login);
foreach (['license', 'online', 'initialOnline', 'initial'] as $gone) {
    assert_true(!in_array($gone, $methods, true), "LoginController has no $gone()");
}
assert_true(in_array('initialOffline', $methods, true) && in_array('login', $methods, true) && in_array('offline', $methods, true),
    'and still has the offline login and the first-boot switch');
assert_true(strpos($login, 'APP_AUTHEN') === false, 'LoginController never redirects to APP_AUTHEN');
assert_true(strpos($login, 'ntpdate') === false, 'and no longer runs sudo ntpdate on the return leg');
assert_true(strpos($login, 'License::') === false, 'and never touches the licence helper');
assert_true(strpos($login, 'CTRL_ONLINE_MODE') === false, 'and does not consult an online-mode switch');

$csrf = code_only($root . '/store/app/Http/Middleware/VerifyCsrfToken.php');
assert_true(preg_match('/\$except\s*=\s*\[\s*\]\s*;/', $csrf) === 1,
    'VerifyCsrfToken exempts nothing: the return leg from authen.pnetlab.com was the only entry');

$mode = code_only($root . '/store/app/Http/Controllers/Admin/ModeController.php');
$modeMethods = public_methods($mode);
foreach (['getOwner', 'keepAlive', 'keepAliveCaptcha', 'setOnline', 'setOffline', 'setDefault'] as $gone) {
    assert_true(!in_array($gone, $modeMethods, true), "ModeController has no $gone()");
}
assert_true(strpos($mode, 'Query::') === false && strpos($mode, 'License::') === false,
    'ModeController reaches no server and no licence');

$react = react_files($root);
$reactHits = [];
foreach ($react as $p) {
    $src = file_get_contents($p);
    foreach (['auth/login/online', 'initialOnline', 'KeepAliveCaptcha', 'LoginInitial', 'mode/keepAlive', 'mode/getOwner', 'mode/setOnline'] as $needle) {
        if (strpos($src, $needle) !== false) $reactHits[] = basename($p) . ': ' . $needle;
    }
}
assert_same([], $reactHits, 'no React source links to the online login or the keep-alive');
assert_true(!is_file($root . '/store/resources/react/pages/auth/LoginInitial.js'), 'the online/offline mode chooser page is gone');

echo "2. the licence machinery is gone\n";

foreach (['Relicense.php', 'KeepAlive.php', 'crontab'] as $f) {
    assert_true(!is_file($root . '/store/app/Console/Commands/' . $f), "Console/Commands/$f is gone");
}
assert_true(strpos(file_get_contents($root . '/store/app/Console/Commands/pnetlab'), 'keepalive') === false,
    'the pnetlab helper script no longer has a keepalive branch');
assert_same([], app_files_mentioning($root, 'License::keepalive('), 'nothing calls License::keepalive()');
assert_same([], app_files_mentioning($root, 'License::relicense('), 'nothing calls License::relicense()');
assert_same([], app_files_mentioning($root, 'function keepalive('), 'no keepalive() is defined');
assert_same([], app_files_mentioning($root, 'function relicense('), 'no relicense() is defined');

$sudoers = file_get_contents($root . '/install/sudoers.d/pnetlab');
assert_true(preg_match('/^www-data.*ntpdate/m', $sudoers) === 0, 'the ntpdate grant went with its only caller');

echo "3. the lab marketplace is gone\n";

foreach (['User/LabsController', 'User/VersionsController', 'User/DependenceController',
          'Admin/VersionsController', 'Admin/DependenceController'] as $c) {
    assert_true(!is_file($root . "/store/app/Http/Controllers/$c.php"), "$c is gone");
}
$labs = code_only($root . '/store/app/Http/Controllers/Admin/LabsController.php');
$labMethods = public_methods($labs);
foreach (['uploader', 'addGetId', 'getOwnLabs', 'drop', 'edit', 'read', 'mapping', 'public', 'unpublic',
          'sellable', 'getUserAgreement', 'getDepends', 'view', 'create', 'editview', 'store'] as $gone) {
    assert_true(!in_array($gone, $labMethods, true), "Admin/LabsController has no $gone()");
}
sort($labMethods);
assert_same(['search', 'terminal', 'workbook', 'workbookview'], $labMethods,
    'and keeps exactly the local lab pages and the lab search');
assert_true(strpos($labs, 'Query::') === false, 'Admin/LabsController reaches no server');

$readonly = include $root . '/store/config/readonly_actions.php';
foreach (['admin/labs/view', 'admin/labs/create', 'admin/labs/editview', 'admin/labs/store',
          'admin/labs/uploader', 'admin/versions/view', 'admin/versions/addview'] as $gone) {
    assert_true(!in_array($gone, $readonly, true), "$gone is not on the GET allowlist");
}

foreach (['pages/admin/LabsStore.js', 'pages/admin/LabsView.js', 'pages/admin/LabsCreate.js',
          'pages/admin/LabsEditView.js', 'pages/admin/VersionsView.js', 'pages/admin/VersionsAddView.js',
          'helpers/lab_downloader.js', 'components/admin/store', 'components/admin/product'] as $f) {
    assert_true(!file_exists($root . '/store/resources/react/' . $f), "React $f is gone");
}
$reactHits = [];
foreach ($react as $p) {
    $src = file_get_contents($p);
    foreach (['user/labs/', 'user/versions/', 'user/dependence/', 'admin/versions/', 'admin/dependence/',
              'admin/labs/uploader', 'admin/labs/store', 'admin/labs/view', 'labs/getOwnLabs',
              'Sell Your Labs', 'Download Labs', 'Go To Store', 'labExpireHandle'] as $needle) {
        if (strpos($src, $needle) !== false) $reactHits[] = basename($p) . ': ' . $needle;
    }
}
assert_same([], $reactHits, 'no React source reaches a marketplace endpoint or links to the store');

echo "4. the notices are gone\n";

assert_true(!is_file($root . '/store/app/Http/Controllers/Notice/NoticeController.php'), 'NoticeController is gone');
foreach (['components/menu/Syslog.js', 'components/menu/OffSyslog.js', 'pages/notice/NoticeView.js'] as $f) {
    assert_true(!file_exists($root . '/store/resources/react/' . $f), "React $f is gone");
}
$reactHits = [];
foreach ($react as $p) {
    $src = file_get_contents($p);
    foreach (['notice/notice/', 'OffSyslog', 'Syslog'] as $needle) {
        if (strpos($src, $needle) !== false) $reactHits[] = basename($p) . ': ' . $needle;
    }
}
assert_same([], $reactHits, 'no React source polls the notice endpoints or renders the bell');

echo "5. the multi-access licences are gone\n";

assert_true(!is_file($root . '/store/app/Helpers/Box/License.php'), 'Helpers/Box/License.php is gone');
assert_same([], app_files_mentioning($root, 'License::'), 'nothing under store/app refers to the licence helper');
$users = code_only($root . '/store/app/Http/Controllers/Admin/UsersController.php');
$userMethods = public_methods($users);
foreach (['apply', 'getLimit', 'view', 'getOffLimit', 'getKeys', 'activeKey', 'deleteKey'] as $gone) {
    assert_true(!in_array($gone, $userMethods, true), "Admin/UsersController has no $gone()");
}
assert_true(strpos($users, 'Query::') === false, 'Admin/UsersController reaches no server');
assert_true(strpos($users, 'dmidecode') === false, 'and never reads the machine UUID');
assert_true(!in_array('admin/users/view', $readonly, true), 'admin/users/view is not on the GET allowlist');
foreach (['pages/admin/UsersView.js', 'components/admin/user/AddUserModal.js',
          'components/admin/user/OfflineLicenseModal.js'] as $f) {
    assert_true(!file_exists($root . '/store/resources/react/' . $f), "React $f is gone");
}
$reactHits = [];
foreach ($react as $p) {
    $src = file_get_contents($p);
    foreach (['users/getLimit', 'users/getOffLimit', 'users/apply', 'users/getKeys', 'users/activeKey',
              'users/deleteKey', 'admin/users/view', 'OfflineLicenseModal', 'AddUserModal', "Box's ID"] as $needle) {
        if (strpos($src, $needle) !== false) $reactHits[] = basename($p) . ': ' . $needle;
    }
}
assert_same([], $reactHits, 'no React source reaches a licence endpoint or the online accounts page');

echo "6. the device store reads the repository index\n";

$devices = code_only($root . '/store/app/Http/Controllers/Admin/DevicesController.php');
assert_true(strpos($devices, 'Query::') === false && strpos($devices, 'APP_CENTER') === false,
    'Admin/DevicesController reaches no server of its own');
assert_true(strpos($devices, 'PackageClient::index()') !== false && strpos($devices, 'PackageClient::device(') !== false,
    'and lists and resolves devices through the repository index');
assert_true(!is_file($root . '/store/app/Console/Commands/DeviceFactory.php'),
    'the legacy device_factory command, which downloaded from a link it was handed, is gone');
$client = code_only($root . '/store/app/Helpers/Packages/PackageClient.php');
assert_true(preg_match('/if \(PACKAGE_CENTER === \'\'\) \{\s*return null;/', $client) === 1,
    'PackageClient::indexUrl() is null with no repository configured, so nothing is fetched by default');
assert_true(strpos($client, "'strict_transport' => true") !== false,
    'and the index is fetched with strict transport');
$item = file_get_contents($root . '/store/resources/react/components/admin/device/DeviceItem.js');
assert_true(strpos($item, 'APP_CENTER') === false, 'DeviceItem no longer links to the upstream guide page');
assert_true(strpos($item, 'file_public(') === false, 'and does not rewrite image URLs through the upstream uploader');

echo "7. the update check reads the index, and the worker needs no sudo php\n";

$upgrade = code_only($root . '/store/app/Helpers/Admin/Upgrade.php');
assert_true(strpos($upgrade, 'Query::') === false && strpos($upgrade, 'APP_CENTER') === false,
    'Helpers/Admin/Upgrade reaches no server of its own');
assert_true(strpos($upgrade, 'PackageClient::index()') !== false, 'and reads the repository index');
assert_true(strpos($upgrade, 'hash_equals(') !== false, 'and holds the download to the advertised digest');
$default = code_only($root . '/store/app/Http/Controllers/Admin/DefaultController.php');
assert_true(strpos($default, 'sudo') === false, 'Admin/DefaultController holds no sudo at all');
assert_true(strpos($default, 'ps -aux') === false, 'and does not grep the process table');
assert_true(strpos($default, "escapeshellarg(PHP_BINARY)") !== false && strpos($default, "' upgrade now") !== false,
    'the upgrade worker is started as the web user, through an escaped argv');
assert_true(preg_match('/^www-data.*\/usr\/bin\/php\b/m', $sudoers) === 0, 'the php grant is gone from the policy');
assert_true(preg_match('/^www-data.*\/usr\/bin\/ps\b/m', $sudoers) === 0, 'and so is ps');
$cmd = code_only($root . '/store/app/Console/Commands/UpgradeCmd.php');
assert_true(strpos($cmd, 'LOCK_EX | LOCK_NB') !== false, 'the worker takes a non-blocking exclusive lock, so two cannot run');
$dialog = file_get_contents($root . '/store/resources/react/components/admin/system/Upgrade.js');
assert_true(strpos($dialog, 'dangerouslySetInnerHTML') === false, 'the version dialog renders the update note as text');

test_summary();
