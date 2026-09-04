# Handover

**State at end of session, 2026-09-04.** `main` is at `5889441` (the merged
security-review fixes). Work since is on branch `phase-05-sever-upstream`,
eleven commits ahead of `main`, none pushed, nothing uncommitted: Phase 05,
severing the upstream dependency, one commit per surface, verified on the
reference VM with outbound traffic rejected at the firewall; then the code
that was dead once upstream was, removed at the user's call. See **Phase 05:
severing the upstream dependency (2026-09-04)** below. The rest of this
document is the state at the close of the Phase 04 session, still current
except where the two sections after it supersede it.

**Everything below was measured on a host that was rolled back to its
post-provision snapshot and built from nothing** — a clean `git archive` of the
branch head, the installer, and two verified downloads. That is a stronger
claim than this document has carried before, and it cost four defects to make:
see "Deploying onto a clean host" for what a fresh host needs and what it
caught.

**The Phase 04 exit gate is clear.** `docs/inactive/PHASE-04-EXIT-FIXES.md` named fifteen
defects found by reviewing the work that closed Phase 04, seven of them critical,
plus fifteen secondary findings. All fifteen are fixed, one commit each, and
verified on the reference VM through the installer; twelve of the secondary
findings are fixed and three carry written deferrals in that file. See "Phase 04:
the exit gate" at the foot of this document for what each fix measured and the
two things a reviewer should know before merging.

**Phase 02's dependency bullet is closed**: axios is 1.20.0 and the committed
bundles were rebuilt against it — see "Phase 02: the axios upgrade" below.

**Roadmap phases 00 through 05 are done**, with three deliberate redefinitions,
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

Phase 05 (severing the upstream dependency) is done on its branch; 06
(frontend currency) and 07 (maintainership) are not started.


---

## Phase 05: severing the upstream dependency (2026-09-04)

Branch `phase-05-sever-upstream`, nine commits, one per surface and one for
the bundles. `docs/OFFLINE-FIRST.md` is the decision (accepted 2026-08-29);
`docs/ROADMAP-STATUS.md` has the bullet table; `tests/Security/
UpstreamSeveredTest.php` pins every removal, one section per commit, and
`tests/Security/PackageIndexTest.php` covers the one thing that was added.

**What the product did before this branch.** Every box talked to
`user.pnetlab.com` — with the caller's licence attached to each request, or
the box's "alive key" and its machine UUID AES-encrypted under a key that was
in the source — for: an online login (via `authen.pnetlab.com`, with an
unauthenticated return leg that was the one CSRF exemption), an hourly licence
keep-alive, a lab marketplace (five controllers, two of which wrote a download
to a path the server chose), a notice bell (two upstream calls on every page
load), account metering, the device-store listing and the update check. Every
one of those URLs was rewritten from https to http before curl saw it.

**What it does now.** Nothing, by default. The one outbound request the admin
UI can make is the package repository's `index.json`, fetched only when the
owner sets `PNET_PACKAGE_CENTER`, only from the device store and the version
dialog, and parsed as hostile data (`docs/PACKAGES.md`, "The index"). The
online login, the keep-alive, the marketplace, the bell, the licences, the
fingerprint, the six hostname constants and the upstream domain on the session
cookie are gone. Four sudo grants went with their only callers: `php` (the
root-equivalent one the policy had flagged), `ps`, `ntpdate`, `dmidecode`.
The policy is at **19 grants**.

| Commit | Surface |
|---|---|
| `security(auth)` | the online login and the licence keep-alive |
| `remove(store): the lab marketplace` | selling, downloading and versioning labs upstream |
| `remove(store): the notice bell` | the notices |
| `remove(store): the multi-access licences` | account metering, the online accounts page, "Box's ID" |
| `packages: the device store lists the repository's own index` | the device listing |
| `packages: the update check reads the index` | the update check; the upgrade worker off `sudo php` |
| `security(upstream)` | `Query::center()/boxCenter()`, the https→http rewrite, the fingerprint, the constants, the cookie domain |
| `build: rebuild the React bundles` | one rebuild for all of it, from `npm ci` |

**Verified on the reference VM, 2026-09-04, with upstream unreachable.** The
installer was re-run over the previous session's install (a repeat deploy,
not a snapshot rollback — the snapshot was consumed by the security-review
verification the day before, and a repeat deploy is what an upgrade of a
running box is). Then, for the whole of the unit and integration run, an
iptables rule rejected every packet leaving the VM's interface for anything
outside the lab network:

```
sudo iptables -I OUTPUT 1 -o ens18 ! -d 192.168.0.0/16 -j REJECT
curl https://user.pnetlab.com/                 → unreachable, for the whole run

sudo bash install/install.sh --server-name pnetlab.test
→ INSTALLER-EXIT=0, every step, 0 [fail], all verification checks passed

tools/run-tests.sh (as root, PHP 8.4)      → 2282 assertions across 39 files, 0 failed
tools/php-lint.sh (8.4)                    → 349 files, 0 failed
make -C platform/wrappers/src test         → 279 unit assertions, 0 failed
bash tools/integration/lab-functional.sh   → 59 shell assertions, 8 data-plane checks, 0 failed
bash tools/integration/node-types.sh       → 30 passed, 0 failed, 1 skipped (IOL)
bash tools/integration/db-backup-restore.sh→ 67 passed, 0 failed, 0 skipped
bash tools/integration/guacamole-console.sh→ 35 assertions, 0 failed
bash tools/integration/wrapper-console.sh  → 44 assertions, 0 failed
bash tools/integration/wrapper-docker.sh   → 45 assertions, 0 failed
bash tools/integration/iol-dataplane.sh    → 76 assertions, 0 failed
```

Zero `unl*` accounts and zero taps afterwards; the rule was removed and
outbound confirmed working again. Every integration number equals the
2026-09-03 baseline: nothing the product does depended on the calls that
went. The unit count moved from 2149 to 2282 (two new files, 180 assertions,
against 47 retired with the code they pinned); the lint count from 359 to 349
files (ten deleted). `LaravelBootTest`'s route-table floor is 13, not 17, for
the four login routes that no longer exist; that test's boot half runs only on
a deployed host, which is where it caught it.

**Things a reviewer should know.**

- **The device store and the version dialog are empty until a repository
  exists.** That is the honest state: nothing publishes signed packages yet.
  Both screens say so rather than erroring. Standing one up is
  `docs/PACKAGES.md` "Publishing a package" plus an `index.json`.
- **The index is unsigned.** Discovery can lie about what exists; contents
  cannot be forged. That was true of the upstream listing too, and is
  recorded under "What is deliberately not here yet".
- **The dead code went too, in the last commit.** `Notice_web`, the
  `Uploader` module, the `pages/uploader`, `pages/control` and notice React
  pages, `Namecard`/`Profile`, `ModeCmd`'s online-mode branches, the seven
  control constants nothing read and the two control rows the installer
  seeded for them. Each was confirmed unreachable first; UpstreamSeveredTest
  section 9 pins the absences. The last commit was verified with the local
  suite and a bundle rebuild, not on the VM: it deletes unreachable code and
  two seed rows, and the VM run before it covers everything that executes.
- **The workbook editors never used the upstream uploader** — their upload
  adapter was already commented out — so a workbook image is still inline or a
  URL. Nothing regressed there.

---

## Security review fixes (2026-09-03)

An external review (Codex) of the merged tree raised six findings. Each was
verified against this checkout first, then fixed on branch
`security-review-fixes`, one commit per finding in the order they were asked for
(2, 6, 5, 1, 4, 3), plus one test-infrastructure commit and a docs commit.

| # | What was wrong | Fix | Test |
|---|---|---|---|
| 2 | `/admin/users` `filter` and `read` returned the whole active-user directory — emails, IP addresses, licence keys, notes — to any logged-in account. The `/admin/{controller}/{method}` dispatcher is `auth`-only, so each method owns its role check, and these two had none. | `read` is root-only; `filter` gives a non-root caller a five-column projection (`UsersController::PEER_COLUMNS`) with its filter and sort keys cut to match, so an unreturned column cannot be probed either. | `UsersDirectoryTest.php` |
| 6 | `` `Net-` `` in the P2P branch of `api.php` was PHP's backtick operator — `shell_exec` — so every P2P request ran a command named `Net-` and dropped the prefix. | quoted string. | `BacktickOperatorTest.php` — tokeniser scan, no backtick anywhere in the tree. |
| 5 | six lab-session branches (interfaces `setquality`/`setSuspend`, wireshark `add`/`capture`/`delete`, multi_cfg `active`) changed state with no edit-permission or lock check, so a read-only participant in a shared lab could use them. | both checks on each branch. | `LabActionPermissionTest.php` — the action table as a test. |
| 1 | workbook HTML reached the DOM through `dangerouslySetInnerHTML` after an `output_secure()` that did nothing (a string literal where a RegExp was meant): stored XSS by any lab editor against every viewer, an admin included. | a server allowlist sanitiser (`includes/html_sanitizer.php`, ext-dom) on write and DOMPurify in the viewer on render, neither trusting the other. | `WorkbookHtmlTest.php` |
| 4 | the IOL serial UDP data plane bound every interface, so any host that could reach the appliance could inject frames into a node's serial port, unauthenticated. | binds `127.0.0.1`; a `-R` flag opts into the old wildcard bind for a cross-host link. | `iol_udp_open` unit test + an `iol-dataplane.sh` bind check. |
| 3 | the IOL in-process privilege drop kept root's supplementary groups, took the uid from an unvalidated `id -u`, and checked no return. | the drop confirms the uid against passwd, clears the groups before `setuid`, and checks and verifies every step. | `IolPrivilegeDropTest.php` |

**What stays deferred.** Finding 3's real fix — moving IOL onto
`device::spawnAsTenant()` so the wrapper stays root — is unchanged and still
gated on a licensed IOL image, because no IOL node has ever started here and
nothing would catch a mistake in the start path. Only the drop's *completeness*
was fixed, which needs no image. `docs/inactive/PHASE-04-EXIT-FIXES.md` and
`docs/ROADMAP-STATUS.md` carry the same deferral.

**Verified from scratch on the reference VM, 2026-09-03.** The VM was at its
post-provision snapshot (the interrupted `dpkg` again, cleared with
`dpkg --configure -a`); a clean `git archive` of `cef483c` was unpacked, the
Guacamole artefacts and the CirrOS image staged, the captcha turned off, and
the installer run end to end — the same recipe as "Deploying onto a clean
host" below, nothing carried over.

```
sudo bash install/install.sh --server-name pnetlab.test
→ INSTALLER-EXIT=0, every step, 0 [fail], all verification checks passed

tools/run-tests.sh (as root, PHP 8.4)      → 2149 assertions across 37 files, 0 failed
tools/php-lint.sh (8.4)                    → 359 files, 0 failed
make -C platform/wrappers/src test         → 279 unit assertions, 0 failed
bash tools/integration/lab-functional.sh   → 59 shell assertions, 8 data-plane checks, 0 failed
bash tools/integration/node-types.sh       → 30 passed, 0 failed, 1 skipped (IOL)
bash tools/integration/db-backup-restore.sh→ 67 passed, 0 failed, 0 skipped
bash tools/integration/guacamole-console.sh→ 35 assertions, 0 failed
bash tools/integration/wrapper-console.sh  → 44 assertions, 0 failed
bash tools/integration/wrapper-docker.sh   → 45 assertions, 0 failed
bash tools/integration/iol-dataplane.sh    → 76 assertions, 0 failed  (was 75: the loopback bind check)
```

Zero `unl*` accounts and zero taps left on the host afterwards. The five new
PHP test files add 305 assertions over the Phase 04 baseline. The C unit count
is host-dependent now: the `iol_udp_open` loopback test asserts once per
non-loopback interface, so it reads 279 on the VM (one NIC) and 284 on the
workstation (several); it is also clean under `-fsanitize=address,undefined`.
The integration numbers match the Phase 04 baseline in "Where this got to"
below except `iol-dataplane.sh`, which gained the bind check.

---

## Where this got to

The fork **deploys to a brand-new Ubuntu 24.04 server on PHP 8.4, runs labs, and
no longer needs anything from the upstream appliance image.** The installer
compiles the console wrappers from source as one of its steps.

Every line below was measured **from scratch**: a VM rolled back to its
post-provision snapshot, a clean `git archive` of this commit unpacked onto it,
and `install/install.sh` run end to end. No step was carried over from an
earlier session, and no state on the host predated the run except the
preconditions listed in "Deploying onto a clean host" below.

```
sudo bash install/install.sh --server-name pnetlab.test
→ INSTALLER-EXIT=0, every step, 0 [fail], all verification checks passed

bash tools/integration/lab-functional.sh   → 59 shell assertions, 8 data-plane checks, 0 failed
bash tools/integration/node-types.sh       → 30 passed, 0 failed, 1 skipped (IOL)
bash tools/integration/db-backup-restore.sh→ 67 passed, 0 failed, 0 skipped
bash tools/integration/guacamole-console.sh→ 35 assertions, 0 failed
bash tools/integration/wrapper-console.sh  → 44 assertions, 0 failed
bash tools/integration/wrapper-docker.sh   → 45 assertions, 0 failed
bash tools/integration/iol-dataplane.sh    → 75 assertions, 0 failed
make -C platform/wrappers/src test         → 253 unit assertions, 0 failed
tools/run-tests.sh (as root)               → 1844 assertions across 32 files, 0 failed
tools/php-lint.sh (8.4)                    → 353 files, 0 failed
```

**`tools/php-lint.sh` runs against 8.4 only on a clean host.** Nothing installs
PHP 7.4; the two-version matrix in earlier revisions of this document was
measured on a box where someone had installed it by hand. CI still covers 8.4
and 8.5.

The sudo policy is at **23 grants**, down from 42 (and at 19 after Phase 05, above).

---

## Deploying onto a clean host

The installer was, until this session, only ever run onto hosts that had
already had it run on them. Doing it properly — snapshot rollback, fresh
provision, clean `git archive`, nothing else — caught **four** defects that
an accreted host hides, and established what a genuinely fresh host needs.
Read this before claiming a deploy works.

### What it caught

| | |
|---|---|
| **No C compiler** | `step_platform()` compiles the three console wrappers from source and died with `FATAL: no C compiler found`. Nothing in the installer had ever installed one; every host it had run on already carried gcc. **Fixed** — `build-essential` is now in the platform step's own package list. |
| **The captcha is ON for a fresh install** | Not fixed, and not a bug — the default is right. What was wrong was the suite comment asserting the opposite, now corrected. See below. |
| **A stale `node_modules` silently downgraded axios** | Rebuilding the bundles against whatever `node_modules` held, rather than after `npm ci`, produced bundles carrying axios 0.19.2 against a lockfile pinning 1.20.0, reverting the CSRF hardening. `CsrfTest` caught it. **Fixed**; the lesson is that regenerating build output *is* a behavioural change and the suite has to be re-run after one. |
| **The snapshot itself had an interrupted `dpkg`** | `apache2` was unpacked but not configured, and the preflight correctly refused to start: `FATAL: dpkg has an interrupted transaction`. One `sudo dpkg --configure -a` clears it. This is a property of the snapshot, not of this repository, so it recurs on every rollback to that snapshot until the snapshot is retaken. |

### The preconditions

Only the first is a product requirement; the rest are what the *verification*
needs, and none of them was written down before.

**1. A clean dpkg state.** `sudo dpkg --configure -a` if the preflight says so.

**2. The captcha must be off for the suites to log in.**

```sql
REPLACE INTO control (control_name, control_value) VALUES ('ctrl_captcha','0');
```

`install/sql/seed-control.sql` seeds four rows and **not** `ctrl_captcha`, and
`Ctrl::get(CTRL_CAPTCHA, true)` in `LoginController` defaults it to ON when the
row is absent. So a fresh install enforces the captcha, every scripted login
returns `Captcha is Wrong`, and that one fact cascades into 46 failures in
`lab-functional.sh`, 5 in `node-types.sh` and a loud skip in
`db-backup-restore.sh`.

`tools/integration/lab-functional.sh` used to state the opposite — "Requires
the captcha to be off, which a fresh install has" — which was written against
a box where someone had already run the SQL above. That comment is corrected,
and now says what the failure looks like, because the symptom points nowhere
near the cause: 46 assertions failing with "User is not authenticated".

Having the suites set the value themselves is still a decision nobody has
made. They do not, deliberately: a test suite that reconfigures
authentication on the host it is measuring is a different kind of tool.

**3. The Guacamole artefacts.** ~23 MB of Apache binaries, deliberately not
committed (`install/vendor/guacamole/.gitignore` says why). Staged by a
maintainer on a connected host:

```bash
bash tools/vendor-guacamole.sh          # verifies twice: Apache's published
                                        # checksum, and the committed SHA512SUMS
sudo install/install.sh --only guacamole
```

Without them the installer skips HTML5 consoles loudly and correctly, but
`guacamole-console.sh` cannot run and six assertions across `node-types.sh`
and `db-backup-restore.sh` fail on the missing console link.

**4. A bootable QEMU image.**

```bash
sudo bash tools/vendor-qemu-test-image.sh
```

Stages CirrOS 0.6.2 and verifies it twice, the same shape
`tools/vendor-guacamole.sh` uses: against the MD5SUMS cirros publishes beside
the image, which is only a transport check because it comes from the same host
as the bytes, and against a SHA-512 pinned in the script, which is the anchor
because it is committed here and reviewed. It refuses a version it has no hash
for, refuses to overwrite a file that does not match the pin, is a no-op when
the image is already staged correctly, and confirms with `qemu-img` that what
landed really is qcow2.

The layout it produces is load-bearing at both ends, which is why the script
owns it rather than the operator: `node-types.sh` takes the first directory
under `/opt/unetlab/addons/qemu/` and derives the template from the part
before the first `-`, while `device_qemu.php` matches disks against
`hd[a-z]+.qcow2`. So `linux-cirros/hda.qcow2` means template `linux`, image
`linux-cirros`, disk `hda`. Rename either half and the suite skips, or starts
a node with no disk.

**5. IOL, if you want the one node type that has never run.** Three things
have to be on the host, none of them in this repository and none of them
supplied by the installer:

| | |
|---|---|
| the image | a 32-bit IOL binary under `/opt/unetlab/addons/iol/bin/` |
| `keepalive.pl` | `device_iol::prepare()` symlinks it into the node workspace |
| `iourc` | the Cisco licence, keyed to the host's `hostname` and `hostid` |

The installer now enables i386 multiarch and installs `libc6:i386` and
`libgcc-s1:i386`, which it previously did not — every IOL image Cisco published
is a 32-bit ELF, so without those the image cannot exec at all and the error
names a binary that plainly exists. That half is done and proven: an L3 image
staged on the reference VM loads and reaches its licence check.

`CiscoIOUKeygen.py` is **not** shipped here and `refreshIolLicense()` expects it
on the host. Note that it is Python 2 and prefers `/usr/bin/python3`, so on any
supported host it runs under Python 3, hits a `SyntaxError` and silently
returns false. The licence path is therefore broken on 24.04 independently of
whether an image is present, and this project does not generate licences.

### What a repeat deploy will and will not hit

The compiler defect is fixed in the tree, so it will not recur. The dpkg state
will recur on every rollback to that same snapshot. The captcha, the Guacamole
artefacts and the QEMU image are host setup, not code, so they are needed again
on every fresh host — a product deploy is fine without the last two, and only
the verification suites need them.

### What is still not proven from scratch

**IOL**, unchanged: licensed Cisco binaries this project does not carry, so
`node-types.sh` skips it and `iol-dataplane.sh` drives the wrapper directly
against a stand-in. And **PHP 7.4**, which nothing installs, so the lint matrix
on a clean host is 8.4 alone.

---

## The emulator no longer starts through a shell

For VPCS and QEMU, `device::spawnAsTenant()` execs the emulator directly. It
used to hand the assembled command line to `/bin/sh -c`, and the comment above
it said an argv array "would break every template", which was true of the
obvious approach and false of the one taken.

**Why it could not simply be escaped.** Four values reach that line unescaped
on purpose: `qemu_options`, `dynamips_options`, `iol_options`, and the
per-interface flags `getFlag()` concatenates. They are multi-argument by
design, so wrapping one in `escapeshellarg()` makes it a single argument and
breaks every one of the 115 templates that carries one. That is why they sat in
`tests/Security/shell-escaping-baseline.txt` rather than being fixed.

**Why it mattered more than the baseline implied.** These are not admin-only
template data. `__lab.php` reads every node attribute whose key appears in that
device's `getOptions()`; `device_qemu::getOptions()` returns `qemu_options`;
and `templates/device/qemu.yml` renders it as an editable field with no
`show: 0`. So the value rides inside a `.unl` file and is set per node, and any
user who could author or import a lab could put words in it.

**What was done.** `unl_command_argv()` in `includes/functions.php` splits the
line the way a shell would — quoted runs, the `'\''` splice, adjacent runs
concatenating into one word — and returns argv plus the redirection. Word
splitting survives, so the option strings still do what they are for. No
interpreter survives, so nothing acts on a `;` or a `>` even if one got past
`SECURE_LINE`.

**The part that nearly went wrong, and is the reason the tokeniser returns more
than argv.** `SECURE_LINE` deliberately PERMITS `>`, because the call sites
build their own redirection. A tokeniser that honoured that faithfully would
have preserved the injection it was meant to remove. Both tenant node types
redirect to exactly one file inside the node's running directory, so
`spawnAsTenant()` refuses a line carrying more than one redirection, or one
whose target is outside that directory. The **count** matters as well as the
target: a shell opens and truncates every redirection in a line, not just the
last one it ends up using.

There is deliberately no fallback to `/bin/sh` when the split fails. A fallback
would reinstate the shell on exactly the input that confused the tokeniser.

**How it is tested.** `tests/Security/CommandArgvTest.php` is differential where
it counts: for every line it compares argv against what `/bin/sh` actually
produces, obtained with `printf '%s\0'`, rather than against an opinion about
shell grammar. Splitting `-drive file='a b'` wrongly would silently corrupt
every escaped value containing a space, and only ground truth catches that. It
also pins that the tokeniser's grammar is `secure_line_parse()`'s in both
directions, so a line cannot pass the guard and then surprise the splitter.

The claim made visible, from a running node:

```
unl1  42011  1      /opt/vpcsu/bin/vpcs -m 1 -i 1 -p 30001 -e -d vunl1_0
unl1  42012  42011  /opt/vpcsu/bin/vpcs -m 1 -i 1 -p 30001 -e -d vunl1_0
/bin/sh processes owned by a tenant: 0
```

**What this did NOT cover.** Dynamips and IOL do not run as the tenant, so they
still reach `exec($cmd . ' &')` and still get a shell; for them `SECURE_LINE`
is still the only thing between an option string and an interpreter. Converting
them needs whatever replaces the backgrounding that `&` provides, plus a
licensed image to verify against. And the baseline still reads 47: the sweep is
static and cannot see that this path ends at an `execv`, so retiring those
entries means teaching the sweep first.

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

**The sudo policy is down from 42 grants to 23.** Retired: `nproc`, `top`,
`docker`, `dos2unix`, `php`, `perl`, `kill`, `cp`, `mv`, `mkdir`, `link`,
`chown`, `chmod`, `touch`, `tee`, `echo`, `useradd`, `qemu-img`.
`tests/Security/SudoersPolicyTest.php`
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
CAP_SYS_ADMIN. **Dynamips is not flipped**, and an earlier revision of this
paragraph said that was only for want of an IOS image. That was wrong and the
reason is in "The shell layer" below: for a node with serial interfaces it is a
three-part change, not a one-line one. **IOL still drops in-process** -- the
move onto `device::spawnAsTenant()` is unchanged and still deferred, because no
IOL node has ever run here and `iol-dataplane.sh` drives `iol_wrapper` directly
rather than `device_iol`, so nothing would catch a mistake in the start path;
the latent bug that move would also fix (a second IOL node in one start-all
cannot create its account) is still there. Two IOL hardenings that do NOT need
an image landed with the security-review fixes, though: `device_iol::prepare()`
now completes the drop it already performed -- it confirms the uid against the
passwd database instead of parsing `id -u`, clears root's supplementary groups
before `setuid()`, and checks and verifies every step (a compromised IOL used
to keep group 0) -- and the `iol_wrapper` serial data plane binds `127.0.0.1`
rather than every interface, with a `-R` opt-in for a cross-host link. See
`tests/Security/IolPrivilegeDropTest.php` and the `iol_udp_open` unit test.

---

## The shell layer

Phase 02's two shell bullets, closed against the tree rather than against
intent. Both are recorded bullet by bullet in `docs/ROADMAP-STATUS.md`.

**`secureCmd()` is an allowlist.** It was `/[#;|&]|\.{2,}/m` — five characters
and a traversal check — applied to whole command lines on some paths and to bare
values on others, and `SecureCmdTest` had already measured the ten
metacharacters it let through. It is now three named shapes, `SECURE_TOKEN`,
`SECURE_PATH` and `SECURE_LINE`, and **every call site declares which one it
means**; the default is the strictest, so a call site added without declaring
one fails closed. `SECURE_LINE` is parsed rather than pattern-matched: the line
must be single-quoted runs (`escapeshellarg`'s output, its `'\''` joiner
included), double-quoted runs with no expansion, and unquoted text from a safe
class.

It is **defence in depth and now says so**. `SECURE_LINE` proves a string cannot
spawn a second command; it does not prove the arguments are the intended ones,
because an unquoted space is still a word separator. That distinction is why
`devices/device.php`'s emulator line is still in the escaping baseline.

**The escaping baseline is 47, down from 73.** With one exception everything
left is `devices/` — the template option strings, `getFlag()`'s per-interface
flags and the TiMOS family, which are argument injection by design. Every route
from request data to a shell through an ordinary API handler has gone. The
highest-severity entry the baseline named — the QEMU binary path built from
`qemu_arch` and `qemu_version` off the request body — went with one
`escapeshellarg($bin)`, which `device_qemu_wp.php` already had and the other two
backends did not.

**Two findings from this that are worth more than the fixes.**

1. `checkFolder()` was the real control on the folder routes, not `secureCmd`.
   It is an allowlist over the whole path in `devices/functions.php`, applied
   before the `exec`, stricter than anything in `api_folders.php`, and written
   down nowhere. Measured against the parent commit: a folder named
   `x$(touch proof)y` **can** be created, because `apiAddFolder()` validated
   nothing at all, and deleting it is then refused with 60009 and nothing runs.
   The genuinely open half was `apiAddFolder`, which is validated now — so the
   three folder routes finally agree about what a folder name is.

   Reading it also turned up two wrong characters in all four validators there:
   `\s` is `[ \t\n\r\f\v]` and not a space, so a folder or lab name
   containing a **newline** passed every one of them, and `$` without `/D`
   matches before a trailing newline. This is the third place in this tree the
   `$` trap has been found.

2. `Admin/LabsController::getDepends()` **was** held up only by the blocklist,
   and it is the one site in the tree that was: `sudo qemu-img info
   --backing-chain <path> | grep image`, with the path built from the `image`
   attribute of an uploaded lab's XML. It needs a crafted name on disk and the
   root role, so it is not a drive-by, but the sudo is gone now — `qemu-img
   info` returns the chain as `www-data`, measured — and the **`qemu-img` grant
   went with it**, which is why the policy is 23 and not 24.

One trap to remember when moving entries off the baseline: writing an int
coercion as `$value = (string) $value` makes the sweep stop reporting that
symbol, because it resolves `$value`, finds an assignment whose right-hand side
is `$value`, and the cycle guard that stops it looping also stops it reporting.
That silently retired a live entry. Assign to a second name.

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
  shrink. It is **47** now — see "The shell layer" above.
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

1. ~~**Review and merge `phase-04-exit-fixes`.**~~ Merged (`5e2bb03`), and
   the security-review fixes after it. Nothing gates Phase 05 any more.
2. **IOL, with a licensed image.** Everything else is proven; this is the only
   feature claim resting on unit tests alone.
3. **Finish the sudo migration.** `rm` is the last of the file-mutation grants,
   and `tests/Security/SudoersPolicyTest.php` makes each step checkable.
4. **The template option strings** (item 4 above) — the last documented
   argument-injection surface, and a design decision rather than a bug fix.
   Cheaper now than it was: the shell that expands them runs as `unl<session>`
   for VPCS and QEMU, so the blast radius is a tenant rather than the host.
5. **Dynamips unprivileged**, which is three changes and a licensed IOS image,
   not the one line this list used to claim. `runsAsTenant()` is the one line
   and it is enough for an Ethernet-only node. A node with **serial**
   interfaces also needs `/tmp/dynamips`, because the adapters build
   `-s <slot>:<port>:unix:<local>:<remote>` and dynamips itself creates the
   socket there — and `device_dynamips::prepare()` makes that directory with a
   bare `mkdir()` from inside `unl_wrapper`, so it lands `0755 root:root` and a
   dropped process cannot write it. If it is made shared it is the same
   cross-tenant seam as item 6; `iol.c` already solved this shape by deriving
   `/tmp/netio<uid>` from the running uid, and that is the pattern to copy.
6. **Tighten `/opt/unetlab/tmp`**, the last untouched item in Phase 02's
   privilege-model list. Node workspaces are `root:unl 0775`, so every tenant
   can write every other tenant's workspace. Now that emulators run as their
   tenant, this is the seam that decides whether that isolation means anything.
7. ~~**Phase 05, severing the upstream dependency.**~~ Done on
   `phase-05-sever-upstream`; review and merge it. Phase 06 (frontend
   currency) and 07 (maintainership) are open.
8. **Phase 08, the fork's own package repository and lab store** — added to
   the roadmap on 2026-09-04. The device store and the version dialog read
   an `index.json` nobody publishes yet; a repository we run, with signed
   packages and eventually a signed index, is what fills them. The lab store
   is new work with decisions (hosting, accounts, what may be redistributed)
   ahead of the code; the roadmap section says which.
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

**The reference VM can now build the frontend, and drive it.** Node 22.14.0 is
unpacked at `/opt/node22` with `node`/`npm`/`npx` symlinked into
`/usr/local/bin`, matching the `node-version: '22'` the `frontend-build` CI job
uses; `npm install && NODE_OPTIONS=--openssl-legacy-provider npm run production`
from a clean tree reproduces the committed bundles **byte for byte**, which is
what makes a diff in `store/public/react/` attributable. Playwright 1.49.1 and
its headless Chromium are in `~/verify` for driving the deployed SPA over HTTP.
Neither is in the repository and neither is an install dependency — they are
tooling on the reference host, and the build still must not be run on the
workstation.

---

## Documents

Active docs live in `docs/`. A doc for work that is finished moves to `docs/inactive/` — still tracked, so it travels with every clone and keeps its history, but out of the way of live work and no longer updated. `docs/README.md` is the index of both.

| File | What it is |
|---|---|
| `docs/README.md` | index of the active docs, and what has been archived |
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
| `docs/inactive/PHASE-04-EXIT-FIXES.md` | **archived:** the Phase 04 exit gate, cleared and merged into `main` |
| `docs/audit.html` | single-page summary of the live-box findings |

`docs/ROADMAP.md` predates most of this session. Where it and this document
disagree about what works, this one was measured more recently. Note that
`docs/ROADMAP.md` is referenced in the table above but is not in the tree — it
has never been committed on this branch, and `git log --all -- docs/ROADMAP.md`
finds nothing.

---

## Phase 02: the axios upgrade

0.19.2 → **1.20.0**, the last open bullet before Phase 05. Latest 0.x (0.33.0)
was the prepared fallback and was not needed: webpack 4 parsed 1.20.0 without
complaint and `npm run production` exited 0 under the legacy provider.

`npm audit` reports **26 axios advisories** against 0.19.2 (range `<=0.32.0`)
plus five in its `follow-redirects`, and **none** against 1.20.0. Which of the 26
were actually reachable, and which were not, is set out in
`docs/ROADMAP-STATUS.md` — the short version is that the node http adapter is not
in a browser bundle, so the SSRF and proxy-header family was never live here,
while the ReDoS in `trim()` demonstrably was: 0.19.2's
`str.replace(/^\s*/,'').replace(/\s*$/,'')` is in the committed `app.js` and is
gone from the rebuilt one.

**This is a frontend change, so it is only real because the bundles were
rebuilt.** `install/lib/deploy.sh` only warns about a missing bundle and the
installer never builds a frontend, so a deployed install serves whatever
`store/public/react/` holds in the repository. That the bundles are tracked
build output rather than a built artefact is `docs/LICENSING.md` gap **G2**,
which this does not close and was not trying to. 34 files under
`store/public/react/` are regenerated output in the commit. The chunk names and
the file set are unchanged; the page chunks all moved because webpack renumbers
modules when the dependency graph shifts.

**Two things broke, and this is the part worth reading.**

1. **`window.axios = require('axios')` stopped returning axios**, because 1.x
   ships an ES module entry and webpack 4 predates the `exports` map, so
   `require()` hands back the namespace object and the instance is on
   `.default`. `VERSION` is a named export and reads fine; `.request` is
   undefined. `app.js` calls `axios.request` at module scope, so the page died
   before React mounted and the login screen was **blank**. 107 of the 109
   front-end files that use axios reach it through that global.

2. **`error_helper.js` stopped seeing the 419.** It tested `error.name ==
   'Error'`, true only because 0.x rejected with `enhanceError(new Error(...))`;
   1.x rejects with an `AxiosError`. The status stayed 200 and the bounce to the
   login page became a toast — silently. 192 call sites pass it the raw error.
   It keys on `error.response` now, which is what axios documents and is the same
   on both lines.

**XSRF is still automatic and is deliberately still unconfigured.** 1.x decides
with `withXSRFToken === true || (withXSRFToken == null && isURLSameOrigin(url))`.
Unset takes the second clause, which is the behaviour 0.19 had, and every URL
the front end asks for is root-relative. Setting `withXSRFToken: true` would take
the *first* clause and send the token to any origin — reinstating the very
advisory (GHSA-wf5p-g6vw-rhxx) whose fix introduced the option. The default is
both the compatible answer and the safe one; `bootstrap.js` says so and
`CsrfTest` asserts nothing sets it.

**How it was proven.** Headless Chromium against the deployed install: login
through the real form, a mutating admin POST, a lab created and deleted through
`delLab()` — the one DELETE in the tree carrying a request body — and the same
POST with the `XSRF-TOKEN` cookie stripped. 18 checks, green on 0.19.2 *before*
the change and on 1.20.0 after it, so the suite is calibrated rather than merely
passing. The X-XSRF-TOKEN header was observed on the wire and matched the cookie.

**`CsrfTest` is 120 assertions, up from 107, and the reason is a lesson.** The
assertion it used to rest on was that the bundle contains
`xsrfCookieName:"XSRF-TOKEN"`. That string is still there on 1.x and **no longer
decides anything** — it would have passed against a bundle that sends no token at
all. A test that pins a name rather than a decision survives exactly the change
it exists to catch. What is pinned now is the decision, in the source and in the
committed bundle, and it was mutation-checked: reverting the bundles to 0.19
fails ten assertions, reverting only `error_helper.js` fails two, setting
`withXSRFToken` fails one.

Traps, both of which cost time:

  - **The 0.19 bundle and the 1.x bundle both contain the string this test used
    to look for.** See above. If you are upgrading a bundled dependency, check
    what the existing assertions would still say against the new artefact before
    you trust a green run.
  - **A comment that quotes the thing it removed fails the test that checks it
    was removed** — handover trap 6, hit again, this time in JavaScript. The
    note in `error_helper.js` explaining why `error.name` had to go satisfied
    the assertion that it is gone. `CsrfTest` has a `js_code_only()` now, the
    line-oriented counterpart of the existing `code_without_comments()`.

Not done, deliberately: `axios` is still in `devDependencies` rather than
`dependencies`, which is where a bundled runtime library arguably belongs; and
`app.js`'s `import Axios from 'axios'` is unused (capital A — the module-scope
call is the lowercase global). Both are cosmetic and neither was worth widening
this commit for.

**Found while verifying, not fixed: six committed chunks the build does not
produce.** A clean-room build — `rm -rf store/public/react`, `npm ci`,
`npm run production` — reproduces the three entry bundles and all 26 page chunks
**byte for byte**, and emits nothing the tree does not already have. But the tree
carries six extra files it does not emit, all under the doubled
`pages/<chunk>~./store/public/react/pages/` prefix and several with content
hashes in their names:

```
pages/admin-LabsCreate-js~./…/admin-VersionsAddView-js.js
pages/admin-Lab_sessionsView-js~./…/admin-LabsCreate-js~~7c77302f.js
pages/admin-Lab_sessionsView-js~./…/admin-LabsCreate-js~~fbd71f6d.js
pages/admin-Lab_sessionsView-js~./…/admin-UsersOffline-j~8405d653.js
pages/admin-ModeView-js~./…/admin-SystemView-js.js
pages/admin-User_rolesView-js~./…/admin-UsersOffline-js~~2e9e45b3.js
```

They are tracked, they predate this work, and this commit does not touch them —
they are dead output from an earlier chunk-hash generation, kept alive only by
`git`. Nothing loads them: chunk names come from the runtime manifest inside
`app.js`, which names only what the current build emits. They should go with
whatever change next audits `store/public/react/`; they were left alone here for
the same reason as everything else in this section.

That the byte-for-byte reproduction holds at all is the useful part: it means a
diff under `store/public/react/` is attributable to a source or dependency
change and not to build nondeterminism, which is what let the negative controls
above mean anything.

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

**One thing is not finished.** `getInfo()` still reports a `uksm` field, pinned
to `'unsupported'`. Removing it needs a frontend rebuild: the committed bundle
draws a **live** toggle for any value that is not that literal, an absent key
included, so dropping the key turns a correctly inert row into a button for a
control that does not exist.

This used to say the blocker was that no node toolchain existed on the reference
VM. **That is no longer true** — the axios upgrade installed Node 22 there and
rebuilt the bundles (see "Phase 02: axios" below), so the mechanical obstacle is
gone and this is now just an unclaimed piece of work. It was deliberately not
folded into the axios commit, so that one commit changes one thing.

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
tools/run-tests.sh                     1691 assertions across 31 files, 0 failed
                                                            (was 1480 across 28)
tools/php-lint.sh (8.4 and 7.4)        352 files, 0 failed  (was 349)
sudo policy                            23 grants
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

---

## Phase 04: the exit gate

`docs/inactive/PHASE-04-EXIT-FIXES.md` is the record: every item with the commit that
fixed it and what was measured. This section is what that file does not say —
the shape of the work, and what to watch for.

**The ordering the file prescribed was right.** Items 1, 4 and 5 (the `fail()`
narrowing, the two `set -e` aborts) were done first, and the rest could then be
verified by running rather than reading. The pre-change `PackageRun` really does
fatal — `Access level to …::fail() must be public` — which means every `php
artisan` invocation on a deployed box had been fatalling, scheduler included.

**Three fixes are bigger than their line numbers suggest.**

  - *Item 6, the image-commit TOCTOU*, could not be fixed by checking harder:
    the header qemu-img reads is in a file the tenant owns. The fix hands
    qemu-img the checked chain as a `json:` block spec with `backing` explicit,
    so the header's pointer is never dereferenced for a write. That was
    measured both ways on qemu-img 8.2 before the code was written; the `json:`
    form rather than `--image-opts` because `"backing": null` (open with NO
    backing file) is only expressible there.
  - *Item 12, the fixperms TOCTOU*, could not be fixed in PHP at all: no
    `fchownat()`, so no race-free walk. GNU `chown -R -h -P` does it correctly
    (openat traversal, `AT_SYMLINK_NOFOLLOW`, dev/ino re-check), and the action
    now delegates to it. The test's oracle is chown's own `-v` log, which is
    what lets it assert "never visited" without planting a link to a root-owned
    file — important because the suite may run as root, and a regression would
    then chown `/etc/shadow`.
  - *Item 15, the lost SIGTERM*, is the classic self-pipe. It is not testable
    deterministically in a unit test; it is verified by construction and by the
    wrapper suites still passing.

**Two things a reviewer should know.**

  - `?relicense=1` (item 9) was sent by nothing in this tree. The `CsrfTest`
    comment that named `error_helper.js:88` as its outbound leg described code
    that is not there; the parameter was set by the upstream store's redirect,
    which Phase 05 severs. If a relicense-after-purchase flow is ever wanted
    again, it POSTs to `admin/default/relicense`.
  - `Query::make()` rewrites **every** upstream URL from https to http, and
    always has — login credentials to `user.pnetlab.com` go in the clear. This
    session pinned the package download to https and left the rewrite for the
    upstream calls, on the record, because removing it is what Phase 05 does
    and a TLS failure today would look like a login outage. Phase 05 should
    delete the rewrite with the calls.

**One trap this session added to the list.** `fork/.claude/worktrees/` holds
nine full copies of the tree from earlier agent sessions. Every tree walker
and `php-lint.sh` crawled them: the sudoers test reported grants for call
sites that only exist in an old copy, the escaping sweep reported dozens of
NEW values, and the lint took minutes. All of them prune `.claude/` now, and
so should any new walker — and `rsync --exclude .claude` when copying the
tree to the VM.

**Verification, on the reference VM against a clean `git archive` of the
branch head:**

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
make -C platform/wrappers/src test         → 253 unit assertions, 0 failed (248 before; also clean under ASan+UBSan)
tools/run-tests.sh (as root)               → 1778 assertions across 31 files, 0 failed
tools/php-lint.sh (8.4 and 7.4)            → 352 files, 0 failed
sudo policy                                → 23 grants, unchanged in number; shutdown/reboot now argument-pinned
```

`lab-functional.sh` and `node-types.sh` each failed once during this session,
and both failures were state left on the host by a hand-driven check earlier
in the session (a lab whose session was never destroyed shifted every node
session id by one, so the console the suite expected on `:30001` was on
`:30002`). Both passed on a cleaned host. Worth knowing because the symptom —
"console connection refused" on the first node — does not say "stale
session" anywhere: check `lab_sessions` and `node_sessions` before reading
code.
