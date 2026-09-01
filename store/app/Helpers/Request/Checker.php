<?php 
namespace App\Helpers\Request;

use Exception;
use Illuminate\Support\Facades\Validator;

class Checker {
    
    public static $REGEX = array(
        'IPv4' => "/^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$/",
        'Netmask' => "/^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/",
        'Email' => "/^[\w\d\.\-\+\_]+\@[\w\d\.\-\+\_]+$/",
        'Number' => "/^[\d\.\-]+$/",
        'Word' => "/^[\w\x{00C0}-\x{00FF}\x{1EA0}-\x{1EFF}]+$/u",
        'Name' => "/^[\w\s\.\_\-\x{00C0}-\x{00FF}\x{1EA0}-\x{1EFF}]+$/u",
        'Domain' => "/^[a-z\.A-Z\-0-9\_]+\.[a-zA-Z0-9\-]+$/",
        'Phone' => "/^[\d\+\-\s]+$/",
        'DateTime' => "/^[\d\-\s\:]+$/",
        'Varchar'=>"/^[\\\\\/\|\#\@\+\…\,\.\{\}\[\]\(\)\~\w\s\–\_\-\:\%\$\^\&\*\;\=\?\!\x{00C0}-\x{00FF}\x{1EA0}-\x{1EFF}]+$/u",
        'Path' => "/^[\w\s\_\-\:\/\.\x{00C0}-\x{00FF}\x{1EA0}-\x{1EFF}]+$/u",
        'Logic' => "/^[\<\>\=\w\!]+$/u",
        'SQL' => '/^[^\"\'\`]+$/u',
    );
    
    public static $log = '';

    public static function validate($data, $regexType = null){
//         try{
            
            if($regexType == 'Pass'){
                return true;
            }
            
            if (is_array($data)) {
                $checkResult = true;
                foreach ($data as $value) {
                    
                    if(!self::validate($value, $regexType)){
                        return false;
                    };
                    
                }
                return $checkResult;
            } else {
                self::$log = $regexType.': '.$data;
                
                if($regexType == 'Json'){
                    if ($data == "") return true;
                    json_decode($data);
                    return (json_last_error() == JSON_ERROR_NONE);
                }
                
                if(isset(self::$REGEX[$regexType])){
                    if (preg_match(self::$REGEX[$regexType], $data) || $data == "") {
                        return true;
                    } else {
                        return false;
                    }
                }
                
                $validator = Validator::make(['data'=>$data], ['data'=>$regexType]);
                if($validator->passes()){
                    return true;
                }else{
                    self::$log = $validator->errors()->all()[0];
                    return false;
                } 
                
                
            }
//         }catch (Exception $e){
//             return false;
//         }
    }

    public static function method($method){
        $request = request();
        if(!$request->isMethod($method)) Reply::finish(false, 'ERROR', ['data' => 'Not Support']);
        return true;
    }

    /**
     * The verb guard for the three dynamic dispatchers in routes/web.php.
     *
     * Those routes take the controller AND the method out of the URL and accept
     * both verbs, so 157 controller methods were reachable by GET. Only 39 of
     * them called method('post') above. SameSite=Lax does not help: it withholds
     * the token cookie from a cross-site POST but sends it on a top-level GET
     * navigation, and VerifyCsrfToken only verifies POST/PUT/PATCH/DELETE. So
     * every unguarded method was a one-line <a href> CSRF until this existed.
     *
     * The fix deliberately extends method('post') rather than introducing a
     * second mechanism: this is the same check, the same FinishException, and
     * the same {result:false, data:'Not Support'} body the SPA's error_handle()
     * already understands -- only hoisted to the router, where it is applied by
     * default instead of per method. Doing it the other way round (adding
     * method('post') to each of the 118 unguarded methods) would have been ~118
     * edits, would have had to touch a controller another agent owns, and would
     * be default-allow: the 119th method to be written gets it wrong.
     *
     * @param string $group      'admin', 'user' or 'notice'
     * @param string $controller controller segment as it appeared in the URL
     * @param string $method     method segment as it appeared in the URL
     */
    public static function action($group, $controller, $method){

        // POST is the only verb these routes register besides GET, and it is
        // the one VerifyCsrfToken checks. Everything else has to be listed.
        if(request()->isMethod('post')) return true;

        $action = strtolower($group . '/' . $controller . '/' . $method);
        if(in_array($action, self::readOnlyActions(), true)) return true;

        // Same reply, same shape, as if the method had guarded itself.
        return self::method('post');
    }

    /**
     * config/readonly_actions.php, lowercased once.
     *
     * Failing closed matters more than failing loudly here: if the config is
     * missing the list is empty, every dispatched action becomes POST-only, and
     * the admin UI stops rather than the guard stopping.
     */
    public static function readOnlyActions(){
        static $actions = null;
        if($actions === null){
            $actions = array_map('strtolower', array_values((array) config('readonly_actions', [])));
        }
        return $actions;
    }

}

?>