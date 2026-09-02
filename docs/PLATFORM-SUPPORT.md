# Platform support

**Ubuntu 24.04 LTS is the supported platform. It is the only one anything here
has been verified on.** The installer runs on other releases, says so once, and
names what will break; it does not refuse.

This document exists so that the eventual move to 26.04 is a short job rather
than a discovery exercise. It records what is verified, what is at risk with the
evidence for each, and what a bring-up still owes. Written 2026-09-01.

**Nothing in this document was tested on 26.04.** No 26.04 host exists in this
project: the reference VM is 24.04, and Ubuntu gates LTS-to-LTS upgrades until
the `.1` point release, so the box reports no upgrade available. Every 26.04
claim below is an archive query, cited, and is a statement about *package
availability only*. Where a question cannot be answered without a running host,
it is listed as unanswered rather than guessed at.

---

## Supported and verified: Ubuntu 24.04 LTS (noble), amd64

"Verified" means, from `docs/HANDOVER.md`, measured on the reference VM against
a clean checkout of HEAD unpacked onto a provisioned host:

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

This block was stale by three sessions when it was last read. If you change a
suite, change it here too; the numbers are the only thing that distinguishes
"verified" from "believed".

That is what the word carries here: the installer completes from nothing, its
own verification step passes, and the suites above pass on the host it built.
It does **not** cover IOL nodes, which are implemented and unit-tested but have
never been run against a licensed image.

The stack that was verified: PHP 8.4 from `ppa:ondrej/php` under FPM, Apache
2.4 with `mod_proxy_fcgi`, MariaDB 10.11, guacd 1.3.0 with the Guacamole 1.5.5
web application on jetty9 9.4.53, openjdk-17, Docker 29.1.3 on cgroup v2 with
the systemd driver.

Three kernel-era facts the installer now asserts rather than assumes, because
each was an appliance-era assumption that inverted on a stock kernel:

| Fact | On 24.04 | Was, on the appliance |
|---|---|---|
| memory dedup | mainline KSM at `/sys/kernel/mm/ksm/run`; UKSM absent | UKSM only — `CONFIG_UKSM=y`, `CONFIG_KSM_LEGACY=n` in a custom 4.15 |
| cgroups | `cgroup2fs`, v2 only, `Cgroup Driver: systemd` | v1 with `cgroupfs` |
| AppArmor | enabled, `unprivileged_userns` enforcing, `apparmor_restrict_unprivileged_userns=1` | `apparmor=0` on the kernel command line |

The memory-dedup toggle is a **QEMU** control and nothing else: KSM scans only
mappings whose owner called `madvise(MADV_MERGEABLE)`, QEMU does that by default
(`mem-merge`), and VPCS, dynamips, IOL and Docker-backed nodes do not. Measured:
three 512 MB CirrOS guests reached `pages_sharing` 22900 within one full scan.
`docs/HANDOVER.md` has the rest, including why `off` writes 0 rather than 2.

---

## What the installer derives, and what it still pins

| Fact | How it is decided |
|---|---|
| PHP version | Preference 8.4; resolved against what the host can install, floor 8.2. `--php-version` or `PHP_VERSION=` makes it a pin, and an unavailable pin is fatal |
| fpm socket, unit name, drop-in dir, `a2enconf` name, verify checks | all derived from the resolved PHP version |
| `ppa:ondrej/php` | added only when the archive does not already carry the wanted PHP, and only after a HEAD on the PPA's `Release` for this release's codename |
| `libguac-client-*` package names | resolved: the `t64` name, else the pre-transition name |
| Guacamole packages as a set | checked for availability before anything is installed; absent means the step skips, not that the install fails |
| mariadb/mysql client binary | resolved by `have` |
| Supported release | `SUPPORTED_RELEASE` in `install/install.sh`, one warning naming the risks |
| **Still pinned:** `jetty9` and its `/etc/jetty9`, `/var/lib/jetty9`, `jetty9.service` paths | there is no second servlet container to fall back to |
| **Still pinned:** `openjdk-17-jre-headless` | Jetty 9.4 claims support only through 17 |
| **Still pinned:** `JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64` | the drop-in is installed byte-for-byte, not rendered; amd64 is the only architecture this fork has run on |
| **Still pinned:** Guacamole 1.5.5 `.war` + JDBC extension, by SHA-512 | `install/vendor/guacamole/SHA512SUMS`; the pin is the point |
| **Still pinned:** guacd 1.3.0 | whatever the archive has; the web app negotiates down |

---

## The 26.04 risk register

Ordered worst first. Every "evidence" line is a query anyone can repeat.

### 1. HTML5 consoles have no packages at all — blocking

`guacamole-server` — `guacd` and every `libguac-client-*` — is published for
24.04 and was not carried forward.

- `https://packages.ubuntu.com/search?keywords=guacd&searchon=names&suite=all&section=all`
  returns jammy `1.3.0-1.1` and noble `1.3.0-1.3ubuntu1`, and nothing for
  questing (25.10) or resolute (26.04).
- `https://packages.ubuntu.com/search?keywords=guac&searchon=names&suite=resolute&section=all`
  returns "Sorry, your search gave no results".
- `https://launchpad.net/ubuntu/+source/guacamole-server` lists published
  versions for noble, jammy, bionic, xenial and trusty only.

So the `t64` question — will `libguac-client-telnet0t64` survive 26.04? — has an
answer, and it is not the one that was expected: the name does not survive
because the *source package* does not. The `t64` suffix itself is not the
fragile part; a renamed library keeps the suffix until its SONAME moves again.

The installer now checks for these packages and skips the console step with an
explanation instead of dying inside apt. **A 26.04 bring-up must build
guacamole-server from source** (or find a maintained backport), and must match
guacd's version to the staged web application. Note that
`install/lib/guacamole.sh` documents 1.3.0 as the fallback *because* it is exact
parity with noble's guacd; building from source removes that constraint and
makes 1.5.5-on-1.5.5 the obvious pairing instead.

### 2. PHP 8.4 is not obtainable the way it is today — needs a decision

- 26.04 ships PHP 8.5: the `php-fpm` metapackage in resolute is
  `2:8.5+99ubuntu1`
  (`https://packages.ubuntu.com/search?keywords=php-fpm&searchon=names&suite=resolute&section=all`).
- `php8.4-fpm` is in questing (25.10) and **not** in resolute
  (`https://packages.ubuntu.com/search?keywords=php8.4-fpm&searchon=names&suite=all&section=all`).
- `ppa:ondrej/php` has no resolute pocket. Measured directly, 2026-09-01, from
  this workstation:
  `HEAD https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/resolute/Release`
  → 404, while the same URL with `noble` → 200. Launchpad's PPA page lists
  published packages for noble and jammy only.

Left alone, the old installer would have added a repository with no Release
file for this release and then failed every subsequent apt call. It now probes
first, does not add it, and falls back to the newest `phpX.Y-fpm` the archive
carries — 8.5 on 26.04 — with a warning that says the fallback is not verified.

**Unanswered:** whether this tree runs on PHP 8.5. CI lints 8.4 and 8.5, so the
syntax is covered; nothing has ever been *executed* on 8.5. That is a bring-up
task, not a fact.

### 3. jetty9 is fine, which is the surprise

`docs/ROADMAP.md` treats the servlet container as the thing most likely to
break. It is not.

- `jetty9` is published for resolute at `9.4.58-1`, universe
  (`https://packages.ubuntu.com/search?keywords=jetty9&searchon=names&suite=all&section=all`),
  as well as jammy `9.4.45-1`, noble `9.4.53-1` and questing `9.4.57-1`.
- The documented escape hatch survives too: `libtomcat9-java` is in resolute at
  `9.0.115-1ubuntu0.1`.

Jetty 9.4 is still EOL upstream and still loopback-only behind Apache, which is
what contains it. The debt is unchanged; the deadline is not 26.04.

### 4. Supporting packages are all present

Checked against suite `resolute`, so these are not open questions:

| Package | resolute |
|---|---|
| `openjdk-17-jre-headless` | `17.0.20+8-1~26.04` |
| `libmariadb-java` | `2.7.6-1build1` |
| `libtomcat9-java` | `9.0.115-1ubuntu0.1` |
| `vpcs` | `0.8.3-1.1` |
| `dynamips` | `0.2.14-1build5` (multiverse) |
| `uml-utilities` | `20070815.4-2.1` |
| `mariadb-server` | `1:11.8.6-5ubuntu0.1` (24.04 has 10.11) |

MariaDB moving 10.11 → 11.8 is a real jump and the two schemas (`pnetlab_db`,
`guacdb`) have never been imported into it. The installer creates databases as
`utf8mb4 / utf8mb4_general_ci` explicitly, so the server default does not
silently decide it — but "the dumps import cleanly" is untested.

### 5. What no archive query can answer

None of these can be settled without a 26.04 host, and none should be written
down as done until one exists:

- Does the application run on PHP 8.5 (not just lint)?
- Does a source-built guacd negotiate with the staged web application, and does
  `tools/integration/guacamole-console.sh` pass?
- The php-fpm confinement drop-in against a newer systemd — the `ProtectSystem`
  behaviour it works around is version-sensitive, and the trap recorded in
  `install/systemd/php-fpm-pnetlab.conf` was measured on 24.04's systemd.
- AppArmor and unprivileged user namespaces. The fork still ships no profile
  and that is deliberate (`docs/APPARMOR.md`); what a 26.04 bring-up owes is a
  re-run of the four host facts `--only verify` now checks — the kernel switch,
  `apparmor=0` absent from the command line, the userns restriction, and
  `docker-default` loaded — since a release that changed any of them would
  change the fork's security posture without anyone deciding to.
- `qemu-system-x86` behaviour changes, and whether the `/opt/qemu/bin` symlink
  layout still satisfies the templates.

---

## Checklist for whoever does the 26.04 bring-up

Roughly in dependency order. Each item is either a decision or a measurement;
none of it is design work.

1. **Build a clean 26.04 host** and record it in `docs/REFERENCE-ENVIRONMENT.md`
   the way the 24.04 one is recorded.
2. **Run the installer unmodified** and keep the transcript. It should warn
   about the release, resolve PHP to 8.5, skip the console step naming guacd,
   and otherwise complete. Anything else it does is a finding worth a commit
   message.
3. **Decide the PHP version.** Either accept 8.5 from the archive — then run
   `tools/php-lint.sh` and `tools/run-tests.sh` with `PHP=php8.5` on the box,
   not the workstation — or find a source for 8.4 and pin it with
   `--php-version`. Do not leave it implicit.
4. **Run the suites** listed at the top of this document and diff the counts
   against the 24.04 numbers. A changed count is the interesting output, not
   the pass/fail.
5. **guacamole-server.** Build it from source at the version of the staged
   `.war` (1.5.5 today), package or install it, and then re-run
   `sudo install/install.sh --only guacamole`. If the version pairing changes,
   `install/vendor/guacamole/SHA512SUMS` needs a reviewed line for the new
   artefacts — never edit an existing line.
6. **Re-check the availability guard.** Once guacd exists locally, confirm the
   guard passes rather than skipping; a guard that cannot be turned off is a
   bug.
7. **Import both schemas into MariaDB 11.8** and confirm table counts
   (`pnetlab_db` ~11, `guacdb` ~23) and that logins work.
8. **Docker on cgroup v2**, then `tools/integration/wrapper-docker.sh`. The
   installer now checks the hierarchy and the daemon's own report, so
   `--only verify` answers this before any node is started; on 24.04 it reports
   `cgroup2fs`, `Cgroup Version: 2` and `Cgroup Driver: systemd`, all green.
9. **Confirm the php-fpm drop-in still does its job**: start a node and watch
   for `useradd: cannot lock /etc/passwd`. If the confinement behaviour moved,
   update the drop-in's header with what was measured, not with what was tried.
10. **Then, and only then**, change `SUPPORTED_RELEASE` in
    `install/install.sh`, update the verified block at the top of this file with
    the new numbers, and say what "verified" now covers.

Two things to keep true while doing it: the installer must still work on 24.04
when you are finished, and nothing goes in this document as verified that was
not run.
