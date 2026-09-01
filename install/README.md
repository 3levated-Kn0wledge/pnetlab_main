# install/ — the platform layer

The upstream repository contains only the web layer (what ships as
`/opt/unetlab/html`). The platform layer — installer, service wiring, kernel and
sudo policy — shipped in the PNETLab ISO and was never published.

That gap matters, because **the single highest-value security fix available has
nowhere else to live**: the web user's sudo policy. This directory is where the
fork starts owning its own install path.

## Contents

| Path | Purpose | Status |
|---|---|---|
| `install.sh` | The installer. Clean Ubuntu 24.04 → serving web layer | Written, **not yet run end to end on a fresh machine** |
| `lib/*.sh` | One file per step, sourced by `install.sh` | as above |
| `apache/pnetlab.conf.in` | Virtual host template (FPM, `AllowOverride All`) | as above |
| `sql/seed-control.sql` | Control rows that put the appliance in offline mode | as above |
| `sql/seed-admin.sql` | Default administrator, applied only when there is none | as above |
| `sql/schema/` | Where you drop the appliance schema dumps; empty on purpose | — |
| `sudoers.d/pnetlab` | Privilege policy for `www-data` | Deployable, and **not a boundary** — see below |

## Running it

```bash
sudo ./install/install.sh
```

Steps, in order, each independently re-runnable with `--only`:

| Step | What it does |
|---|---|
| `preflight` | Checks the host, and checks that `BASE_DIR` and the database credentials in the source still match what the installer assumes |
| `packages` | `apache2`, `mariadb-server`, PHP 8.4 from `ppa:ondrej/php` under **FPM** |
| `deploy` | Creates `/opt/unetlab/{labs,tmp,addons,scripts,wrappers,data/Logs,data/Exports}` and rsyncs the web layer to `/opt/unetlab/html` |
| `sudoers` | Validates the policy with `visudo -cf`, installs it 0440 root:root, removes `/etc/sudoers.d/unetlab`, re-validates the whole tree and rolls back if it broke |
| `database` | Creates `pnetlab_db` and `guacdb` and their users, imports a schema if you supplied one, applies the offline seed |
| `apache` | Modules, vhost, `configtest` **before** restart |
| `store` | `store/.env`: a per-installation `APP_KEY`, `APP_DEBUG=false` |
| `verify` | Read-only. Services, layout, sudo policy, DB logins, `GET /api/auth` over the loopback |

Useful flags: `--only`/`--skip` a step list, `--schema-dir DIR`, `--reset-admin`,
`--prune` (let rsync delete files under the docroot that are not in the source
tree), `--with-node-tools`, `--strip-sudoers-grants`, `--with-store-vendor`.
`--help` documents all of them.

`sudo ./install/install.sh --only verify` changes nothing and can be run at any
time, including against a host somebody else built.

## What it deliberately does not do

- **It does not create the database schema.** The schema is not in this
  repository; it shipped inside the appliance image. The installer creates the
  databases and the users, then either imports a dump from
  `install/sql/schema/` or tells you the tables are missing and that the
  application will fail on every query. It does not invent a schema and it does
  not report success over an empty database.
- **It does not make the Laravel admin UI work.** It cannot. `store/` is
  Laravel 5.5; `composer install` fails outright on PHP 8.4, and forcing it with
  `--ignore-platform-reqs --no-plugins --no-scripts` produces a `vendor/` that
  then fatals at runtime (`ReflectionParameter::getClass()`,
  `Collection::offsetExists()`). `--with-store-vendor` will run that forced
  install if you ask, and says plainly that it buys a different error rather
  than a working UI. What does work after an install is the legacy API, the
  themes, and the platform layer.
- **It does not build the frontend.** `npm run production` must run from the
  repository root before deploying (`docs/BUILD.md`); the installer warns if the
  bundle is absent rather than running npm itself.
- **It does not set a MariaDB root password.** Administration goes through the
  stock unix_socket root account. The appliance set root's password to
  `pnetlab`, which handed database access to every local account on the box.
  One consequence: `store/app/Console/Commands/MysqlRecovery.php` still shells
  out to `mysql -uroot -ppnetlab` and will not work against a host installed
  this way. The installer says so.
- **It does not install emulators, wrappers, vendor images, Guacamole or
  systemd units.** Those are separate work. `--with-node-tools` installs the
  host binaries the sudo policy allowlists, and nothing more.
- **It does not edit `/etc/sudoers`** unless you pass
  `--strip-sudoers-grants`. It does detect and warn when a surviving blanket
  `NOPASSWD:ALL` there makes the new policy decorative.

## What has not been verified

**The script has not been run end to end on a fresh machine.** It is
syntax-checked, its rsync exclusion set was validated with `rsync --dry-run`
against this tree, its Apache template renders, and `visudo -cf` accepts the
policy — but no clean Ubuntu 24.04 host has been taken from nothing to a
serving install by it. Every step was derived from the manual procedure in
`docs/REFERENCE-ENVIRONMENT.md`, which was performed by hand and does work.
Treat the first run on a disposable VM as the test it has not had, and read the
`verify` output rather than the exit status.

Two specifics that a first run should settle:

- the exact database default character set. The installer creates both schemas
  as `utf8mb4`; the appliance's own dumps may specify something else per table,
  in which case the import decides and this does not matter.
- whether anything in the tree writes into the document root at runtime. The
  installer makes `/opt/unetlab/html` root-owned and not writable by
  `www-data` — deliberately, given the shell interpolation still in the tree —
  with only `data`, `labs`, `tmp`, `store/storage` and `store/bootstrap/cache`
  writable. The appliance let the web user own its own code.

## The sudo problem

A stock install grants the web user unconditional passwordless root:

```
$ sudo -l -U www-data
    (ALL : ALL) NOPASSWD: ALL
```

Upstream's `/etc/sudoers.d/unetlab` contains the correct narrow rule and then
negates it on the next line with a blanket `NOPASSWD:ALL`, plus
`%unl ALL=(ALL) NOPASSWD:ALL` — and every lab tenant account created at node
start has `unl` as its primary group. Combined with the web layer's unescaped
shell interpolation, any injection is an immediate host compromise.

## What the policy does and does not achieve

The shell sweep produced the inventory this was waiting on: **42 distinct
binaries across 142 call sites**. The policy allowlists exactly those, and
`tests/Security/SudoersPolicyTest.php` fails if the code and the policy drift
apart in either direction — a binary the code needs but the policy omits breaks
the product silently, and a binary the policy grants but the code never invokes
is a standing privilege nobody needs.

**It is not a privilege boundary, and should not be described as one.** At least
a dozen of those binaries are root-equivalent however they are scoped: `php` and
`perl` execute arbitrary code, `tee`/`cp`/`mv`/`rm`/`chmod`/`chown` write or take
ownership of arbitrary paths, `docker` mounts the host filesystem, `nohup` and
`service` launch arbitrary processes, and `useradd` can create a second uid 0
account. Anyone who can influence their arguments can still become root.

What it buys today, verified on the reference host:

- the `%unl` group grant is gone. Node start creates a Unix account per session
  in that group with a login shell, and never removes them; nothing runs under
  those accounts via sudo, so the grant had no legitimate use at all.
- everything not on the list is refused. `sudo su`, `sudo bash`, `sudo visudo`
  and `sudo passwd` all stop working, confirmed by running them as `www-data`.
- the escalation surface is explicit and countable rather than unbounded, which
  is the precondition for reducing it.

The commands the product actually uses still work — `ip`, `brctl`, `tunctl`,
`sysctl` and `echo` were each run as `www-data` through the new policy, and the
application still serves.

### Reducing it from here

The end state is that every entry except `unl_wrapper` is removed and its
behaviour moved behind the wrapper. Entries marked ROOT-EQUIVALENT in the policy
should go first. The drift test is what makes attempting that safe: move a call
site behind the wrapper, delete its policy line, and the test tells you
immediately whether anything else still needed it.

Until that work is done, **treat a compromise of the web layer as a compromise
of the host.**

## Not yet written

- **Testing the installer on a clean target image.** The script exists; the
  clean-image run does not. That is the single largest gap in this directory.
- Rotation of the hardcoded `pnetlab` / `guacuser` application credentials.
  They are in `includes/functions.php` and cannot be chosen at install time
  without a code change, so the installer reads them out of the source and
  fails preflight if they drift rather than pretending they are configurable.
- Tenant-account lifecycle: node start creates `unlN` Unix accounts with login
  shells that are never removed, and a failed start additionally leaks its tap
  interface.
- systemd units, currently hand-placed in `/etc/systemd/system`.
- HTTPS. The vhost is plain `*:80`; TLS is a deployment decision the installer
  does not make for you.
- AppArmor profile. Upstream's answer to AppArmor was `apparmor=0` on the kernel
  command line; a profile carrying a `userns,` rule is new work, not a port.
