<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Admin\SystemHelper;
use App\Helpers\Auth\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\Request\Checker;
use App\Helpers\Request\Reply;
use Illuminate\Support\Facades\Auth;
use App\Helpers\DB\Models;
use App\Model\Relation;

use function PHPSTORM_META\type;

class Node_sessionsController extends Controller
{

    function __construct()
    {
        parent::__construct();
        $this->mainModel = Models::get('Admin/Node_sessions');
        $this->viewblade = 'reactjs.reactjs';
        $this->dependCols = array_unique(array_column($this->mainModel->registerDepend, 1, 1));
        $this->dependCols[NODE_SESSION_ID] = true;
    }

    public function getNodeWorkspace(Request $request)
    {
        $node_id = $request->input('node_id', '');
        $nodeSession = $this->mainModel->read([[[NODE_SESSION_NID, '=', $node_id], [NODE_SESSION_LAB, '=', Auth::user()->{USER_LAB_SESSION}]]]);
        if (!$nodeSession['result']) return $nodeSession;
        if (!isset($nodeSession['data'][0])) Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Node Session']);
        $node = $nodeSession['data'][0];
        $path = $nodeSession['data'][0]->{NODE_SESSION_WORKSPACE};
        if (is_dir($path)) {
            $status = 1;
            $size = SystemHelper::FolderSize($path);
        } else {
            $status = 0;
            $size = 0;
        }
        $attach = '';
        if($node->{NODE_SESSION_TYPE} == 'docker'){
            $attach = 'docker container attach docker'.$node->{NODE_SESSION_ID};
        }
        Reply::finish(true, 'success', ['path' => $path, 'status' => $status, 'size' => $size, 'attach' => $attach]);
    }

    public function checkCommitDevice(Request $request)
    {

        if (!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $node_id = $request->input('node_id', '');
        if ($node_id == '') Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Node ID']);

        $node_image = $request->input('node_image', '');
        if ($node_image == '') Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Node Image']);

        $type = $request->input('type', '');
        if ($type == '') Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Commit Type']);

        $nodeSession = $this->mainModel->read([[[NODE_SESSION_NID, '=', $node_id], [NODE_SESSION_LAB, '=', Auth::user()->{USER_LAB_SESSION}]]]);
        if (!$nodeSession['result']) return $nodeSession;
        if (!isset($nodeSession['data'][0])) Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Node Session']);
        $nodeSession = $nodeSession['data'][0];
        if ($nodeSession->{NODE_SESSION_TYPE} != 'docker' && $nodeSession->{NODE_SESSION_TYPE} != 'qemu') Reply::finish(false, 'This device does not support committing');

        if ($nodeSession->{NODE_SESSION_TYPE} == 'docker') {
            $addSize = SystemHelper::getNodeDisk($nodeSession);
            if ($addSize == null) Reply::finish(false, 'Can not find Docker container');
        } else if ($nodeSession->{NODE_SESSION_TYPE} == 'qemu') {

            $addSize = 0;
            if ($type == 'snapshot') {
                $addSize = (int) SystemHelper::FolderSize($nodeSession->{NODE_SESSION_WORKSPACE});
            } else {
                // The disk images and their backing chain used to be walked
                // here, with `sudo qemu-img info --backing-chain` on a path
                // built from the workspace and passed through secureCmd(),
                // which is a blocklist of [#;|&] and '..' and lets a backtick
                // straight through. The wrapper does the walk now and reports
                // one number; it also refuses a chain that points outside the
                // image roots, which this loop never checked.
                $imageResult = $this->imageCommit($nodeSession->{NODE_SESSION_ID}, 'check');
                if (!$imageResult['ok']) {
                    Reply::finish(false, 'Can not read the disk images: {data}', ['data' => $imageResult['error']]);
                }
                $addSize = (int) $imageResult['size'];
            }
        }

        Reply::finish(true, 'success', $addSize);
    }

    public function commitDevice(Request $request)
    {
        if (!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $node_id = $request->input('node_id', '');
        if ($node_id == '') Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Node ID']);

        $node_image = $request->input('node_image', '');
        if ($node_image == '') Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Node Image']);

        $type = $request->input('type', '');
        if ($type == '') Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Commit Type']);

        if ($type == 'snapshot' || $type == 'new') {
            $deviceName = $request->input('device_name');
            $deviceName = preg_replace('/[^\w]/', '_', $deviceName);
            if ($deviceName == '') Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Device Name']);
        }

        $nodeSession = $this->mainModel->read([[[NODE_SESSION_NID, '=', $node_id], [NODE_SESSION_LAB, '=', Auth::user()->{USER_LAB_SESSION}]]]);
        if (!$nodeSession['result']) return $nodeSession;
        if (!isset($nodeSession['data'][0])) Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Node Session']);
        $nodeSession = $nodeSession['data'][0];
        if ($nodeSession->{NODE_SESSION_TYPE} != 'docker' && $nodeSession->{NODE_SESSION_TYPE} != 'qemu') Reply::finish(false, 'ERROR', ['data' => 'This device does not support committing']);

        $freeDisk = SystemHelper::getTotalDisk();
        if (!isset($freeDisk['free'])) Reply::finish(false, 'Your hardisk is full');
        $freeDisk = $freeDisk['free'] * 1024;

        if ($nodeSession->{NODE_SESSION_TYPE} == 'docker') {
            $addSize = SystemHelper::getNodeDisk($nodeSession);
            if ($addSize == null) Reply::finish(false, 'Can not find Docker container');
            if (($freeDisk - $addSize) < (100 * 1024 * 1024)) Reply::finish(false, 'You do not have enough free hard disk to save the new device');

            if ($type == 'new' || $type == 'snapshot') {
                $imageDocker = explode(':', $node_image);
                if (count($imageDocker) < 2) Reply::finish(false, ERROR_FORMAT, ['data' => 'Docker Image']);
                $newName = $imageDocker[0] . ':' . $deviceName;

                // Every value below is escaped. These no longer go through
                // sudo, but talking to the Docker socket is root-equivalent on
                // this host, so an image name that can become a second command
                // is worth exactly as much to an attacker as the sudo was.
                $result = exec('docker -H=unix:///var/run/docker.sock images -q ' . escapeshellarg($newName), $o, $r);
                if (isset($o[0])) Reply::finish(false, 'This Name already exists');

                $result = exec('docker -H=unix:///var/run/docker.sock commit '
                    . escapeshellarg('docker' . $nodeSession->{NODE_SESSION_ID}) . ' ' . escapeshellarg($newName), $o, $r);
                if ($r != 0) Reply::finish(false, 'Docker Commit Failed');
                Reply::finish(true, 'success', ['name' => $newName]);
            } else if ($type == 'existed') {

                $result = exec('docker -H=unix:///var/run/docker.sock images -q ' . escapeshellarg($node_image), $o, $r);
                if (!isset($o[0])) Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Docker Image']);
                $oldId = $o[0];

                $o = [];
                $result = exec('docker -H=unix:///var/run/docker.sock inspect ' . escapeshellarg($oldId) . ' --format="{{.Parent}}"', $o, $r);
                if (!isset($o[0]) || $o[0] == '') Reply::finish(false, "docker_commit_alert");

                $result = exec('docker -H=unix:///var/run/docker.sock commit '
                    . escapeshellarg('docker' . $nodeSession->{NODE_SESSION_ID}) . ' ' . escapeshellarg($node_image), $o, $r);
                if ($r != 0) Reply::finish(false, 'Docker Commit Failed', 'Docker Commit Failed');

                if (isset($oldId)) $result = exec('docker -H=unix:///var/run/docker.sock image rm ' . escapeshellarg($oldId), $o, $r);

                Reply::finish(true, 'success', ['name' => '']);
            }
        } else if ($nodeSession->{NODE_SESSION_TYPE} == 'qemu') {
            $addSize = SystemHelper::getNodeDisk($nodeSession);
            if ($addSize == null) Reply::finish(false, 'Can not find Qemu folder');

            $addSize += (int) SystemHelper::FolderSize('/opt/unetlab/addons/qemu/' . $node_image);
            if ($freeDisk - $addSize < 100 * 1024 * 1024) Reply::finish(false, 'You do not have enough free hard disk to save the new device');

            // What was here: a scandir for *.qcow2, then between four and
            // fifteen `sudo` commands per commit — qemu-img info, mkdir, cp,
            // qemu-img rebase, qemu-img commit, mv, rm -rf and chown -R —
            // every one of them built by concatenating $newFolder, $qcowFile,
            // $tmpFile and $parentFile into a root shell without a single
            // quote. $newFolder came from explode('-', $request->input(
            // 'node_image'))[0], so the destination of a root `mkdir` was
            // request input.
            //
            // It is now one wrapper call. The controller decides nothing about
            // paths: it sends a session id, a type word from a closed set, and
            // a name, and the wrapper builds every path under a root it owns.
            if ($type == 'existed') {
                $imageResult = $this->imageCommit($nodeSession->{NODE_SESSION_ID}, 'existed');
                if (!$imageResult['ok']) Reply::finish(false, 'Qemu Commit Failed. {data}', ['data' => $imageResult['error']]);
                Reply::finish(true, 'success', ['name' => '']);
            } else if ($type == 'snapshot' || $type == 'new') {
                $imageQemu = explode('-', $node_image);
                if (count($imageQemu) < 1) Reply::finish(false, ERROR_FORMAT, ['data' => 'Qemu Image']);

                $newName = $imageQemu[0] . '-' . $deviceName;

                // These two checks are kept here so the user gets the message
                // the screen expects. Neither is the security boundary: the
                // wrapper repeats both, refuses a name that is not a plain
                // slug, and creates nothing outside the addons root.
                if (is_dir('/opt/unetlab/addons/qemu/' . $newName)) Reply::finish(false, 'This Name already exists');
                if ($type == 'new' && !is_dir('/opt/unetlab/addons/qemu/' . $node_image)) {
                    Reply::finish(false, 'Original Folder is not exists');
                }

                $imageResult = $this->imageCommit($nodeSession->{NODE_SESSION_ID}, $type, $newName);
                if (!$imageResult['ok']) Reply::finish(false, 'Qemu Commit Failed. {data}', ['data' => $imageResult['error']]);

                Reply::finish(true, 'success', ['name' => $newName]);
            }
        }
    }

    /**
     * Ask unl_wrapper to do one enumerated thing to this node's disk images.
     *
     * Three values cross the privilege boundary and no more: an integer session
     * id, a type word chosen from a closed set BY THIS METHOD (the branches
     * below append literals, so $type never reaches the command line), and a
     * name that is escaped here and revalidated against
     * ^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$ inside the wrapper. No path is sent,
     * because no path the web layer can name should decide where root writes.
     *
     * @return array ['ok'=>bool,'error'=>string|null,'name'=>string|null,'size'=>int,'files'=>int]
     */
    private function imageCommit($sessionId, $type, $name = null)
    {
        $cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a image-commit -S ' . (int) $sessionId;
        if ($type === 'check') {
            $cmd .= ' --type check';
        } else if ($type === 'existed') {
            $cmd .= ' --type existed';
        } else if ($type === 'snapshot') {
            $cmd .= ' --type snapshot';
        } else if ($type === 'new') {
            $cmd .= ' --type new';
        } else {
            return ['ok' => false, 'error' => 'unsupported commit type', 'name' => null, 'size' => 0, 'files' => 0];
        }
        if ($name !== null) $cmd .= ' --name ' . escapeshellarg($name);

        $o = [];
        exec($cmd, $o, $r);
        foreach ($o as $line) {
            if (strpos($line, 'IMAGE-COMMIT-RESULT ') !== 0) continue;
            $decoded = json_decode(substr($line, strlen('IMAGE-COMMIT-RESULT ')), true);
            if (is_array($decoded) && isset($decoded['ok'])) {
                return $decoded + ['error' => null, 'name' => null, 'size' => 0, 'files' => 0];
            }
        }
        return ['ok' => false, 'error' => 'the wrapper returned no result', 'name' => null, 'size' => 0, 'files' => 0];
    }


    public function read(Request $request)
    {
        Checker::method('post');
        $readResult = $this->mainModel->read([], function($db){
            $db->where(NODE_SESSION_LAB, '=', Auth::user()->{USER_LAB_SESSION});
        });
        return $readResult;
    }

    public function getConsume(Request $request)
    {
        if($request->isMethod('get')) Reply::finish(false, 'ERROR', ['data'=>'Not support Get']);
        $readResult = $this->mainModel->read([], null, true, [
            NODE_SESSION_LAB, 
            NODE_SESSION_CPU, 
            NODE_SESSION_RAM, 
            NODE_SESSION_HDD,
            NODE_SESSION_POD
        ]);
        return $readResult;
    }



}
