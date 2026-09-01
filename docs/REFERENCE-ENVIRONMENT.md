# Reference environment

A clean host used to verify that changes actually run, as opposed to merely
linting. Linting cannot catch a call to a function PHP removed; deploying can.

## Host

| | |
|---|---|
| OS | Ubuntu 24.04.3 LTS |
| Kernel | 6.8.0-138-generic |
| PHP | 8.4.25, **FPM** (not mod_php — deliberately) |
| Web | Apache 2.4.58, `proxy_fcgi` |
| DB | MariaDB 10.11.14 |

PHP 8.4 comes from `ppa:ondrej/php`; 24.04 ships 8.3 and the roadmap pins 8.4.

**FPM is the point, not an implementation detail.** mod_php does not exist on a
current LTS, and `.htaccess` `php_value` directives are a fatal configuration
error under FPM. Deploying under FPM is what proves the `.user.ini` migration
works.

## What this host confirmed

Assumptions the roadmap previously carried as Launchpad catalogue lookups, now
measured on a 6.x kernel:

```
cgroup:           cgroup2fs                    (v2 only)
KSM:              /sys/kernel/mm/ksm/run       (mainline present)
UKSM:             absent                       (so the swap is viable)
iptables:         v1.8.10 (nf_tables)          (nft backend default)
userns restrict:  kernel.apparmor_restrict_unprivileged_userns = 1
apparmor:         Y
/dev/kvm:         absent                       (QEMU would fall back to TCG)
```

## Setup

```bash
sudo add-apt-repository -y ppa:ondrej/php && sudo apt-get update
sudo apt-get install -y apache2 mariadb-server \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring php8.4-curl \
  php8.4-gd php8.4-zip php8.4-sqlite3 php8.4-intl php8.4-bcmath php8.4-yaml
sudo a2enmod proxy_fcgi setenvif rewrite headers
sudo a2enconf php8.4-fpm
```

Deploy the web layer to `/opt/unetlab/html` — `BASE_DIR` is hardcoded to
`/opt/unetlab` in `includes/init.php`, so the path is not negotiable. Exclude
`docs/`, `tools/`, `tests/`, `install/`, `.github/` and the root build files;
they are repository furniture, not part of what ships.

```
/opt/unetlab/{labs,tmp,addons,scripts,wrappers,data/Logs,data/Exports}
```
`data`, `labs` and `tmp` must be writable by `www-data`.

The vhost needs `AllowOverride All` (the app's routing lives in `.htaccess`) and
a `SetHandler` pointing at the FPM socket.

## Database

Two schemas, `pnetlab_db` (11 tables) and `guacdb` (23). The credentials are
hardcoded in the application and cannot currently be chosen:

| Database | User | Source |
|---|---|---|
| `pnetlab_db` | `pnetlab` / `pnetlab` | `includes/functions.php`, `checkDatabase()` |
| `guacdb` | `guacuser` / `pnetlab` | `includes/functions.php`, `html5_checkDatabase()` |

Rotating these is install-path work — see `install/README.md`.

Minimum seed for an offline login:

```sql
REPLACE INTO control (control_name,control_value) VALUES
 ('ctrl_offline_mode','1'),('ctrl_online_mode','0'),
 ('ctrl_default_mode','offline'),('ctrl_version','5.3.13');
REPLACE INTO users (pod,username,password,role,offline,user_status,folder,html5,online_time)
 VALUES (2,'admin',SHA2('pnet',256),'admin',1,1,'/',0,UNIX_TIMESTAMP());
```

`SHA2(...,256)` reproduces the application's current unsalted hashing. That is a
defect being fixed, not a pattern to copy.

## Known state of a fresh deploy

**The legacy API works.** `GET /api/auth` returns a correct 401. It is
vendor-independent — bundled Slim, no autoloader.

**The Laravel half does not run.** `store/public/index.php` fatals on
`require __DIR__.'/../vendor/autoload.php'`. `store/vendor` is not committed and
cannot be produced: `composer install` on PHP 8.4 reports 37 dependency
problems, 36 packages pinned below PHP 8, and a `composer-plugin-api ^1.0`
requirement Composer 2 cannot satisfy. `--ignore-platform-reqs` dies on a
Composer-1-only plugin. This is Phase 03's problem and is why that phase must
start from a fresh skeleton.

So a deploy today exercises the legacy API, the shell-out paths and the platform
layer — which is most of what Phase 02 touches — but not the admin UI.

## What is absent

No wrappers, no emulators, no vendor images, no Guacamole. This host verifies the
**web layer**. Node start/stop and console behaviour need the PNETLab appliance;
`docs/FINDINGS-LIVE-BOX.md` records what that looks like on a real install.
