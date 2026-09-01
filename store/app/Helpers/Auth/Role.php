<?php 
namespace App\Helpers\Auth;

use App\Helpers\DB\Models;
use App\Helpers\Request\Reply;
use Illuminate\Support\Facades\Auth;

class Role {

    function __construct(){
    }
    /**
     * Is this role value the root role?
     *
     * Two values mean root, and they always have. install/sql/seed-admin.sql
     * writes the string 'admin' for the built-in account, while License.php
     * maps that same 'admin' onto 0 when it provisions an online account -- so
     * 'admin' and 0 are the same thing wearing different clothes, and the
     * column is a `text` holding both.
     *
     * checkRoot() used to test `== 0` alone. On PHP 7 that caught both, because
     * 'admin' == 0 was true: the string was converted to a number, and a
     * non-numeric string converted to 0. PHP 8 compares a number to a
     * non-numeric string AS STRINGS, so 'admin' == 0 became false and the
     * built-in admin silently stopped being root -- every root-only screen in
     * the Laravel UI answering ERROR_PERMISSION to the one account that ships
     * with the product.
     *
     * Spelled out rather than left to the comparison, so it cannot rot again:
     * an arbitrary string is NOT root (which is the bug PHP 7 had in the other
     * direction -- 'anything' == 0 was true, so any non-numeric role was root).
     */
    public static function isRootRole($role){
        if(is_string($role) && strtolower(trim($role)) === 'admin') return true;
        return is_numeric($role) && (int) $role === 0;
    }

    public static function checkRoot(){
        return self::isRootRole(Auth::user()->{USER_ROLE});
    }

    public static function isOffline(){
        return Auth::user()->{USER_OFFLINE} == 1;
    }

    public static function getWorkspace(){
        if(self::checkRoot()){
            $workspace = '/';
        }else{
            $role = Models::get('Admin/User_roles')->read([[[USER_ROLE_ID, '=', Auth::user()->{USER_ROLE}]]]);
            if(!$role['result'] || !isset($role['data'][0])) Reply::finish(false, 'ERROR', ['data'=>'You don have Permission']);
            $workspace = $role['data'][0]->{USER_ROLE_WORKSPACE};
            if(Auth::user()->{USER_WORKSPACE} != null && Auth::user()->{USER_WORKSPACE} != ''){
                $workspace .= Auth::user()->{USER_WORKSPACE};
                $workspace = str_replace('//', '/', $workspace);
            }
        }
        return $workspace;
    }
}

?>