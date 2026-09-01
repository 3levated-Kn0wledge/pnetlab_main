<?php

namespace App\Helpers\Request;
use App\Helpers\Control\Ctrl;
use App\Helpers\Request\Reply;
use App\Helpers\System\Wrapper;
use Illuminate\Support\Facades\Auth;


class Query {

    /** Seconds to wait for a TCP/TLS connection to an upstream service. */
    const CONNECT_TIMEOUT = 5;

    /** Seconds to wait for a complete response on a normal API call. */
    const TIMEOUT = 30;

    /**
     * For transfers (downloads) a hard total timeout would abort large but
     * healthy files, so those are bounded by throughput instead: abort if the
     * transfer moves less than LOW_SPEED_LIMIT bytes/sec for LOW_SPEED_TIME
     * seconds.
     */
    const LOW_SPEED_LIMIT = 512;
    const LOW_SPEED_TIME  = 60;

    /** The file `unl_wrapper -a set-proxy` writes. Read here, never written here. */
    const PROXY_FILE = '/etc/apt/apt.conf.d/00proxy';

    public static $ch = null;
    
    public static function make($url, $method = 'get', $post=array(), $options=[]){

        $url = preg_replace('/^https/', 'http', trim($url));
       
        self::$ch = curl_init();
        
        curl_setopt(self::$ch, CURLOPT_URL, $url);
        curl_setopt(self::$ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(self::$ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt(self::$ch, CURLOPT_POST, false);

        // Bound every upstream call. libcurl defaults to a 300s connect timeout
        // with no total cap, so an unreachable upstream pins the calling worker
        // (Apache runs mpm_prefork) for five minutes and a handful of such calls
        // exhausts the pool. An unreachable upstream must fail fast, not hang.
        curl_setopt(self::$ch, CURLOPT_CONNECTTIMEOUT,
            isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : self::CONNECT_TIMEOUT);

        if (isset($options['file']) || isset($options['process'])) {
            // A transfer: bound by stall, not by total duration.
            curl_setopt(self::$ch, CURLOPT_LOW_SPEED_LIMIT, self::LOW_SPEED_LIMIT);
            curl_setopt(self::$ch, CURLOPT_LOW_SPEED_TIME, self::LOW_SPEED_TIME);
        } else {
            curl_setopt(self::$ch, CURLOPT_TIMEOUT,
                isset($options['timeout']) ? (int) $options['timeout'] : self::TIMEOUT);
        }

        if(isset($options['header'])){
            curl_setopt(self::$ch, CURLOPT_HTTPHEADER, $options['header']);
        }
        
        if(isset($options['process'])){
            curl_setopt(self::$ch, CURLOPT_PROGRESSFUNCTION, $options['process']);
            curl_setopt(self::$ch, CURLOPT_NOPROGRESS, false);
        }

        if(isset($options['file'])){
            curl_setopt(self::$ch, CURLOPT_FILE, $options['file']); 
        }

        if($method == 'post'){
            curl_setopt(self::$ch, CURLOPT_POST, count($post));
            curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $post);
        }


        $proxy = self::getProxy();
        if($proxy != null){
            if(isset($proxy['proxy_ip']) && isset($proxy['proxy_port'])){
                $proxyaddress = $proxy['proxy_ip'].':'.$proxy['proxy_port'];
                curl_setopt(self::$ch, CURLOPT_PROXY, $proxyaddress);
                if(isset($proxy['proxy_username']) && isset($proxy['proxy_password'])){
                    $proxyauth = $proxy['proxy_username']. ':' . $proxy['proxy_password'];
                    curl_setopt(self::$ch, CURLOPT_PROXYUSERPWD, $proxyauth); 
                }
            }
        }
        
        $responseString = curl_exec(self::$ch); 
        
        if(!isset($options['continue']) || !$options['continue']){
            curl_close(self::$ch);
        }
        
        if(!$responseString){
            return Reply::make(false, 'ERROR', ['data'=>'Can not connect to server']);
        }
       
        if(isset($options['dataType']) && $options['dataType'] == 'json'){
            $responseString = json_decode($responseString, true);
            if(json_last_error() != JSON_ERROR_NONE) return Reply::make(false, 'ERROR', ['data'=>'Data is wrong format']);;
        }
        
        return $responseString;
    
    }

    public static function center($url, $method = 'post', $data=[], $options=[]){
        
        if($method == 'get'){
            $data = self::getDataFromUrl($url);
        }
        if(isset($options['user'])){
            $user = $options['user'];
        }else{
            $user = Auth::user();
        };
        if(!isset($user->{USER_LICENSE})){
            return Reply::make(false, 'ERROR', ['data'=>'Can not get license']);
        }
        $data['time'] = time();
        $data['license'] = $user->{USER_LICENSE};
        $data = json_encode($data);
        
        if($method == 'get'){
            $urlPath = explode('?', $url);
            if(isset($urlPath[1])){
                $url = $urlPath[0].'?'.$urlPath[1].'&'.'data='.$data;
            }else{
                $url = $urlPath[0].'?data='.$data;
            }
            
        }
        
        return self::make($url, $method, ['data'=>$data], $options);
    }


    public static function boxCenter($url, $data=[], $options=[]){
        
        
        $indenfify = new \indentify();
        $data['license'] = Ctrl::get(CTRL_ALIVE_KEY, '');
        $data['key'] = $indenfify->getKey();
        $data = json_encode($data);
        
        return self::make($url, 'post', ['data'=>$data], $options);
    }
    
    private static function getDataFromUrl($url){
        $dataString = explode('?', $url);
        if(!isset($dataString[1])) return [];
        $dataString = $dataString[1];
        $dataString = explode('&', $dataString);
        $data = [];
        foreach ($dataString as $value){
            $valueArray = explode('=', $value);
            if(!isset($valueArray[1]) || $valueArray[1]=='') continue;
            $data[trim($valueArray[0])] = trim($valueArray[1]);
        }
        return $data;
    }

    /**
     * Read the configured proxy back out of the apt configuration.
     *
     * The pattern is wider than the one it replaces in three ways, because the
     * writer is now correct and the reader has to keep up with it:
     *
     *   - `[\d\w\.]+` could not match a hostname containing a hyphen, nor a
     *     bracketed IPv6 literal. Both are accepted by the validator in
     *     actions/UnlSetProxy.php, so both have to be readable back.
     *   - the credential is percent-encoded in the file (that is what makes a
     *     rich password safe to write at all), so it is decoded here. The old
     *     `([^@]+)` would have handed the encoded form to the admin form and to
     *     curl, and the password would have silently stopped working.
     *   - the old pattern anchored on Acquire::https::Proxy with /m, and the
     *     file it was reading had literal backslash-n rather than newlines in
     *     it, so ^ never matched and this function returned null for the whole
     *     life of the feature.
     */
    public static function getProxy()
    {
        try {
            if (!is_file(self::PROXY_FILE)) return null;
            $data = file_get_contents(self::PROXY_FILE);
            return $data === false ? null : self::parseProxy($data);
        } catch (\Exception $th) {
            return null;
        }
    }

    /**
     * The parser, separated from the file so it can be tested against exactly
     * what the wrapper writes. tests/Security/SetProxyTest.php asserts the round
     * trip, which is the property that broke silently before: a writer and a
     * reader that disagree leave an admin looking at an empty form over a
     * working proxy, or the reverse.
     *
     * @return array|null proxy_ip, proxy_port, and the credential when present
     */
    public static function parseProxy($data)
    {
        if (!is_string($data)) return null;
        $re = '/^Acquire::https?::Proxy\s+"https?:\/\/'
            . '(?:([^:@\/"]*):([^@\/"]*)@)?'
            . '(\[[0-9A-Fa-f:]+\]|[A-Za-z0-9.-]+):([0-9]{1,5})\/?";/m';
        if (!preg_match($re, $data, $matches)) return null;

        $proxyData = [
            'proxy_ip'   => trim($matches[3], '[]'),
            'proxy_port' => $matches[4],
        ];
        if ($matches[1] !== '') {
            $proxyData['proxy_username'] = rawurldecode($matches[1]);
            $proxyData['proxy_password'] = rawurldecode($matches[2]);
        }
        return $proxyData;
    }

    /**
     * Configure or clear the system proxy.
     *
     * WHAT THIS USED TO BE. The four lines below replace the shortest path from
     * an HTTP request to root that this tree contained:
     *
     *     if(!is_file($file)) exec('sudo touch '.$file);
     *     $proxyAddr = $p['proxy_username'].':'.$p['proxy_password'].'@'
     *                . $p['proxy_ip'].':'.$p['proxy_port'];
     *     $result = exec("echo '".$proxyAddr."' | sudo tee ".$file);
     *
     * Every component came off the request body and none was escaped. They went
     * into a single-quoted shell string, so one apostrophe ended the quoting and
     * the remainder ran; and whatever survived was written as root into an apt
     * configuration file, which can carry APT::Update::Pre-Invoke and therefore
     * execute at the next apt run. Two root primitives, from one admin form.
     *
     * Nothing is composed here now. The four values cross the boundary as four
     * separate arguments — the password on stdin — and are validated
     * individually on the far side by actions/UnlSetProxy.php, which owns the
     * destination path and writes the file itself.
     *
     * @return array ['ok'=>bool,'error'=>string|null,...]
     */
    public static function setProxy($p){
        return Wrapper::setProxy(is_array($p) ? $p : []);
    }

}

