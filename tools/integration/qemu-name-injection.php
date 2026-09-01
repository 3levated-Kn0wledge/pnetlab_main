<?php
/*
 * Integration test — run as root on a disposable host, not in CI.
 *
 * Requires: the fork deployed to /opt/unetlab/html, an image at
 * /opt/unetlab/addons/qemu/linux-ubuntu-24.04/virtioa.qcow2, and
 * /opt/qemu/bin/qemu-system-x86_64 (PNETLab's expected path).
 *
 * Point /opt/qemu/bin/qemu-system-x86_64 at /bin/true while running this. A
 * payload injected after a ';' only executes once the emulator exits, so a real
 * QEMU would still be booting when any timeout fired and the injection would be
 * invisible. Stubbing it isolates command construction, which is where the
 * defect lives. Restore the symlink afterwards.
 *
 * Measured against the pre-fix tree:
 *     built:  -name 'a'; touch /tmp/pwned-hostile; '' -uuid 1111...
 *     payload ran: YES
 * and against the fixed tree:
 *     built:  -name 'a'\''; touch /tmp/pwned-hostile; '\''' -uuid '1111...
 *     payload ran: no
 */
/**
 * Does device_qemu::command() still allow injection through the node name?
 *
 * Before the fix, the finished command was passed through
 *   preg_replace('/\'|"|\\\\"|\\\\\'/m', "'", $cmd)
 * which collapsed every quote to a bare single quote, so a node named
 *   a'; touch /tmp/pwned; '
 * produced  -name 'a'; touch /tmp/pwned; ''  and the shell ran the payload.
 *
 * This builds the command with the real class and then actually executes it.
 */
$GLOBALS['db'] = null;
require_once '/opt/unetlab/html/includes/messages_en.php';
require_once '/opt/unetlab/html/includes/functions.php';
require_once '/opt/unetlab/html/devices/device.php';
require_once '/opt/unetlab/html/devices/qemu/device_qemu.php';

class HarnessQemu extends device_qemu
{
    public $name, $uuid = '11111111-2222-3333-4444-555555555555';
    public $cpu = 1, $ram = 256, $image = 'linux-ubuntu-24.04';
    public $console = 'vnc', $console_2nd = '', $ethernet = 1;
    public $qemu_arch = 'x86_64', $qemu_version = '', $tpl = ['qemu_arch' => 'x86_64'];
    public $map_port = 0, $map_port_2nd = 0, $delay = 0, $first_nic = '';
    private $runpath;

    public function __construct($name, $runpath) { $this->name = $name; $this->runpath = $runpath; }
    public function getPort()          { return 35901; }
    public function getSecondPort()    { return 35902; }
    public function getSession()       { return 1; }
    public function getTenant()        { return 1; }
    public function getRunningPath()   { return $this->runpath; }
    public function getFlag()          { return ''; }
    public function getScriptTimeout() { return 30; }
    public function createNodeMac($id) { return '50:00:00:01:00:' . sprintf('%02x', (int)$id % 256); }
    public function createFirstMac()   { return '50:00:00:01:00:01'; }
}

function run($label, $name, $marker)
{
    $run = '/tmp/qemu-harness';
    @mkdir($run, 0755, true);
    @unlink($marker);

    $n = new HarnessQemu($name, $run);
    $cmd = $n->command();
    if ($cmd === false || is_array($cmd)) { echo "  $label: command() refused to build\n"; return; }

    echo "  $label\n    node name: " . var_export($name, true) . "\n";
    // Everything from -name onwards, so the shell metacharacters are visible.
    $i = strpos($cmd, '-name');
    echo "    built:     " . substr($cmd, $i, 60) . "\n";

    // /opt/qemu/bin/qemu-system-x86_64 is pointed at /bin/true for this test, so
    // the "emulator" exits instantly. Otherwise an injected payload after a ';'
    // would not run until QEMU itself exited, and a timeout would mask it.
    exec('sh -c ' . escapeshellarg($cmd) . ' >/dev/null 2>&1');
    echo "    payload ran: " . (file_exists($marker) ? "YES  <-- INJECTION" : "no") . "\n";
}

echo "== device_qemu::command() injection test ==\n";
run('1. benign name', 'testnode', '/tmp/pwned-benign');
run('2. hostile name', "a'; touch /tmp/pwned-hostile; '", '/tmp/pwned-hostile');
echo "\nTEST-DONE\n";
