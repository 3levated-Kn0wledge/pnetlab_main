<?php

namespace App\Console\Commands;

use App\Helpers\Control\Ctrl;
use App\Helpers\DB\Models;
use Illuminate\Console\Command;

/**
 * `php artisan mode reset offline` -- recover the admin account from the
 * console; `php artisan mode reset all` -- remove every admin and send the
 * box back through first boot.
 *
 * This command used to switch between an online and an offline mode and set
 * the default. There is one mode since Phase 05 (docs/OFFLINE-FIRST.md), so
 * what is left is the recovery half.
 */
class ModeCmd extends Command
{
    protected $signature = 'mode {action} {object?}';
    protected $description = 'Recover the admin account (reset offline) or send the box back through first boot (reset all)';

    public function handle()
    {
        $action = $this->argument('action');
        $obj = $this->argument('object');
        if ($action != 'reset') {
            $this->line("usage: mode reset offline | mode reset all");
            return 1;
        }
        if ($obj == 'offline') {
            Ctrl::set(CTRL_OFFLINE_MODE, '1');
            $userModel = Models::get('Admin/Users');
            if ($userModel->is_exist([[[USER_USERNAME, '=', 'admin']]])) {
                $userModel->edit([
                    DATA_KEY => [[[USER_USERNAME, '=', 'admin']]],
                    DATA_EDITOR => [USER_ROLE => '0', USER_PASSWORD => \unl_password_hash(LOCAL_PASS), USER_OFFLINE => '1'],
                ]);
            } else {
                $userModel->add([[
                    USER_USERNAME => 'admin',
                    USER_ROLE => '0',
                    USER_OFFLINE => '1',
                    USER_PASSWORD => \unl_password_hash(LOCAL_PASS),
                ]]);
            }
            $this->line("Admin account reset. Log in as admin/" . LOCAL_PASS . " and change the password.");
            return 0;
        }
        if ($obj == 'all') {
            Ctrl::set(CTRL_OFFLINE_MODE, '0');
            Models::get('Admin/Users')->drop([[[USER_ROLE, '=', '0']]]);
            $this->line("Every admin account removed and offline mode switched off; the next visit runs first boot again.");
            return 0;
        }
        $this->line("usage: mode reset offline | mode reset all");
        return 1;
    }
}
