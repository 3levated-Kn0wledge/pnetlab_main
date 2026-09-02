# Phase 04 exit: fixes to complete first

Phase 04's bullets are met (`ROADMAP-STATUS.md`). This file is the other half of
that judgement: a full review of the work that met them found defects that the
bullet-level audit does not catch, because a bullet asks "was the thing built"
and a review asks "does the thing work". **These were the fixes to complete
before Phase 05 opens.**

## Status — 2026-09-02: the gate is clear

All fifteen gate items are fixed, one commit each, on `phase-04-exit-fixes`.
Every secondary row is fixed or carries a written deferral below. Each fix was
verified on the reference VM from a clean `git archive` of the branch head,
through the installer; the numbers are in the "Verification" section at the
foot of this file. Where a PHP finding could only be verified by reading when
this file was first written, it has now been verified by running.

Source of the findings: review of `git diff origin/phase-02-shell-hardening...HEAD`
on 2026-09-02 — 60 non-merge commits, 206 files, ~35k insertions, working tree
clean. Seven parallel passes (device/exec PHP, legacy `includes/` + `api.php`,
privileged wrapper actions, C console/IOL wrappers, signed-package system,
Laravel `store/` + front-end, installer shell).

---

## The gate

| # | Where | What | Sev | Fixed |
|---|---|---|---|---|
| 1 | `store/app/Console/Commands/PackageRun.php:152` | private `fail()` illegally narrows `Command::fail()`; every `php artisan` fatals | 🔴 | `7e9730a` — renamed `abort()`. Old class fatals on the VM with "Access level to …fail() must be public"; new one declares; `php artisan list` runs |
| 2 | `store/app/Http/Controllers/Admin/SystemController.php:109` | Fix Permissions reverses this branch's own docroot hardening and world-reads `APP_KEY` | 🔴 | `dbb2d28` — the wrapper case applies deploy.sh's recipe; pinned both ways by `HostHardeningTest`. Pressed on the VM: docroot `root:root`, `.env` `root:www-data 0640`, zero world-writable files |
| 3 | `platform/wrappers/src/console.c:133` | listener not `CLOEXEC`; an orphaned child holds the port and the node reads as running forever | 🔴 | `f7f207d` — `SOCK_CLOEXEC` on the listener, `accept4(SOCK_CLOEXEC)` on every client |
| 4 | `install/lib/platform.sh:54,89` | `set -e` aborts the installer; the graceful-degradation branches are dead and the PHP-FPM drop-in never installs | 🔴 | `a5f963b` — `apt_install` and both `run_ok` calls tested, as packages.sh already did |
| 5 | `install/lib/verify.sh:334` | `code="$(http_code …)"` kills the run; every verification check this branch adds is unreachable exactly when it matters | 🔴 | `3318244` — `http_code()` always returns 0 (curl still prints `000`); two more piped substitutions guarded |
| 6 | `platform/wrappers/actions/UnlImageCommit.php:261` | backing-chain TOCTOU; root `qemu-img commit` writes to an attacker-chosen path | 🔴 | `1198e48` — commit and convert take the checked chain as a `json:` block spec with `backing` explicit (or `null`). Measured on qemu-img 8.2: a header rebased to a hostile path after the check is committed into the verified template and the hostile file is untouched |
| 7 | `platform/packages/PnetPackageApplier.php:810` | `recoverInterrupted()` rolls back a concurrently running apply; no locking anywhere | 🔴 | `b11cccd` — non-blocking `flock()` on `<state>/lock` for the whole of apply/uninstall; the test holds it and proves both refuse |
| 8 | `store/config/readonly_actions.php:74` | `refreshToken` missing from the allowlist; session keep-alive silently refused, admins logged out mid-session | 🟠 | `9bcff23` — listed, with what it does. On the VM a GET with the session cookie returns 202 and a fresh `token` cookie |
| 9 | `MainController.php:27` + `VersionsController.php:142` + `LabsController.php:176` | `?relicense=1` makes three allowlisted GET actions state-changing and CSRF-reachable | 🟠 | `9bcff23` — parameter removed from all three; `admin/default/relicense` (POST-only) is the trigger. `CsrfTest` no longer accepts `License::` on any GET and asserts no allowlisted method reads `'relicense'`. On the VM the view renders and the GET trigger is refused |
| 10 | `devices/device.php:720` | empty command treated as success; a QEMU node with a bad NIC driver leaks taps and pins its tenant account | 🟠 | `6c6900d` — the three QEMU classes return `array(False, False)` for an invalid NIC, the same path as the arch/binary failures. On the VM: `Invalid QEMU NIC driver (80017)` → `start failed (80046); releasing taps`, zero taps and zero `unl<N>` accounts after |
| 11 | `includes/functions.php:1501` | `reap-tenant` called before taps are released, so the broken-session leak the comment claims to close stays open | 🟠 | `c1fa92f` — taps released through `unl_session_taps()`/`delTap()`, rows deleted, then reaped; `TenantAccountTest` pins the order |
| 12 | `platform/wrappers/actions/UnlFixPerms.php:188` | `is_link`/`chown` TOCTOU on a www-data-owned tree | 🟠 | `e12ae34` — the walk is GNU `chown -R -h -P -v --preserve-root --`, argv through `proc_open()`; the test pins every flag and a live run proves, from chown's own log, that a planted link's target is never visited |
| 13 | `platform/wrappers/actions/UnlIolKeepalive.php:226` | missing `posix_initgroups()` (the sibling call site has it), plus a hard-coded gid | 🟠 | `14ab3ea` — initgroups before the drop; gid from the passwd entry and refused unless it is the platform group |
| 14 | `platform/wrappers/src/iol.c:658` | one-byte heap overflow in `iol_sh_quote()` — **confirmed under ASan** | 🟠 | `a817248` — bound is `n + 5`; the boundary test uses exact-size heap buffers and fails the old code under ASan |
| 15 | `platform/wrappers/src/loop.c:165` | SIGTERM lost outside `select()`; `stopall` hangs the wrapper with IOL still running | 🟠 | `d152ab5` — self-pipe: the handler writes a byte, the read end is in every `select()` set, flags are acted on before every sleep and after every wake |

---

## The two that defeat their own change

These are worth reading in full, because in both cases the code does the
opposite of what the change was written to do.

### 8 · The session keep-alive is refused

`store/public/assets/js/default.js:169,171` calls `admin/default/refreshToken`
via `$.get`, on `window.onload` and every 20 minutes thereafter. `default.js` is
loaded on every page of both UIs.

There is no entry for it in `readonly_actions.php` and no static route in
`store/routes/web.php`, so since the allowlist inversion the call falls through
`Checker::action()` (`store/app/Helpers/Request/Checker.php:109-115`) to
`method('post')` and is refused. `AuthCookie::issue()` gives the `token` cookie a
60-minute life, and this call was the only thing re-issuing it on the served
host: an admin working continuously is bounced to login after about an hour.

There is no `.fail` handler on the call and the refusal returns a 200-class
status, so it fails completely silently — which is why the test suite does not
see it either.

**Fix:** add the action to the allowlist with the usual justifying comment. It
reads and re-issues; it does not write user state.

### 9 · `?relicense=1` is a cross-origin write

`MainController::view()`, `VersionsController` (`:142`) and `LabsController`
(`:176`) each open with:

```php
$relicense = $request->input('relicense', false);
if($relicense) License::relicense(false, Auth::user());
```

That POSTs to the central server and writes `USER_LICENSE` to the user row. All
three actions are on the read-only allowlist, whose header states that listed
actions return a view "and nothing else", and asserts that `CsrfTest` fails if a
listed action can change state. Neither holds.

`SameSite=Lax` sends cookies on a top-level GET, so
`location = 'http://box/store/public/admin/main/view?relicense=1'` from any site
performs the write cross-origin. This is the exact hole the allowlist inversion
was written to close.

**Fix:** move the relicense trigger to its own POST action and drop the query
parameter from the three view actions. Then make `CsrfTest` actually enforce
what its comment claims — the assertion that would have caught this does not
currently exist.

---

## Secondary — fixed, or deferred on the record

Real, lower severity. The rule this file is written under is that anything left
here at Phase 05 is a written decision, not an oversight. Every row now says
which.

| Where | What | Outcome |
|---|---|---|
| `includes/functions.php:2251`, `store/app/Helpers/System/Wrapper.php:166`, `store/app/Http/Controllers/Admin/SystemController.php:195` | three new exec helpers drain stdout to EOF before reading stderr, both pipes blocking — classic two-pipe deadlock holding an fpm worker until timeout. Reachable via `unzip -o -d … '*.unl'` on a corrupt archive (`api_labs.php:406`) and `Wrapper::idlepc()` (300 s dynamips) | **fixed** `fd7ab43` — both pipes served by `stream_select()` in all three |
| `includes/api_origin_guard.php:222` | compares `Origin` hostname against `HTTP_HOST`. Apache `ProxyPass` rewrites `Host` without `ProxyPreserveHost On`, as does nginx `proxy_pass` without `proxy_set_header Host $host` — both defaults. Behind a standard fronting proxy every POST/PUT/PATCH/DELETE on the legacy API 403s, `X-Forwarded-Host` is deliberately not consulted, and the only diagnostic is `api.txt`. Not broken with the shipped vhost | **deferred.** The shipped vhost is the supported deployment and the guard is correct for it. Consulting `X-Forwarded-Host` would reopen the guard to anyone who can set a header, which is the wrong trade. A fronting proxy must preserve `Host` — `ProxyPreserveHost On` for Apache, `proxy_set_header Host $host` for nginx — and that requirement, with the 403 as its symptom, belongs in the deployment documentation when a fronted deployment is first supported. Revisit if and when that happens |
| `store/app/Services/Auth/JwtGuard.php:210` | still `if($user->{USER_ROLE} != 0)` — the PHP 8 string-vs-zero trap this branch documents at length and fixes in `Role.php` and `LoginController.php:190`, left behind in a file the branch edits | **fixed** `b5b058a` — `Role::isRootRole()` |
| `store/app/Http/Controllers/Admin/LabsController.php:65` | `secureCmd(..., SECURE_PATH)` inside a `scandir()` loop with no `try`/`catch`. Post-inversion that *throws*, so one image filename containing `:`, `'`, `%`, `#`, `&`, `!` or `~` turns a partial dependency listing into an uncaught exception for the whole lab | **fixed** `ec13a03` — the entry is skipped and logged |
| IOL `abandonStart()` | unwind is a no-op | **deferred, by the same decision that leaves IOL untouched.** `device_iol::prepare()` calls `posix_setuid()` in the wrapper's own process before the failure points that follow it, so the `releaseTaps()`/`reapTenant()` unwind runs as the tenant and its `sudo` calls fail. The fix is the one `docs/HANDOVER.md` already names — move IOL onto `device::spawnAsTenant()` so the wrapper stays root — and it is gated on a licensed image, because no IOL node has ever started here and nothing would catch a mistake. `iol-dataplane.sh` drives `iol_wrapper` directly and does not cover this |
| package applier | incomplete-journal case | **fixed** `67f41cb` — `writeJournal()` checks the write and the rename, fsyncs where available, and raises before the operation it describes |
| package fetch | HTTPS→HTTP downgrade | **fixed for packages** `597c1ca` — `PackageClient::download()` asks `Query::make()` for strict transport: no scheme rewrite, redirects pinned to https, `MAXREDIRS` bounded. **The rewrite itself stays for the upstream calls**, deliberately: `Query::make()` has always rewritten `https` to `http` for every call to `APP_CENTER` (which is `https://user.pnetlab.com`), login included. Removing it is part of severing those calls in Phase 05; removing it today would present any TLS failure to pnetlab.com as a login outage. Recorded here so Phase 05 does not rediscover it |
| sudo policy | `shutdown`/`reboot` inode grant | **fixed** `025bd84` — arguments pinned to `-h now` and none, which is what the two call sites pass |
| `SudoersPolicyTest` | `_no_blanket_grant` passes vacuously | **fixed** `19835d3` — parses every `www-data`/`unl` line, refuses `ALL` or a glob in any spelling; a six-line negative control proves it catches five |
| guacamole install | checksum fail-open | **fixed** `67d2a03` — a missing `SHA512SUMS` is fatal |
| `unl_wrapper` | `-S 0` is unreapable | **fixed** `56946b5` — session 0 exists (`createNodeSession()` allocates modulo 30000); the global `-S` check lets `'0'` through for `reap-tenant` only |
| `UnlBackupDatabase` | partial rename | **fixed** `056f8b2` — the previous generation is held by a hard link; a failed second rename puts the first back |
| `UnlSetProxy` | temp-file mode | **fixed** `f61e05f` — created under a 077 umask |
| `render_template` | sed escaping | **fixed** `706d888` — `\`, `&` and the delimiter escaped in the value; `a&b|c\d/e` round-trips |
| test suite | `.claude/` worktree tree-walk fails the run locally | **fixed** `1eca925` — every walker and `php-lint.sh` prune `.claude/` beside `.git/` |

## Two patterns worth sweeping for as a class

Both came out of the review as themes rather than single defects, and both are
cheaper to fix by sweep than one at a time.

**A fix applied at one call site and missed at its sibling.** `posix_initgroups`
(13), the PHP 8 role comparison (`JwtGuard`), stderr draining (three helpers),
`run_ok` usage (4). Every one of these has a correct twin elsewhere in the same
change. Grep for the correct form and check each of its siblings.

**Comments and tests asserting properties the code does not have.** The
allowlist's "and nothing else" (9), the broken-session reap comment (11),
`_no_blanket_grant`. This is more costly than a plain bug: a confident comment
is exactly what stops the next reader from re-checking. Where one of these is
fixed, the assertion that should have caught it gets written at the same time.

---

## Ground the review cleared

Recorded because it is as useful as the findings, and because otherwise it gets
re-reviewed:

- The origin guard's Slim mechanics, and its behaviour against every real
  client — the legacy theme's global `$.ajaxPrefilter` rewrites jQuery POSTs to
  JSON, so they pass the guard.
- `SECURE_LINE` validated against all 200+ template option strings, with no
  false rejections.
- The full `readonly_actions` ↔ route ↔ SPA-page mapping (which is how 8 was
  found).
- The Laravel 12 API surface used by `Http/Kernel.php` and the JWT provider.
- The axios 1.x call-site audit.

---

## Exit criterion

Phase 04 is not past until items 1–15 are fixed and verified on the reference
VM, and every secondary row is either fixed or carries a written deferral in
this file. Items 1 and 4–5 are the ordering constraint: while `php artisan`
fatals and the installer aborts, none of the rest can be verified by running it.

**Met, 2026-09-02.** Three secondary rows carry deferrals (the origin guard
behind a proxy, the IOL unwind, the upstream scheme rewrite); each names the
condition under which it reopens.

## Verification

Measured on the reference VM (Ubuntu 24.04, PHP 8.4.25, qemu-img 8.2.2)
against a clean `git archive` of the branch head, unpacked and installed end
to end, then the suites run against that install:

```
sudo bash install/install.sh --server-name pnetlab.test
→ INSTALLER-EXIT=0, 0 [fail], "all verification checks passed"

bash tools/integration/lab-functional.sh   → 59 shell assertions, 8 data-plane checks, 0 failed
bash tools/integration/node-types.sh       → 30 passed, 0 failed, 1 skipped (IOL)
bash tools/integration/db-backup-restore.sh→ 67 assertions, 0 failed
bash tools/integration/guacamole-console.sh→ 35 assertions, 0 failed
bash tools/integration/wrapper-console.sh  → 44 assertions, 0 failed
bash tools/integration/wrapper-docker.sh   → 45 assertions, 0 failed
bash tools/integration/iol-dataplane.sh    → 75 assertions, 0 failed
make -C platform/wrappers/src test         → 253 unit assertions, 0 failed (248 before; also clean under ASan+UBSan)
tools/run-tests.sh (as root)               → 1778 assertions across 31 files, 0 failed
tools/php-lint.sh (8.4 and 7.4)            → 352 files, 0 failed
sudo policy                                → 23 grants, unchanged in number; shutdown/reboot now argument-pinned
```

Item-specific checks beyond the suites, all on that install: the pre-change
`PackageRun` class fatals with `Access level to …::fail() must be public` and
the current one declares; `-a fixpermissions` leaves the docroot `root:root`
with `store/.env` at `root:www-data 0640` and no world-writable file; a GET of
`admin/default/refreshToken` with the session cookie returns 202 and a fresh
`token` cookie; `admin/main/view?relicense=1` renders and a GET of
`admin/default/relicense` is refused; a QEMU node with `qemu_nic=bad_nic`
fails to start with `80017`, the wrapper logs `releasing taps`, and no
`vunl*` interface or `unl<N>` account survives; the `iol_sh_quote()` boundary
test fails the pre-change code under ASan with a one-byte heap write and
passes the current code; `qemu-img commit`/`convert` with an explicit `json:`
backing spec write into the verified file with a hostile header in place.
