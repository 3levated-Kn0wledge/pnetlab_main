<?php
// Build a command with the real device_qemu::command() and boot it.
$GLOBALS['db'] = null;
require_once '/opt/unetlab/html/includes/messages_en.php';
require_once '/opt/unetlab/html/includes/functions.php';
require_once '/opt/unetlab/html/devices/device.php';
require_once '/opt/unetlab/html/devices/qemu/device_qemu.php';

class BootQemu extends device_qemu {
    public $name, $uuid = '11111111-2222-3333-4444-555555555555';
    public $cpu = 1, $ram = 512, $image = 'linux-ubuntu-24.04';
    public $console = 'vnc', $console_2nd = '', $ethernet = 0;
    public $qemu_arch = 'x86_64', $qemu_version = '', $tpl = ['qemu_arch' => 'x86_64'];
    public $map_port = 0, $map_port_2nd = 0, $delay = 0;
    private $rp;
    public function __construct($n, $rp) { $this->name = $n; $this->rp = $rp; }
    public function getPort()          { return 35911; }
    public function getSecondPort()    { return 35912; }
    public function getSession()       { return 1; }
    public function getTenant()        { return 1; }
    public function getRunningPath()   { return $this->rp; }
    public function getFlag()          { return ''; }
    public function getScriptTimeout() { return 30; }
    public function createNodeMac($id) { return '50:00:00:01:00:' . sprintf('%02x', (int)$id % 256); }
    public function createFirstMac()   { return '50:00:00:01:00:01'; }
}

$rp = '/tmp/qemu-boot-run';
@mkdir($rp, 0755, true);
copy('/opt/unetlab/addons/qemu/linux-ubuntu-24.04/virtioa.qcow2', $rp . '/virtioa.qcow2');

$n = new BootQemu("node-with-a-space and 'quote'", $rp);
$cmd = $n->command();
if ($cmd === false || is_array($cmd)) { echo "command() refused to build\n"; exit(1); }

echo "=== generated command ===\n$cmd\n\n";
// Strip the log redirect and force headless serial so we can see the guest boot.
$cmd = preg_replace('/\s*>\s*\S+wrapper\.txt.*$/', '', $cmd);
$cmd .= ' -nographic -serial mon:stdio -display none';
echo "=== booting it (from the running path, as unl_wrapper does) ===\n";
chdir($rp);
passthru('timeout 40 sh -c ' . escapeshellarg($cmd) . ' 2>&1 | head -12');
echo "\nBOOT-TEST-DONE\n";
