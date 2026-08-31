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
| `sudoers.d/pnetlab` | Privilege policy for `www-data` | **Incomplete — do not deploy yet** |

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

## Why this is not yet a drop-in fix

The obvious fix — allow only `unl_wrapper` — **breaks the product.** The web
layer sudo-invokes roughly two dozen binaries directly, not just the wrapper:
`ip`, `brctl`, `tunctl`, `iptables`, `ovs-vsctl`, `docker`, `nsenter`,
`qemu-img`, `nc`, `kill`, `rm` and others. Packet capture
(`includes/functions.php`) and Docker consoles (`devices/docker/device_docker.php`)
both call `sudo` directly.

So the work is in two parts:

1. **Now (this branch).** Drop the blanket grants — `www-data ALL=(ALL)
   NOPASSWD:ALL` and `%unl ALL=(ALL) NOPASSWD:ALL`. These have no legitimate use
   and their removal is where nearly all the risk reduction is.
2. **Phase 02.** Route the remaining call sites through `unl_wrapper`, or scope
   them individually, until this file holds one rule. Note that a per-binary
   NOPASSWD entry is not by itself a boundary when the arguments are
   attacker-influenced — `sudo rm` and `sudo ip` with interpolated arguments are
   about as good as root.

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
