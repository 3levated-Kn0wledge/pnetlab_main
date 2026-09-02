# Phase 04 exit: fixes to complete first

Phase 04's bullets are met (`ROADMAP-STATUS.md`). This file is the other half of
that judgement: a full review of the work that met them found defects that the
bullet-level audit does not catch, because a bullet asks "was the thing built"
and a review asks "does the thing work". **These are the fixes to complete
before Phase 05 opens.**

Source: review of `git diff origin/phase-02-shell-hardening...HEAD` on
2026-09-02 — 60 non-merge commits, 206 files, ~35k insertions, working tree
clean. Seven parallel passes (device/exec PHP, legacy `includes/` + `api.php`,
privileged wrapper actions, C console/IOL wrappers, signed-package system,
Laravel `store/` + front-end, installer shell).

**What the evidence is worth.** The C findings were built and run under ASan.
The PHP findings are code reading plus cross-file contract checks, **not
execution** — no PHP interpreter exists on this host. Every file:line below was
re-checked against the tree when this file was written; the reasoning behind
each is the review's. Treat the PHP items as "verified by reading, unverified by
running", and confirm on the reference VM as each is fixed.

---

## The gate

| # | Where | What | Sev |
|---|---|---|---|
| 1 | `store/app/Console/Commands/PackageRun.php:152` | private `fail()` illegally narrows `Command::fail()`; every `php artisan` fatals | 🔴 |
| 2 | `store/app/Http/Controllers/Admin/SystemController.php:109` | Fix Permissions reverses this branch's own docroot hardening and world-reads `APP_KEY` | 🔴 |
| 3 | `platform/wrappers/src/console.c:133` | listener not `CLOEXEC`; an orphaned child holds the port and the node reads as running forever | 🔴 |
| 4 | `install/lib/platform.sh:54,89` | `set -e` aborts the installer; the graceful-degradation branches are dead and the PHP-FPM drop-in never installs | 🔴 |
| 5 | `install/lib/verify.sh:334` | `code="$(http_code …)"` kills the run; every verification check this branch adds is unreachable exactly when it matters | 🔴 |
| 6 | `platform/wrappers/actions/UnlImageCommit.php:261` | backing-chain TOCTOU; root `qemu-img commit` writes to an attacker-chosen path | 🔴 |
| 7 | `platform/packages/PnetPackageApplier.php:810` | `recoverInterrupted()` rolls back a concurrently running apply; no locking anywhere | 🔴 |
| 8 | `store/config/readonly_actions.php:74` | `refreshToken` missing from the allowlist; session keep-alive silently refused, admins logged out mid-session | 🟠 |
| 9 | `store/app/Http/Controllers/Admin/MainController.php:27` + `VersionsController.php:142` + `LabsController.php:176` | `?relicense=1` makes three allowlisted GET actions state-changing and CSRF-reachable | 🟠 |
| 10 | `devices/device.php:720` | empty command treated as success; a QEMU node with a bad NIC driver leaks taps and pins its tenant account | 🟠 |
| 11 | `includes/functions.php:1501` | `reap-tenant` called before taps are released, so the broken-session leak the comment claims to close stays open | 🟠 |
| 12 | `platform/wrappers/actions/UnlFixPerms.php:188` | `is_link`/`chown` TOCTOU on a www-data-owned tree | 🟠 |
| 13 | `platform/wrappers/actions/UnlIolKeepalive.php:226` | missing `posix_initgroups()` (the sibling call site has it), plus a hard-coded gid | 🟠 |
| 14 | `platform/wrappers/src/iol.c:658` | one-byte heap overflow in `iol_sh_quote()` — **confirmed under ASan** | 🟠 |
| 15 | `platform/wrappers/src/loop.c:165` | SIGTERM lost outside `select()`; `stopall` hangs the wrapper with IOL still running | 🟠 |

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

## Secondary — fix, or defer on the record

Real, lower severity. The rule this file is written under is that anything left
here at Phase 05 is a written decision, not an oversight.

| Where | What |
|---|---|
| `includes/functions.php:2251`, `store/app/Helpers/System/Wrapper.php:166`, `store/app/Http/Controllers/Admin/SystemController.php:195` | three new exec helpers drain stdout to EOF before reading stderr, both pipes blocking — classic two-pipe deadlock holding an fpm worker until timeout. Reachable via `unzip -o -d … '*.unl'` on a corrupt archive (`api_labs.php:406`) and `Wrapper::idlepc()` (300 s dynamips). The sibling helpers written in the same change avoid it with `2 => ['file','/dev/null','w']` |
| `includes/api_origin_guard.php:222` | compares `Origin` hostname against `HTTP_HOST`. Apache `ProxyPass` rewrites `Host` without `ProxyPreserveHost On`, as does nginx `proxy_pass` without `proxy_set_header Host $host` — both defaults. Behind a standard fronting proxy every POST/PUT/PATCH/DELETE on the legacy API 403s, `X-Forwarded-Host` is deliberately not consulted, and the only diagnostic is `api.txt`. Not broken with the shipped vhost |
| `store/app/Services/Auth/JwtGuard.php:210` | still `if($user->{USER_ROLE} != 0)` — the PHP 8 string-vs-zero trap this branch documents at length and fixes in `Role.php` and `LoginController.php:190`, left behind in a file the branch edits. The seeded role is the string `'admin'`, so the root account now goes through the `user_status` gate PHP 7 skipped, and is locked out on any box whose admin row has `user_status` 0 or NULL |
| `store/app/Http/Controllers/Admin/LabsController.php:65` | `secureCmd(..., SECURE_PATH)` inside a `scandir()` loop with no `try`/`catch`. Post-inversion that *throws*, so one image filename containing `:`, `'`, `%`, `#`, `&`, `!` or `~` turns a partial dependency listing into an uncaught exception for the whole lab |
| IOL `abandonStart()` | unwind is a no-op |
| package applier | incomplete-journal case |
| package fetch | HTTPS→HTTP downgrade |
| sudo policy | `shutdown`/`reboot` inode grant |
| `SudoersPolicyTest` | `_no_blanket_grant` passes vacuously |
| guacamole install | checksum fail-open |
| `unl_wrapper` | `-S 0` is unreapable |
| `UnlBackupDatabase` | partial rename |
| `UnlSetProxy` | temp-file mode |
| `render_template` | sed escaping |
| test suite | `.claude/` worktree tree-walk fails the run locally |

---

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
