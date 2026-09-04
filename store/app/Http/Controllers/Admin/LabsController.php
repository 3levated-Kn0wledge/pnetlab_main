<?php
namespace App\Http\Controllers\Admin;

use App\Helpers\Auth\Role;
use App\Helpers\DB\Models;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\Request\Checker;
use App\Helpers\Request\Reply;

/**
 * The lab pages that are the box's own: the workbook, its read-only view,
 * the terminal, and the lab search the open-lab dialog uses.
 *
 * This controller used to be mostly the box's half of the upstream lab
 * marketplace -- "Sell Your Labs" and "Download Labs" -- sixteen methods that
 * each posted the caller's licence to user.pnetlab.com and relayed whatever
 * came back, plus an image uploader that stored the pictures on
 * uploader.pnetlab.com and served them back through this box, and a
 * dependency scanner for the listing wizard. Phase 05 removed the
 * marketplace (docs/OFFLINE-FIRST.md): a lab is a file under
 * /opt/unetlab/labs, and moving one between boxes is a copy.
 */
class LabsController extends Controller
{
    
    function __construct()
    {
        parent::__construct();
        $this->viewblade = 'reactjs.reactjs';
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
