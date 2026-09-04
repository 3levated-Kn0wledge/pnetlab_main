<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Admin\Upgrade;
use App\Helpers\Packages\PackageClient;
use App\Helpers\Auth\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\Request\Reply;
use App\Helpers\System\Wrapper;
use Illuminate\Support\Facades\Auth;
use App\Helpers\View\JS;
use Illuminate\Support\Facades\Cookie;
use App\Helpers\Auth\AuthCookie;
use App\Helpers\Control\Ctrl;
use App\Helpers\DB\Models;
use Illuminate\Support\Facades\Response;

class DefaultController extends Controller
{

    function __construct()
    {
        //Load intital variable to page that is not React
        parent::__construct();
    }

    function initial()
    {

        Auth::check();
        $server = [];
        $user = Auth::user();
        if (!$user) $user = (object) [];

        $common = [
            'APP_SLOGAN' => APP_SLOGAN,
            'APP_TITLE' => APP_TITLE,
            'APP_NAME' => APP_NAME,
            CTRL_DOCKER_WIRESHARK => Ctrl::get(CTRL_DOCKER_WIRESHARK, '0'),
        ];


        JS::make_var($server + [
            'user' => [
                USER_USERNAME => isset($user->{USER_USERNAME}) ? $user->{USER_USERNAME} : '',
                USER_EMAIL => isset($user->{USER_EMAIL}) ? $user->{USER_EMAIL} : '',
                USER_ROLE => isset($user->{USER_ROLE}) ? $user->{USER_ROLE} : '',
                USER_HTML5 => isset($user->{USER_HTML5}) ? $user->{USER_HTML5} : '',
                USER_OFFLINE => isset($user->{USER_OFFLINE}) ? $user->{USER_OFFLINE} : '',
                USER_POD => isset($user->{USER_POD}) ? $user->{USER_POD} : '',
            ],

            'common' => $common,

        ], 'server', $result);



        $response = Response::make($result, 200);
        $response->header('Content-Type', 'application/javascript');
        return $response;
    }

    function language(Request $req)
    {
        $lang = str_replace('.', '', $req->input('lang', ''));
        $langRes = loadLanguage($lang);
        Reply::finish(true, 'success', $langRes);
    }

    function refreshToken()
    {
        $cookie = Cookie::get('token', '');
        AuthCookie::issue($cookie, 60);
        Models::get('Admin/Users')->edit([
            DATA_KEY => [[[USER_USERNAME, '=', Auth::user()->{USER_USERNAME}]]],
            DATA_EDITOR => [USER_ONLINE_TIME => time(), USER_SESSION => time() + SESSION],
        ]);
        Reply::finish(true, 'success', '');
    }

    public function folder(Request $req)
    {
        try {
            $folder = $req->input('folder', '');
            if ($folder == '') Reply::finish(false, 'Folder is not found');
            $files = array_filter(glob($folder . '/*'), 'is_file');
            $folders = glob($folder . '/*', GLOB_ONLYDIR);

            Reply::finish(true, 'Success', [
                'files' => array_values($files),
                'folders' => array_values($folders),
            ]);
        } catch (\ErrorException $e) {
            Reply::finish(false, $e->getMessage());
        }
    }

    public function getVersion()
    {
        // What the dialog shows. When there is no update channel -- no
        // repository configured, one that did not answer, or one that
        // publishes no appliance record -- "latest" is the current version
        // and the note says why, so the dialog is informative rather than an
        // error toast. That is the default state of a box, not a failure.
        $version = Ctrl::get(CTRL_VERSION, '1.0.0');
        $latest = Upgrade::checkUpgrade();
        if (!$latest['result'] || !isset($latest['data'])) {
            $reason = isset($latest['data']['data']) && is_string($latest['data']['data']) ? $latest['data']['data'] : 'Can not check for updates';
            Reply::finish(true, 'success', ['version' => $version, 'latest' => [
                UPGRADE_VERSION => $version,
                UPGRADE_NOTE => $reason,
            ]]);
        }
        Reply::finish(true, 'success', ['version' => $version, 'latest' => [
            UPGRADE_VERSION => $latest['data'][UPGRADE_VERSION],
            UPGRADE_NOTE => $latest['data'][UPGRADE_NOTE],
        ]]);
    }

    /**
     * Start the upgrade worker and return at once.
     *
     * This used to be `sudo ps -aux | grep "artisan upgrade"` to see whether
     * one was running, then `sudo php .../artisan upgrade now &` -- the last
     * two callers of the root-equivalent php and ps grants, both of which
     * are gone from the policy with this. The worker runs as www-data, like
     * the device-package worker: everything it does is unprivileged except
     * the one `sudo unl_wrapper -a package` call, and it takes a lock so a
     * second click cannot start a second applier.
     */
    public function upgrade()
    {
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        if(!PackageClient::ensureDirectories()) Reply::finish(false, 'Cannot create '. PACKAGE_INCOMING_DIR);
        $processModel = Models::get('Admin/Process');
        $proccessId = 'upgrade';
        // The row first, synchronously: the dialog polls a second later and
        // expects it to be there. The worker drops it when it finishes.
        if (!$processModel->is_exist([[[PROCESS_ID, '=', $proccessId]]])) {
            $processResult = $processModel->add([[PROCESS_ID => $proccessId, PROCESS_DTOTAL => 0, PROCESS_DNOW => 0]]);
            if (!$processResult['result']) return $processResult;
        }
        $logfile = PACKAGE_LOG_DIR . '/upgrade.log';
        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(base_path('artisan'))
            . ' upgrade now >> ' . escapeshellarg($logfile) . ' 2>&1 &';
        exec($cmd);
        Reply::finish(true, 'success');
    }

    public function upgrading()
    {
        //Check upgrade proccess
        $processModel = Models::get('Admin/Process');
        $result = $processModel->read([[[PROCESS_ID, '=', 'upgrade']]]);
        if (!$result['result']) return;
        if (!isset($result['data'][0])) {
            Reply::finish(true, 'Success', [true, Ctrl::get(CTRL_VERSION, '1.0.0')]);
        } else {
            $percent = 0;
            $proccess = $result['data'][0];
            if ($proccess->{PROCESS_DTOTAL} > 0) {
                $percent = floor($proccess->{PROCESS_DNOW} * 100 / $proccess->{PROCESS_DTOTAL});
            }

            Reply::finish(true, 'Success', [false, $percent]);
        }
    }

    public function changeConsole(Request $request)
    {
        

        $html = $request->input('html', 1);
        /**
         *In case success create an account in local db then run origin login proccess of evelabbox
         */
        $userModel = Models::get('Admin/Users');
        $result = $userModel->edit([
            DATA_KEY => [[[USER_USERNAME, '=', Auth::user()->{USER_USERNAME}]]],
            DATA_EDITOR => [USER_HTML5 => $html],
        ]);

        return $result;


        // require_once(BASE_DIR . '/html/includes/api_authentication.php');

        // $db = \checkDatabase();
        // $html5_db = \html5_checkDatabase();
        // $cookie = \genUuid();

        // $p = [
        //     'username' => Auth::user()->{USER_USERNAME},
        //     'password' => LOCAL_PASS,
        //     'html5' => $html,
        //     'pod' => Auth::user()->{USER_POD},
        //     'role' => Auth::user()->{USER_ROLE},
        // ];

        // $output = \apiLogin($db, $html5_db, $p, $cookie);
        // if ($output['code'] == 200) {

        //     Cookie::queue(Cookie::make('token', $cookie, 60, '/', $_SERVER['SERVER_NAME']));

        //     Reply::finish(true, 'success');
        // }
        // Reply::finish(false, 'ERROR', ['data'=>$output['message']]);

    }

    public function updateGuacToken()
    {
        \updateUserToken(Auth::user()->{USER_USERNAME}, \unl_guacamole_secret(Auth::user()->{USER_USERNAME}), Auth::user()->{USER_POD});
        Reply::finish(true, 'success', '');
    }



    /*
     * Compute a dynamips idle-PC value for one template and one image.
     *
     * WHAT THIS USED TO DO, AND WHY IT IS WORTH SPELLING OUT
     *
     * It ran, under sudo:
     *
     *     sudo /opt/unetlab/html/store/app/Console/Commands/idlepc
     *          --option=<the template's dynamips_options> -f <image path>
     *
     * `idlepc` was a 9.4 MB stripped PyInstaller bundle committed to this
     * repository with no source, no build recipe and no licence. Its archive
     * was unpacked and the entry script read. Before it computed anything it
     * ran:
     *
     *     ssh-keygen -t rsa -N '' -f /root/.ssh/id_rsa_dy
     *     cat /root/.ssh/id_rsa_dy.pub >> /root/.ssh/authorized_keys
     *
     * and then paramiko-connected to root@127.0.0.1 with that key. So pressing
     * this button left a standing passwordless root SSH key on the appliance —
     * the same key this fork had already deleted from docker_wrapper by giving
     * it a PTY instead of a loopback SSH hop.
     *
     * It did all of that for a terminal. The computation is dynamips' own, and
     * the blob ran dynamips with no -T, which puts the console on stdin. With
     * `-T <port>` the same Ctrl-] monitor console is reachable over a plain TCP
     * socket, so the replacement needs no terminal, no SSH and no key.
     *
     * WHAT CROSSES NOW: two names. The option string does not. This method used
     * to read `dynamips_options` out of the template and hand it over sudo;
     * template option strings are argument injection by design (item 4 of
     * docs/HANDOVER.md), and on this path the far side is root. The wrapper
     * action reads the option string from the template file itself and accepts
     * an allowlist of options, so an operator who can edit a template can no
     * longer choose a root process's argv through this button.
     *
     * NOT PROVEN AGAINST AN IMAGE. The calibration needs a real Cisco IOS image
     * for dynamips and this project deliberately carries none — the same
     * position as iol_wrapper. Everything up to that point is covered by
     * tests/Security/IdlePcTest.php; the console conversation is derived from
     * dynamips' own source and has not been run against an image.
     * docs/LICENSING.md section 3 says the same thing at greater length.
     */
    public function idlepc(Request $req){
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);
        // The wrapper bounds itself — a connect timeout, a boot timeout and a
        // calibration timeout — and this has to outlast the sum of them, or PHP
        // kills the request while dynamips is still running. The action's own
        // shutdown guard reaps it in that case; this is here so the ordinary
        // path does not depend on the guard.
        set_time_limit(300);

        $ios = $req->input('ios', '');
        $template = $req->input('template', '');

        if(!is_string($ios) || !is_string($template) || $ios === '' || $template === ''){
            Reply::finish(false, 'Data is empty');
        }

        // No secureCmd(). There is no shell on this path any more, and
        // secureCmd() is a blocklist that passes backticks, $( ), spaces and
        // quotes — it was never what made this safe, and leaving it here would
        // imply it was. Wrapper::idlepc() checks both names against an anchored
        // allowlist pattern and UnlIdlePc checks them again, as root.
        $result = Wrapper::idlepc($template, $ios);
        if(empty($result['ok']) || !isset($result['idlepc']) || $result['idlepc'] === null){
            Reply::finish(false, 'Can not get Idle-PC: {data}',
                ['data' => isset($result['error']) && $result['error'] !== null
                    ? $result['error'] : 'the wrapper returned no value']);
        }
        $idlepc = $result['idlepc'];

        // Built from the name the PRIVILEGED side accepted and echoed back,
        // never from the request field. If those two could disagree, the
        // wrapper's is the one that decided which template dynamips was
        // configured from, so it is the one this write has to follow.
        $tempFolder = '/opt/unetlab/html/templates';
        $file = $tempFolder . '/' . $result['template'] . '.yml';
        if(!is_file($file)){
            Reply::finish(false, '{data} not found', ['data' => $file]);
        }
        $content = file_get_contents($file);
        $p = yaml_parse_file($file);

        if(!isset($p['idlepc'])){
            $content = preg_replace('/^name\s?:\s?(.+)$/m', 'name: $1\nidlepc: "'.$idlepc.'"', $content);
        }else{
            $content = preg_replace('/^idlepc\s?:\s?(.+)$/m', 'idlepc: "'.$idlepc.'"', $content);
        }

        // This chown looks like cargo — the very next line writes the file — and
        // it is not. $file is /opt/unetlab/html/templates/<template>.yml, and on
        // a deployed box that tree is root:root 0644; checked on the reference
        // install rather than assumed. Without the chown, file_put_contents()
        // runs as www-data and silently writes nothing, which is why it was
        // there.
        //
        // What was wrong was its shape: `sudo chown www-data:www-data ` . $file
        // where $file is built from $req->input('template') by way of
        // secureCmd(), a blocklist that passes backticks, $( ), spaces and
        // quotes — the call site group 8 of the shell-escaping baseline names
        // by hand. The templates tree is one of the wrapper's scopes, so the
        // repair is now a scope word and the path stays on the far side of the
        // boundary.
        Wrapper::fixperms('templates');
        clearstatcache(true, $file);

        if (!is_writable($file)) {
            Reply::finish(false, 'Cannot write {data}', ['data' => basename($file)]);
        }
        file_put_contents($file, $content);

        Reply::finish(true, 'idlepc_success_alert', ['idlepc'=>$idlepc, 'file' => basename($file)]);

    }
}
