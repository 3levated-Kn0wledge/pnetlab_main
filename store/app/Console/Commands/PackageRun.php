<?php

namespace App\Console\Commands;

use App\Helpers\DB\Models;
use App\Helpers\Packages\PackageClient;
use Illuminate\Console\Command;

/**
 * The background half of a device install or removal.
 *
 * DevicesController cannot do this work in the request: a device image is
 * measured in gigabytes and the admin UI polls for progress a second after the
 * call returns, so the download has to outlive the HTTP request. Upstream
 * solved that by backgrounding a shell script it had just fetched. This does it
 * by backgrounding a command that is part of the fork.
 *
 * Everything supplier-controlled — the URL, the digest, the package id — is
 * read out of a job file, keyed by a device id that the controller has already
 * checked against a strict pattern. The command line carries the device id and
 * nothing else, so there is no supplier string in an argv anywhere on this
 * path.
 *
 * The row in process_device is what the dialog renders, and dropping it is what
 * tells the dialog to close. It is dropped in a finally block: if this command
 * dies, the dialog must still stop spinning.
 */
class PackageRun extends Command
{
    protected $signature = 'pnet:package-run {device : the device id whose job file to run}';

    protected $description = 'Download and apply the package queued for a device, or remove an installed one';

    public function handle()
    {
        $deviceId = (string) $this->argument('device');
        if (!PackageClient::validId($deviceId)) {
            $this->error('invalid device id');
            return 1;
        }

        $processModel = Models::get('Admin/Process_device');
        $jobPath = PackageClient::jobPath($deviceId);
        $packagePath = PackageClient::incomingPath($deviceId);
        $status = 1;

        try {
            $job = json_decode((string) @file_get_contents($jobPath), true);
            if (!is_array($job) || !isset($job['action'])) {
                $this->line('No job queued for this device.');
                return 1;
            }

            if ($job['action'] === 'remove') {
                $status = $this->remove($job, $processModel, $deviceId);
            } else {
                $status = $this->install($job, $processModel, $deviceId, $packagePath);
            }
        } catch (\Throwable $e) {
            $this->line('ERROR: ' . $e->getMessage());
            PackageClient::appendLog($deviceId, 'ERROR: ' . $e->getMessage());
        } finally {
            @unlink($jobPath);
            @unlink($packagePath);
            $processModel->drop([[[PROCESS_DEVICE_ID, '=', $deviceId]]]);
        }

        return $status;
    }

    private function install(array $job, $processModel, $deviceId, $packagePath)
    {
        $url = isset($job['url']) ? $job['url'] : '';
        if (!PackageClient::validUrl($url)) {
            $this->fail($deviceId, 'The package location is not a usable URL.');
            return 1;
        }

        $this->note($processModel, $deviceId, 'Downloading package');
        $download = PackageClient::download($url, $packagePath, function ($total, $now) use ($processModel, $deviceId) {
            $processModel->edit([
                DATA_KEY => [[[PROCESS_DEVICE_ID, '=', $deviceId]]],
                DATA_EDITOR => [
                    PROCESS_DEVICE_DTOTAL => $total,
                    PROCESS_DEVICE_DNOW => $now,
                ],
            ]);
        });
        if (!$download['result']) {
            $this->fail($deviceId, $download['message']);
            return 1;
        }

        // If the marketplace stated a digest, hold it to it. This is a
        // transport check, not a trust decision: the signature inside the
        // package is what decides whether the contents are believed, and it is
        // checked by root, after this process has stopped being able to touch
        // the file.
        if (!empty($job['sha256'])) {
            if (!preg_match('/^[0-9a-f]{64}$/', $job['sha256'])) {
                $this->fail($deviceId, 'The advertised package digest is malformed.');
                return 1;
            }
            $actual = hash_file('sha256', $packagePath);
            if (!hash_equals($job['sha256'], $actual)) {
                $this->fail($deviceId, 'The downloaded package does not match the advertised digest.');
                return 1;
            }
            PackageClient::appendLog($deviceId, 'Package digest matches the marketplace listing.');
        }

        @chmod($packagePath, 0644);
        $this->note($processModel, $deviceId, 'Installing');
        $applied = PackageClient::apply($packagePath);
        PackageClient::appendLog($deviceId, $applied['log']);

        if (!$applied['result']) {
            $this->fail($deviceId, 'The package was refused. See the log above.');
            return 1;
        }
        PackageClient::appendLog($deviceId, 'Done.');
        return 0;
    }

    private function remove(array $job, $processModel, $deviceId)
    {
        $packageId = isset($job['package']) ? $job['package'] : '';
        if (!PackageClient::validId($packageId)) {
            $this->fail($deviceId, 'The installed-state record names an unusable package id.');
            return 1;
        }
        $this->note($processModel, $deviceId, 'Removing');
        $removed = PackageClient::remove($packageId);
        PackageClient::appendLog($deviceId, $removed['log']);
        if (!$removed['result']) {
            $this->fail($deviceId, 'The package could not be removed. See the log above.');
            return 1;
        }
        PackageClient::appendLog($deviceId, 'Done.');
        return 0;
    }

    private function note($processModel, $deviceId, $message)
    {
        $this->line($message);
        $processModel->edit([
            DATA_KEY => [[[PROCESS_DEVICE_ID, '=', $deviceId]]],
            DATA_EDITOR => [PROCESS_DEVICE_LOG => $message],
        ]);
    }

    private function fail($deviceId, $message)
    {
        $this->line('ERROR: ' . $message);
        PackageClient::appendLog($deviceId, 'ERROR: ' . $message);
    }
}
