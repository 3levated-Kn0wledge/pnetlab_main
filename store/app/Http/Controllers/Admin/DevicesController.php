<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\Request\Checker;
use App\Helpers\Request\Reply;
use App\Helpers\DB\Models;
use App\Helpers\Packages\PackageClient;

/**
 * The marketplace device installer.
 *
 * WHAT THIS USED TO DO
 *
 * Three separate paths in this file took a string out of a JSON response from
 * pnetlab.com and handed it to a shell:
 *
 *   filter()  exec($device['device_check'])                      as www-data,
 *             once per device, on every listing of the store
 *   get()     the same, then wrote $device['device_script'] to
 *             /tmp/pnet_device_factory_<id>, chmod 0755, and ran
 *             `sudo dos2unix <f>` followed by `sudo <f> > <log> 2>&1 &`
 *   delete()  the same with $device['device_delete']
 *
 * The device id was interpolated into `sudo pkill -f pnet_device_factory_<id>`
 * unescaped as well, so a request could inject a command of its own without
 * needing the upstream server's help at all.
 *
 * None of the sudo parts had worked since the sudo policy was scoped —
 * /tmp/pnet_device_factory_* is not on the allowlist and never was. So this is
 * not a working feature being taken away. It is a broken feature being rebuilt
 * in a shape that is safe to switch back on.
 *
 * WHAT IT DOES NOW
 *
 * A device install is a signed package. This controller downloads one, stages
 * it, and asks the wrapper to apply it; every decision about what goes where is
 * made by code we own, reading a manifest that can only express operations from
 * a fixed list. See docs/PACKAGES.md.
 *
 * WHERE THE LISTING COMES FROM
 *
 * Until Phase 05, filter() and get() still asked user.pnetlab.com for the
 * device records (with the box's alive key and encrypted UUID attached to
 * every call). They now read the repository's own index.json through
 * PackageClient::index() -- one file, fetched only when PNET_PACKAGE_CENTER
 * is set, parsed as untrusted data. With no repository configured the store
 * is empty and says so; nothing is contacted.
 *
 * The HTTP contract is unchanged. /admin/devices/{filter,get,delete,process}
 * take and return exactly what they did, the Process_device rows still carry
 * the progress the dialog renders, and process() still returns the log text the
 * dialog shows — so the admin screens need no changes.
 */
class DevicesController extends Controller
{

    function __construct()
    {
        parent::__construct();
        $this->viewblade = 'reactjs.reactjs';
    }

    public function filter(Request $request)
    {
        $index = PackageClient::index();
        // "Is this device already on the box?" is answered from the box's own
        // record of what it has installed. It used to be answered by running a
        // shell command the marketplace supplied, once per device, which meant
        // rendering the store gave pnetlab.com command execution as www-data.
        $installed = PackageClient::installed();
        $devices = $index['devices'];
        foreach($devices as $key=>$device){
            $devices[$key]['available'] =
                PackageClient::isDeviceInstalled($device[DEVICE_ID], $installed) ? '1' : '0';
        }
        // An empty store carries the reason in the message, which the page
        // shows: no repository configured, or one that did not answer.
        Reply::finish(true, $index['result'] ? 'success' : $index['message'], $devices);
    }

    public function get(Request $request)
    {
        $deviceId = (string) $request->input(DEVICE_ID, '');
        $overwritten = $request->input('overwritten', false);

        // The id becomes part of a filename and part of a database key. It is
        // checked before either, not after.
        if(!PackageClient::validId($deviceId)) Reply::finish(false, 'Invalid device id');

        $device = PackageClient::device($deviceId);
        if($device === null){
            $index = PackageClient::index();
            Reply::finish(false, $index['result']
                ? 'This device is not in the package repository index'
                : $index['message']);
        }

        if(!$overwritten && PackageClient::isDeviceInstalled($deviceId)){
            Reply::finish(false, 'device_existed_alert', ['confirm' => true]);
        }

        $url = PackageClient::deviceUrl($device, $deviceId);
        if($url === null){
            // Deliberately explicit rather than falling back to the old
            // behaviour. There is no safe way to install from a shell script we
            // cannot attribute to anyone, so the answer is "no package", not
            // "run it anyway".
            Reply::finish(false,
                'This device has no signed package. The fork installs devices from signed '
                . 'packages only; set PNET_PACKAGE_CENTER to a repository that publishes them, '
                . 'or see docs/PACKAGES.md to build one.');
        }

        $this->startJob($deviceId, $device, [
            'action' => 'install',
            'url' => $url,
            'sha256' => isset($device[DEVICE_PACKAGE_SHA256]) ? (string) $device[DEVICE_PACKAGE_SHA256] : '',
        ], 'Start loading '. (isset($device[DEVICE_NAME]) ? $device[DEVICE_NAME] : $deviceId));

        Reply::finish(true, 'success');
    }

    public function delete(Request $request)
    {
        $deviceId = (string) $request->input(DEVICE_ID, '');
        if(!PackageClient::validId($deviceId)) Reply::finish(false, 'Invalid device id');

        $installed = PackageClient::installed();
        if(!isset($installed['device:'.$deviceId])){
            Reply::finish(false, 'This device was not installed from a package, so there is nothing to remove');
        }
        $record = $installed['device:'.$deviceId];

        // Removal runs the uninstall plan the package shipped, which was
        // recorded by root when the signature verified. Nothing is
        // re-downloaded and no new instructions are accepted at removal time.
        $this->startJob($deviceId, ['device_name' => $record['name']], [
            'action' => 'remove',
            'package' => $record['id'],
        ], 'Delete '. $record['name']);

        Reply::finish(true, 'success');
    }

    /**
     * Hand the work to a background worker and return at once.
     *
     * The UI polls process() a second later and expects a row to be there
     * already, so the row is created here, synchronously, before the worker
     * starts. The worker is `php artisan pnet:package-run <id>`: no sudo, and
     * the only thing on its command line is a device id that has already been
     * checked against a strict pattern. Everything else it needs is in a job
     * file it reads by that id, so no URL and no supplier-controlled string
     * ever appears in a command line.
     */
    private function startJob($deviceId, $device, array $job, $firstLogLine)
    {
        if(!PackageClient::ensureDirectories()){
            Reply::finish(false, 'Cannot create '. PACKAGE_INCOMING_DIR);
        }

        $logfile = PackageClient::logPath($deviceId);
        @unlink($logfile);
        @file_put_contents(PackageClient::jobPath($deviceId), json_encode($job));

        $processModel = Models::get('Admin/Process_device');
        $processModel->drop([[ [PROCESS_DEVICE_ID, '=', $deviceId] ]]);
        $processModel->add([[
            PROCESS_DEVICE_ID => $deviceId,
            PROCESS_DEVICE_LOG => $firstLogLine,
        ]]);

        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(base_path('artisan'))
            . ' pnet:package-run ' . escapeshellarg($deviceId)
            . ' >> ' . escapeshellarg($logfile) . ' 2>&1 &';
        exec($cmd);
    }

    public function process(Request $request){
        $deviceId = (string) $request->input(DEVICE_ID, '');
        if(!PackageClient::validId($deviceId)) Reply::finish(false, 'Invalid device id');

        $processModel = Models::get('Admin/Process_device');
        $result = $processModel->read([[[PROCESS_DEVICE_ID, '=', $deviceId]]]);

        $logfile = PackageClient::logPath($deviceId);

        if(!$result['result'] || !isset($result['data'][0])){
            // The worker drops the row when it finishes, which is what tells
            // the dialog to close. The log is left on disk under
            // /opt/unetlab/data/Logs/packages so a failed install can still be
            // read afterwards; it used to be deleted with `sudo rm -f` on a
            // path built from request input.
            Reply::finish(true, 'success', ['finish'=>true, 'data'=>null]);
        }else{
            $data = $result['data'][0];
            if(is_file($logfile)){
                $data->log = file_get_contents($logfile);
            }
        }
        Reply::finish(true, 'success', ['finish'=>false, 'data'=>$data]);
    }

    public function store()
    {
        return view($this->viewblade);
    }

}
