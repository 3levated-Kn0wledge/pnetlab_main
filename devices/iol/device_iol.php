<?php

use Illuminate\Console\Parser;

/**
 * 
 * @author LIN 
 * @copyright pnetlab.com
 * @link https://www.pnetlab.com/
 * 
 */

class device_iol extends device
{

    // IOL uses porgroups, 4 interfaces each portgroup
    // Ethernets before Serials
    // i = x/y -> i = x + y * 16 -> x = i - y * 16 = i % 16

    public function createEthernets($quantity)
    {
        $ethernets = [];
        for ($x = 0; $x < $quantity; $x++) {
            for ($y = 0; $y <= 3; $y++) {
                $i = $x + $y * 16;      // Interface ID
                $n = 'e' . $x . '/' . $y;     // Interface name
                if (!isset($this->ethernets[$i])) {
                    try {
                        $ethernets[$i] = new Interfc($this, array('name' => $n, 'type' => 'ethernet'), $i);
                    } catch (Exception $e) {
                        error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][40020]);
                        error_log(date('M d H:i:s ') . (string) $e);
                        return false;
                    }
                } else {
                    $ethernets[$i] = $this->ethernets[$i];
                }
            }
        }
        $this->ethernets = $ethernets;
        return $this->ethernets;
    }

    public function createSerials($quantity)
    {
        $serials = [];
        $ethGroupCount = $this->ethernet;
        for ($x = 0; $x < $quantity; $x++) {
            for ($y = 0; $y <= 3; $y++) {
                $i = $ethGroupCount + $x + $y * 16;      // Interface ID 
                $n = 's' . ($x + $ethGroupCount) . '/' . $y;   // Interface name
                if (!isset($this->serials[$i])) {
                    try {
                        $serials[$i] = new Interfc($this, array('name' => $n, 'type' => 'serial'), $i);
                    } catch (Exception $e) {
                        error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][40022]);
                        error_log(date('M d H:i:s ') . (string) $e);
                        return false;
                    }
                } else {
                    $serials[$i] = $this->serials[$i];
                }
            }
        }

        $this->serials = $serials;
        return $this->serials;
    }

    public function editParams($p)
    {
        
        if (isset($p['iol_options'])) {
            $this->iol_options = (string) $p['iol_options'];
        }
        if (isset($p['keepalive'])) {
            $this->keepalive = (string) $p['keepalive'];
        }

        parent::editParams($p);
    }

    public function getParams()
    {
        $params = parent::getParams();
        
        return array_replace($params, [
            'iol_options' => $this->iol_options,
            'keepalive' => $this->keepalive
        ]);
    }

    public function command()
    {
        $iol_id = $this->node->getIolId();
        if ($iol_id == null) {
            error_log(date('M d H:i:s ') . 'ERROR: maximum 512 IOL node foreach user');
            return 12;
        }

        // if($this->isKeepAlive()){
        //     $cmd = '/opt/unetlab/wrappers/iol_wrapper_telnet ';
        // }else{
        //     $cmd = '/opt/unetlab/wrappers/iol_wrapper ';
        // }
        $cmd = '/opt/unetlab/wrappers/iol_wrapper ';
        $cmd .= '-D ' . escapeshellarg($iol_id)
            . ' -S ' . escapeshellarg($this->getSession())
            . ' -P ' . escapeshellarg($this->getPort())
            . ' -t ' . escapeshellarg($this->name)
            . ' -F ' . escapeshellarg($this->node->getRunningPath() . '/' . $this->image)
            . ' -d ' . (int)$this->delay
            . ' -e ' . (int)$this->ethernet
            . ' -s ' . (int)$this->serial;

        foreach ($this->getSerials() as $interface_id => $interface) {
            $remote_id = $interface->getRemoteId();
            if ($remote_id > 0) {
                $remote_node = $this->getNode($remote_id);
                if (!$remote_node) {
                    error_log('ERROR: Can not find node ' + $remote_id);
                    return;
                }
                $cmd .= ' -l ' . escapeshellarg($interface_id . ':localhost:' . $remote_node->getIolId() . ':' . $interface->getRemoteIf() . ':' . $remote_node->getPort());
            }
        }
        
        $flags = ' -n ' . escapeshellarg($this->nvram);  // Size of nvram in Kb
        $flags .= ' -q';                       // Suppress informational messages
        $flags .= ' -m ' . escapeshellarg($this->ram);    // Megabytes of router memory

        if($this->isKeepAlive()) $flags .= ' -l'; // Add L1 keepalive option

        if ($this->config == '1') {
            $flags .= ' -c startup-config';        // Configuration file name
        }

        // sweep-exempt: the template's iol_options string supplies multiple arguments.
        $flags .= isset($this->iol_options) ? ' '.$this->iol_options : '';

        $cmd .= ' -- ' . $flags . ' > ' . escapeshellarg($this->getRunningPath() . '/wrapper.txt');
        return $cmd;
    }


    public function prepare()
    {
        $result = parent::prepare();
        if($result != 0) return $result;

        if (!checkUsername($this->getSession())) {
            error_log(date('M d H:i:s ') . date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][14]);
            return 14;
        }

        $user = 'unl' . $this->getSession();
        

        foreach ($this->getEthernets() as $interface_id => $interface) {
           
			$tap_name = 'vunl' . $this->getSession() . '_' . $interface_id;
            $network = $this->getNetwork($interface->getNetworkId());
			if ($network && $network->isCloud()) {
				// Network is a Cloud
				$net_name = $network->getNType();
			} else {
				$net_name = 'vnet' . $this->getLabSession() . '_' . $interface->getNetworkId();
			}

			// Remove interface
			$rc = delTap($tap_name);
			if ($rc !== 0) {
				// Failed to delete TAP interface
				return $rc;
			}

			// Add interface
			$rc = addTap($tap_name, $user);
			if ($rc !== 0) {
				// Failed to add TAP interface
				return $rc;
			}

			if ($interface->getNetworkId() !== 0) {
				// Connect interface to network
				$rc = connectInterface($net_name, $tap_name);
				if ($rc !== 0) {
					// Failed to connect interface to network
					return $rc;
				}
            }
        }

        // if($this->isKeepAlive()){

        //     $netmap = $this->getRunningPath().'/NETMAP';
        //     $netmapWriter = fopen($netmap, 'w');
        //     $ifIndex = 0;
        //     foreach ($this->getEthernets() as $interface_id => $interface) {
        //         $ifIndex ++;
        //         fwrite($netmapWriter, $this->getIolId(). ":" . preg_replace('/[a-zA-Z]/', '' , $interface->getName()) . " " . ($this->getIolId() + $ifIndex). ":" . preg_replace('/[a-zA-Z]/', '' , $interface->getName()));
        //         fwrite($netmapWriter, "\n");
        //     }

        //     fclose($netmapWriter);
        // }
        

        if (!is_file($this->getRunningPath() . '/.prepared') && !is_file($this->getRunningPath() . '/.lock')) {

            // Node is not prepared/locked
            if (!is_dir($this->getRunningPath()) && !mkdir($this->getRunningPath(), 0775, True)) {
                // Cannot create running directory
                error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80037]);
                return 80037;
            }


            if (!is_file('/opt/unetlab/addons/iol/bin/iourc')) {
                // IOL license not found
                error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80039]);
                return 80039;
            }

            if (!file_exists($this->getRunningPath() . '/iourc') && !symlink('/opt/unetlab/addons/iol/bin/iourc', $this->getRunningPath() . '/iourc')) {
                // Cannot link IOL license
                error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80040]);
                return 80040;
            }

            if (file_exists('/opt/unetlab/addons/iol/bin/' . $this->image)) {
                symlink('/opt/unetlab/addons/iol/bin/' . $this->image, $this->getRunningPath() . '/' . $this->image);
            }

            if (file_exists('/opt/unetlab/addons/iol/bin/keepalive.pl')) {
                symlink('/opt/unetlab/addons/iol/bin/keepalive.pl', $this->getRunningPath() . '/keepalive.pl');
            }
        }

        if (!touch($this->getRunningPath() . '/.prepared')) {
            // Cannot write on directory
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80044]);
            return 80044;
        }

        // Complete the privilege drop, in the wrapper's OWN process.
        //
        // This is still an in-process drop: the wrapper setuid()s itself
        // rather than forking, which is why unl_wrapper postpones IOL in its
        // start-all loop and why abandonStart()'s unwind cannot sudo. Moving
        // IOL onto device::spawnAsTenant() -- fork, drop in the child, exec --
        // is the real fix and stays deferred: it is gated on a licensed IOL
        // image, because no IOL node has ever started here and nothing would
        // catch a mistake (docs/HANDOVER.md, docs/ROADMAP-STATUS.md).
        //
        // What is fixed here is the COMPLETENESS of the drop, which does not
        // need an image to get right and was wrong three ways:
        //
        //   - the uid came from the first line of `id -u`, unvalidated: a
        //     blank or non-numeric $o[0] went straight into posix_setuid(),
        //     which reads it as 0 -- so a lookup failure kept root;
        //   - device::prepare() set the primary gid to unl, but nothing
        //     cleared root's SUPPLEMENTARY groups, so the emulator kept group
        //     0 and could read or write whatever root:root left group-
        //     accessible -- the tenant boundary the drop is supposed to draw;
        //   - posix_setgid()'s and posix_setuid()'s returns were unchecked, so
        //     a failed drop continued toward exec() still privileged.
        //
        // The uid is COMPUTED and CONFIRMED against the passwd database,
        // exactly as device::spawnAsTenant() and UnlIolKeepalive do, never
        // parsed out of `id`. 32768 + session is the platform's per-session
        // tenant uid.
        if (!function_exists('posix_getpwnam') || !function_exists('posix_setuid')
            || !function_exists('posix_setgid')) {
            error_log(date('M d H:i:s ') . 'ERROR: ext-posix is required to drop privileges for an IOL node');
            return 80036;
        }

        $expected = 32768 + (int) $this->getSession();
        $entry = posix_getpwnam($user);
        if ($entry === false || (int) $entry['uid'] !== $expected) {
            error_log(date('M d H:i:s ') . 'ERROR: tenant account ' . $user
                . ' is missing or holds the wrong uid; not starting the IOL node');
            return 80036;
        }
        $gid = (int) $entry['gid'];

        // Supplementary groups FIRST, while still root and before setuid():
        // posix_initgroups() installs the tenant's own group list from
        // /etc/group and drops root's, and posix_setgroups([$gid]) is the
        // fallback where initgroups is unavailable. After setuid() there is no
        // way back to change any of this.
        if (function_exists('posix_initgroups')) {
            if (!posix_initgroups($user, $gid)) {
                error_log(date('M d H:i:s ') . 'ERROR: could not set the supplementary groups for '
                    . $user . '; not starting the IOL node');
                return 80036;
            }
        } elseif (function_exists('posix_setgroups')) {
            if (!posix_setgroups([$gid])) {
                error_log(date('M d H:i:s ') . 'ERROR: could not clear the supplementary groups for '
                    . $user . '; not starting the IOL node');
                return 80036;
            }
        } else {
            error_log(date('M d H:i:s ') . 'ERROR: no way to drop the supplementary groups for '
                . $user . '; not starting the IOL node');
            return 80036;
        }

        // Primary gid, then uid. setuid is last because it is the drop that
        // cannot be undone, and its return is checked, unlike before.
        if (!posix_setgid($gid) || !posix_setuid($expected)) {
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80036]);
            return 80036;
        }

        // Confirm the drop took before the emulator is exec'd as this
        // identity. A setuid that silently failed would otherwise run IOL as
        // root; the supplementary vector is checked too, because that is the
        // hole this change closes.
        if (posix_getuid() !== $expected || posix_geteuid() !== $expected) {
            error_log(date('M d H:i:s ') . 'ERROR: privilege drop did not take; refusing to start '
                . $user);
            return 80036;
        }
        if (function_exists('posix_getgroups')) {
            foreach (posix_getgroups() as $g) {
                if ($g === 0) {
                    error_log(date('M d H:i:s ') . 'ERROR: IOL node ' . $user
                        . ' still holds a root supplementary group after the drop; refusing to start');
                    return 80036;
                }
            }
        }

        return 0;
    }


    public function start(){
        $result = parent::start();
        if( $this->isKeepAlive()){
            $interfaces = $this->getInterfaces();
            foreach($interfaces as $interface){
                if($interface->getNType() == 'ethernet'){
                    if($interface->getNetworkId() > 0 && $interface->getSuspendStatus() != 1){
                        usleep(100000); // waiting for device ready
                        $interface->setLinkState('up');
                    }
                }else{
                    // serial link
                    if($interface->getRemoteId() > 0 && $interface->getSuspendStatus() != 1){
                        usleep(100000); // waiting for device ready
                        $interface->setLinkState('up');
                    }
                }
            } 
        }
        return $result;
    }

    public function stop(){

        // Reap every keepalive helper this node session started.
        //
        // The pids are resolved inside unl_wrapper, from /proc, by the tenant
        // uid that owns them. What was here before ran `ps -aux | grep
        // keepalive | grep vunl<session>_ | cut -d " " -f 2` and handed each
        // field to `sudo kill -9`: the pattern matched process TITLES, so any
        // process whose command line happened to contain those substrings was
        // killed as root, and a non-numeric field would have been passed to
        // kill(1) unquoted. It also never matched the helper it was aiming at,
        // because the helper is started with -n <session>_<iface> and no
        // 'vunl' prefix.
        $cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a iol-keepalive'
            . ' -S ' . (int) $this->getSession() . ' --state down';
        exec($cmd, $o, $rc);
        return parent::stop();
    }

    public function export()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'unl_cfg_' . $this->getSession());

        if (is_file($tmp) && !unlink($tmp)) {
            // Cannot delete tmp file
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80059]);
            return 80059;
        }


        error_log(date('M d H:i:s ') . 'SCAN: ' . $this->getRunningPath());
        foreach (scandir($this->getRunningPath()) as $filename) {
            if (preg_match('/nvram_/', $filename)) {
                $nvram = $this->getRunningPath() . '/' . $filename;
                break;
            }
        }

        if (!isset($nvram)) {
            // NVRAM file not found
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80066]);
            return 80066;
        }

        $cmd = '/opt/unetlab/scripts/wrconf_iol.py -p ' . escapeshellarg($this->getPort()) . ' -t 30';
        exec($cmd, $o, $rc);
        error_log(date('M d H:i:s ') . 'INFO: force write configuration ' . $cmd);
        if ($rc != 0) {
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80060]);
            error_log(date('M d H:i:s ') . implode("\n", $o));
            return 80060;
        }
        $cmd = '/opt/unetlab/scripts/iou_export ' . escapeshellarg($nvram) . ' ' . escapeshellarg($tmp);
        exec($cmd, $o, $rc);
        usleep(1);
        error_log(date('M d H:i:s ') . 'INFO: exporting ' . $cmd);
        if ($rc != 0) {
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80060]);
            error_log(date('M d H:i:s ') . implode("\n", $o));
            return 80060;
        }
        // Add no shut
        if (is_file($tmp)) file_put_contents($tmp, preg_replace('/(\ninterface.*)/', '$1' . chr(10) . ' no shutdown', file_get_contents($tmp)));

        if (!is_file($tmp)) {
            // File not found
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80062]);
            return 80062;
        }

        // Now save the config file within the lab
        clearstatcache();
        $fp = fopen($tmp, 'r');
        if (!isset($fp)) {
            // Cannot open file
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80064]);
            return 80064;
        }
        $config_data = fread($fp, filesize($tmp));
        if ($config_data === False || $config_data === '') {
            // Cannot read file
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80065]);
            return 80065;
        }

        $activeConfig = $this->getActiveConfig();
        if($activeConfig == ''){
            $this->config_data = $config_data;
        }else{
            $this->multi_config[$activeConfig] = $config_data;
        }
        if (!unlink($tmp)) {
            // Failed to remove tmp file
            error_log(date('M d H:i:s ') . 'WARNING: ' . $GLOBALS['messages'][80070]);
        }
        return 0;
    }


    public function isKeepAlive(){
        // if(count($this->getSerials()) > 0) return false;
        return $this->keepalive == 1;
    }

    /** Return ethernet index in ethernets array. Using for create iou2net command */
    public function getEthernetIndex($ifId){
        $index = 0;
        $ethernets = $this->getEthernets();
        foreach($ethernets as $ethernet){
            if($ethernet->getId() == $ifId) return $index;
            $index ++;
        }
        return null;
    }

    /** Return ethernet index in all interface array. Using for create iou2net command */
    public function getInterfaceIndex($type, $ifId){
        $index = 0;
        if($type == 'ethernet'){
            $ethernets = $this->getEthernets();
            foreach($ethernets as $ethernet){
                if($ethernet->getId() == $ifId) return $index;
                $index ++;
            }
        }else if($type == 'serial'){
            $serials = $this->getSerials();
            foreach($serials as $serial){
                if($serial->getId() == $ifId) return $index;
                $index ++;
            }
        }
        return null;
        
    }

}
