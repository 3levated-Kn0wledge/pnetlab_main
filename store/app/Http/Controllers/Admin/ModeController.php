<?php
namespace App\Http\Controllers\Admin;

use App\Helpers\Auth\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\Request\Reply;
use App\Helpers\Request\Checker;
use App\Helpers\Control\Ctrl;

/**
 * System mode.
 *
 * There is one mode. This controller used to manage two -- an "online" mode
 * in which accounts were authenticated by authen.pnetlab.com and the box
 * relicensed itself against user.pnetlab.com, and an "offline" mode with
 * local accounts -- plus the switches between them, a "keep alive" that
 * shipped the machine's encrypted UUID upstream in exchange for an alive key,
 * and an "owner" lookup against the same server. Phase 05 removed the online
 * mode and everything that phoned home (docs/OFFLINE-FIRST.md), and with it
 * the offline toggle: turning offline mode off with no online mode to fall
 * back to would lock every account out.
 *
 * What is left is the one setting that is genuinely the box's own: whether
 * the login page asks for a captcha.
 */
class ModeController extends Controller
{

    function __construct()
    {
        //Load intital variable to page that is not React
        parent::__construct();
        $this->viewblade = 'reactjs.reactjs';
        if(!Role::checkRoot()) Reply::finish(false, ERROR_PERMISSION);

    }

    public function getModeData(){
        Checker::method('post');
        Reply::finish(true, 'success', [
            'captcha' => Ctrl::get(CTRL_CAPTCHA, '1'),
        ]);
    }

    public function setOfflineCaptcha(Request $request){
        $captcha = $request->input('captcha', '1');
        Ctrl::set(CTRL_CAPTCHA, $captcha);
        if($captcha == 1){
            $log = 'Captcha for Offline Login is enabled';
        }else{
            $log = 'Captcha for Offline Login is disabled';
        }
        Reply::finish(true, 'success', $log);
    }

    public function view()
    {
        return view($this->viewblade);
    }

}
