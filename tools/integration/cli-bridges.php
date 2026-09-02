<?php
// Exercise the real cli.php functions against real bridges and taps.
// Run as root on a disposable host.
$GLOBALS['db'] = null;
require_once '/opt/unetlab/html/includes/messages_en.php';
require_once '/opt/unetlab/html/includes/functions.php';
require_once '/opt/unetlab/html/includes/cli.php';

function show($label, $v) { printf("  %-52s %s\n", $label, var_export($v, true)); }
function iface_exists($n) { return is_dir('/sys/class/net/' . $n); }

echo "== 1. valid names still work ==\n";
show('unl_valid_ifname("vnet1_1")',  unl_valid_ifname('vnet1_1'));
show('unl_valid_ifname("vunl12_0")', unl_valid_ifname('vunl12_0'));
show('unl_valid_ifname("pnet0")',    unl_valid_ifname('pnet0'));
show('unl_valid_ifname("docker0")',  unl_valid_ifname('docker0'));

echo "\n== 2. hostile names rejected ==\n";
foreach (['a;id', 'a$(id)', "a\nid", 'a`id`', 'a>b', '../etc', 'x'.str_repeat('y',20)] as $bad) {
    show('unl_valid_ifname(' . json_encode($bad) . ')', unl_valid_ifname($bad));
}

echo "\n== 3. addBridge creates a real bridge ==\n";
$rc = addBridge(['name' => 'vnetTEST_1', 'count' => 1]);
show('addBridge rc', $rc);
show('bridge exists', iface_exists('vnetTEST_1'));

echo "\n== 4. addTap + connectInterface ==\n";
$rc = addTap('vunlTEST_0', 'root');
show('addTap rc', $rc);
show('tap exists', iface_exists('vunlTEST_0'));
$rc = connectInterface('vnetTEST_1', 'vunlTEST_0');
show('connectInterface rc', $rc);
$master = @file_get_contents('/sys/class/net/vunlTEST_0/master/uevent');
show('tap enslaved to bridge', $master !== false);

echo "\n== 5. injection attempt is neutralised ==\n";
@unlink('/tmp/pwned');
$rc = addBridge(['name' => 'v; touch /tmp/pwned; #', 'count' => 1]);
show('addBridge rc (hostile)', $rc);
show('/tmp/pwned created (MUST be false)', file_exists('/tmp/pwned'));

echo "\n== 6. teardown ==\n";
show('disconnectInterface rc', disconnectInterface('vnetTEST_1', 'vunlTEST_0'));
show('delTap rc', delTap('vunlTEST_0'));
show('delBridge rc', delBridge('vnetTEST_1'));
show('tap gone', !iface_exists('vunlTEST_0'));
show('bridge gone', !iface_exists('vnetTEST_1'));
echo "\nHARNESS-DONE\n";
