<?php 

namespace App\Helpers\Admin;

use App\Helpers\Request\Query;
use App\Helpers\Request\Reply;
use Illuminate\Support\Facades\Auth;

class SystemHelper {
    
    /**
     * Run a program and return its output lines. No shell is involved.
     *
     * proc_open() given an argv ARRAY execs the binary directly, so nothing in
     * the arguments can be read as syntax and there is nothing to escape. The
     * parameter is typed `array` because that is the shape the tokenizer sweep
     * in tests/Security/ShellEscapingTest.php can prove is not a shell.
     */
    private static function captureArgv(array $argv, array &$output)
    {
        $output = [];
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $pipes = [];
        $proc = @proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) return 127;
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($proc);
        $out = rtrim($out, "\n");
        if ($out !== '') $output = explode("\n", $out);
        return $code;
    }

    public static function FolderSize($path){
        // Was exec('/usr/bin/du -sb ' . $path). $path is a node session's
        // workspace, read back out of the database, and nothing escaped it.
        $o = [];
        $r = self::captureArgv(['/usr/bin/du', '-sb', '--', $path], $o);
        if($r != 0 || !isset($o[0])) return null;
        return preg_split("/[\s]+/", $o[0])[0];
    }

    public static function getTotalDisk(){
        $cmd = 'df -k /';
        exec($cmd, $o, $rc);
        $data = [];
        foreach ($o as $output) {
            if (preg_match('/^.*\s([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d]+)%.*$/mi', $output, $match)) {
                $data['free'] = (int)$match[3];
                $data['used'] = (int)$match[2];
                $data['total'] = (int)$match[1];
                $data['percent'] = (float)$match[4];
            }
        }
        return $data;
    }

    public static function getNodeDisk($nodeSession){
        if($nodeSession->{NODE_SESSION_TYPE} == 'docker'){
            // Was a double-quoted shell string with the session id interpolated
            // into it. Access to the daemon socket is root-equivalent by design
            // — anyone who can talk to it can bind-mount / into a container —
            // so an id that can become a second command was worth exactly as
            // much as a sudo. An argv array reaches no shell at all.
            $o = [];
            $r = self::captureArgv(['docker', '-H=unix:///var/run/docker.sock',
                'inspect', '--size', 'docker' . $nodeSession->{NODE_SESSION_ID},
                '--format={{.SizeRw}}'], $o);
            if($r != 0 || !isset($o[0])) return null;
            $addSize = (int)$o[0];
            return $addSize;

        }else {
            $addSize = (int)self::FolderSize($nodeSession->{NODE_SESSION_WORKSPACE});
            return $addSize;
        }
    }

}