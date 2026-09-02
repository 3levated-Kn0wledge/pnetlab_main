<?php
/**
 * Docker on cgroup v2, asserted at install time.
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
