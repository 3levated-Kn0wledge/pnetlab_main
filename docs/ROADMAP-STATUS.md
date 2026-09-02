# Roadmap status

Bullet-by-bullet state of `ROADMAP.md` phases 00–04, audited against the tree on
2026-09-02 rather than against anyone's recollection. Every "done" below was
checked; every "open" has the evidence that says so.

The roadmap itself lives outside this repository, in the investigation
workspace. This file is the fork's own record of what has actually been met,
because "are we done with phase N" was a question nothing in the repo could
answer.

**Phases 05, 06 and 07 are not started** and are not audited here.

Two bullets are **declined by decision** rather than outstanding. They are
marked as such, with the reasoning, because a deferred item that looks like an
overlooked one gets rediscovered every few months.

---

## 00 · Repository hygiene, secret triage

| Bullet | State | Evidence |
|---|---|---|
| Cut the blanket root grants | done | policy is an allowlist; `SudoersPolicyTest` |
| Set curl timeouts | done | `Query.php` `CONNECT_TIMEOUT = 5`, `TIMEOUT = 30` |
| Purge `store/.env` from history | **declined** | see below |
| Add a root `.gitignore` | done | present |
| Rotate the `APP_KEY`, make generation a mandatory install step | done | `install/lib/store.sh` generates one and refuses the committed key |
| Flip defaults to `APP_DEBUG=false`, `APP_ENV=production` | done | `store/.env.example`, and the installer forces both |
| Delete committed junk | done | `store/public/error_log`, `store/v8-compile-cache-0` both untracked |
| Publish `SECURITY.md` | done | present |

### Declined: purging `store/.env` from history

The file is untracked as of `323aaab` and replaced by `store/.env.example`, and
`EnvNotTrackedTest` keeps it that way. The history was **not** rewritten.

The reasoning, so it is not relitigated: the committed `APP_KEY` is published in
upstream's own repository, so purging ours removes it from one copy of two and
changes nothing about its exposure. What actually protects a deployment is
`install/lib/store.sh`, which generates a per-installation key and explicitly
refuses to keep the committed one — that was already true before the file was
untracked, and it is why no deployed box was ever affected. Against that,
rewriting history means rewriting 22 already-published commits and invalidating
every existing clone.

**Treat the committed key as burned.** It is in this history and in upstream's.
Revisit this decision if the repository is ever made public with a stronger
claim than "this key was always public".

---

## 01 · Build and boot on the modern stack

Complete. All five bullets verified:

| Bullet | Evidence |
|---|---|
| Drop `node-sass` | absent from `package.json` |
| `ckeditor5-build-classic` imported but not declared | now declared, `package.json` |
| `webpack.mix.js` copies an uncommitted `assets/fonts` | copy removed; only `img` and `js` remain |
| Rename the colliding `array_find()` | no such function in `includes/functions.php` |
| `ReturnTypeWillChange` on ~12 Slim methods | 12 — six in `Helper/Set.php`, six in `Middleware/Flash.php` |
| Migrate `.htaccess` off mod_php | directives guarded behind `<IfModule mod_php.c>` / `mod_php7.c`, with `.user.ini` carrying them for FPM |

---

## 02 · Establish a privilege boundary

Most of it is done, and the parts that are not are named here rather than
rounded up.

### Done

| Bullet | Evidence |
|---|---|
| Build the `www-data` sudo allowlist | 42 grants → 24 |
| Reap tenant accounts | `unl_wrapper -a reap-tenant`; a full lab run leaves zero `unl*` accounts |
| Tighten `/opt/unetlab/tmp` | `755 www-data:www-data` here, against `2777 root:unl` on the appliance |
| Replace unsalted SHA-256 with `password_hash` | `PasswordHashingTest` |
| Stop returning the password hash from `GET /api/auth` | asserted in `lab-functional.sh` |
| `SameSite` and `Secure` on the token cookie | `AuthCookie`; Secure is conditional on `request()->isSecure()`, which is correct for a box served over plain HTTP |
| Fix MD5 lab passwords | `LabPasswordTest` |
| Rotate the hardcoded MySQL root password | installer uses unix-socket root and sets no password |
| Remove or authenticate `/auth/{controller}/{method}` | `RoutingTest` |
| Re-enable `VerifyCsrfToken` | `CsrfTest`, 107 assertions |
| Delete `CorsMidware` and the wildcard origin | middleware gone |
| Regression tests for metacharacter payloads | `ShellEscapingTest`, `SecureCmdTest` |

### Open

| Bullet | State |
|---|---|
| **Invert `secureCmd` to an allowlist** | **not done.** Still the denylist `/[#;\|&]\|\.{2,}/m`. `SecureCmdTest` documents ten metacharacters it accepts — backticks, `$( )`, newline, `>`, `<`, space, `$HOME`, quotes, globs — so the gap is measured, not suspected |
| **Upgrade axios** | **not done.** `package.json` pins `^0.19.0` |
| **Convert call sites to argument arrays** | **partial.** 73 sites remain in `tests/Security/shell-escaping-baseline.txt`. Everything added this cycle uses `proc_open` with an argv array |
| **Run emulators as the tenant user** | **partial.** VPCS and QEMU do. Docker cannot — there is no emulator process, the daemon runs the container as root, and the host-side work needs `CAP_NET_ADMIN`. Dynamips is one line away but has no image to verify against. IOL still drops in-process |

---

## 04 · Platform bring-up

The roadmap targets 26.04. **The supported platform is 24.04** — see
`docs/PLATFORM-SUPPORT.md` for why, and for what a 26.04 bring-up still owes.
The bullets below are assessed against what was actually built.

### Done

| Bullet | Evidence |
|---|---|
| Wrappers: the compiled surface is smaller than assumed | three reimplemented, two established as unnecessary, `nsenter` is stock util-linux |
| Drop the custom kernel | running mainline 6.8 |
| Port the Guacamole console proxy | `guacamole-console.sh`, 35 assertions, plus a live tunnel to a real node in `node-types.sh` |
| Migrate the database engine | MariaDB 10.11, against the appliance's EOL MySQL 5.7 |
| Swap UKSM for mainline KSM | done — see the correction below |
| Fix the tap leak on failed node start | `TapLeakTest`, plus a FAILED START section in `lab-functional.sh` that fails against the parent commit |
| Verify Docker against cgroup v2 | `verify.sh` hard-checks `cgroup2fs`, no hybrid mounts, and `Cgroup Version: 2`; `HostHardeningTest` |
| Solve offline Docker image seeding | `tools/docker-images.sh`, a `docker-images` installer step, `docs/DOCKER-IMAGES.md`; exercised with no registry reachable |
| Ship an AppArmor profile | **decided against**, with the audit and ordering in `docs/APPARMOR.md` — see below |

### Open

| Bullet | State |
|---|---|
| Migrate `brctl`/`tunctl`/`ifconfig` to iproute2 | **deferred** — see below |
| Validate 32-bit IOL via i386 multiarch | **blocked.** Needs a licensed IOL image, which this project deliberately does not carry |

### Correction: what was actually wrong with KSM

My first audit of this bullet was wrong, and it is corrected here rather than
quietly amended. I claimed the memory-dedup toggle in the admin UI had been
doing nothing. It had not: `apiSetKsm` already wrote `/sys/kernel/mm/ksm/run`
and already worked, measured over HTTP before any change was made. The UKSM row
beside it was *inert* rather than broken — `getInfo()` cat'd the missing UKSM
path, got `unsupported`, and the frontend draws that value as a non-clickable
toggle.

What was genuinely wrong was smaller and worse than a dead path: a live route
still wrote a path no supported kernel has, status polling `cat`'d a file on
every poll, and **the off half of both setters was unreachable for form-encoded
callers**, because `'false' == true` is TRUE in PHP. Asking to turn KSM off
turned it on.

What the toggle achieves is now measured rather than assumed: **QEMU nodes
only**. KSM scans only mappings whose owner marks them `MADV_MERGEABLE`, and
QEMU does that by default; three CirrOS guests took `pages_sharing` from 0 to
22,900. VPCS, dynamips, IOL and Docker get nothing from it, and a template
setting `mem-merge=off` opts out in a way the toggle cannot override. Off writes
`0` and not `2`, because unmerging N pages needs N free pages immediately, on
the host that had reason to enable sharing in the first place.

### Decided: no AppArmor profile

Not shipped, and that is a decision rather than an omission. `unl_wrapper`
needs `/etc/passwd`, `tunctl`, `ip`, `brctl`, the Docker socket, `mysqldump` and
the ability to exec template-supplied command lines — a profile permitting all
of that permits everything, and a QEMU profile needs `/opt/unetlab/tmp`
tightened first or its workspace rule globs across tenants.

What ships instead: the audit in `docs/APPARMOR.md`, the order the work has to
happen in, and assertions in `verify.sh` and `HostHardeningTest` that nothing in
our installer disables AppArmor — which is exactly what upstream did, with
`apparmor=0` on the kernel command line.

### Deferred: iproute2 migration

20 call sites across `includes/` and `devices/` still use `brctl`, `tunctl` and
`ifconfig`, and all three remain in the sudo policy. Migrating to `ip link` /
`ip tuntap` would retire three grants, taking the policy from 24 to 21.

Deferred deliberately to Phase 05 or later. All three tools still ship on 24.04
and work correctly, so this is forward-looking rather than a live defect, and
the change sits squarely on the node-start and data-plane path — the highest
regression risk per unit of benefit in the remaining work. The grants it would
retire are real but are not the dangerous ones; `ip` itself stays regardless,
and `ip netns exec` is a root shell, so the count would fall without the
privilege surface changing much.

---

## What "done" means here

Every phase above is judged on evidence in the tree or on a measurement against
the reference VM, not on a commit having been written. Where a bullet was met by
a different route than the roadmap proposed — signed packages instead of
deleting the marketplace, 24.04 instead of 26.04, reaping instead of a fixed
account pool — the divergence is recorded in the relevant document rather than
folded quietly into a tick.
