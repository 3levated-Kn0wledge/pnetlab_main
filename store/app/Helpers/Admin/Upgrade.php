<?php
namespace App\Helpers\Admin;


use App\Helpers\Control\Ctrl;
use App\Helpers\DB\Models;
use App\Helpers\Packages\PackageClient;
use App\Helpers\Request\Reply;


/**
 * The self-upgrader.
 *
 * WHAT THIS USED TO DO
 *
 *   exec("sudo rm -rf $upgradeFolder");
 *   ... download a zip from pnetlab.com, ZipArchive::extractTo() it ...
 *   exec("sudo chmod 755 -R $folder");
 *   exec("find $folder -type f -print0 | xargs -0 dos2unix 2>&1");
 *   exec("sudo $folder/upgrade 2>&1");
 *
 * That last line was the only `sudo $variable` in the tree, which is precisely
 * the shape tests/Security/SudoersPolicyTest.php cannot see — it matches a
 * literal binary name after `sudo`, and there is no literal here. The
 * extraction was ZipArchive's, which writes whatever names the archive
 * contains, so a member called ../../../etc/cron.d/x needed no exploit at all,
 * just a malicious or compromised zip.
 *
 * It had not worked in a long time regardless: $folder/upgrade is not in the
 * sudo policy, so the exec failed and the version never changed. Which brings
 * out a second bug worth recording — the check below the exec compared
 * Ctrl::get(CTRL_VERSION) against the version read at the top of the same
 * request, and Ctrl::get memoises in a static. Even a successful upgrade
 * returned the cached old value and threw. The upgrade reported failure whether
 * it worked or not.
 *
 * WHAT IT DOES NOW
 *
 * The same shape — check, download with progress into the same process row,
 * apply, report — but what is downloaded is a signed package and what applies
 * it is `unl_wrapper -a package`. The version is taken from the manifest the
 * wrapper verified rather than re-read through a memoising cache.
 *
 * WHERE THE UPDATE COMES FROM
 *
 * Until Phase 05 the check and the download link still came from
 * user.pnetlab.com (/api/offboxs/upgrade/check and /upgrade, each carrying
 * the box's alive key and encrypted UUID). Both now read the `appliance`
 * record of the repository's own index.json through PackageClient::index()
 * -- see docs/PACKAGES.md "The index". With no repository configured there
 * is no request and no update, and the dialog says so.
 *
 * This runs as www-data, from `php artisan upgrade now` started by
 * Admin/DefaultController::upgrade() with no sudo. The only privileged step
 * is PackageClient::apply(), which is `sudo unl_wrapper -a package <path>`,
 * and root decides from the signature inside the file whether to believe it.
 */
class Upgrade {

    public static function run(){
        $processModel = Models::get('Admin/Process');
        $proccessId = 'upgrade';
        $packagePath = null;

        try {
            set_time_limit(0);

            $oldVersion = Ctrl::get(CTRL_VERSION, '');

            $newVersion = self::checkUpgrade();
            if(!$newVersion['result'] || !isset($newVersion['data'])){
                $processModel->drop([[[PROCESS_ID, '=', $proccessId]]]);
                return $newVersion;
            }
            $update = $newVersion['data'];
            $newVersion = $update[UPGRADE_VERSION];
            if($newVersion == $oldVersion){
                $processModel->drop([[[PROCESS_ID, '=', $proccessId]]]);
                return Reply::make(true, 'success');
            }
            $url = $update['package'];
            $sha256 = $update['sha256'];
            if(!PackageClient::validUrl($url)){
                $processModel->drop([[[PROCESS_ID, '=', $proccessId]]]);
                return Reply::make(false, 'Error', 'The update channel did not offer a downloadable package');
            }

            if(!PackageClient::ensureDirectories()){
                $processModel->drop([[[PROCESS_ID, '=', $proccessId]]]);
                return Reply::make(false, 'Error', 'Cannot create '. PACKAGE_INCOMING_DIR);
            }
            $packagePath = PACKAGE_INCOMING_DIR . '/upgrade.pnetpkg';

            if(!$processModel->is_exist([[[PROCESS_ID, '=', $proccessId]]])){
                $processResult = $processModel->add([[PROCESS_ID => $proccessId]]);
                if(!$processResult['result']) return $processResult;
            }

            $download = PackageClient::download($url, $packagePath, function($total, $now) use ($processModel, $proccessId){
                $processModel->edit([
                    DATA_KEY => [[[PROCESS_ID, '=', $proccessId]]],
                    DATA_EDITOR => [
                        PROCESS_DTOTAL => $total,
                        PROCESS_DNOW => $now,
                        PROCESS_UTOTAL => 0,
                        PROCESS_UNOW => 0,
                        PROCESS_FINISH => '0',
                    ]
                ]);
            });

            if(!$download['result']) throw new \ErrorException($download['message']);
            // If the index stated a digest, hold the file to it. A transport
            // check, not a trust decision: the signature inside the package is
            // what decides whether the contents are believed, and root checks
            // that after this process has stopped being able to touch the file.
            if($sha256 !== '' && !hash_equals($sha256, hash_file('sha256', $packagePath))){
                throw new \ErrorException('The downloaded package does not match the digest the repository advertised');
            }
            @chmod($packagePath, 0644);

            // One call, one argument. Whether this host ends up running new
            // code is decided by whether the manifest inside that file carries
            // a signature from a key in /opt/unetlab/data/packages/trusted.d,
            // and that decision is made by root after this process has stopped
            // being able to touch the file.
            $applied = PackageClient::apply($packagePath);

            $processModel->drop([[[PROCESS_ID, '=', $proccessId]]]);
            @unlink($packagePath);

            if(!$applied['result']) throw new \ErrorException($applied['log']);

            // The version comes from the manifest the wrapper verified. Reading
            // it back through Ctrl::get() would return the value memoised at
            // the top of this method and report a working upgrade as a failure,
            // which is what the previous version of this code did.
            $appliedVersion = isset($applied['data']['version']) ? $applied['data']['version'] : '';
            if($appliedVersion === '' || $appliedVersion === $oldVersion){
                throw new \ErrorException('The package applied but did not change the version');
            }
            Ctrl::set(CTRL_VERSION, $appliedVersion);

            return Reply::make(true, 'success', $appliedVersion);

        }
        catch (\ErrorException $e){
            $processModel->drop([[[PROCESS_ID, '=', $proccessId]]]);
            if($packagePath !== null) @unlink($packagePath);
            return Reply::make(false, 'Error', $e->getMessage());
        }
    }

    private static $checkUpgradeResult=null;
    /**
     * What the repository says the current appliance version is.
     *
     * @return array Reply shape; on success data carries UPGRADE_VERSION,
     *               UPGRADE_NOTE, package (URL) and sha256 ('' if none)
     */
    public static function checkUpgrade(){
        if(isset(self::$checkUpgradeResult)) return self::$checkUpgradeResult;
        $index = PackageClient::index();
        if(!$index['result']){
            self::$checkUpgradeResult = Reply::make(false, 'ERROR', ['data'=>$index['message']]);
        }elseif($index['appliance'] === null){
            self::$checkUpgradeResult = Reply::make(false, 'ERROR', ['data'=>'The package repository publishes no appliance update']);
        }else{
            $a = $index['appliance'];
            self::$checkUpgradeResult = Reply::make(true, 'success', [
                UPGRADE_VERSION => $a['version'],
                UPGRADE_NOTE => $a['note'],
                'package' => $a['package'],
                'sha256' => $a['sha256'],
            ]);
        }
        return self::$checkUpgradeResult;
    }
}
