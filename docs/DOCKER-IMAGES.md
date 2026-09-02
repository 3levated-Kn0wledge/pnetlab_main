# Docker images on an offline host

**A Docker-backed node needs its image already in the local daemon. There is no
other source on an air-gapped box, and nothing in the product tells you so
before the node fails to start.** This document is the seeding path, what it
does not cover, and where it should end up.

Written 2026-09-02, against the reference VM: Ubuntu 24.04, Docker 29.1.3,
cgroup v2, `Cgroup Driver: systemd`.

---

## What is actually broken, measured

**1. Node start has no fallback.** `device_docker::prepare()` runs

```
docker -H=unix:///var/run/docker.sock create -ti --memory 256M … <image>
```

Docker resolves an image reference locally and then goes to a registry. With no
registry reachable, `create` fails and `prepare()` returns **80083**, "Failed to
create docker container". That is the whole of the diagnosis the user gets.

**2. The UI does not stop you getting there.** `getTemplates()` in
`includes/functions.php` decides whether a node type is offered:

```php
if ($p['type'] == 'docker') {
    if ($templ == 'docker') {
        $found = 1;                                  // hardcoded
    } else {
        $cmd = 'docker … images | grep ' . escapeshellarg($templ);
        exec($cmd, $o, $r);
        if (count($o) > 0) $found = 1;
    }
}
```

So a *named* docker template (`mysql-server`) is correctly greyed out when its
image is absent — but the **generic `docker` template is always selectable**,
because that branch never asks the daemon anything. On a host with zero images
the node type list looks fine, the node is accepted into the lab, and the
failure arrives at start. The appliance shipped exactly this state: zero images,
no registry access.

So the product's first-run expectation of images is: **none, and it does not
degrade gracefully.** Nothing seeds an image, nothing warns that none exist, and
one of the two paths through the type list does not check.

**3. The name is the contract.** That `grep` is why a loaded image has to be
*named* for the template to find it. An image loaded from an archive with no
repository tag — `Loaded image ID: sha256:…` — is on the box, works with
`docker run`, and is invisible to PNETLab.

---

## The seeding path

One directory, alongside the image trees for the other three node types:

```
/opt/unetlab/addons/qemu/        already existed
/opt/unetlab/addons/iol/         already existed
/opt/unetlab/addons/dynamips/    already existed
/opt/unetlab/addons/docker/      this
```

`docker save` on a machine that has a registry; `docker load` on the appliance.

### On a connected machine

```bash
tools/docker-images.sh save alpine:3.20 nginx:1.27
```

Pulls each reference if it is not already local, then writes one gzipped archive
per image into `/opt/unetlab/addons/docker` (override with
`PNET_DOCKER_IMAGE_DIR`). Or by hand, which is all the script does:

```bash
docker pull alpine:3.20
docker save alpine:3.20 | gzip > alpine-3.20.tar.gz
```

### On the appliance

Copy the archives into `/opt/unetlab/addons/docker`, then:

```bash
sudo install/install.sh --only docker-images
```

The step is part of a normal install run too, so images staged on the release
media are loaded without a second command. It does nothing, and says so, when
the directory is empty — which is the normal state of a fresh install.
`tools/docker-images.sh load` is the same operation for an operator who is not
re-running the installer.

`install/lib/verify.sh` reports the image count, so
`sudo install/install.sh --only verify` answers "can this box run a Docker node
at all" without starting one.

### Making an image usable by a template

A template is a plain YAML file naming an image:

```yaml
type: docker
name: Alpine
image: alpine:3.20
```

`getTemplates()` matches the **template's filename stem** against `docker images`
output, so `templates/alpine.yml` needs a repository containing `alpine`. If an
archive loads untagged, the installer says so and prints the `docker tag` command
to fix it.

---

## What this does NOT do, plainly

**There is no signature and no verification.** A tarball in
`/opt/unetlab/addons/docker` is loaded because root put it there. `docker load`
writes layers into `/var/lib/docker` as root and is not a sandbox, so the
directory is root-owned 0755 and the trust model is exactly "root chose this
file". That is weaker than everything else the fork installs, and it is the
reason for the section below.

**It does not ship any image.** Nothing in this repository carries one, and
nothing should: images are large, licensed variously, and none of them is ours.
The mechanism is here; the content is the operator's.

**It does not fix the generic-template gap.** `getTemplates()` still offers the
`docker` type on a host with no images. Fixing it means asking the daemon in
that branch too, and deciding what the type list should do when Docker is not
installed at all — a UI decision, not a packaging one, and not made here.

---

## Where this should end up: a package verb

`docs/PACKAGES.md` is the fork's answer to "how does content get onto a box",
and it already has a Docker verb:

| Verb | Arguments | Effect | Reversible |
|---|---|---|---|
| `docker_pull` | `image` | `docker pull <image>` | no |

**`docker_pull` cannot work on the hosts this fork is for.** It is a registry
fetch, and the fork's stated direction is offline-only
(`docs/OFFLINE-FIRST.md`). A signed device package for a Docker-backed node
today can declare a template, an icon and a config script, and then has no way
to deliver the one artefact the node cannot start without.

The missing verb is `install_docker_image`: a payload member, sha256'd and
size-bounded by the signed manifest exactly like every other payload member,
handed to `docker load`. It fits the existing model without loosening it —
the manifest already authenticates payload bytes before extraction, which is
strictly more than the directory above does — and it would let a device package
be complete.

It is not done here because it is not a one-line change: it needs a verb and its
argument patterns in `PnetPackage.php`, an applier with a working uninstall
(`docker image rm` by the reference the archive carried, which means recording
that reference at install time), the build tool's payload accounting, and its
own tests. Doing it badly would put an unreviewed `docker load` inside the one
mechanism whose whole point is that supplier content is never executed. The
directory above is the honest interim: small, obvious, and easy to delete when
the verb exists.

**When that verb lands, `docker_pull` should go.** It is the only verb in the
list that requires the network, in a product that has adopted offline-first, and
leaving both would be two ways to do one thing with only one of them usable.
