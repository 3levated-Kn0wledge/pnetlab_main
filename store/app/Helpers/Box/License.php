<?php 
namespace App\Helpers\Box;

use App\Helpers\DB\Models;

class License {
    
    public static function get_uuid(){
        return exec("sudo dmidecode --string system-uuid");
    }

    public static function updateUserLicense($user){
        // update license to running enviroiment. fix bug when uploading the license change.
        $userData = Models::get('Admin/Users')->read([[[USER_EMAIL, '=', $user->{USER_EMAIL}]]]);
        if($userData['result'] && isset($userData['data'][0])){
            $user->{USER_LICENSE} = $userData['data'][0]->{USER_LICENSE};
        }
        return $user;
    }

}
