<?php
/**
 * Two host-level properties the installer must not quietly give away.
 *
 * 1. APPARMOR STAYS ON. Upstream's answer to AppArmor was `apparmor=0` on the
 *    kernel command line, alongside the whole host-hardening set, off, for VM
 *    density. The fork ships no profile of its own (docs/APPARMOR.md says why)
 *    but it must never be the thing that turns AppArmor off, and "we did not"
 *    is a claim that decays the first time somebody debugs a node-start failure
 *    and finds that disabling it helps.
 *
 * 2. DOCKER IS ON CGROUP V2, ASSERTED AT INSTALL TIME.
 *
 * The appliance ran Docker 20.10 on cgroup **v1** with the `cgroupfs` driver.
 * The reference host is v2 with the systemd driver and it works -- measured:
 * `stat -fc %T /sys/fs/cgroup` is `cgroup2fs`, `docker info` reports
 * `Cgroup Version: 2` and `Cgroup Driver: systemd`, and node-types.sh passes.
 * Nothing checked any of it.
 *
 * The failure this defends against is not "Docker is broken". It is a host that
 * presents v1, a hybrid host, or a daemon started with
 * --exec-opt native.cgroupdriver=cgroupfs against a v2 kernel -- each of which
 * produces containers that start and then behave differently under resource
 * pressure. The symptom is a lab that misbehaves, discovered weeks later.
 *
 * These are source assertions, not host assertions: the suite runs against a
 * bare interpreter on any machine, and what is being defended is the
 * INSTALLER's behaviour, which is the same wherever it runs.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$verify = file_get_contents($root . '/install/lib/verify.sh');

// -------------------------------------------------- nothing disables apparmor

/** Every shell file the installer and the platform layer ship. */
function installer_scripts($root)
{
    $out = [];
    foreach ([
        $root . '/install',
        $root . '/tools',
        $root . '/platform',
    ] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $p = $f->getPathname();
            // Documentation is allowed to name the thing it is warning about.
            if (preg_match('/\.(md|yml|yaml|json|sql|pub|png)$/', $p)) continue;
            $out[] = $p;
        }
    }
    sort($out);
    return $out;
}

$scripts = installer_scripts($root);
assert_true(count($scripts) > 20, 'the installer file sweep found something to check');

// The literal upstream shipped. Anything writing it into a GRUB file, a
// bootloader config or a running kernel's parameters is the regression.
$offenders = [];
foreach ($scripts as $p) {
    $body = file_get_contents($p);
    // Strip shell comments line by line: install/lib/verify.sh has to name
    // `apparmor=0` in order to grep /proc/cmdline for it, and the check that
    // enforces this rule must not be the thing that trips it.
    $code = '';
    foreach (explode("\n", $body) as $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '#') continue;
        $code .= $line . "\n";
    }
    // Naming a thing is not doing it. verify.sh has to greps /proc/cmdline for
    // `apparmor=0` and read the userns sysctl in order to CHECK them, so the
    // patterns below describe writes: a sysctl -w, a redirect into /proc/sys,
    // an edit of a GRUB file, or a regeneration of the bootloader config.
    //
    // Matching on the bare strings was the first version of this and it flagged
    // verify.sh twice, once for `sysctl -n <knob> 2>/dev/null` -- the `2>` read
    // as a write redirect.
    foreach ([
        '/\bsysctl\s+-w[^\n]*apparmor/i'                    => 'writes an apparmor sysctl',
        '/>\s*\/proc\/sys\/kernel\/apparmor/i'              => 'writes /proc/sys/kernel/apparmor',
        '/\baa-(disable|teardown)\b/'                       => 'runs aa-disable or aa-teardown',
        '/\bGRUB_CMDLINE[A-Z_]*\s*=/'                       => 'sets a GRUB command line',
        '/>\s*\/etc\/default\/grub|\/etc\/default\/grub[^\n]*<</' => 'writes /etc/default/grub',
        '/\b(update-grub|grub-mkconfig|grub2-mkconfig)\b/'  => 'regenerates the bootloader config',
        '/\bapparmor=0\b[^\n]*(>>?|tee)\s*\S/'              => 'writes apparmor=0 somewhere',
    ] as $re => $what) {
        if (preg_match($re, $code)) {
            $offenders[] = substr($p, strlen($root) + 1) . ': ' . $what;
        }
    }
}
assert_same([], $offenders, 'nothing in install/, tools/ or platform/ disables AppArmor or edits the kernel command line');

// And nothing stops or masks the service.
$svc = [];
foreach ($scripts as $p) {
    if (preg_match('/systemctl\s+(stop|mask|disable)\s+apparmor/', file_get_contents($p))) {
        $svc[] = substr($p, strlen($root) + 1);
    }
}
assert_same([], $svc, 'nothing stops, masks or disables apparmor.service');

// -------------------------------------------------------- verify.sh asserts it

$verify = file_get_contents($root . '/install/lib/verify.sh');

foreach ([
    'verify_apparmor'                              => 'there is an apparmor section',
    '/sys/module/apparmor/parameters/enabled'      => 'it reads the kernel switch',
    '/proc/cmdline'                                => 'it reads the kernel command line',
    'apparmor_restrict_unprivileged_userns'        => 'it reports the userns restriction',
    'docker-default'                               => 'it reports whether containers are confined',
] as $needle => $what) {
    assert_true(strpos($verify, $needle) !== false, "verify.sh: $what");
}

// The checks are SOFT on purpose. The fork works without AppArmor; what it must
// not do is switch it off. A hard failure here would make a deliberately
// AppArmor-less host fail its install, which is not this project's call.
assert_true(preg_match('/check_soft\s+"AppArmor is enabled/', $verify) === 1,
    'and reports them as warnings, not as a failed install');

// ----------------------------------------------------------- cgroup v2, docker

foreach ([
    'cgroup2fs'         => 'verify.sh asserts the v2 hierarchy by filesystem type',
    'Cgroup Version: 2' => 'and that the daemon agrees',
    'Cgroup Driver: systemd' => 'and reports the driver',
    'verify_docker'     => 'there is a docker section',
] as $needle => $what) {
    assert_true(strpos($verify, $needle) !== false, $what);
}

// The hierarchy is a HARD check and the driver is soft, which is the deliberate
// split: nothing in this tree has ever run on v1, but a cgroupfs driver on a v2
// host is a configuration smell the installer did not create and should not
// silently change.
assert_true(preg_match('/\bcheck\s+"\/sys\/fs\/cgroup is cgroup2fs/', $verify) === 1,
    'the v2 hierarchy is a hard check');
assert_true(preg_match('/check_soft\s+"and Cgroup Driver: systemd"/', $verify) === 1,
    'the cgroup driver is a soft check');


test_summary();
