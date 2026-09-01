<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Auth\Role;
use App\Helpers\Control\Ctrl;
use App\Helpers\Request\Checker;
use App\Helpers\Request\Query;
use App\Helpers\Request\Reply;
use App\Helpers\System\Wrapper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\Resource;

class SystemController extends Controller
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

    public function getProxy(){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        Reply::finish(true, 'success', Query::getProxy());
    }

    public function setProxy(Request $request){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        $proxy_ip = $request->input('proxy_ip', null);
        $proxy_port = $request->input('proxy_port', null);
        $proxy_username = $request->input('proxy_username', null);
        $proxy_password = $request->input('proxy_password', null);
       
        // The wrapper validates each of these separately and says which one it
        // refused. That answer is worth relaying: the old code reported success
        // unconditionally, including for the settings it silently mangled into
        // a shell command.
        $result = Query::setProxy([
            'proxy_ip' => $proxy_ip,
            'proxy_port' => $proxy_port,
            'proxy_username' => $proxy_username,
            'proxy_password' => $proxy_password,
        ]);
        if (empty($result['ok'])) {
            Reply::finish(false, 'ERROR', ['data' => $result['error']]);
        }

        Reply::finish(true, 'success');

    }


    public function update(Request $request){
        $datas = $request->all();
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        foreach($datas as $key=>$value){
            if(is_array($value)){
                $query = Ctrl::set($key, $value, true);
            }else{
                $query = Ctrl::set($key, $value);
            }
            if(!$query['result']) return $query;
        }
        Reply::finish(true, 'success');
    }

    public function get(){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        $returnData = [
            CTRL_DOCKER_WIRESHARK => Ctrl::get(CTRL_DOCKER_WIRESHARK, '0'),
            CTRL_DEFAULT_CONSOLE => Ctrl::get(CTRL_DEFAULT_CONSOLE, ''),
            CTRL_DEFAULT_LANG => Ctrl::get(CTRL_DEFAULT_LANG, ''),
        ];
        Reply::finish(true, 'success', $returnData);
    }

    public function getShareFolder(){
        $shareFolder = Ctrl::get(CTRL_SHARED, [], true);
        $permission = Ctrl::get(CTRL_SHARED_PERMISSION, (object)[], true);
        return Reply::finish(true, 'Success', [CTRL_SHARED => $shareFolder, CTRL_SHARED_PERMISSION => $permission]);
    }

    public function shutdown(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo shutdown -h now > /dev/null 2>&1 &');
        Reply::finish(true, 'success');
    }

    public function reboot(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo reboot > /dev/null 2>&1 &');
        Reply::finish(true, 'success');
    }

    public function fixPermission(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo /opt/unetlab/wrappers/unl_wrapper -a fixpermissions');

        // `-a fixpermissions` chmods the addons tree but does not hand it to
        // the web user, and everything below this line depends on it being the
        // web user's: on a deployed box /opt/unetlab/addons is root:root 0755,
        // so www-data can neither chmod the keygen nor write iourc. One scope
        // word fixes that, which is what this button is for.
        Wrapper::fixperms('addons');

        $this->refreshIolLicense();

        Reply::finish(true, 'success');
    }

    /**
     * Regenerate /opt/unetlab/addons/iol/bin/iourc from the IOL keygen.
     *
     * WHAT THIS REPLACES
     *
     *     exec('sudo chmod 755 /opt/unetlab/addons/iol/bin/CiscoIOUKeygen.py');
     *     exec('license=$(python .../CiscoIOUKeygen.py | grep "=" | grep -v "hostname")
     *           && sudo echo -e "[license]\n$license" > .../iourc');
     *
     * The second line is the same non-privileged-write bug already recorded in
     * includes/cli.php: the sudo applies to `echo`, while the REDIRECTION runs
     * in the calling shell as www-data. Nothing about that write was ever
     * privileged. It worked on an appliance whose addons tree already belonged
     * to www-data, and silently wrote nothing anywhere else.
     *
     * So this does not move behind the wrapper — it is done here, unprivileged,
     * with no shell. Handing the job to root would be strictly worse than the
     * bug: CiscoIOUKeygen.py lives in a directory the web user owns, so "run
     * the keygen as root" reads "run whatever www-data put there, as root". The
     * keygen keeps running as www-data, exactly as `python ...` in the pipeline
     * above always did.
     *
     * The pipeline is gone with it. `grep "=" | grep -v "hostname"` is two
     * substring tests, and doing them in PHP costs a shell.
     *
     * @return bool false when there is no IOL addon installed, which is the
     *              normal state of a box without licensed images.
     */
    private function refreshIolLicense()
    {
        $bin = '/opt/unetlab/addons/iol/bin';
        $keygen = $bin . '/CiscoIOUKeygen.py';
        if (!is_file($keygen) || is_link($keygen)) return false;

        @chmod($keygen, 0755);

        $python = null;
        foreach (['/usr/bin/python3', '/usr/bin/python2', '/usr/bin/python'] as $candidate) {
            if (is_executable($candidate)) { $python = $candidate; break; }
        }
        if ($python === null) return false;

        $output = $this->capture([$python, $keygen]);
        if ($output === null) return false;

        $license = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            if (strpos($line, 'hostname') !== false) continue;
            $license[] = $line;
        }
        if (!count($license)) return false;

        return file_put_contents($bin . '/iourc',
            "[license]\n" . implode("\n", $license) . "\n") !== false;
    }

    /**
     * Run a program and return its stdout, or null.
     *
     * proc_open() with an argv ARRAY execs the binary directly: no shell is
     * started, so nothing in the arguments can be parsed as syntax. The
     * parameter is typed `array` because that is the shape the tokenizer sweep
     * in tests/Security/ShellEscapingTest.php can prove is not a shell.
     */
    private function capture(array $argv)
    {
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) return null;
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return $code === 0 ? $out : null;
    }

    public function stopAllNodes(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo /opt/unetlab/wrappers/unl_wrapper -a stopall');
        Reply::finish(true, 'success');
    }

    public function restartWebService(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo service apache2 restart');
        Reply::finish(true, 'success');
    }

    public function restartDBService(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo service mysql restart');
        Reply::finish(true, 'success');
    }

    public function restartHTMLConsoleService(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo service guacd restart');
        exec('sudo service tomcat8 restart');
        Reply::finish(true, 'success');
    }

    public function restartDockerService(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo service docker restart');
        Reply::finish(true, 'success');
    }

    public function restartPnetNatService(){
        Checker::method('post');
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION, '');
        exec('sudo service pnetnat restart');
        Reply::finish(true, 'success');
    }



}