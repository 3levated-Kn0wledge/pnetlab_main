<?php
namespace App\Helpers\Admin;


use App\Helpers\Control\Ctrl;
use App\Helpers\DB\Models;
use App\Helpers\Packages\PackageClient;
use App\Helpers\Request\Query;
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

            $newVersion = $newVersion['data'][UPGRADE_VERSION];

            if($newVersion == $oldVersion){
                $processModel->drop([[[PROCESS_ID, '=', $proccessId]]]);
                return Reply::make(true, 'success');
            }

            $upgradeResult = Query::boxCenter(APP_CENTER.'/api/offboxs/upgrade/upgrade', [
                UPGRADE_VERSION=>$oldVersion,
            ], ['dataType'=>'json']);

            if(!$upgradeResult['result']){
                $processModel->drop([[[PROCESS_ID, '=', $proccessId]]]);
                return $upgradeResult;
            }
            $upgradeResult = $upgradeResult['data'];

            // A package-aware update channel states where its package is. The
            // legacy channel returns ulink+utoken pointing at a zip of shell
            // scripts, which this will fetch and the wrapper will then refuse,
            // because it is not a signed package — which is the correct
            // outcome, not a regression to work around.
            $url = isset($upgradeResult['package']) && PackageClient::validUrl($upgradeResult['package'])
                ? $upgradeResult['package']
                : (isset($upgradeResult['ulink'])
                    ? $upgradeResult['ulink'].'?utoken='.$upgradeResult['utoken']
                    : '');

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
    public static function checkUpgrade(){
        if(isset(self::$checkUpgradeResult)) return self::$checkUpgradeResult;
        $currentVersion = Ctrl::get(CTRL_VERSION, '4.0.0');
        $upgradeResult = Query::boxCenter(APP_CENTER.'/api/offboxs/upgrade/check', ['version' => $currentVersion], ['dataType'=>'json']);
        if(!$upgradeResult){
            self::$checkUpgradeResult = Reply::make(false, 'ERROR', ['data'=>'Check new version faild']);
        }else {
            self::$checkUpgradeResult = $upgradeResult;
        }
        return self::$checkUpgradeResult;
    }
}
