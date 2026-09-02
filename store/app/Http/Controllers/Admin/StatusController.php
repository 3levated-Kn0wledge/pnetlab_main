<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Auth\Role;
use App\Helpers\Request\Reply;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\Resource;

class StatusController extends Controller
{

    function __construct()
    {
        //Load intital variable to page that is not React
        parent::__construct();
        $this->viewblade = 'reactjs.reactjs';
        
    }

    public function view()
    {
        return view($this->viewblade);
    }

    public function getInfo()
    {

        $cmd = '/opt/qemu/bin/qemu-system-x86_64 -version | sed \'s/.* \([0-9]*\.[0-9.]*\.[0-9.]*\).*/\1/g\'';

        $o = '';
        exec($cmd, $o, $rc);
        if ($rc != 0) {
            error_log(date('M d H:i:s ') . 'ERROR: 60044');
            $qemu_version = '';
        } else {
            $qemu_version = $o[0];
        }

        $cmd = 'nproc';
        $o = '';
        exec($cmd, $o, $rc);
        if ($rc != 0) {
            $cores = '';
        } else {
            $cores = $o[0];
        }

        // Memory dedup. One control, read once — see unl_ksm_state() in
        // includes/cli.php for what it does and does not cover.
        $ksm = unl_ksm_state();

        // 'uksm' is reported, always as 'unsupported', and that is the truth
        // rather than a placeholder: UKSM is an out-of-tree patch that lived in
        // the appliance's custom 4.15 kernel and the fork ships stock Ubuntu,
        // so /sys/kernel/mm/uksm/ cannot exist here. What used to be here was
        // `cat` of that path, once per status poll, to reach the same answer.
        //
        // The FIELD stays because the committed React bundle indexes it
        // (store/public/react/pages/admin-StatusView-js.js): on 'unsupported'
        // it draws a greyed, non-clickable toggle, and on anything else — an
        // ABSENT key included — it draws a live one wired to apiSetUksm.
        // Dropping the key here would turn a correctly-inert row into a working
        // button for a control that does not exist. The row itself goes when
        // the frontend is next built; docs/PLATFORM-SUPPORT.md records why that
        // could not be done in the same change.
        $uksm = 'unsupported';

        $o = "";
        $cmd = 'systemctl is-active cpulimit.service';
        exec($cmd, $o, $rc);
        if ($rc != 0) {
            $cpulimit = 'disabled';
        } else {
            if ($o[0] == "active") {
                $cpulimit = 'enabled';
            } else {
                $cpulimit = 'disabled';
            }
        }

        Reply::finish(true, 'success', [
            'qemu_version' => $qemu_version,
            'uksm' => $uksm,
            'ksm' => $ksm,
            'cpulimit' => $cpulimit,
            'cores' => $cores,
        ]);
    }

    public function getRunningNodes()
    {
        if (!Role::checkRoot()) Reply::finish(true, 'success', []);
        $cmd = 'pgrep -f -c -P 1 iol_wrapper';
        exec($cmd, $o_iol, $rc);
        $cmd = 'pgrep -f -c -P 1 dynamips';
        exec($cmd, $o_dynamips, $rc);
        $cmd = 'pgrep -f -c -P 1 qemu-system';
        exec($cmd, $o_qemu, $rc);
        $cmd = 'docker -H=unix:///var/run/docker.sock ps -q | wc -l';
        exec($cmd, $o_docker, $rc);
        $cmd = 'pgrep -f -c -P 1 vpcs';
        exec($cmd, $o_vpcs, $rc);
        $data = array(
            'iol' => (int) current($o_iol),
            'dynamips' => (int) current($o_dynamips),
            'qemu' => (int) current($o_qemu),
            'docker' => (int) current($o_docker),
            'vpcs' => (int) current($o_vpcs)
        );
        Reply::finish(true, 'success', $data);
    }

    public function getSystemInfo()
    {
        
       
        $data = [
            'cpu' => 0,
            'ram' => 0,
            'swap' => 0,
            'disk' => 0,
            'total_ram' => 0,
            'total_swap' => 0,
            'total_disk' => 0,
        ];

        $o = [];
        $cmd = 'free -m';
        exec($cmd, $o, $rc);
        foreach ($o as $output) {
            if (preg_match('/^mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+).*$/mi', $output, $match)) {
                if((int) $match[1] > 0){
                    $data['ram'] = 100 - round((int) $match[6] * 100 / (int) $match[1]);
                    $data['total_ram'] = $match[1]*1024;
                }
            }
            if (preg_match('/^swap:\s+(\d+)\s+(\d+)\s+(\d+).*$/mi', $output, $match)) {
                if((int)$match[1] > 0){
                    $data['swap'] = round((int) $match[2] * 100 / (int) $match[1]);
                    $data['total_swap'] = $match[1]*1024;
                }
                
            }
        }

        if (!Role::checkRoot()) Reply::finish(true, 'success', $data);

        $o = [];
        $cmd = 'top -b -n2 -p1 -d1';
        exec($cmd, $o, $rc);

        foreach ($o as $output) {
            if (preg_match('/^%cpu.*\s([\d\.]+)(?=\sid).*$/mi', $output, $match)) {
                $data['cpu'] = 100 - (int) round($match[1]);
            }
        }

        $o = [];
        $cmd = 'df -h /';
        exec($cmd, $o, $rc);
        foreach ($o as $output) {
            if (preg_match('/^.*\s([\d\.]+[TGMKB]+)\s*([\d\.]+[TGMKB]+)\s*([\d\.]+[TGMKB])\s*([\d]+)%.*$/mi', $output, $match)) {
                $data['disk'] = $match[4];
                $data['total_disk'] = $match[1];
            }
        }

        Reply::finish(true, 'success', $data);
    }


    function apiSetCpuLimit(Request $request)
    {
        if (!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $state = $request->input('state', true);
        if ($state == true) {
            $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a cpulimiton";
        } else {
            $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a cpulimitoff";
        }
        exec($cmd, $o, $rc);
        
        if ($rc == 0) {
            Reply::finish(true, 'success');
        } else {
            Reply::finish(false, 'Change CPU limit status fail');
        }
    }

    /*
     * Function to set UKSM status.
     *
     * Retained, and deliberately identical to apiSetKsm below: there is one
     * memory-dedup control on this platform and both entry points reach it. It
     * cannot normally be called — getInfo() reports uksm as 'unsupported', which
     * is what makes the status page's UKSM toggle inert — but it is a live route
     * and a hand-made POST used to get back "Change UKSM status fail" from a
     * wrapper verb writing to a path that no stock kernel has.
     *
     * @return  Bool Success operation
     */
    function apiSetUksm(Request $request)
    {
        return $this->apiSetKsm($request);
    }

    /*
* Function to set KSM status.
*
* @return  Bool Success operation
*/

    function apiSetKsm(Request $request)
    {
        if (!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $state = $request->input('state', true);
        // filter_var, not `$state == true`. The SPA posts a JSON body and its
        // booleans survive as PHP booleans, so that comparison happened to work
        // from the browser — but any form-encoded caller sends the STRING
        // 'false', and 'false' == true is true in PHP, so the off half of the
        // toggle silently turned KSM on. Both spellings, and '0'/'off'/'', now
        // map the way they read.
        $on = filter_var($state, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($on === null) $on = true;
        // Two whole literals rather than one literal plus a chosen verb: the
        // shell-escaping sweep traces every exec() argument back to its source
        // and a concatenated request-derived value is a finding even when both
        // branches are safe. Nothing is interpolated, so nothing needs escaping.
        if ($on) {
            $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a ksmon";
            error_log(date('M d H:i:s ') . 'DEBUG: ksm on');
        } else {
            $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a ksmoff";
            error_log(date('M d H:i:s ') . 'DEBUG: ksm off');
        }
        exec($cmd, $o, $rc);
        if ($rc == 0) {
            Reply::finish(true, 'success');
        } else {
            Reply::finish(false, 'Change KSM status fail');
        }
    }
}
