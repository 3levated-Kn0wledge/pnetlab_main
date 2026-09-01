# Handover

**State at end of session, 2026-09-01.** Branch `phase-02-shell-hardening`,
21 commits ahead of `main`, all pushed. Nothing uncommitted.

---

## Where this got to

The fork **deploys to a brand-new Ubuntu 24.04 server on PHP 8.4 and runs labs**.
That was the goal, and it is met for VPCS and VNC-console QEMU nodes.

Last verified run, on a freshly provisioned host with no manual preparation:

```
sudo bash install/install.sh --server-name pnetlab.test --with-node-tools
→ INSTALLER-EXIT=0, all nine steps, every verification check green

bash tools/integration/lab-functional.sh
→ 47 shell assertions, 0 failed
→  8 data-plane checks,  0 failed
```

That covers login, lab CRUD, three nodes on one bridge, host-level confirmation
(processes, taps, tenant accounts, consoles), ping in three directions, a
negative ping, full teardown, and lab deletion.

---

## What works, and what does not

| | |
|---|---|
| Legacy API (18 routes) | works |
| Laravel 10 admin UI on PHP 8.4 | works |
| VPCS nodes | works, end to end |
| QEMU nodes, VNC console | works (boots an Ubuntu cloud image under KVM) |
| QEMU nodes, **telnet** console | **needs `qemu_wrapper_telnet`** |
| **Docker**-backed nodes | **needs `docker_wrapper`** |
| **IOL** nodes | **needs `iol_wrapper`** (images licensed anyway) |
| Guacamole HTML5 consoles | **not installed**; `guacd` is packaged, the `.war` is not |

Those three wrappers are small compiled binaries published nowhere but the
upstream appliance. No source exists, so they cannot be rebuilt — they must be
copied from an appliance, or their behaviour reimplemented. That is the **only**
remaining dependency on the ISO. Everything else now comes from this repository
plus Ubuntu packages.

---

## Answers to the three questions that were asked

**Does it behave like stock PNETLab?** For the node types above, yes. Two visible
differences: node names do not appear in the VPCS prompt (stock VPCS has no `-N`;
the appliance ships a patched build, and `device_vpcs.php` now probes for it),
and starting several nodes simultaneously can race on `useradd` — upstream has
that bug too, it is just easier to hit here.

**Are kernel edits needed?** No, and this was measured rather than assumed. The
appliance's `4.15.18-pnetlab2` differs from stock in **4 deliberate options out
of 8,266**, and only UKSM is functional. Kernel 6.8 works with mainline KSM.
Dropping the custom kernel also *gains* Secure Boot. What replaced kernel work is
**systemd confinement** — see below.

**Can someone clone and run it?** Yes, as of this session. That was not true at
the start of it: five setup steps were being done by hand. They are now in
`install/lib/platform.sh`, verified by tearing the manual work down and
re-running only that step.

---

## The five non-obvious things

Anyone continuing this work will hit these. They are all documented in the code,
but they are the ones that cost real time.

1. **PHP-FPM systemd confinement blocks the platform layer.** `ProtectSystem=full`
   mounts `/etc` read-only, so `useradd` cannot take its lock and *no node can
   start*. `ProtectKernelTunables` blocks the per-tap sysctl; `PrivateDevices`
   hides `/dev/kvm` and `/dev/net/tun`. The appliance never hit this because it
   ran mod_php inside Apache. Fixed by `install/systemd/php-fpm-pnetlab.conf`.
   **`ReadWritePaths=/etc` alongside `ProtectSystem=full` does not work** — that
   was tried; the drop-in records it so it is not tried again.

2. **Laravel 5.5 could not boot for two separate reasons, and the second is not
   the obvious one.** Beyond the PHP 8 incompatibilities, `PackageManifest`
   cannot read Composer 2's `installed.json` format. That is fixed by the
   Laravel 10 upgrade, but it is why "install vendor and it will work" failed.

3. **`user_roles` is empty on a stock appliance**, so `getRoleByPod()` returns
   null. Indexing null was a notice on PHP 7 and is fatal on PHP 8. Six sites
   guarded in `includes/functions.php`.

4. **Template option strings are argument injection by design.** `qemu_options`,
   `docker_options`, `iol_options` and `getFlag()` are user-editable in the UI and
   exist to supply multiple arguments. They are marked `sweep-exempt` with
   reasons. Escaping cannot fix this; it is a design decision the fork must make.

5. **The sudo policy is not a privilege boundary.** It allowlists the 42 binaries
   the code invokes, but at least a dozen (`php`, `perl`, `tee`, `cp`, `mv`, `rm`,
   `chmod`, `chown`, `docker`, `nohup`, `service`, `useradd`) are root-equivalent
   however they are scoped. It removes the blanket grants and makes the surface
   countable. **Treat a web-layer compromise as a host compromise until every
   call site moves behind `unl_wrapper`.**

---

## Suggested next steps, in order

1. **Guacamole.** `guacd` is packaged; fetch `guacamole.war` and wire the Tomcat
   app. Gets HTML5 consoles working and is the largest remaining feature gap.
2. **Merge the branch.** 21 commits is a lot to review at once, but they are
   individually scoped and each commit message explains its reasoning.
3. **Reduce the sudo policy.** `tests/Security/SudoersPolicyTest.php` fails if the
   policy and the code drift, which is what makes this safe to attempt: move a
   call site behind `unl_wrapper`, delete its policy line, and the test says
   immediately whether anything else needed it.
4. **The three wrapper binaries.** Either copy them from an appliance, or
   reimplement — they are console multiplexers and process babysitters, and the
   originals are unstripped, so `strings` and `ltrace` are viable.
5. **CSRF.** `VerifyCsrfToken` is still disabled, with the evidence recorded in
   `store/app/Http/Kernel.php`. The blocker is CKEditor's upload adapter posting
   without a token. The finding is "could not be shown safe", not "unsafe".

---

## Environment

| | |
|---|---|
| Reference VM | `192.168.4.93`, `labadmin`, key auth installed, passwordless sudo |
| SSH | needs `-b 192.168.3.105`; the default route picks the wrong source and times out |
| PNETLab appliance | `10.85.44.5`, `labadmin`, needs `-o IPQoS=none` or ssh hangs |
| Repo | `github.com:3levated-Kn0wledge/pnetlab_main`, SSH key present |
| Verification | run lint and tests **on the VM**, not the workstation — `mgmt-host` runs AWX and k3s, and container churn there caused `hung_task` stalls and starved postgres |

`tools/php-lint.sh` and `tools/run-tests.sh` take `PHP=` to select an interpreter.
Current state: **312 files linted on PHP 8.4 and 7.4, 0 failures; 115 assertions
across 7 test files, 0 failures.**

---

## Documents

The investigation record — roadmap, findings, adversarial review — currently
lives **outside this repository**, in the working directory at
`pnetlab-project/docs/`. It is not committed, for a reason that needs a
decision rather than a default:

> Those documents describe **unpatched vulnerabilities in upstream PNETLab**,
> with payloads and exact injection vectors. Upstream is still deployed by other
> people. This fork is public. Committing them publishes working exploit detail
> for software others are running, ahead of any coordinated disclosure.
>
> `SECURITY.md` already states the *classes* of defect, which is the right level
> for a public repository. The specifics are a separate call — publish, redact,
> or keep them private until upstream has had notice.
>
> Until that is decided the record exists in one place only, which is its own
> risk. Back it up.

| File | What it is |
|---|---|
| `docs/ROADMAP.md` | the plan, v2, revised against live-box evidence |
| `docs/REVIEW-ADVERSARIAL.md` | an adversarial review of that plan |
| `docs/FINDINGS-LIVE-BOX.md` | Q1–Q8 answered against a running appliance |
| `docs/FINDINGS-KERNEL.md` | the kernel investigation |
| `docs/REFERENCE-ENVIRONMENT.md` | how the test host is built |
| `docs/OFFLINE-FIRST.md` | the accepted architectural direction |
| `docs/audit.html` | single-page summary of the live-box findings |

Two claims in those documents were **corrected during this session** and are
marked as such: the UKSM toggle (it works; `unl_wrapper` runs as root) and the
Laravel dependency tree (it installs; it just could not run).
