# AppArmor

**The fork ships no AppArmor profile, deliberately — and it does not turn
AppArmor off, which is what upstream did.** This document is the evidence for
both halves, what a profile would have to cover, and the one target where
writing it is worth doing first.

Measured 2026-09-02 on the reference VM: Ubuntu 24.04, kernel 6.8.0-138,
QEMU 8.2.2, Docker 29.1.3.

---

## Where this starts

`docs/ROADMAP.md`, Phase 04:

> **Ship an AppArmor profile** with a `userns,` rule. **v2 correction:** this is
> *net-new work*, not a port. Upstream's answer to AppArmor was `apparmor=0` on
> the kernel command line. Budget accordingly.

And the appliance's GRUB line, from the same investigation:

```
mitigations=off pti=off spectre_v2=off nopti l1tf=off nospec_store_bypass_disable
no_stf_barrier apparmor=0
```

with AppArmor confirmed off at runtime.

---

## What is true on the fork's host, measured

```
$ cat /proc/cmdline
BOOT_IMAGE=/vmlinuz-6.8.0-138-generic root=/dev/mapper/ubuntu--vg-ubuntu--lv ro

$ cat /sys/module/apparmor/parameters/enabled
Y

$ sudo aa-status
apparmor module is loaded.
119 profiles are loaded.
24 profiles are in enforce mode.
   … docker-default … unprivileged_userns …
4 profiles are in complain mode.

$ sysctl kernel.apparmor_restrict_unprivileged_userns
kernel.apparmor_restrict_unprivileged_userns = 1
```

**AppArmor is on, and nothing in this repository turns it off.** A sweep of
`install/`, `tools/` and `platform/` for `GRUB`, `apparmor`, `aa-disable`,
`cmdline` and `userns` returns no code — only the prose in `install/README.md`
that records the gap. The installer never edits `/etc/default/grub`, never
stops or masks `apparmor.service`, and never writes the userns sysctl.

`install/lib/verify.sh` now asserts all four of the facts above, softly, so that
"we did not disable it" stops being a claim that decays quietly. The obvious way
this regresses is somebody debugging a node-start failure with `apparmor=0`,
finding that it works, and leaving it.

**Two things in this stack are already confined, and neither is ours:**
`docker-default` is enforcing, so every container a Docker-backed node starts is
under a profile; and `unprivileged_userns` plus the sysctl is what the roadmap's
`userns,` rule was reaching for — Ubuntu 24.04 ships it, restricting
unprivileged user namespaces by default. The `userns,` rule belongs in a profile
that *grants* the capability to something that needs it, and nothing here does:
node start creates namespaces via the Docker daemon, which has its own.

So the roadmap item is smaller than it was written. What remains is confining
the fork's own processes.

---

## Can a profile even be loaded here? Yes — measured

Not a rhetorical question: a profile that cannot attach is not worth designing.
An empty complain-mode profile was loaded over the QEMU binary with
`apparmor_parser -r` — **no reboot** — and a CirrOS node was started through the
API:

```
$ ps -eo user,args | grep [q]emu-system
unl1  /opt/qemu/bin/qemu-system-x86_64 -device virtio-net-pci,netdev=net0,… \
      -netdev tap,id=net0,ifname=vunl1_0,script=no -machine type=pc,accel=kvm …

$ cat /proc/<pid>/attr/current
pnetlab-qemu-probe (complain)
```

The node booted, the telnet console answered, and the tap passed frames. The
attachment survives the `/opt/qemu/bin/qemu-system-x86_64` symlink — AppArmor
matches the resolved binary — and it survives `spawnAsTenant()`'s fork/setuid
into `unl<N>`, which was the part most likely not to work.

**Profiles do not need a reboot. Only kernel command-line changes do, and the
fork makes none.**

*(A trap, recorded because it wasted a run: a complain-mode profile logs only
what it would have DENIED. The first version of this probe granted `file,
capability, network, …` at the top level, which grants everything, and produced
a completely clean audit log that read exactly like a successful confinement.
The profile body has to be empty to get an inventory.)*

---

## What a QEMU profile would have to cover

From the audit records of that run, with the classes named rather than the
individual paths (the kernel rate-limits and de-duplicates, so this is a floor,
not a complete list):

| Access | Example | Why |
|---|---|---|
| `getattr`, `open`, `mmap` under `/usr/lib/x86_64-linux-gnu/` | libglib, libslirp, libpixman, … | QEMU is dynamically linked against ~60 libraries |
| `r` on `/etc/ld.so.cache` | | every exec |
| `r` on `/opt/unetlab/addons/qemu/<template>/*.qcow2` | `linux-cirros/virtioa.qcow2` | the backing file of the linked clone, read-only |
| `rw` on `/opt/unetlab/tmp/<lab>/<node>/` | `virtioa.qcow2`, `console.sock`, `monitor.sock`, `wrapper.txt` | the node workspace, including the two unix sockets QEMU creates |
| `r` on `/sys/devices/system/cpu/online` | | smp probing |

Not in the captured records but certainly required, from the command line above
and from `docs/HANDOVER.md`: `rw` on `/dev/kvm` (`accel=kvm`), `rw` on
`/dev/net/tun` plus the tap by name (`-netdev tap,ifname=vunl1_0`), `unix`
rules for the two sockets, and `signal receive` from the wrapper, which stops
nodes with `fuser -k -TERM` and `pkill -term`.

That is a tractable profile, and it is the one worth writing first: QEMU is the
only component here that executes attacker-supplied code as its whole purpose,
it already runs as an unprivileged tenant, and the paths above are all
predictable. **The two variable parts are why it is not written today:** the
workspace path contains a lab-session and node-session id, so the rule has to be
globbed (`/opt/unetlab/tmp/*/*/`), which weakens the isolation between tenants
that `runsAsTenant()` just bought; and `qemu_options` in a template is a
user-editable multi-argument string (`docs/HANDOVER.md`, item 4) that can name
any file it likes as a drive, a bios, or a chardev. A profile tight enough to be
worth having would reject templates that work today, and finding out *which*
templates means running node types this project has no images for.

---

## What could not be confined, and why saying so matters

`unl_wrapper` is the privileged entry point every node action goes through. It
runs as root and, by design, must be able to:

- `useradd` and `userdel` — it manufactures and reaps a Unix account per node
  session, which means writing `/etc/passwd`, `/etc/shadow`, `/etc/group`;
- `tunctl`, `ip`, `brctl`, `ovs-vsctl`, `sysctl` — the whole data plane;
- talk to the Docker daemon socket, which is root-equivalent by design;
- run `mysqldump` and `mysql` against both schemas (`-a backupdb`/`restoredb`);
- **`exec` a command line assembled from a template's option strings**, as an
  arbitrary user, in an arbitrary node workspace.

A profile permitting that set permits nearly everything a root process can do.
Writing one would produce a document that reads like confinement and is not, and
it would be the sort of thing that gets pointed at in a README as evidence of a
property the fork does not have. The same argument applies to the php-fpm pool,
which reaches `sudo unl_wrapper` and holds membership of the `docker` group.

**The boundary that actually exists here is the tenant account, not AppArmor.**
VPCS and QEMU nodes run as `unl<session>`; taps are handed to that uid by name
so one node's tenant cannot open another's; the sudo policy is at 24 grants and
tested in both directions. That is the control worth strengthening next, and
`docs/HANDOVER.md`'s "tighten `/opt/unetlab/tmp`" is the next honest step in it
— it is also, not coincidentally, the thing that would make a globbed QEMU
workspace rule defensible.

---

## What to do when someone picks this up

1. **Do not start with `unl_wrapper`.** See above.
2. **Start with QEMU**, in complain mode, with an empty profile, and collect a
   full inventory — with `auditd` installed, so the kernel ring buffer's rate
   limiting does not silently truncate it the way it did above.
3. **Do it after `/opt/unetlab/tmp` is tightened**, so the workspace rule can be
   per-tenant rather than `/opt/unetlab/tmp/*/*/`.
4. **Run every node type against it**, including the templates whose
   `qemu_options` name extra files. A profile that has only been tested against
   CirrOS is not evidence.
5. **Ship it in complain mode first**, and give the installer a switch to enforce
   it. A profile that forces the next person to run `aa-disable` is worse than
   no profile, which is the whole reason this document exists instead of one.
6. **Never fix a node-start failure with `apparmor=0`.** If that is the answer,
   the profile is wrong.
