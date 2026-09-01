# Handover

**State at end of session, 2026-09-01.** Branch `phase-02-shell-hardening`,
50 commits ahead of `main`, none pushed. Nothing uncommitted.

The previous handover is the commit before this one, if you want to see what
changed and why.

---

## Where this got to

The fork **deploys to a brand-new Ubuntu 24.04 server on PHP 8.4, runs labs, and
no longer needs anything from the upstream appliance image.** The installer
compiles the console wrappers from source as one of its steps.

Last verified run, from a clean `git archive` of HEAD onto a provisioned host:

```
sudo bash install/install.sh --server-name pnetlab.test
→ INSTALLER-EXIT=0, every step, all verification checks green

bash tools/integration/lab-functional.sh   → 49 shell assertions, 8 data-plane checks, 0 failed
bash tools/integration/node-types.sh       → 28 passed, 0 failed, 1 skipped (IOL)
bash tools/integration/guacamole-console.sh→ 35 assertions, 0 failed
bash tools/integration/wrapper-console.sh  → 44 assertions, 0 failed
bash tools/integration/iol-dataplane.sh    → 75 assertions, 0 failed
make -C platform/wrappers/src test         → 248 unit assertions, 0 failed
tools/run-tests.sh                         → 865 assertions across 22 files, 0 failed
tools/php-lint.sh (8.4 and 7.4)            → 340 files, 0 failed
```

---

## What works, and what does not

| | |
|---|---|
| Legacy API (18 routes) | works |
| Laravel 10 admin UI on PHP 8.4 | works |
| VPCS nodes | works, end to end |
| QEMU nodes, VNC console | works |
| QEMU nodes, **telnet** console | works — reimplemented wrapper |
| **Docker**-backed nodes | works — reimplemented wrapper, unix socket |
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

## The five non-obvious things

Unchanged from the last handover, because they are still true and still cost
time. Items 1–3 are as before; 4 and 5 have moved.

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

---

## Security work in this session

**The sudo policy is down from 42 grants to 26.** Retired: `nproc`, `top`,
`docker`, `dos2unix`, `php`, `perl`, `kill`, `cp`, `mv`, `mkdir`, `link`,
`chown`, `chmod`, `touch`, `tee`, `echo`. `tests/Security/SudoersPolicyTest.php`
enforces drift in **both** directions, so a grant cannot return without a call
site and a call site cannot return without a grant.

It is still **not a privilege boundary**. `rm` remains (four `rm -rf` sites on
escaped workspace paths), and `www-data` is in the `docker` group, which is
root-equivalent by design — anyone who can talk to the daemon can bind-mount
`/` into a container. What changed is that the surface is countable and the
remaining items each have a named reason in the policy file.

Four root-code-execution paths were closed:

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

1. **Review and merge.** 50 commits is a lot, but each is individually scoped
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
5. **Phase 05, severing the upstream dependency.** `License::keepalive()` still
   relicenses against pnetlab.com and `Query::boxCenter()` still ships an
   encrypted machine UUID upstream. The signed-package work makes this cheaper
   than it was.
6. **Backup and restore** (`unl_wrapper backupdb`/`restoredb`) is a shipped
   feature no plan has ever priced, and it moves with the `guacdb` schema.

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
| `docs/OFFLINE-FIRST.md` | the accepted architectural direction |
| `platform/wrappers/src/README.md` | the wrapper core API and its provenance |
| `docs/audit.html` | single-page summary of the live-box findings |

`docs/ROADMAP.md` predates most of this session. Where it and this document
disagree about what works, this one was measured more recently.
