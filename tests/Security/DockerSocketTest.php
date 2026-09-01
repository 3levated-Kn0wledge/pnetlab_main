<?php
/**
 * The web layer must not talk to the Docker daemon over TCP.
 *
 * Every `docker` invocation in the tree — thirty of them, across includes/,
 * devices/ and store/ — used to name `-H=tcp://127.0.0.1:4243`. Two facts about
 * that endpoint, both verified on the reference host:
 *
 *   1. It is unauthenticated. The Docker API has no notion of a user: anything
 *      that can open a loopback connection can POST /containers/create with
 *      Binds: ["/:/host"] and get a root shell on the host. www-data reaching
 *      4243 is www-data being root, whatever install/sudoers.d/pnetlab says, and
 *      so is any SSRF or any other local account.
 *   2. Nothing in install/ ever configured it. Docker listens on
 *      /var/run/docker.sock out of the box; making it listen on 4243 as well
 *      takes an explicit daemon flag or drop-in that this repository has never
 *      shipped. So on a clean install every one of those commands failed to
 *      connect and Docker-backed nodes could not work at all.
 *
 * The call sites now use the unix socket, which is root:docker 0660 — reachable
 * by group membership rather than by an open port — and consequently need no
 * sudo. This test is what stops the TCP endpoint coming back the next time
 * somebody copies a line from an old file or from upstream.
 *
 * Comments are stripped before the assertions run: `# docker -H=tcp://...` in a
 * comment is documentation (this file's own header is full of it), and a
 * commented-out call site must not be able to satisfy a check that live code
 * would fail.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

const DOCKER_SOCKET   = 'unix:///var/run/docker.sock';
const DOCKER_TCP_HOST = '127.0.0.1:4243';

/**
 * The file with every comment removed, so the assertions below only ever see
 * code that runs.
 */
function code_without_comments($path)
{
    $out = '';
    foreach (@token_get_all(file_get_contents($path)) as $token) {
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

/**
 * Every PHP file that is part of the application: no vendor, no bundles, no
 * tests (this file names the forbidden string on purpose), no tools.
 */
function application_php_files($root)
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        $path = str_replace('\\', '/', $file->getPathname());
        foreach (['/.git/', '/store/vendor/', '/store/public/', '/node_modules/',
                  '/tests/', '/tools/'] as $skip) {
            if (strpos($path, $skip) !== false) continue 2;
        }
        $files[] = $path;
    }
    sort($files);
    return $files;
}

$files = application_php_files($root);
assert_true(count($files) > 100, 'the scan found the application (' . count($files) . ' php files)');

/*
|--------------------------------------------------------------------------
| 1. Nothing addresses the daemon over TCP
|--------------------------------------------------------------------------
*/
$tcp = [];
$sock = [];
$sudoDocker = [];
foreach ($files as $path) {
    $code = code_without_comments($path);
    $rel  = substr($path, strlen($root) + 1);

    if (strpos($code, DOCKER_TCP_HOST) !== false)  $tcp[] = $rel;
    if (strpos($code, DOCKER_SOCKET) !== false)    $sock[] = $rel;

    // `sudo docker ...` in live code, whatever endpoint it names.
    if (preg_match('/sudo\s+(?:-\S+\s+)*(?:\/usr\/bin\/)?docker\b/', $code)) {
        $sudoDocker[] = $rel;
    }
}

assert_same([], $tcp,
    'no call site addresses the Docker daemon at ' . DOCKER_TCP_HOST);
foreach ($tcp as $f) echo "        still uses the unauthenticated TCP socket: $f\n";

assert_true(count($sock) >= 10,
    'the docker call sites address the unix socket instead (' . count($sock) . ' files)');

/*
|--------------------------------------------------------------------------
| 2. Every -H names the unix socket, and nothing names a bare tcp:// endpoint
|--------------------------------------------------------------------------
| A -H the test does not recognise is as bad as the old one: the point is that
| the endpoint is a single reviewed value, not that one particular port is gone.
*/
$badEndpoint = [];
foreach ($files as $path) {
    $code = code_without_comments($path);
    if (!preg_match_all('/-H=(\S+?)[\'"\s]/', $code, $m)) continue;
    foreach ($m[1] as $endpoint) {
        if ($endpoint !== DOCKER_SOCKET) {
            $badEndpoint[] = substr($path, strlen($root) + 1) . ': -H=' . $endpoint;
        }
    }
}
assert_same([], $badEndpoint, 'every docker -H= names ' . DOCKER_SOCKET);
foreach ($badEndpoint as $b) echo "        unexpected endpoint: $b\n";

/*
|--------------------------------------------------------------------------
| 3. sudo is no longer how the web layer reaches Docker
|--------------------------------------------------------------------------
| One call site remains: api.php's Wireshark image check. It is listed here by
| name so that this test records the debt rather than hiding it, and so that a
| NEW sudo docker call fails the assertion. When api.php is converted, delete
| the exception here and the /usr/bin/docker line from install/sudoers.d/pnetlab
| — tests/Security/SudoersPolicyTest.php will then insist on it.
*/
assert_same(['api.php'], $sudoDocker,
    'api.php is the only remaining sudo-docker call site');
foreach ($sudoDocker as $f) {
    if ($f !== 'api.php') echo "        new sudo docker call site: $f\n";
}

/*
|--------------------------------------------------------------------------
| 4. The wrapper agrees with the PHP
|--------------------------------------------------------------------------
| docker_wrapper attaches the console. It used to do it by ssh'ing to
| root@localhost with a standing passwordless key purely to obtain a TTY, and it
| pinned the same TCP endpoint. Neither may come back: the TTY is a local PTY
| now (platform/wrappers/src/child.c, CHILD_PTY).
*/
$wrapper = $root . '/platform/wrappers/src/docker.c';
assert_true(is_file($wrapper), 'the docker_wrapper front-end exists');

/**
 * C with its comments removed. The same discipline as above and for the same
 * reason: docker.c documents the ssh hop it replaced, at length, so a substring
 * search over the raw file would fail on its own explanation.
 *
 * String literals are matched FIRST and kept, which is not fastidiousness: the
 * endpoint this test is looking for is "unix:///var/run/docker.sock", and a
 * line-comment rule that does not know about strings would delete it from the
 * // onwards and then cheerfully report that the wrapper does not use it.
 */
function c_code_without_comments($path)
{
    return preg_replace_callback(
        '#"(?:\\\\.|[^"\\\\])*"|/\*.*?\*/|//[^\n]*#s',
        function ($m) { return $m[0][0] === '"' ? $m[0] : ' '; },
        file_get_contents($path)
    );
}

$wsrc = c_code_without_comments($wrapper)
      . c_code_without_comments($root . '/platform/wrappers/src/docker.h');

assert_true(strpos($wsrc, '"' . DOCKER_SOCKET . '"') !== false,
    'docker_wrapper builds its command against the unix socket');
assert_true(strpos($wsrc, DOCKER_TCP_HOST) === false,
    'docker_wrapper executes nothing against ' . DOCKER_TCP_HOST);
assert_true(strpos($wsrc, 'ssh ') === false && strpos($wsrc, 'ssh root@') === false,
    'docker_wrapper runs no ssh command (R7: the PTY is local)');
assert_true(strpos($wsrc, 'id_rsa') === false,
    'docker_wrapper references no root ssh key');
assert_true(strpos($wsrc, 'CHILD_PTY') !== false,
    'docker_wrapper gets its TTY from the core PTY mode');

/*
|--------------------------------------------------------------------------
| 5. The installer makes the socket reachable
|--------------------------------------------------------------------------
| The socket is root:docker 0660, so this only works if the PHP-FPM user is in
| the docker group. Group membership is resolved when a process starts, so the
| installer must add the group BEFORE it restarts php-fpm; a running pool never
| re-reads its supplementary groups. That ordering is the whole trap, so assert
| the order, not just the presence.
*/
$platform = file_get_contents($root . '/install/lib/platform.sh');

assert_true(strpos($platform, 'usermod -aG docker "$WEB_USER"') !== false,
    'the installer puts the PHP-FPM user in the docker group');
assert_true(strpos($platform, 'apt_install docker.io') !== false,
    'the installer installs a Docker daemon');

$groupAt   = strpos($platform, 'step_platform_docker' . "\n");
$restartAt = strpos($platform, 'systemctl restart "php${PHP_VERSION}-fpm"');
assert_true($groupAt !== false && $restartAt !== false && $groupAt < $restartAt,
    'the docker group is joined before php-fpm is restarted, so the pool picks it up');

/*
|--------------------------------------------------------------------------
| 6. The FPM confinement does not hide the socket
|--------------------------------------------------------------------------
| This project has already been bitten once by php-fpm's systemd confinement
| blocking the platform layer (ProtectSystem=full made useradd fail, so no node
| could start). A unit that cannot see /run cannot see the socket on it, and the
| failure looks like a Docker problem rather than a confinement one.
*/
$dropin = file_get_contents($root . '/install/systemd/php-fpm-pnetlab.conf');

assert_true(preg_match('/^\s*After=.*docker\.service/m', $dropin) === 1,
    'php-fpm is ordered after docker.service');
assert_true(preg_match('/^\s*Requires=/m', $dropin) === 0,
    'php-fpm does not hard-require docker.service (an install with no containers is valid)');
foreach ([
    '/^\s*ProtectSystem\s*=\s*strict/mi'   => 'ProtectSystem=strict',
    '/^\s*PrivateNetwork\s*=\s*(yes|true)/mi' => 'PrivateNetwork=yes',
    '/^\s*InaccessiblePaths\s*=.*\/run/mi' => 'InaccessiblePaths=/run',
    '/^\s*RestrictAddressFamilies\s*=(?!.*AF_UNIX)/mi' => 'RestrictAddressFamilies without AF_UNIX',
] as $re => $what) {
    assert_true(preg_match($re, $dropin) === 0,
        "the drop-in does not hide the docker socket with: $what");
}

test_summary();
