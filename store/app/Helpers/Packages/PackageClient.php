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
     * The repository index states it in the device record. Failing that, the
     * configured repository is asked for it by id. Failing that there is no
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

    /*
    |----------------------------------------------------------------------
    | The repository index
    |----------------------------------------------------------------------
    |
    | What the device store lists, and what the update check compares
    | against, used to come from user.pnetlab.com: /api/offboxs/devices/filter
    | answered with device records that carried shell scripts, and
    | /api/offboxs/upgrade/check with a version. Phase 05 severed both. A
    | repository now describes itself in one file, PACKAGE_CENTER/index.json:
    |
    |   {
    |     "devices": [
    |       {"device_id": "vios", "device_name": "Cisco vIOS",
    |        "device_des": "...", "device_img": "https://.../vios.png",
    |        "device_package": "https://.../devices/vios.pnetpkg",
    |        "device_package_sha256": "<hex>", "device_guide": "https://..."}
    |     ],
    |     "appliance": {"version": "5.3.14",
    |                   "package": "https://.../pnetlab-5.3.14.pnetpkg",
    |                   "sha256": "<hex>", "note": "what changed"}
    |   }
    |
    | Every field is optional except device_id. The index is DATA FROM THE
    | NETWORK and is treated as such: parseIndex() keeps only the fields it
    | knows, only in the shapes it expects, and drops a record rather than
    | guess at it. Nothing in it is executed, and nothing in it decides what
    | an install does -- that is the signed manifest inside the package. What
    | the index can do is lie about what exists, which is docs/PACKAGES.md's
    | "signed index" caveat and no worse than the listing it replaces.
    |
    | With PACKAGE_CENTER unset there is no index, no request, and an empty
    | store that says why. That is the default, and docs/OFFLINE-FIRST.md is
    | why it is the default.
    */

    /** Longest string the index may put on a screen. */
    const INDEX_TEXT_MAX = 2000;
    const INDEX_NAME_MAX = 128;
    const VERSION_PATTERN = '/^[0-9][0-9A-Za-z.+-]{0,31}$/';
    const SHA256_PATTERN = '/^[0-9a-f]{64}$/i';

    private static $index = null;

    /** Where the repository describes what it serves, or null when none is configured. */
    public static function indexUrl()
    {
        if (PACKAGE_CENTER === '') {
            return null;
        }
        $url = rtrim(PACKAGE_CENTER, '/') . '/' . PACKAGE_INDEX_FILE;
        return self::validUrl($url) ? $url : null;
    }

    /**
     * The repository index, fetched once per request and normalised.
     *
     * This is the one network call the admin UI still makes, and it is made
     * only when the owner has pointed PNET_PACKAGE_CENTER at a repository --
     * an explicit opt-in -- and only from the two screens that ask (the
     * device store and the version dialog). It is bounded by the same
     * connect and total timeouts as every other call through Query::make().
     *
     * @return array result, message, devices (list of records), appliance (record|null)
     */
    public static function index()
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $url = self::indexUrl();
        if ($url === null) {
            return self::$index = self::emptyIndex(
                'No package repository is configured. Set PNET_PACKAGE_CENTER to a repository '
                . 'that publishes signed packages and an index.json; see docs/PACKAGES.md.');
        }
        $body = Query::make($url, 'get', null, array('strict_transport' => true, 'timeout' => 15));
        if (!is_string($body)) {
            return self::$index = self::emptyIndex('The package repository at ' . $url . ' did not answer');
        }
        if (strlen($body) > PACKAGE_INDEX_MAX_BYTES) {
            return self::$index = self::emptyIndex('The package repository index is larger than a listing should be');
        }
        return self::$index = self::parseIndex($body);
    }

    /** For tests: forget the memoised index. */
    public static function resetIndex()
    {
        self::$index = null;
    }

    private static function emptyIndex($message)
    {
        return array('result' => false, 'message' => $message, 'devices' => array(), 'appliance' => null);
    }

    /**
     * Normalise an index document. Pure, so it can be tested against hostile
     * input without a server.
     *
     * A device record survives only with a valid id; every other field is
     * kept if it has the expected shape and dropped otherwise. URLs must be
     * http(s) with a host (validUrl), so a javascript: or data: image cannot
     * reach the page; digests must be 64 hex characters; text is trimmed and
     * bounded. Duplicate ids keep the first record.
     *
     * @return array same shape as index()
     */
    public static function parseIndex($json)
    {
        $doc = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($doc)) {
            return self::emptyIndex('The package repository index is not valid JSON');
        }
        $devices = array();
        $seen = array();
        if (isset($doc['devices']) && is_array($doc['devices'])) {
            foreach ($doc['devices'] as $raw) {
                if (!is_array($raw) || !isset($raw[DEVICE_ID]) || !self::validId($raw[DEVICE_ID])) {
                    continue;
                }
                $id = (string) $raw[DEVICE_ID];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $devices[] = array(
                    DEVICE_ID => $id,
                    DEVICE_NAME => self::indexText($raw, DEVICE_NAME, self::INDEX_NAME_MAX, $id),
                    DEVICE_DES => self::indexText($raw, DEVICE_DES, self::INDEX_TEXT_MAX, ''),
                    DEVICE_IMG => self::indexUrlField($raw, DEVICE_IMG),
                    DEVICE_PACKAGE => self::indexUrlField($raw, DEVICE_PACKAGE),
                    DEVICE_PACKAGE_SHA256 => self::indexDigest($raw, DEVICE_PACKAGE_SHA256),
                    DEVICE_GUIDE => self::indexUrlField($raw, DEVICE_GUIDE),
                );
            }
        }
        $appliance = null;
        if (isset($doc['appliance']) && is_array($doc['appliance'])) {
            $a = $doc['appliance'];
            $version = isset($a['version']) && is_string($a['version']) && preg_match(self::VERSION_PATTERN, $a['version'])
                ? $a['version'] : '';
            $package = self::indexUrlField($a, 'package');
            if ($version !== '' && $package !== '') {
                $appliance = array(
                    'version' => $version,
                    'package' => $package,
                    'sha256' => self::indexDigest($a, 'sha256'),
                    'note' => self::indexText($a, 'note', self::INDEX_TEXT_MAX, ''),
                );
            }
        }
        return array('result' => true, 'message' => 'success', 'devices' => $devices, 'appliance' => $appliance);
    }

    /** One device record from the index, by id, or null. */
    public static function device($deviceId)
    {
        $index = self::index();
        foreach ($index['devices'] as $device) {
            if ($device[DEVICE_ID] === (string) $deviceId) {
                return $device;
            }
        }
        return null;
    }

    private static function indexText(array $raw, $key, $max, $default)
    {
        if (!isset($raw[$key]) || !is_string($raw[$key])) {
            return $default;
        }
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw[$key]));
        if ($text === '') {
            return $default;
        }
        return strlen($text) > $max ? substr($text, 0, $max) : $text;
    }

    private static function indexUrlField(array $raw, $key)
    {
        return isset($raw[$key]) && self::validUrl($raw[$key]) ? (string) $raw[$key] : '';
    }

    private static function indexDigest(array $raw, $key)
    {
        return isset($raw[$key]) && is_string($raw[$key]) && preg_match(self::SHA256_PATTERN, $raw[$key])
            ? strtolower($raw[$key]) : '';
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
        // strict_transport: Query::make() otherwise rewrites https to http
        // before the request is made, and follows a redirect to any scheme.
        // A package is verified by its signature, not by its transport, but
        // there is no reason to hand the URL of a root-applied artefact to
        // the network in the clear when the marketplace gave it as https.
        $options = array('file' => $fp, 'strict_transport' => true);
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
