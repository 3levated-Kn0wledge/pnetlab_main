<?php
namespace App\Helpers\Packages;

use App\Helpers\Request\Query;

/**
 * The web layer's half of the package mechanism.
 *
 * Everything here runs as www-data. It downloads, it stages, it reads installed
 * state, and it asks the wrapper to apply a file. It does not decide what an
 * install does — it cannot, because the only thing it can say to the wrapper is
 * "apply this path", and the path is one it wrote itself into a directory it
 * owns.
 *
 * The single privileged call in this file is
 *
 *     sudo /opt/unetlab/wrappers/unl_wrapper -a package -P <escaped path>
 *
 * which replaced
 *
 *     sudo /tmp/pnet_device_factory_<id>          (a script from pnetlab.com)
 *     sudo /tmp/upgrade/upgrade_package/upgrade   (a script from pnetlab.com)
 *
 * neither of which was in the sudo policy, so neither had worked since the
 * policy was scoped. Nothing is regressing here; the feature is being built
 * back in a shape that can be allowed to work.
 */
class PackageClient
{
    /** Device ids come from a request and end up in filenames. */
    const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public static function validId($id)
    {
        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Where a device's package comes from.
     *
     * A package-aware marketplace states it in the device record. Failing that,
     * a configured repository is asked for it by id. Failing that there is no
     * answer, and the caller says so rather than falling back to running
     * whatever shell the record happens to carry — that fallback is the bug
     * this whole change exists to remove.
     *
     * @return string|null
     */
    public static function deviceUrl(array $device, $deviceId)
    {
        if (isset($device[DEVICE_PACKAGE]) && is_string($device[DEVICE_PACKAGE]) && $device[DEVICE_PACKAGE] !== '') {
            return self::validUrl($device[DEVICE_PACKAGE]) ? $device[DEVICE_PACKAGE] : null;
        }
        if (PACKAGE_CENTER === '') {
            return null;
        }
        $url = rtrim(PACKAGE_CENTER, '/') . '/devices/' . rawurlencode($deviceId) . '.pnetpkg';
        return self::validUrl($url) ? $url : null;
    }

    /** http(s) with a host, and nothing else. file:// and friends are not URLs we fetch. */
    public static function validUrl($url)
    {
        if (!is_string($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        return in_array(strtolower($parts['scheme']), array('http', 'https'), true) && $parts['host'] !== '';
    }

    public static function incomingPath($id)
    {
        return PACKAGE_INCOMING_DIR . '/' . $id . '.pnetpkg';
    }

    public static function jobPath($id)
    {
        return PACKAGE_INCOMING_DIR . '/' . $id . '.job';
    }

    public static function logPath($id)
    {
        return PACKAGE_LOG_DIR . '/' . $id . '.log';
    }

    public static function ensureDirectories()
    {
        foreach (array(PACKAGE_INCOMING_DIR, PACKAGE_LOG_DIR) as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        return is_dir(PACKAGE_INCOMING_DIR) && is_dir(PACKAGE_LOG_DIR);
    }

    /**
     * Every package the applier has recorded as installed.
     *
     * This replaced running the marketplace's `device_check` string as a shell
     * command — which every listing of the store did, once per device, as
     * www-data, with a string pnetlab.com supplied. Whether a device is present
     * is now something the box knows about itself.
     *
     * @return array id => record, plus device_id => record for those that have one
     */
    public static function installed()
    {
        $out = array();
        $dir = PACKAGE_STATE_DIR . '/installed';
        if (!is_dir($dir)) {
            return $out;
        }
        foreach ((array) glob($dir . '/*.json') as $path) {
            $record = json_decode((string) file_get_contents($path), true);
            if (!is_array($record) || !isset($record['id'])) {
                continue;
            }
            $out['id:' . $record['id']] = $record;
            if (isset($record['device_id']) && $record['device_id'] !== null && $record['device_id'] !== '') {
                $out['device:' . $record['device_id']] = $record;
            }
        }
        return $out;
    }

    public static function isDeviceInstalled($deviceId, array $installed = null)
    {
        if ($installed === null) {
            $installed = self::installed();
        }
        return isset($installed['device:' . $deviceId]);
    }

    /**
     * Download a package, reporting progress into a process row.
     *
     * @param callable|null $progress function(int $total, int $now)
     * @return array ['result' => bool, 'message' => string]
     */
    public static function download($url, $dest, $progress = null)
    {
        if (!self::validUrl($url)) {
            return array('result' => false, 'message' => 'The package location is not a usable URL');
        }
        $fp = fopen($dest, 'w+');
        if ($fp === false) {
            return array('result' => false, 'message' => 'Cannot write into ' . PACKAGE_INCOMING_DIR);
        }
        $lastReport = 0;
        $options = array('file' => $fp);
        if ($progress !== null) {
            $options['process'] = function ($resource, $downloadSize = 0, $downloaded = 0, $uploadSize = 0, $uploaded = 0)
                use ($progress, &$lastReport) {
                $now = time();
                if (($now - $lastReport) > 1 || ($downloaded != 0 && $downloadSize == $downloaded)) {
                    $lastReport = $now;
                    call_user_func($progress, (int) $downloadSize, (int) $downloaded);
                }
            };
        }
        $response = Query::make($url, 'get', null, $options);
        fclose($fp);

        if (is_array($response) && isset($response['result']) && !$response['result']) {
            @unlink($dest);
            return array('result' => false, 'message' => 'Cannot download the package');
        }
        if (!is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);
            return array('result' => false, 'message' => 'The package download was empty');
        }
        return array('result' => true, 'message' => 'success');
    }

    /**
     * Hand a staged package to the wrapper.
     *
     * The path is escaped, but that is the second defence: the wrapper takes it
     * as the value of -P and opens it. There is no interpretation of the
     * contents on this side of the boundary at all.
     *
     * @return array ['result' => bool, 'log' => string, 'code' => int, 'data' => array|null]
     */
    public static function apply($path)
    {
        $output = array();
        $code = 0;
        $cmd = 'sudo ' . PACKAGE_WRAPPER . ' -a package -P ' . escapeshellarg($path) . ' 2>&1';
        exec($cmd, $output, $code);
        $log = implode("\n", $output);

        $data = null;
        foreach ($output as $line) {
            if (strpos($line, 'PNETPKG-RESULT ') === 0) {
                $decoded = json_decode(substr($line, strlen('PNETPKG-RESULT ')), true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        return array(
            'result' => $code === 0 && (!is_array($data) || !empty($data['ok'])),
            'log' => $log,
            'code' => $code,
            'data' => $data,
        );
    }

    /**
     * Ask the wrapper to undo an installed package.
     *
     * @return array same shape as apply()
     */
    public static function remove($packageId)
    {
        $output = array();
        $code = 0;
        $cmd = 'sudo ' . PACKAGE_WRAPPER . ' -a packageremove -I ' . escapeshellarg($packageId) . ' 2>&1';
        exec($cmd, $output, $code);
        $log = implode("\n", $output);

        $data = null;
        foreach ($output as $line) {
            if (strpos($line, 'PNETPKG-RESULT ') === 0) {
                $decoded = json_decode(substr($line, strlen('PNETPKG-RESULT ')), true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        return array(
            'result' => $code === 0 && (!is_array($data) || !empty($data['ok'])),
            'log' => $log,
            'code' => $code,
            'data' => $data,
        );
    }

    /** Append a line to a package's log, which is what the admin UI shows. */
    public static function appendLog($id, $text)
    {
        self::ensureDirectories();
        @file_put_contents(self::logPath($id), rtrim($text, "\n") . "\n", FILE_APPEND);
    }
}
