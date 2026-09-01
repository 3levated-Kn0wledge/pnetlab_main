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
| `sudoers.d/pnetlab` | Privilege policy for `www-data` | Deployable, and **not a boundary** — see below |

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

- A scripted, reproducible install tested on a clean target image.
- Per-installation `APP_KEY` generation (see `docs/OFFLINE-FIRST.md`).
- Rotation of the hardcoded MySQL `root` / `pnetlab` credential, which currently
  grants database access to **any** local account, not just the web user.
- Tenant-account lifecycle: node start creates `unlN` Unix accounts with login
  shells that are never removed, and a failed start additionally leaks its tap
  interface.
- systemd units, currently hand-placed in `/etc/systemd/system`.
- AppArmor profile. Upstream's answer to AppArmor was `apparmor=0` on the kernel
  command line; a profile carrying a `userns,` rule is new work, not a port.
