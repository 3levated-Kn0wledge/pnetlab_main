<?php
namespace App\Http\Controllers\Admin;

use App\Helpers\Admin\Upgrade;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\Request\Reply;
use Illuminate\Support\Facades\Auth;
use App\Helpers\View\JS;
use Illuminate\Support\Facades\Cookie;
use App\Helpers\Control\Ctrl;
use App\Helpers\DB\Models;
use Illuminate\Support\Facades\Response;

class MainController extends Controller  
{

    function __construct()
    {
        //Load intital variable to page that is not React
        parent::__construct();
        
    }
    
    public function view(Request $request){
        // This used to accept ?relicense=1 and call License::relicense() --
        // a POST to the central server and a write to the user row -- from a
        // GET that is on the read-only allowlist. SameSite=Lax sends the
        // cookie on a top-level GET, so `location = '.../main/view?relicense=1'`
        // from any site performed that write cross-origin: the exact hole the
        // allowlist was written to close. The trigger is DefaultController::
        // relicense(), which is POST-only by the router's default.
        return view('main.main');
    }
}