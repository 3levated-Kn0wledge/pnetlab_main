# Handover

**State at end of session, 2026-09-02.** Branch `phase-02-shell-hardening`,
75 commits ahead of `main`, none pushed. Nothing uncommitted.

**Roadmap phases 00 through 04 are done**, with three deliberate redefinitions,
each recorded in its own document:

- the supported platform is **24.04, not 26.04** — 26.04 cannot be built or
  tested here, and `guacamole-server` is absent from it entirely
  (`docs/PLATFORM-SUPPORT.md`);
- the licence is **adopted, not published** — BSD-3-Clause is declared and is
  now the standard, but publishing is gated on the incompatible components
  named in `docs/LICENSING.md`;
- there is **no AppArmor profile, deliberately** — AppArmor is on and nothing
  here disables it, which is the half that matters, and `docs/APPARMOR.md` says
  what a profile would have to cover and why `unl_wrapper` cannot usefully be
  one of its subjects.

Phase 05 (severing the upstream dependency), 06 (frontend currency) and 07
(maintainership) are not started, though the signed-package work makes 05
cheaper than it was.

---

## Where this got to

The fork **deploys to a brand-new Ubuntu 24.04 server on PHP 8.4, runs labs, and
no longer needs anything from the upstream appliance image.** The installer
compiles the console wrappers from source as one of its steps.

Every line below was measured on the reference VM against a clean `git archive`
of this commit, unpacked onto the provisioned host — including the installer,
which was re-run end to end rather than carried over from an earlier session.

```
sudo bash install/install.sh --server-name pnetlab.test
→ INSTALLER-EXIT=0, every step, all verification checks green

bash tools/integration/lab-functional.sh   → 59 shell assertions, 8 data-plane checks, 0 failed
bash tools/integration/node-types.sh       → 30 passed, 0 failed, 1 skipped (IOL)
bash tools/integration/db-backup-restore.sh→ 67 assertions, 0 failed
bash tools/integration/guacamole-console.sh→ 35 assertions, 0 failed
bash tools/integration/wrapper-console.sh  → 44 assertions, 0 failed
bash tools/integration/wrapper-docker.sh   → 45 assertions, 0 failed
bash tools/integration/iol-dataplane.sh    → 75 assertions, 0 failed
make -C platform/wrappers/src test         → 248 unit assertions, 0 failed
tools/run-tests.sh                         → 1559 assertions across 31 files, 0 failed
tools/php-lint.sh (8.4 and 7.4)            → 352 files, 0 failed
```

The sudo policy is at **24 grants**, down from 42.

---

## What works, and what does not

| | |
|---|---|
| Legacy API (18 routes) | works |
| Laravel 12 admin UI on PHP 8.4 | works |
| VPCS nodes | works, end to end — and run as the tenant, not root |
| QEMU nodes, VNC console | works |
| QEMU nodes, **telnet** console | works — reimplemented wrapper; run as the tenant |
| **Docker**-backed nodes | works — reimplemented wrapper, unix socket. Still root, unavoidably |
| Guacamole **HTML5 consoles** | works, for real nodes, through Apache |
| **IOL** nodes | **implemented, never run** — see below |

**IOL is the one honest gap.** `iol_wrapper` is written, unit-tested, and its
data plane is exercised end to end against a stand-in IOL that speaks the same
AF_UNIX bus. But IOL images are licensed Cisco binaries this project
deliberately does not carry, so no IOL node has ever started. What is unproven:
that real IOL accepts our `NETMAP`, that its bus header layout is what we
believe, that it binds `/tmp/netio<uid>/<id>`, and that IOS passes traffic.
Closing it needs two licensed nodes wired both by Ethernet and by serial.
Everything else in this table was driven through the HTTP API on a real host.

---

## The wrappers

These were the fork's last dependency on the upstream ISO. They are now
**reimplemented from scratch** in `platform/wrappers/src` (C, ~2,500 lines) and
built by the installer.

Reimplemented: `qemu_wrapper_telnet`, `docker_wrapper`, `iol_wrapper`.
**Not needed, and not missing:** `qemu_wrapper` and `dynamips_wrapper` have no
live call site (QEMU's own `-vnc` is the console listener; dynamips has `-T`),
`iol_wrapper_telnet` is referenced only from commented-out code, and `nsenter`
is stock util-linux, which the installer symlinks.

**This was written clean-room, and that was a choice.** Source for these exists
publicly — `dainok/unetlab`, plus `pnetlab/pnetlab_wrapper` for the two variants
upstream never shipped — and vendoring it would have been days rather than
weeks. The decision was to own the implementation outright. The
specification was written by one party from the fork's own PHP and from observed
behaviour; no implementer read the originals. If you continue this work, keep
that property: do not paste upstream source into these files.

An earlier revision of this paragraph called both sources BSD-3-Clause. Only
`dainok/unetlab` is: `pnetlab/pnetlab_wrapper` has no licence file and reports
`"license": null`, so vendoring the two variants from it would have imported
exactly the unlicensed-code problem `docs/LICENSING.md` §2.3 is about. The
clean-room decision turns out to have bought more than it was made for.

Two deliberate improvements over the originals are recorded in the code:
`docker_wrapper` allocates its own PTY instead of shelling out over SSH to
`root@localhost`, which deletes a standing passwordless root SSH key from every
appliance; and the vendor licence check is simply absent.

---

## The non-obvious things

Items 1–5 are unchanged from the last handover, because they are still true and
still cost time. 6, 7 and 8 are new, and each of them cost an hour or more this
session because none of them announces itself.

1. **PHP-FPM systemd confinement blocks the platform layer.** `ProtectSystem=full`
   mounts `/etc` read-only, so `useradd` cannot take its lock and *no node can
   start*. `ProtectKernelTunables` blocks the per-tap sysctl; `PrivateDevices`
   hides `/dev/kvm` and `/dev/net/tun`. Fixed by
   `install/systemd/php-fpm-pnetlab.conf`. **`ReadWritePaths=/etc` alongside
   `ProtectSystem=full` does not work** — the drop-in records that so it is not
   tried again. Jetty's unit has the same confinement class and needed nothing,
   which is also recorded.

2. **Laravel 5.5 could not boot for two separate reasons**, and the second is
   that `PackageManifest` cannot read Composer 2's `installed.json`.

3. **`user_roles` is empty on a stock appliance**, so `getRoleByPod()` returns
   null; indexing null is fatal on PHP 8. Six sites guarded.

4. **Template option strings are argument injection by design.** `qemu_options`,
   `docker_options`, `iol_options` and `getFlag()` are user-editable in the UI
   and exist to supply multiple arguments. Marked `sweep-exempt` with reasons.
   Escaping cannot fix this; it is a design decision the fork still owes.

5. **opcache will lie to you while you are testing.** php-fpm runs with
   `validate_timestamps=On` and `revalidate_freq=2`, so editing a deployed PHP
   file and immediately re-requesting it serves the OLD bytecode. This produced
   a convincing "the bug is not real" result during this session. Wait out the
   window before concluding a change had no effect.

6. **A tap's group decides whether its owner can open it.** The kernel's
   `tun_not_capable()` denies `TUNSETIFF` unless the caller is BOTH the tap's
   owner AND in the tap's group. `tunctl -u unl<N> -g root` therefore left the
   owning tenant locked out of its own interface, with a bare `EPERM` and no
   diagnostic: the node started, the console answered, and no frame moved.
   `addTap()` uses `-g unl` now, which does not widen access to other tenants —
   the owner clause still binds them, and that was measured both ways.

7. **A QEMU node's real error message is destroyed a second after it is
   written.** `command()` sends QEMU's output to `wrapper.txt`, and
   `device_qemu::start()` then points `qemu_wrapper_telnet` at the same file and
   truncates it. Any QEMU startup failure presents as "the node does not start"
   with an empty log. This is not fixed; if you are debugging a QEMU node, run
   the command line out of `unl_wrapper.txt` by hand as `unl<session>`.

8. **`--only deploy` used to break the app it had just deployed.** `deploy.sh`
   does `chown -R root:root $WEB_ROOT`, which reaches `store/.env` even though
   rsync excludes it; Laravel then cannot read `APP_KEY` and every request 500s
   as "No application encryption key has been specified", which the API reports
   as a session timeout on every call. Fixed, but the shape is worth
   remembering: a permissions problem here does not look like one.

---

## Security work in this session

**The sudo policy is down from 42 grants to 25.** Retired: `nproc`, `top`,
`docker`, `dos2unix`, `php`, `perl`, `kill`, `cp`, `mv`, `mkdir`, `link`,
`chown`, `chmod`, `touch`, `tee`, `echo`, `useradd`. `tests/Security/SudoersPolicyTest.php`
enforces drift in **both** directions, so a grant cannot return without a call
site and a call site cannot return without a grant.

It is still **not a privilege boundary**. `rm` remains (four `rm -rf` sites on
escaped workspace paths), and `www-data` is in the `docker` group, which is
root-equivalent by design — anyone who can talk to the daemon can bind-mount
`/` into a container. What changed is that the surface is countable and the
remaining items each have a named reason in the policy file. There is now also
a boundary below it that did not exist: VPCS and QEMU nodes run as their own
tenant account, so a bug in an emulator or in a template's option string no
longer starts from uid 0.

**Five** root-code-execution paths were closed:

- **The marketplace and self-updater** fetched a shell script from pnetlab.com
  and ran it as root. Replaced by signed packages — see `docs/PACKAGES.md`.
  Read that before touching it; it is the mechanism a future first-party
  marketplace should be built on, and it states plainly what its trust model
  does *not* cover.
- **A setuid-and-`shell_exec` gadget** reachable from an ordinary link-state
  change, whose uid/gid drops were guarded by `> 0` so uid 0 kept root. Now
  `unl_wrapper -a iol-keepalive`.
- **The apt proxy form** interpolated four request fields into a single-quoted
  shell string and wrote the result as root into `/etc/apt/apt.conf.d/`, which
  can carry `Pre-Invoke` directives. Now `-a set-proxy`, with each component
  validated separately and the file written without a shell.
- **An unauthenticated Docker daemon** on `tcp://127.0.0.1:4243`, which the
  installer never even configured — so Docker nodes could not have worked. All
  31 call sites now use `/var/run/docker.sock`.
- **The Idle-PC button installed a root backdoor.** `store/app/Console/Commands/idlepc`
  was a 9.4 MB stripped PyInstaller blob run under `sudo`; its bytecode runs
  `ssh-keygen`, appends the public key to `/root/.ssh/authorized_keys`, and SSHes
  to `127.0.0.1`. It did that solely to obtain a TTY, which dynamips does not
  require — `0x1d` over its `-T` console reaches the same handler. Replaced by
  `unl_wrapper -a idlepc`. The key it planted, `id_rsa_dy`, is the same one
  `docker_wrapper` consumed; that consumer was removed earlier on this branch,
  and this was what created it.

**CSRF protection is on.** The recorded blocker (CKEditor's upload adapter) did
not exist: it is CKEditor 5's stock adapter, gated on a config key nothing sets,
and the only file that registers one for real is imported by nothing. The real
blocker was that the three dynamic dispatchers accepted GET for 157 controller
methods, 118 of them unguarded — and `SameSite=Lax` sends cookies on top-level
GET navigation, so `location = '.../admin/status/apiSetKsm?state=0'` ran as the
logged-in admin. The router now defaults to POST-only, with 23 GET-reachable
actions listed in `store/config/readonly_actions.php`, each justified from a
caller.

---

## Tenant accounts, and running nodes unprivileged

These are the two items Phase 02 still owed, and they interact, so read them
together.

**Node start manufactured a Unix account per node session and nothing ever
removed one.** There was no `userdel`, no `deluser` and no expiry anywhere in
the tree; a login-shell account and a home directory accumulated for every node
ever started. It is also a correctness bug: `createNodeSession()` allocates ids
modulo 30000 and skips ids present in `node_sessions`, so an id is reused once
its row is deleted, while the account and its home directory survive.

The fix is `unl_wrapper -a reap-tenant -S <session>` (or `--scope all`), in
`platform/wrappers/actions/UnlTenantAccount.php`, which also owns creation so
the two halves cannot disagree about the name, uid or group. **A fixed pool was
considered and rejected**, and the reason is worth keeping: the uid is
load-bearing per session. Taps are handed to the account by name so one node's
tenant cannot open another's, and `iol.c` derives its AF_UNIX bus directory
from the running uid (`/tmp/netio<uid>`). A pool preserving that would have to
be 30000 accounts wide.

The reaper refuses rather than trusting its caller: no process running as the
uid, no surviving `vunl<N>_*` tap, and no node session reporting status 2 or 3.
Four places end a session and all four reap — `device::stop()`,
`destroyBrokenLabSession()` (which never calls `stop()`), `-a delete` and
`-a stopall`. `useradd` retired from the sudo policy with it: its one caller is
only ever reached inside `unl_wrapper`, which is already root.

**VPCS and QEMU now run as `unl<session>`.** `device::spawnAsTenant()` forks,
drops in the child and execs, so the wrapper stays root — unlike the old IOL
drop, which called `posix_setuid()` in the wrapper's own process and is why the
start-all loop postpones IOL nodes.

**Docker does not drop and cannot**: there is no emulator process (the daemon
runs the container), and the host-side work left over needs CAP_NET_ADMIN and
CAP_SYS_ADMIN. **Dynamips is not flipped** although it looks identical to VPCS,
because there is no IOS image on the reference host to prove it with. **IOL is
untouched**, still dropping in-process; moving it would also delete a latent bug
(a second IOL node in one start-all cannot create its account), but no IOL node
has ever run here and `iol-dataplane.sh` drives `iol_wrapper` directly rather
than `device_iol`, so nothing would have caught a mistake.

---

## Bugs found by testing, not by reading

Worth listing separately, because every one of them was invisible to static
review and several had been shipping for years.

- **The functional suite could not fail.** It ended on a `printf` with no
  `exit`, so it reported "0 failed" and returned success regardless.
- **The shell-escaping test asserted over an empty set.** It skipped any line
  containing `escapeshellarg` after narrowing to interpolated lines; zero lines
  reached the assertion. Rewritten as a tokenizer sweep: 340 files, 322 call
  sites, and a baseline of **73** genuinely unescaped values that must only ever
  shrink.
- **Every QEMU node was unstartable on 24.04**, three times over: `qemu-img`
  refuses `-b` without `-F` since 5.0; templates pin `/opt/qemu-<version>` paths
  that a package-managed host does not have; and `device::stop()` passed
  `command()`'s `array(False,False)` error return to `escapeshellarg()`, a fatal
  on PHP 8 — so a node that failed to start could not be stopped either, and
  teardown died mid-request.
- **The built-in admin stopped being root.** `checkRoot()` tested `role == 0`
  and the seeded role is the string `'admin'`; `'admin' == 0` was true on PHP 7
  and is false on PHP 8. Every root-only admin screen denied the only account a
  fresh install has.
- **`paloalto1` was a fatal.** `templates/paloalto1.yml` ships, and
  `device_paloalto1.php` declared `class device_paloalto`, so the loader
  instantiated a class nothing declared.
- **Logout never cleared the token cookie** — the clearing calls were scoped to
  `APP_DOMAIN` while the live cookie was scoped to `SERVER_NAME`.

---

## Suggested next steps, in order

1. **Review and merge.** 53 commits is a lot, but each is individually scoped
   and its message explains the reasoning. Note that two commits carry files
   they do not describe: `iol.c` landed inside the Guacamole commit `dfc1764`
   because parallel work shared one index. `8707cbc` is an empty commit
   recording that. Content is correct and verified; only the attribution is
   wrong. Rewriting that history is a reasonable pre-merge tidy if you want it.
2. **IOL, with a licensed image.** Everything else is proven; this is the only
   feature claim resting on unit tests alone.
3. **Finish the sudo migration.** `rm` is the last of the file-mutation grants,
   and `tests/Security/SudoersPolicyTest.php` makes each step checkable.
4. **The template option strings** (item 4 above) — the last documented
   argument-injection surface, and a design decision rather than a bug fix.
   Cheaper now than it was: the shell that expands them runs as `unl<session>`
   for VPCS and QEMU, so the blast radius is a tenant rather than the host.
5. **Dynamips unprivileged**, which needs one licensed IOS image and nothing
   else. It is the same shape as VPCS — a tap handed to the tenant, a
   workspace, a console port from `-T` — and the only reason it is still root
   is that the claim could not be measured here. `runsAsTenant()` in
   `devices/dynamips/device_dynamips.php` is a one-line change; the work is
   running `node-types.sh` against a real image afterwards.
6. **Tighten `/opt/unetlab/tmp`**, the last untouched item in Phase 02's
   privilege-model list. Node workspaces are `root:unl 0775`, so every tenant
   can write every other tenant's workspace. Now that emulators run as their
   tenant, this is the seam that decides whether that isolation means anything.
7. **Phase 05, severing the upstream dependency.** `License::keepalive()` still
   relicenses against pnetlab.com and `Query::boxCenter()` still ships an
   encrypted machine UUID upstream. The signed-package work makes this cheaper
   than it was.
8. ~~**Backup and restore**~~ — done, and recorded in "Phase 04: backup and
   restore" at the foot of this document. What is left of it is
   `store/app/Console/Commands/MysqlRecovery.php`, which is a second copy of the
   same defects against a different directory.

---

## Environment

| | |
|---|---|
| Reference VM | `192.168.4.93`, `labadmin`, key auth, passwordless sudo |
| SSH | needs `-b 192.168.3.105`; the default route picks the wrong source and times out |
| PNETLab appliance | `10.85.44.5`, `labadmin`, needs `-o IPQoS=none` or ssh hangs |
| Repo | `github.com:3levated-Kn0wledge/pnetlab_main`, SSH key present |
| Verification | run lint and tests **on the VM**, not the workstation — `mgmt-host` runs AWX and k3s, and container churn there caused `hung_task` stalls |

**Ubuntu 24.04 is the supported platform**, and the installer now says so: it
warns once on any other release, naming what will break, and derives the PHP
version from what the host can install rather than assuming 8.4 — so the fpm
socket, the unit name and its drop-in follow. `--php-version` still pins it, and
a pin it cannot satisfy is fatal. `docs/PLATFORM-SUPPORT.md` has the evidence
and the 26.04 bring-up checklist.

`tools/php-lint.sh` and `tools/run-tests.sh` take `PHP=` to select an
interpreter. Both PHP 8.4 and 7.4 are installed on the VM, so the lint matrix in
the numbers above is real; CI covers 8.4 and 8.5.

The reference VM carries a CirrOS image at
`/opt/unetlab/addons/qemu/linux-cirros/` so `node-types.sh` can exercise a QEMU
telnet console. It is a 21 MB public test image, not a vendor one, and it is not
in the repository.

---

## Documents

| File | What it is |
|---|---|
| `docs/LICENSING.md` | the licence position: what is inherited, what blocks publishing, a recommendation, a pre-public checklist |
| `THIRD-PARTY.md` | the attribution that must accompany every distribution |
| `docs/PACKAGES.md` | the signed-package format, trust model and threat model |
| `docs/ROADMAP.md` | the plan, v2, revised against live-box evidence |
| `docs/REVIEW-ADVERSARIAL.md` | an adversarial review of that plan |
| `docs/FINDINGS-LIVE-BOX.md` | Q1–Q8 answered against a running appliance |
| `docs/FINDINGS-KERNEL.md` | the kernel investigation |
| `docs/REFERENCE-ENVIRONMENT.md` | how the test host is built |
| `docs/PLATFORM-SUPPORT.md` | what is supported, what 26.04 risks, and the checklist |
| `docs/APPARMOR.md` | why no profile ships, what one would cover, and what stops it being disabled |
| `docs/DOCKER-IMAGES.md` | seeding Docker images onto an offline host, and why it is not a package yet |
| `docs/OFFLINE-FIRST.md` | the accepted architectural direction |
| `platform/wrappers/src/README.md` | the wrapper core API and its provenance |
| `docs/audit.html` | single-page summary of the live-box findings |

`docs/ROADMAP.md` predates most of this session. Where it and this document
disagree about what works, this one was measured more recently. Note that
`docs/ROADMAP.md` is referenced in the table above but is not in the tree — it
has never been committed on this branch, and `git log --all -- docs/ROADMAP.md`
finds nothing.

---

## Phase 03: Laravel 10 -> 12

Done, in two hops, each verified before the next. `store/` is now Laravel
12.69.1 on PHP 8.4. The application structure was deliberately not migrated to
the Laravel 11 skeleton — the upgrade guide advises against it and
`store/bootstrap/app.php` still requires `/opt/unetlab/html/includes/init.php`
before the Application is constructed, which is the line welding the two halves
of this tree together.

The application needed exactly two changes, both in the JWT auth layer and both
fatals rather than deprecations, because PHP will not declare a class that does
not implement every method of its interface: `UserProvider` gained
`rehashPasswordIfRequired()` and `Authenticatable` gained
`getAuthPasswordName()` in Laravel 11. Nothing at all was needed for 12.

**The config-set worry was inverted.** `store/config/` is still the Laravel 5.5
set — no `logging.php`, no `hashing.php` — and the fear was a framework that
starts depending on files this application does not ship. Laravel 11 does the
reverse: `LoadConfiguration` now merges the framework's own defaults for any
config file the application does not define (laravel/framework 10.50.3 ships no
`config/` directory; 11 and 12 do). So `config('logging.default')` went from
NULL to `'stack'` and the application now logs through a real channel instead of
LogManager's emergency fallback. The path is unchanged and already www-data
owned, so nothing had to move. `config/readonly_actions.php`, which is this
fork's own file, still loads — 23 entries.

**Going to 12 rather than stopping at 11 was the security answer, not a
preference.** `composer audit` at 11.56.1 reported two laravel/framework
advisories — a CRLF injection in the default email rule (high) and a temporary
signed URL path confusion (medium) — whose fixed versions are 12.60.0 and
12.61.1. There is no 11.x release that carries either fix. Stopping at 11 would
have left the tree knowingly vulnerable. At 12.69.1 the framework audits clean;
the one remaining advisory is `firebase/php-jwt` CVE-2025-45769, which is
pre-existing, low, and has no fixed release (`<7.0.0`, and 7.0.0 does not
exist).

Traps this phase found, none of which announce themselves:

  - **A composer run in a partial `store/` tree silently ships a stale
    autoloader.** `store/composer.json` classmaps `database/seeds` and
    `database/factories`. Run composer somewhere those do not exist and it
    extracts every package, then *fails* at "Generating optimized autoload
    files" — so `vendor/` holds the new framework with the old classmap. The
    symptom is a fatal deep in `Illuminate/Container/Container.php` about a
    missing trait, which reads exactly like a broken framework release. It is
    not: in 12.69.1 `ReflectsClosures.php` moved to
    `src/Illuminate/Reflection/Traits/` while still declaring
    `namespace Illuminate\Support\Traits`, so only a regenerated classmap can
    find it. Run composer in a full copy of `store/`, and never filter its
    output when you need to know whether it worked.
  - **LaravelBootTest prefers a checkout that has a `store/vendor` over the
    deployment**, and building one there is exactly what an upgrade does. A
    checkout has no `.env` — it is gitignored — so the test then booted a tree
    with a framework and no APP_KEY and reported four 500s that looked precisely
    like the upgrade having broken the application. A missing `.env` is now
    skipped as loudly as an unreadable one.
  - **RemovedFunctionsTest scanned generated code.** A Blade view compiled into
    `store/storage/framework/views/` by that same bad run was Laravel's own
    debug exception page, which inlines a minified highlight.js containing
    `e.split(a)`; the test's negative lookbehind excludes `[\w$>:]` but not a
    dot, so it reported a call to PHP's removed `split()` in a file nobody
    wrote. Compiled views are now excluded alongside `vendor/`.

Measured on the reference VM at 12.69.1, against the deployed application:

```
tools/php-lint.sh (8.4 and 7.4)      344 files, 0 failed
tools/run-tests.sh                   990 assertions across 25 files, 0 failed
tests/Laravel/LaravelBootTest.php    22 assertions, 0 failed (as root)
lab-functional.sh                    55 shell, 8 data-plane, 0 failed
node-types.sh                        30 passed, 0 failed, 1 skipped (IOL)
```

`lab-functional.sh` is the suite that matters for the auth changes: it logs in
through `POST /auth/login/login`, which is `JwtGuard::attempt()` ->
`JwtUserProvider::retrieveByCredentials()` -> `validateCredentials()`, with
`VerifyCsrfToken` in front of it. Authenticated admin pages
(`admin/main/view`, `admin/status/view`, `admin/users/view`) were driven by hand
and render byte-identically to Laravel 11.

Not done, and worth knowing: the deployed `store/vendor` was installed by hand
in a scratch copy of `store/` rather than by `install/install.sh --only store`,
because that step returns early when `vendor/autoload.php` already exists and so
cannot perform an upgrade. Making it able to is small and is the honest next
step for the installer.


---

## Phase 04: backup and restore

`unl_wrapper -a backupdb` and `-a restoredb` are real now. They were not.

**What was there.** Three cases in `unl_wrapper`, nine lines, four defects, no
callers — there is none in `store/`, none in `includes/`, none in the crontab,
and none on a stock 5.3.13 appliance either.

```php
case "backupdb":
    shell_exec("mysqldump -uroot -ppnetlab ... --databases pnetlab_db > /opt/unetlab/backup_database/pnetlab_db.sql ; mysqldump ... guacdb > ...");
case "restoredb":
    shell_exec("cat .../pnetlab_db.sql | /usr/bin/mysql --password=pnetlab pnetlab_db ; ...");
case "restoredb_remote":
    shell_exec("cat .../remote/pnetlab_db.sql | ...");
```

**It never wrote a byte, and the reason is not the one it looks like.**
`/opt/unetlab/backup_database` does not exist. Nothing in the tree created it —
not the installer, not the wrapper — and a stock appliance does not have it
either. Measured, running that exact string as root with the directory absent:

```
sh: 1: cannot create /opt/unetlab/backup_database/pnetlab_db.sql: Directory nonexistent
shell_exec() returned NULL
```

and the case then fell through to `break` and exit 0. `-a backupdb` reported
success and produced nothing.

**Be careful how you test the password claim.** The obvious reading is that
`-ppnetlab` cannot authenticate, because `install/lib/database.sh` administers
MariaDB over the unix_socket root account and deliberately does not set a root
password. That reading is wrong as stated: **the unix_socket plugin
authenticates root by peer uid and IGNORES the password supplied**, so
`mysqldump -uroot -ppnetlab` run as root SUCCEEDS on this host, and unl_wrapper
is always root. Measured both ways — as root it dumps; as `nobody` or `labadmin`
it is `ERROR 1698 (28000)`. So the argument buys nothing here and costs the
appliance credential to `ps` for the life of the dump; on an appliance, where
root does have that password, it is the real one.

**What replaced it.** `platform/wrappers/actions/UnlBackupDatabase.php`, in the
established style. Contract:

```
unl_wrapper -a backupdb                        exit 48 on failure
unl_wrapper -a restoredb [--source local|remote]   exit 49 on failure
unl_wrapper -a restoredb_remote                exit 50, retired, names its replacement
```

  - `--protocol=socket -uroot`, matching `mysql_root()`. No password on any
    command line and no defaults file, because socket auth has no credential to
    keep. There is deliberately no option on the class through which one could
    be supplied.
  - `proc_open()` with an argv array; the dump file is opened by PHP and its
    descriptor handed to the child as fd 1, fd 0 on restore. No shell, no `;`,
    no `|`, no `>`.
  - every exit status checked, and the shape of the output too: a dump is
    written to a 0600 temporary file and renamed into place only after
    mysqldump exited 0, the file is over 512 bytes, its `USE` names the schema
    it claims, and it carries mysqldump's `-- Dump completed` trailer. **Both
    schemas are renamed only if both succeeded**, so guacdb failing cannot leave
    a new `pnetlab_db.sql` beside a stale `guacdb.sql`. `--skip-comments` is gone
    because it stripped the trailer, which is the only truncation marker there
    is.
  - restore **refuses while any lab or node session exists**, and refuses when it
    cannot read the session tables at all. It takes a safety dump of the current
    databases into `/opt/unetlab/backup_database/pre-restore/` first and will not
    proceed if that fails. One generation, overwritten by each restore.

**`restoredb_remote` was not imaginary, and it is not dropped.** The writer of
`/opt/unetlab/backup_database/remote/` is `/opt/unetlab/scripts/migrate_new_host.sh`
on a 5.3.13 appliance — an appliance-to-appliance migration helper that this
repository does not ship, which rsyncs a dump from another host over `sshpass`
root SSH. It was dead code even there: the script writes ONE combined
`remotedb.sql` while `restoredb_remote` read two per-schema files nothing
creates, and the script restores inline rather than calling the wrapper. The
capability is kept as `-a restoredb --source remote` rather than as its own
action, because a separate action is exactly how it came to rot.

**The installer creates the directory**, `0700 root:root`, with `remote/` and
`pre-restore/` beside it. That mode is load-bearing: these dumps hold the whole
`users` table with every password digest, and guacdb holds every console
connection with its parameters.

Measured on the reference VM:

```
tools/integration/db-backup-restore.sh   67 passed, 0 failed, 0 skipped
tools/integration/lab-functional.sh      55 shell, 8 data-plane, 0 failed
tools/integration/node-types.sh          30 passed, 0 failed, 1 skipped (IOL)
tools/run-tests.sh                       1315 assertions across 27 files, 0 failed
tools/php-lint.sh (8.4 and 7.4)          347 files, 0 failed
sudo policy                              25 grants, unchanged
```

`db-backup-restore.sh` is the one that matters: it creates a lab, starts a VPCS
node, opens its HTML5 console link so `html5AddSession()` writes a real `guacdb`
row, tears the session down, backs up, **deletes the marker user and every
guacdb connection**, restores, and asserts both halves are back — the guacdb
rows by digest, not merely by count. It then proves the refusal by starting a
node and running `-a restoredb` under it. What it CANNOT prove: that a dump of a
large, busy database is consistent (both schemas are entirely InnoDB and the
dump is `--single-transaction`, but nothing here writes concurrently); that a
restore survives a schema change between backup and restore; or anything at all
about a host whose MariaDB root is password-authenticated, since the suite skips
loudly when socket root does not work.

Two traps worth keeping:

  - **`DROP DATABASE` does not drop the grants on that database.** They live in
    `mysql.db` and survive, so the application can still connect afterwards.
    That was measured rather than assumed, because if it were false a restore
    would silently break every other suite on the host.
  - **`node_sessions` rows survive a node STOP**; only `factory/destroy` clears
    them. A restore attempted between the two is refused, correctly, and that is
    asserted.

**Not done, and it is the same bug again.**
`store/app/Console/Commands/MysqlRecovery.php` runs `mysqldump -uroot -ppnetlab`
and `mysql -uroot -ppnetlab` against `/opt/unetlab/database_backup` — a second
directory nothing creates — with `exec(... 2>/dev/null)` and `rm -rf`. It is
Laravel-side, has no scheduled caller, and `install/lib/database.sh` already
warns about it. It should be deleted or pointed at this action; it was left
alone here so that one commit changes one thing.

---

## Phase 04: the five bullets that were still open

Done 2026-09-02, on the reference VM, against a clean tree of this commit
unpacked onto the provisioned host. Numbers at the foot of this section.

### Memory dedup: UKSM is gone, and the toggle means less than it looks

`unl_wrapper -a uksmon` wrote `/sys/kernel/mm/uksm/run`. UKSM is an out-of-tree
patch that lived in the appliance's custom 4.15 kernel; we ship stock, that path
does not exist on 6.8, and those verbs could only fail.

**It was not, however, the silent breakage it looked like.** `apiSetKsm` already
wrote the right path and worked — measured over HTTP before anything changed.
The UKSM row on the status page was already inert, because `getInfo()` `cat`ted a
path that cannot exist, got `unsupported`, and the React bundle draws a
non-clickable toggle for that value. What *was* wrong: a live route wrote to a
path no supported kernel has, a `cat` of it was spawned on every status poll,
and **the off half of both setters was unreachable for any form-encoded caller** —
`$p['state'] == true` is TRUE for the string `'false'`, so "turn it off" turned
it on.

**What the toggle now achieves, exactly.** Mainline KSM scans a mapping only if
its owner asked with `madvise(MADV_MERGEABLE)`. Neither `smart_scan` nor the
`advisor_mode` governor changes that — they tune how hard ksmd works on the set
it already has. QEMU asks: `mem-merge` defaults to on, and a 512 MB guest's RAM
mapping carries the `mg` VmFlag in `/proc/<pid>/smaps` with no configuration at
all. Three CirrOS guests at 512 MB, `run` 0 → 1:

```
pages_sharing   0 -> 22900        (~89 MB of guest RAM collapsed)
pages_shared    0 -> 9111
general_profit  0 -> 85,590,848 bytes
```

**So it is a QEMU control and nothing else.** VPCS, dynamips and IOL never
madvise; Docker nodes are the container's processes; a template whose
`qemu_options` carry `mem-merge=off` opts that node out and the toggle cannot
override it. `prctl(PR_SET_MEMORY_MERGE)` would cover the rest and is
deliberately not done — a per-node memory-behaviour change made from a host-wide
button.

`run` is 0 stop, 1 run, 2 stop-and-unmerge, and it reads back what was written,
so 2 is a state and not an edge. **Off writes 0, not 2**: unmerging N shared
pages needs N free pages *now*, and the dense host is the only reason to have
had KSM on. Both 0 and 2 report `disabled`, which is true of both. Writing 2 took
`pages_sharing` 22900 → 0, measured.

**One thing is not finished and cannot be here.** `getInfo()` still reports a
`uksm` field, pinned to `'unsupported'`. Removing it needs a frontend rebuild:
the committed bundle draws a **live** toggle for any value that is not that
literal, an absent key included, so dropping the key turns a correctly inert row
into a button for a control that does not exist. There is no node toolchain on
the reference VM and building on the workstation is not allowed. The row goes
when the frontend is next built.

### The tap leak, and the account it now pins

`prepare()` creates the taps first, so every later error return leaked one per
interface — and nothing collected them, because `stopNode()` did all of its work
inside `if ($this->getStatus() != 0)` and a node whose start failed reports 0.
Stop was a no-op on exactly the node that needed it.

Since the reaper landed this is worse: it refuses to remove an account while a
`vunl<session>_*` exists, so **one orphaned tap pins one Unix account for the
life of the host**. Fixed in `device::start()`, the single funnel every node
type reaches through `parent::start()`: every non-success exit unwinds the taps
and reaps the tenant. `stopNode()` releases taps outside the status guard, so an
older orphan is collected the next time that node is stopped or deleted.

**A pre-existing orphan whose lab is already deleted has no caller and stays.**
Recovery is by hand: `sudo tunctl -d vunlN_x`, then
`sudo unl_wrapper -a reap-tenant -S N`. That was not widened into the reaper —
its refusal is what stops a running node losing its uid.

Two other defects fell out of the same six lines. `return;` for an empty command
conflated Docker (no emulator command — success) with
`device_qemu::command()` returning `array(False,False)`, which never reached
that test at all because `secureCmd()` runs first and `preg_match()`s the array
— **a PHP 8 TypeError that took the whole request down**. And the teardown it
replaced matched by prefix: `grep 'vunl1_'` matches `vunl12_0`, so stopping
session 1 on a busy host tore down session 12's data plane.

### AppArmor: not yet, and the fork does not disable it

`/proc/cmdline` is clean, `/sys/module/apparmor/parameters/enabled` is `Y`, 119
profiles are loaded with 24 enforcing — including `docker-default` and
`unprivileged_userns` — and
`kernel.apparmor_restrict_unprivileged_userns = 1`. Nothing in `install/`,
`tools/` or `platform/` touches GRUB, the service or that sysctl. `--only verify`
now asserts all four, softly, and a test holds the installer to it.

A profile *can* be loaded without a reboot — proven: an empty complain-mode
profile attached to a running QEMU node (`pnetlab-qemu-probe (complain)` on the
QEMU pid) and the node still booted and passed frames. It is still not shipped.
`unl_wrapper` cannot usefully be confined, and a QEMU profile needs
`/opt/unetlab/tmp` tightened first or its workspace rule has to be globbed
across tenants. `docs/APPARMOR.md` has the audit records and the order of work.

### Docker images on an offline host

The generic `docker` template is hardcoded selectable in `getTemplates()` —
unlike every named docker template, it never asks the daemon — so a user on an
image-less box adds a Docker node and it fails at start with 80083.
`/opt/unetlab/addons/docker` plus an installer step now load `docker save`
archives; `tools/docker-images.sh` does both halves. It has **no signature
check**, and `docs/PACKAGES.md` now records that its own `docker_pull` verb
cannot work offline and names `install_docker_image` as the replacement.

### Numbers

```
sudo bash install/install.sh --skip packages,store --server-name pnetlab.test
→ INSTALLER-EXIT=0, 0 [fail], all verification checks passed

tools/integration/lab-functional.sh    59 shell, 8 data-plane, 0 failed  (was 55 + 8)
tools/integration/node-types.sh        30 passed, 0 failed, 1 skipped (IOL)
tools/integration/db-backup-restore.sh 67 passed, 0 failed
tools/integration/guacamole-console.sh 35 passed, 0 failed
tools/integration/wrapper-console.sh   44 passed, 0 failed
tools/integration/wrapper-docker.sh    45 passed, 0 failed
tools/integration/iol-dataplane.sh     75 passed, 0 failed
make -C platform/wrappers/src test     248 unit assertions, 0 failed
tools/run-tests.sh                     1559 assertions across 31 files, 0 failed
                                                            (was 1480 across 28)
tools/php-lint.sh (8.4 and 7.4)        352 files, 0 failed  (was 349)
sudo policy                            24 grants, unchanged
shell-escaping baseline                73 of 73, unchanged
```

The four new lab-functional assertions are the FAILED START section. Against
`devices/device.php` at its parent commit, same suite, same host, two of them
fail: *"the failed start left no tap behind — vunl1_0"* and *"and left no tenant
account pinned — unl1 survives with no node running"*. That is the negative
control; the fix is not taken on trust.

### Traps this cost time to find

1. **`--only deploy` does not update `/opt/unetlab/wrappers/unl_wrapper`.** That
   is `--only platform`. A wrapper change tested after a deploy-only run is
   testing the old wrapper, and the symptom is a fix that appears not to work.
2. **A complain-mode AppArmor profile logs only what it would have DENIED.** A
   probe profile granting `file, capability, network, …` at the top level grants
   everything and produces a completely clean audit log that reads exactly like
   a successful confinement. The body has to be empty.
3. **Deleting a bridge does not force a node start to fail.** `unl_wrapper -a
   start` walks the node's interfaces and calls `addNetwork()` for each one
   before starting anything, so it rebuilds the bridge and the node starts. The
   first version of the failed-start test passed for that reason.
4. **Making the running directory immutable does not force it either**, because
   `.prepared` already exists after a successful start and touching an existing
   file is not an entry creation. The FILE has to be immutable.
5. **`node_sessions` has no `node_id` column.** The node's id within the lab is
   `node_session_nid`. A query using the obvious name returns nothing, silently,
   and every path built from it is wrong in a way that looks like a passing test.
6. **A comment that quotes the thing it removed will fail the test that checks
   it was removed.** `code_only()` in `tests/bootstrap.php` strips comments with
   `token_get_all()` for exactly this; the house style of writing down what went
   away is otherwise in tension with the tests that enforce it.
