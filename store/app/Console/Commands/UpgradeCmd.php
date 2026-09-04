<?php

namespace App\Console\Commands;

use App\Helpers\Admin\Upgrade;
use App\Helpers\Packages\PackageClient;
use Illuminate\Console\Command;

/**
 * `php artisan upgrade now` -- the upgrade worker.
 *
 * Started by Admin/DefaultController::upgrade() as www-data, with no sudo.
 * Holds a lock for the whole run so that two clicks, or a click during a
 * scheduled run, cannot start two appliers against the same tree. Without
 * `now` it sleeps a random interval first, which is the shape a cron entry
 * wants; nothing in this repository installs such an entry.
 */
class UpgradeCmd extends Command
{
    protected $signature = 'upgrade {now?}';
    protected $description = 'Apply the appliance update the package repository publishes, if any';

    public function handle()
    {
        set_time_limit(0);
        if ($this->argument('now') != 'now') {
            sleep(rand(0, 3600));
        }
        if (!PackageClient::ensureDirectories()) {
            $this->line('ERROR: cannot create ' . PACKAGE_INCOMING_DIR);
            return 1;
        }
        $lock = fopen(PACKAGE_INCOMING_DIR . '/upgrade.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->line('an upgrade is already running');
            return 1;
        }
        try {
            $result = Upgrade::run();
            print_r($result);
            return empty($result['result']) ? 1 : 0;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
