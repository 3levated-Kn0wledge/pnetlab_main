<?php

namespace App\Http\Controllers\Auth;



use App\Http\Controllers\Controller;
use App\Helpers\Auth\AuthenticatesUsers;
use App\Helpers\Captcha\Captcha;
use App\Helpers\Request\Reply;
use Illuminate\Http\Request;
use App\Helpers\Control\Ctrl;
use App\Helpers\DB\Models;
use Exception;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Cookie;
use App\Helpers\Auth\AuthCookie;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->middleware('guest')->except('logout');
        $this->authenModel = resolve('Models')->getModel('Auth/Authentication');
        $this->viewblade = 'reactjs.reactjs';
    }

    public function captcha(Request $request)
    {
        $id = $request->input('id', 'global');
        $captcha = Captcha::createCaptcha($id);
        Reply::finish(true, 'Success', $captcha);
    }

    /**
     * 
     * Login for offline account
     */
    public function login(Request $request)
    {
        try {
            $username = $request->input('username', '');
            $password = $request->input('password', '');
            $html = $request->input('html', '0');
            $captcha = $request->input('captcha', null);

            if(Ctrl::get(CTRL_CAPTCHA, true)){
                if($captcha == null || !Captcha::verifyCaptcha($captcha)){
                    throw new Exception('Captcha is Wrong');
                }
            }

            $this->apiLogin($username, $password, $html, 1); 
        } catch (\Exception $e) {
            Reply::finish(false, 'ERROR', ['data' => $e->getMessage()]);
        }
        Reply::finish(true, 'success');
        
    }

    private function apiLogin($username, $password, $html, $offline=0)
    {

        $html5_db = \html5_checkDatabase();
        $cookie = \genUuid();

        $ip = $_SERVER['REMOTE_ADDR'];
        $session = time() + SESSION;
        $userModel = Models::get('Admin/Users');
        if ($username == '' || $password == '') throw new Exception('Username or Password empty');
        $user = Models::get('Admin/Users')->read([[[USER_USERNAME, '=', $username], [USER_OFFLINE, '=', $offline] ]]);
        if (!$user['result'] || !isset($user['data'][0])) throw new Exception('Username is not existed');
        $user = $user['data'][0];

        if (!\unl_password_verify($password, $user->{USER_PASSWORD})) {
            throw new Exception('Password is Wrong');
        }

        // Upgrade the stored hash in place. Existing installations hold unsalted
        // sha256 digests and users cannot be asked to reset a password they
        // cannot log in to change, so the migration happens on the one occasion
        // the plaintext is legitimately available.
        if (\unl_password_needs_rehash($user->{USER_PASSWORD})) {
            $userModel->edit([
                DATA_KEY => [[[USER_POD, '=', $user->{USER_POD}]]],
                DATA_EDITOR => [USER_PASSWORD => \unl_password_hash($password)],
            ]);
        }

        // Guacamole is given a per-installation derived credential, not anything
        // related to the user's password. See unl_guacamole_secret().
        $hashPass = \unl_guacamole_secret($username);

        $userModel->edit([
            DATA_KEY => [[[USER_POD, '=', $user->{USER_POD}]]],
            DATA_EDITOR => [
                USER_IP => $ip,
                USER_COOKIE => $cookie,
                USER_SESSION => $session,
                USER_HTML5 => $html,
            ]
        ]);

        $pod = $user->{USER_POD};
		$html5Pod = $pod + 1000;
		
		$query = "REPLACE INTO guacamole_entity (entity_id, name, type) VALUES (:entity_id, :name, 'USER')";
		$statement = $html5_db->prepare($query);
        $statement->execute(['entity_id'=> $html5Pod, 'name'=>$username]);
		
        $query = "REPLACE INTO guacamole_user (user_id, entity_id, password_hash, password_date) VALUES (:user_id, :entity_id, UNHEX(SHA2(:hash, 256)), NOW())";
		$statement = $html5_db->prepare($query);
        $statement->execute(['user_id'=> $html5Pod, 'entity_id'=> $html5Pod, 'hash'=> $hashPass]);
		
        $role = 'READ';
        // Same root test as Role::checkRoot(), and the same PHP 8 trap: the
        // built-in admin's role is the string 'admin', which stopped being == 0
        // in PHP 8. Left as `== 0` this silently downgraded the admin's
        // Guacamole permission from UPDATE to READ.
        if (\App\Helpers\Auth\Role::isRootRole($user->{USER_ROLE})) $role = 'UPDATE';
        $query = "REPLACE INTO guacamole_user_permission (entity_id, affected_user_id, permission) VALUES ( :entity_id , :affected_user_id , :permission ) ;";
        $statement = $html5_db->prepare($query);
        $statement->execute([
			'entity_id' => $html5Pod,
			'affected_user_id' => $html5Pod,
			'permission' => $role
		]);

        updateUserToken($username, $hashPass, $pod);
        
        AuthCookie::issue($cookie, 60);

        return true;
    }

    public function manager(Request $request)
    {
        // Every login is an offline login now. The online path -- a redirect to
        // authen.pnetlab.com and a return leg carrying a licence -- was removed
        // in Phase 05 (docs/OFFLINE-FIRST.md). A box whose offline mode has
        // not been switched on yet is sent through initialOffline(), which
        // switches it on and seeds the admin account; everything else goes to
        // the login page.
        $link = urlencode($request->input('link', '/'));
        $error = $request->input('error', '');
        $success = $request->input('success', '');

        if (Ctrl::get(CTRL_OFFLINE_MODE, 0) != 1) return redirect('/auth/login/initialOffline');
        return redirect('/auth/login/offline?link=' . $link . '&error=' . $error . '&success=' . $success);
    }

    public function initialOffline()
    {
        // First contact with a box that has never been logged into: switch
        // offline mode on and make sure there is an admin account to log in
        // with. Idempotent -- a box that is already in offline mode is simply
        // sent to the login page. This is reachable without authentication
        // because it has to be: nobody can log in before it has run. It
        // creates nothing on a box that already has an admin, and it never
        // resets an existing admin's password.
        $userModel = Models::get('Admin/Users');
        if (Ctrl::get(CTRL_OFFLINE_MODE, 0) == 1) return redirect('/auth/login/offline');

        Ctrl::set(CTRL_OFFLINE_MODE, 1);
        Ctrl::set(CTRL_DEFAULT_MODE, 'offline');
        if ($userModel->is_exist([[ [USER_OFFLINE, '=', 1], [USER_ROLE, '=', 0] ]])) {
            return redirect('/auth/login/offline?success=OFFLINE mode is turned on. Using OFFLINE Accounts to login');
        } else if($userModel->is_exist([[[USER_USERNAME, '=', 'admin']]])) {

            $result = $userModel->edit([
            DATA_KEY => [[[USER_USERNAME, '=', 'admin']]],
            DATA_EDITOR => [
                USER_ROLE => '0',
                USER_OFFLINE => '1',
                USER_STATUS => USER_STATUS_ACTIVE,
                USER_ONLINE_TIME => time(),
                USER_ACTIVE_TIME => null,
                USER_EXPIRED_TIME => null,
            ]]);

            if (!$result['result']) return $result;
            return redirect('/auth/login/offline?success=OFFLINE mode is turned on successfully. The Account with name "admin" has been set as Admin');

        }else{

            $result = $userModel->add([[
                USER_USERNAME => 'admin',
                USER_PASSWORD => \unl_password_hash(LOCAL_PASS),
                USER_ROLE => '0',
                USER_OFFLINE => '1',
                USER_ONLINE_TIME => time(),
                USER_STATUS => USER_STATUS_ACTIVE
            ]]);

            if (!$result['result']) return $result;
            return redirect('/auth/login/offline?success=OFFLINE mode is turned on successfully. Default account to login is admin/'.LOCAL_PASS.'. For security reasons you should change it');
        }
    }

    public function offline()
    {
        // offline login page
        if (Ctrl::get(CTRL_OFFLINE_MODE, 0) != 1) return redirect('/auth/login/initialOffline');
        $isCaptcha = Ctrl::get(CTRL_CAPTCHA, '1');
        $version = Ctrl::get(CTRL_VERSION, '4.0.0');
        $console = Ctrl::get(CTRL_DEFAULT_CONSOLE, '');

        return view($this->viewblade, ['server'=>['captcha' => $isCaptcha, 'version' => $version, 'console' => $console]]);
    }
}
