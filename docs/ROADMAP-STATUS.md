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
| Build the `www-data` sudo allowlist | 42 grants → 23 |
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
| **Invert `secureCmd` to an allowlist** | done — three named shapes, `SecureCmdTest` 146 assertions. See below |

### Open

| Bullet | State |
|---|---|
| **Upgrade axios** | **not done.** `package.json` pins `^0.19.0` |
| **Convert call sites to argument arrays** | **partial.** 47 sites remain in `tests/Security/shell-escaping-baseline.txt`, down from 73. What is left is `devices/` — see below |
| **Run emulators as the tenant user** | **partial.** VPCS and QEMU do. Docker cannot — there is no emulator process, the daemon runs the container as root, and the host-side work needs `CAP_NET_ADMIN`. Dynamips is **not** one line away; the reason is below. IOL still drops in-process |

### Closed: `secureCmd` is an allowlist

Three named shapes, and every call site declares which one it means:
`SECURE_TOKEN` for a bare identifier (the seventeen sites in `includes/cli.php`),
`SECURE_PATH` for a request-supplied path, `SECURE_LINE` for a whole command
line, parsed rather than pattern-matched. The default is the strictest, so a
call site added without declaring a shape fails closed. The ten metacharacters
`SecureCmdTest` used to assert were accepted are now asserted to be rejected,
under the same descriptions, so the two revisions of that file read as a before
and after.

It is **defence in depth and says so**. `SECURE_LINE` proves a string cannot
spawn a second command; it does not prove the arguments are the intended ones,
because an unquoted space is still a word separator. The control is
`escapeshellarg` at the interpolation or `proc_open` with an argv array.

Two things came out of tracing the callers that are worth keeping:

- **`checkFolder()` was the real control on the folder routes**, not `secureCmd`.
  It is `preg_match('/^\/[\/A-Za-z0-9_ -]*\z/', $s)` in `devices/functions.php`,
  an allowlist applied before the `exec`, stricter than anything in
  `api_folders.php`, and documented nowhere. Measured against the parent commit:
  a folder named `x$(touch proof)y` can be created — `apiAddFolder()` validated
  nothing — and deleting it is refused with 60009, nothing executed. The open
  half was `apiAddFolder`, which is validated now.
- **`Admin/LabsController::getDepends()` was the one site genuinely held up by
  the blocklist**: `sudo qemu-img info --backing-chain <path> | grep image` with
  the path built from an uploaded lab's `image` attribute. It is an argv array
  now, and without the `sudo`, which retired the `qemu-img` grant.

Reading `checkFolder()` also turned up two wrong characters in all four
validators in `devices/functions.php`: `\s` is `[ \t\n\r\f\v]` and not a
space, so a folder or lab name containing a **newline** passed; and `$` without
`/D` matches before a trailing newline. Both fixed, both asserted.

### What is left in the escaping baseline

47 entries, and with one exception all of them are `devices/`: the template
option strings, the per-interface flags `getFlag()` concatenates, and the TiMOS
family. Those are **argument injection by design** — the design decision the fork
still owes — and escaping does not fix them. Every route from request data to a
shell through an ordinary API handler has gone.

The exception is `includes/functions.php $value`, which is `secureCmd()`'s own
return: validating a value is not escaping it, and the one live path through it
is the emulator command line, which is the same surface.

Three deliberate leftovers are the accessors `__lab.php`/`__node.php`
`$this->session` and `$this->port`. Casting inside `getSession()` would turn a
null session into `0` at 173 call sites, six of which guard on `== null`. That
is a behaviour change on the node-start path to buy three lines in a text file.

### Dynamips is not one line away

Recorded here because the previous handover said it was, and that was
measurable without an IOS image.

For an **Ethernet-only** dynamips node it probably is: `prepare()` already
creates the tap with `addTap($tap_name, $user)`, the running directory is
`root:unl 0775`, the console comes from `-T` on a 3xxxx port, and none of that
needs root — the same argument that carried VPCS.

For a node with **serial** interfaces it is not. The five adapters in
`devices/dynamips/adapters/` build `-s <slot>:<port>:unix:<local>:<remote>` over
sockets at `/tmp/dynamips/<session>_<if>`, and **dynamips itself creates that
socket**. `device_dynamips::prepare()` does `mkdir('/tmp/dynamips')` with no
mode, from inside `unl_wrapper`, so it lands `0755 root:root` and a dropped
process cannot create a file in it. Flipping `runsAsTenant()` alone would leave
serial-connected dynamips nodes failing to start, on a host where nobody here
can notice.

Making it work needs the directory to be per-tenant or at least group-writable,
and if it is shared it is the same cross-tenant seam as `/opt/unetlab/tmp`: one
tenant could open another's serial socket. `iol.c` already solved this shape by
deriving `/tmp/netio<uid>` from the running uid, and that is the pattern to copy.

So the flag was left alone. It is a three-part change — `runsAsTenant()`, the
socket directory, and its ownership — and it still needs a licensed IOS image to
verify, which is the part that has not changed.


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
