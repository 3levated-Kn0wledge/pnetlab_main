<?php
namespace App\Http\Controllers\Admin;

use App\Helpers\Auth\Role;
use App\Helpers\DB\Models;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\Request\Checker;
use App\Helpers\Request\Reply;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Helpers\Request\Query;
use Illuminate\Queue\RedisQueue;

class LabsController extends Controller  
{
    
    function __construct()
    {
        parent::__construct();
        $this->viewblade = 'reactjs.reactjs';
    }

    public function getDepends(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        // Check lab is exist and not encrpt and get all depend package
        $path = $request->input('path', '');
        if($path == '') Reply::finish(false, ERROR_UNDEFINE, ['data'=>'Lab path']);
        if(!is_file($path)) Reply::finish(false, 'Lab file is not exist');
        $fileContent = file_get_contents($path);
        if(substr($fileContent, 0, 5) != '<?xml') Reply::finish(false, 'You can not upload the lab not owned by you. Please choose another lab');
        $xml = simplexml_load_string($fileContent, 'SimpleXMLElement', LIBXML_PARSEHUGE);
        if (!$xml) Reply::finish(false, 'Lab is wrong format');

        $depends = [];
        $dependParsed = [];
        
        foreach ($xml -> xpath('//lab/topology/nodes/node') as $node_id => $node) {
            if (isset($node -> attributes() -> type) && isset($node -> attributes() -> image)){
                $type = (string) $node -> attributes() -> type;
                $image = (string) $node -> attributes() -> image;
                $tempID = $type.$image;
                if(isset($dependParsed[$tempID])) continue;
                $dependParsed[$tempID] = true;
                switch ($type) {
                    case 'iol':
                        $imagePath = '/opt/unetlab/addons/iol/bin/'. $image;
                        if(is_file($imagePath)){
                            $depends[] = $imagePath;
                        }
                        break;
                    case 'dynamips':
                        $imagePath = '/opt/unetlab/addons/dynamips/'. $image;
                        if(is_file($imagePath)){
                            $depends[] = $imagePath;
                        }
                        break;
                    case 'qemu':
                        $imagePath = '/opt/unetlab/addons/qemu/'. $image;
                        if(is_dir($imagePath)){
                            foreach (array_diff(scandir($imagePath), ['.', '..']) as $filename){
                                // SECURE_PATH, not the old bare secureCmd(): this is a
                                // filesystem path, and the shape it needs is a path.
                                $file = secureCmd($imagePath.'/'.$filename, SECURE_PATH);
                                $depends[] = $file;
                                if(preg_match('/^.+\.qcow2$/', $file)){
                                    // WHAT THIS USED TO BE, AND WHY IT MATTERED
                                    //
                                    //   exec('sudo qemu-img info --backing-chain '.$file.' | grep image', $o, $r)
                                    //
                                    // $file is unescaped, and $image reaching it is the
                                    // `image` attribute of an uploaded lab's XML. The only
                                    // filter was secureCmd(), whose blocklist did not
                                    // contain a backtick, a dollar or a space — so this
                                    // call site was correct only because of a function
                                    // that did not do what its name said, and the command
                                    // it fed was prefixed with sudo.
                                    //
                                    // Two things change. The argv array execs qemu-img
                                    // directly: no shell, so `| grep` is gone too and the
                                    // filtering happens here. And the sudo is gone,
                                    // because reading a qcow2 header needs no privilege —
                                    // measured on the reference host as www-data against
                                    // /opt/unetlab/addons/qemu. install/sudoers.d/pnetlab
                                    // predicted this was the grant's last caller; it was,
                                    // and the grant goes with it.
                                    $raw = [];
                                    $r = $this->captureArgv(
                                        ['qemu-img', 'info', '--backing-chain', '--', $file], $raw);
                                    // `grep image` kept every line with "image" anywhere
                                    // in it, and the loop below indexes off that. Filter
                                    // the same way so the $key == 0 skip still means the
                                    // image's own entry rather than a backing file.
                                    $o = array_values(array_filter($raw, function ($l) {
                                        return strpos($l, 'image') !== false;
                                    }));
                                    foreach($o as $key => $item){
                                        if(preg_match('/^image\:\s+(.+)$/', $item, $match)){
                                            if($key == 0) continue;
                                            if(!is_file($match[1])) continue;
                                            $depends[] = $match[1]; 
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    default:
                        break;
                }

            }
        }

        $depends = array_unique($depends);

        Reply::finish(true, 'Success', $depends);

    }

    /**
     * Run a program and split its output into lines, with no shell involved.
     *
     * proc_open() given an argv ARRAY execs the binary directly, so nothing in
     * the arguments can be read as syntax and no escaping is needed. The
     * parameter is typed `array` because that is the shape the tokenizer sweep
     * in tests/Security/ShellEscapingTest.php can prove is not a shell — see
     * its argv_param fixture. The same helper is
     * Admin/SystemController::capture(); it is not shared because that one is
     * private and returns a blob rather than lines.
     *
     * @param  array $argv    program then arguments
     * @param  array $output  filled with stdout, one line per element
     * @return int            exit status, or 127 if it could not be run
     */
    private function captureArgv(array $argv, array &$output)
    {
        $output = [];
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $pipes = [];
        $proc = @proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) return 127;
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($proc);
        $out = rtrim($out, "\n");
        if ($out !== '') $output = explode("\n", $out);
        return $code;
    }

   

    public function view() 
    {
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        return view($this->viewblade);
    }

    
    public function create() 
    {
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        return view($this->viewblade);
    }
    
    public function editview() 
    {
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        return view($this->viewblade);
    }

    public function store(Request $request) 
    {
        // No ?relicense=1 here any more; see Admin\MainController::view().
        if(!Role::checkRoot()) return redirect('/');
        return view($this->viewblade);
    }
    
    public function workbook() 
    {
        return view($this->viewblade);
    }

    public function workbookview() 
    {
        return view($this->viewblade);
    }

    public function terminal() 
    {
        return view($this->viewblade);
    }
    
    public function uploader(Request $request){
        /*
         * Upload lab_image
         */
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $action = $request->input('action', '');
        if($action == '') Reply::finish(false, ERROR_UNDEFINE, ['data' => 'Action']);

        // This is the one action on config/readonly_actions.php that is not
        // read-only end to end, and the reason it is listed there at all is
        // 'Read': the lab-image previews render
        //     <img src="/store/public/admin/labs/uploader?action=Read&file=...">
        // (components/input/InputImg.js:37, components/func/FuncUploadModal.js:207),
        // and an <img> is a GET. Upload, Delete and History all change state on
        // the store, so they are closed here with the same guard the rest of the
        // application uses. Deleting these two lines reopens a GET-reachable
        // mutation with the config file still looking correct, which is why
        // tests/Security/CsrfTest.php asserts they are present.
        if($action !== 'Read') Checker::method('post');

        switch ($action) {
    
            case 'Upload':{
                
                $data = $request->all();
                $result = Query::center(APP_CENTER.'/api/boxs/labs/uploader', 'post', $data, ['dataType'=>'']);
                return $result;
                
            }
    
            case 'Read':{
                $data = $request->all();
                $result = Query::center(APP_CENTER.'/api/boxs/labs/uploader', 'post', $data, ['continue'=>true]);
                $res = response($result)->header('Content-Type', curl_getinfo(Query::$ch, CURLINFO_CONTENT_TYPE ));
                curl_close(Query::$ch);
                
                return $res;
            }
    
            case 'Delete':{
                $data = $request->all();
                return Query::center(APP_CENTER.'/api/boxs/labs/uploader', 'post', $data, ['dataType'=>'']);
            }
    
            case 'History':{
                $data = $request->all();
                $result = Query::center(APP_CENTER.'/api/boxs/labs/uploader', 'post', $data, ['dataType'=>'']);
                return $result;
            }
            
    
            default:
                Reply::finish(false, 'Error', 'No Permission');
                break;
        }
    
    }


    public function addGetId(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/addGetId', 'post', $data, ['dataType'=>'']);
        return $result;
    }


    public function getOwnLabs(){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $result = Query::center(APP_CENTER.'/api/boxs/labs/getOwnLabs', 'post', [], ['dataType'=>'']);
        return $result;
    }

    public function drop(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/drop', 'post',  $data, ['dataType'=>'']);
        return $result;
    }

    public function edit(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/edit', 'post',  $data, ['dataType'=>'']);
        return $result;
    }

    public function read(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/read', 'post',  $data, ['dataType'=>'']);
        return $result;
    }

    public function mapping(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/mapping', 'post',  $data, ['dataType'=>'']);
        return $result;
    }

    public function public(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/public', 'post',  $data, ['dataType'=>'']);
        return $result;
    }

    public function unpublic(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/unpublic', 'post',  $data, ['dataType'=>'']);
        return $result;
    }

    public function sellable(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/sellable', 'post',  $data, ['dataType'=>'']);
        return $result;
    }

    public function getUserAgreement(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $data = $request->all();
        $result = Query::center(APP_CENTER.'/api/boxs/labs/userAgreement', 'post',  $data, ['dataType'=>'']);
        return $result;
    }

    public function search(Request $request){

        $workspace = Role::getWorkspace();
        $search = strtolower($request->input('search', ''));
        $labs = \scanDirFiles(BASE_LAB.$workspace);

        $labs = array_map(function($item){
            return \Illuminate\Support\Str::replaceFirst(BASE_LAB, '', $item);
        }, $labs);

        if($search != ''){
            $labs = array_filter($labs, function($item)use($search){
                if(strpos(strtolower($item), $search) !== false){
                    return true;
                }else{
                    return false;
                }
            });
            $labs = array_values($labs);
        }
        Reply::finish(true, 'success', $labs);
    }

   

    
    
}
