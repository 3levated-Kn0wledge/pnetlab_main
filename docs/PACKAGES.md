# Packages

**The fork's package and update mechanism.** This is how a device gets installed
on a box and how a box updates itself. Upstream content is one possible source
for it; it is not built around upstream's protocol, and it does not need
upstream to exist.

Written for the person who will publish to it. If you are standing up a
marketplace, [Publishing a package](#publishing-a-package) is the part you want.

---

## Why this exists

Two features fetched a shell script from `pnetlab.com` and ran it as root.

**The marketplace device installer** (`Admin/DevicesController`) took
`device_script` out of a JSON response, prepended `#!/bin/bash`, wrote it to
`/tmp/pnet_device_factory_<id>`, chmod 0755, then:

```php
exec('sudo dos2unix '. $excutefile);
exec('sudo '. $excutefile. ' > '. $logfile.' 2>&1 &');
```

The same file also ran `device_check` — another string from the same response —
through `exec()` **once per device, on every listing of the store**, and
interpolated the request's device id into `sudo pkill -f pnet_device_factory_<id>`
without escaping it.

**The self-upgrader** (`Helpers/Admin/Upgrade`) downloaded a zip, extracted it
with `ZipArchive::extractTo()` — which writes whatever names the archive
contains — and ran:

```php
exec("sudo $folder/upgrade 2>&1");
```

That last line was the only `sudo $variable` in the tree, which is precisely the
shape `tests/Security/SudoersPolicyTest.php` cannot see: it matches a literal
binary name after `sudo`, and there is no literal there to match.

Neither privileged path had worked since the sudo policy was scoped — neither
`/tmp/pnet_device_factory_*` nor `$folder/upgrade` is on the allowlist. So this
document does not describe a replacement for something that worked. It describes
the shape the feature has to have before it is allowed to work again.

**The rule that produced everything below: never execute supplier-provided shell
as root.** A supplier says what they want done, in a declarative manifest that
can only express operations from a list we define. Code we own decides whether
each one is permissible and then performs it.

---

## The container

A package is a **gzip-compressed ustar archive** with the extension `.pnetpkg`.
Its members must appear in this order and there must be nothing else:

```
manifest.json            what to do, and what the payload weighs
manifest.json.minisig    a detached signature over manifest.json's bytes
payload/…                every file the manifest names, and no others
```

Tar rather than zip, and read by a parser in `platform/packages/PnetPackage.php`
rather than by `ZipArchive` or `PharData`. Both of those extract by name and
follow what the archive tells them; every check here has to happen *before* a
byte is written, and the only way to be sure of that is to do the writing
ourselves. Tar also makes the hostile cases explicit — a symlink is a typeflag,
not an attribute — which is easier to refuse and easier to test.

**The manifest is first because it bounds the rest.** It names every payload
member with its exact size and sha256, and it is signed. Once the signature
verifies, the total number of bytes that will ever be written to disk is an
*authenticated* number, known before extraction starts. A decompression bomb
cannot be built out of a signed manifest.

---

## The manifest

```json
{
  "format": 1,
  "id": "cisco-vios-fork",
  "version": "1.0.0",
  "name": "Cisco vIOS (fork)",
  "kind": "device",
  "device_id": "4242",
  "payload": {
    "images/virtioa.qcow2": { "sha256": "…", "size": 1073741824 }
  },
  "install": [
    { "verb": "mkdir",         "path": "addons:qemu/vios-15.6", "mode": "0755" },
    { "verb": "install_image", "emulator": "qemu", "folder": "vios-15.6",
      "name": "virtioa.qcow2", "source": "images/virtioa.qcow2" }
  ],
  "uninstall": [
    { "verb": "remove", "path": "addons:qemu/vios-15.6" }
  ]
}
```

| Key | Meaning |
|---|---|
| `format` | must be `1` |
| `id` | `[a-z0-9][a-z0-9._-]{0,63}`; identifies the package for updates and removal |
| `version` | `1.2.3` or `1.2.3-rc1` |
| `kind` | `device` or `update` |
| `device_id` | optional; links the package to a marketplace listing, which is how the store screen knows the device is installed |
| `payload` | member name → `{sha256, size}`; **computed by the build tool**, not written by hand |
| `install` | the operations, in order |
| `uninstall` | how to undo them; recorded at install time and replayed on removal |

`payload` and `install` are cross-checked: an operation naming a member the
manifest does not declare is rejected, and **a declared member that no operation
uses is also rejected** — an unused member is a file that would be extracted and
never referred to again, which is how a package smuggles something onto disk.

Anything else — an unknown top-level key, an unknown verb, an unknown argument,
a value that fails its pattern — rejects the **whole** package. There is no
partial acceptance and no warn-and-continue.

### Paths

There is no syntax for an absolute path. A destination is written
`root:relative`, where `root` is one of six names:

| root | resolves to |
|---|---|
| `addons` | `/opt/unetlab/addons` |
| `templates` | `/opt/unetlab/html/templates` |
| `icons` | `/opt/unetlab/html/images/icons` |
| `scripts` | `/opt/unetlab/scripts` |
| `html` | `/opt/unetlab/html` |
| `state` | `/opt/unetlab/data/packages` |

Each component must begin with an alphanumeric, so `..` and `.` cannot be
components and a leading `/` cannot occur. The component rule is then restated
as an explicit check, so loosening the pattern later cannot quietly reopen
traversal, and the resolved path is confirmed to be a descendant of its root.

---

## The verb allowlist

Eleven verbs. The list is derived from what an install actually has to do on a
PNETLab host, read off the reference appliance at `10.85.44.5` — not from what a
general-purpose installer might want.

**The evidence.** The appliance has no cached device-factory scripts, no
leftover `/tmp/pnet_device_factory_*` and an empty local docker image list, so
the verbs come from the layout the scripts must produce rather than from the
scripts themselves:

* `/opt/unetlab/addons/{qemu,iol,dynamips}/` — the three emulator image trees.
  `addons/iol` further splits into `bin/` and `lib/`.
* `/opt/unetlab/html/templates/intel/*.yml` — **198 device templates**, plus an
  `amd/` set and five base types under `templates/device/`. A template is a
  plain YAML file; the node-type list is derived from the files present, so
  there is no separate menu or registry to update.
* `/opt/unetlab/html/images/icons/*.png` — the icon each template names in its
  `icon:` field.
* `/opt/unetlab/scripts/config_*.py` — the per-device configuration script a
  template names in `config_script:` (`vios.yml` names `config_vios.py`).
* Docker-backed node types pull an image by name.
* `unl_wrapper -a fixpermissions` shows what the tree's ownership is supposed to
  be: `root:root` under `addons`, `www-data:www-data` under `html`.

| Verb | Arguments | Effect | Reversible |
|---|---|---|---|
| `mkdir` | `path`, `mode?` | create a directory under a managed root | yes |
| `install_file` | `source`, `path`, `mode?`, `owner?` | copy a payload file into place | yes |
| `install_image` | `emulator`, `folder?`, `name`, `source`, `mode?`, `owner?` | place an image under `addons/<emulator>/<folder>/` | yes |
| `install_template` | `arch?`, `name`, `source` | place a `.yml` under `templates/<arch>/`, owned `www-data` | yes |
| `install_icon` | `name`, `source` | place an icon under `html/images/icons/` | yes |
| `install_config_script` | `name`, `source` | place a `config_*.py` under `scripts/`. **Signed packages only** | yes |
| `set_permissions` | `path`, `mode?`, `owner?`, `recursive?` | chmod/chown, never through a link | yes |
| `remove` | `path` | delete a path under a managed root | yes |
| `set_version` | `version` | record the applied version in package state | yes |
| `docker_pull` | `image` | `docker pull <image>` | no |
| `restart_service` | `service` | `systemctl restart <service>.service` | no |

Every argument has a pattern, and a value that fails it rejects the package:

```
mode          0600|0640|0644|0664|0700|0750|0755|0775|2775
owner         root:root | root:unl | www-data:www-data | root:www-data
emulator      qemu | iol | dynamips
arch          intel | amd
service       apache2 | guacd | docker | cpulimit | php<N>.<N>-fpm
version       1.2.3 or 1.2.3-rc1
dockerimage   lowercase name[/name][:tag], no shell characters at all
```

**There is no `run_script` verb and there will not be one.** That is the whole
point: a supplier can say "put this qcow2 here", not "run this".

### `docker_pull` does not work on the hosts this is for

It is `docker pull <image>`: a registry fetch, in a fork that has adopted
offline-only (`docs/OFFLINE-FIRST.md`). Every other verb here delivers its
content out of the signed payload; this one is the single verb that requires the
network, and on an air-gapped appliance it fails. A device package for a
Docker-backed node can therefore declare a template, an icon and a config script
and still not deliver the one artefact without which the node cannot start —
node start runs `docker create <image>`, which has no local fallback.

The missing verb is `install_docker_image`: a payload member, sha256'd and
size-bounded by the manifest exactly like every other payload member, handed to
`docker load`. It needs an uninstall that records the reference the archive
carried, and it is not written yet. Until it is, images are staged out of band
into `/opt/unetlab/addons/docker` by `install/lib/docker_images.sh` — which has
no signature check at all, and says so. `docs/DOCKER-IMAGES.md` has the
measurements and the design. **When `install_docker_image` lands, `docker_pull`
should be removed rather than kept beside it.**

### Why `install_config_script` is here anyway, and what it costs

A configuration script *does* execute later, as root, when a node is configured.
Shipping one is therefore still a code-execution channel — the last one — and
this document is not going to pretend otherwise. It is included because most
real device templates name one and a device package that cannot ship it is not
useful. It is fenced in three ways: the filename must match
`config_*.{py,sh,php}`, it can only land in `/opt/unetlab/scripts`, and it is
**refused outright in an unsigned package**, including in unsigned mode. So the
one verb that ships executable content is the one verb that always requires
somebody to have signed for it.

---

## The trust model

### The mechanism

Detached **Ed25519** signatures over the manifest bytes, in **minisign's file
format**, verified in PHP with `ext-sodium`. Trusted public keys are files in a
directory:

```
/opt/unetlab/data/packages/trusted.d/*.pub      root-owned, 0644, dir 0755
```

Weighed against the alternatives on a clean Ubuntu 24.04:

| | |
|---|---|
| **GPG** | present, but drags in a keyring, an agent, trust levels and expiry semantics we would then have to have opinions about. Rejected: too much machinery for "is this from us". |
| **openssl** | in the base install and can do Ed25519, but a detached-signature workflow means shelling out to a CLI with file arguments, and there is no standard signature *file* format to interoperate on. |
| **signify** | universe only, not installed by default. |
| **minisign** | universe only — **but we do not need the binary.** The format is small enough to verify in ~60 lines of PHP against `ext-sodium`, which is already enabled. |

So: minisign's *format*, not minisign the dependency. Nothing shells out to it.
The benefit of the format choice is that the release key can be generated and
used with the stock `minisign` tool on a machine that has never seen this
repository — which is the arrangement worth having once the fork publishes to
other people's appliances. `pnet-package keygen` writes minisign's unencrypted
secret-key form so either tool can sign with either key.

The signature covers the manifest. The manifest covers the payload, by sha256.
Tampering with a payload byte fails the digest; tampering with the manifest
fails the signature.

### Where the root of trust comes from

Three modes, in descending order of preference:

1. **A fork-held signing key.** The fork's own release key signs the fork's own
   packages. Its public half ships in the repository at
   `platform/packages/trust/` and is installed into `trusted.d`. This is the
   default posture for updates.
2. **An admin-pinned key.** The operator drops any `.pub` into `trusted.d` and
   from then on trusts that publisher. This is what a third-party marketplace
   uses, and it is a deliberate, per-box, root-only act.
3. **Unsigned mode.** Off by default and gated on **two independent things**: the
   `--allow-unsigned` flag *and* a root-owned, non-group-writable marker file at
   `/opt/unetlab/data/packages/ALLOW_UNSIGNED`. One switch that the web layer
   could ever reach is not a gate. Applying an unsigned package logs
   `WARNING: applying an UNSIGNED package. Its contents are not attributable to
   anyone.`, `install_config_script` is refused, and the payload is capped at
   2 GiB because there is no authenticated size to trust.

### What this does NOT defend against — say it plainly

**Upstream's scripts are unsigned. `pnetlab.com` publishes no signature and no
key, so there is nothing to verify against, and inventing a check that verifies
nothing would be worse than having none.**

What that means concretely, today, under this design:

> An admin opens the marketplace and clicks **Get Device**. The store still
> lists devices, because listing comes from upstream's API. The install fails,
> with the message *"This device has no signed package."* It does not fall back
> to running `device_script`. The feature is honestly unavailable rather than
> dishonestly dangerous.

That is the intended outcome, not a gap to be worked around later. Three ways
out of it, all of which are somebody deciding to publish properly:

* the owner stands up their own repository and points `PNET_PACKAGE_CENTER` at
  it (see below) — this is the intended path;
* upstream, or a third party, publishes signed `.pnetpkg` files and an admin
  pins their key;
* an operator turns on unsigned mode for a package they built and inspected
  themselves.

Also **not** defended against, and worth being blunt about:

* **A trusted publisher who is malicious or compromised.** A signature proves
  who, not whether it is safe. A signed package can still `remove` files under a
  managed root, replace a template with a broken one, or ship a config script
  that runs as root. Signing bounds *who can do this to you*; it does not bound
  what they can do.
* **`install_config_script`.** As above: signed supplier code that later runs as
  root. The residual channel, called out rather than hidden.
* **Application code under `html:`.** An update package that replaces
  `/opt/unetlab/html/**` is replacing the application. That is what an update
  *is*; the signature is the whole of the control.
* **Downgrade and rollback attacks.** Nothing yet refuses an older version from
  a trusted key, and there is no revocation. Removing a key from `trusted.d`
  stops future packages; it does not undo past ones.
* **The transport.** Downloads are plain `curl` (and `Query::make` currently
  rewrites `https` to `http`). The signature is what makes the transport not
  matter for *integrity*; it does not stop an observer knowing what you
  installed.
* **A hostile web layer.** A compromised web layer can still call the wrapper
  repeatedly with any file it can write. It cannot make the wrapper do anything
  other than "verify and apply", which is the point — but it can apply any
  package that verifies.
* **Local root.** Anyone who is already root can write `trusted.d` or the
  unsigned marker. This defends the box against its suppliers, not against
  itself.

---

## Extraction, on the assumption the archive is hostile

Two passes over one stream. Nothing is buffered whole; a 40 GB image moves
through in 1 MB chunks, so memory does not scale with package size.

**Pass 1** reads `manifest.json` (capped at 1 MiB) and `manifest.json.minisig`
(8 KiB), verifies the signature, and parses the manifest. No payload byte has
been read yet, and nothing has been written.

**Pass 2** streams the payload. Per member, in this order:

1. The name must begin `payload/`. Anything else — an absolute name, a name at
   the archive root — is refused as "only `payload/*` may follow the manifest".
2. Component-wise traversal check: no empty, `.` or `..` component.
3. Pattern check on the whole relative name.
4. Typeflag must be a regular file. **Symlinks, hardlinks, devices, fifos and
   the GNU long-name extensions are all refused by this one rule**, because tar
   makes them all typeflags. The ustar `prefix` field is refused too — our
   writer never uses it, so a package that does is hiding a name from the checks
   above.
5. The member must be declared in the manifest, must not have appeared already,
   and its header size must equal the *signed* size. A mismatch aborts before
   the body is read.
6. The body streams to a private staging directory (mode 0700) while being
   hashed, and the sha256 must match.

Then: every member the manifest declared must have appeared. Tar header
checksums are verified, so a malformed header is a rejection rather than a
misparse — which is how a nine-byte checksum field bug in the first draft of the
*writer* was caught by the reader.

At apply time, before each operation, the destination is walked from its managed
root downward and **refused if any component is a symbolic link**. The archive
can no longer carry a link, but one could have been planted under a managed root
by something else, and writing "into" it would put a supplier's file wherever it
points.

Nothing runs through a shell. Filesystem work uses PHP's own calls, which take
paths and not command lines. The two verbs that need an external program use
`proc_open()` with an **argv array**, which execs the binary directly: a
semicolon in an image name is a semicolon in `argv[3]`, not a second command.
`tests/Security/PackageApplyTest.php` asserts on the tokens that the applier
contains no `exec`/`shell_exec`/`system`/`passthru`/`popen`/backtick at all.

---

## Transactions, idempotency and interruption

Every reversible operation is journalled to disk *before* it mutates anything. A
file about to be overwritten or removed is **moved** into the staging directory,
not deleted, so undo is a rename back.

* **Reversible operations run first.** If any fails, the journal is replayed
  backwards and the host is where it started. Nothing is recorded as installed.
* **Irreversible operations (`docker_pull`, `restart_service`) run last**, only
  after every reversible one has succeeded. If one of those fails, the
  filesystem changes stand and the error says so. A service restart is not
  pretended to be undoable.
* **Idempotent.** `mkdir` on an existing directory, `remove` of an absent path
  and re-applying the same package are all successes. An admin clicking *Get
  Device* twice is not an error.
* **Atomic per file.** Copy to a sibling temporary, chmod, chown, rename. A
  reader never sees a half-written image.
* **Interruption.** If the process is killed mid-apply, the journal survives.
  The next run finds it, replays it backwards, and only then starts work. A
  half-applied upgrade is not repaired by the next upgrade succeeding — it is
  unwound before the next upgrade begins.

---

## Publishing a package

You need PHP with `ext-sodium`. You do not need a PNETLab host.

### 1. Make a key, once

```bash
platform/packages/pnet-package keygen --out release --comment "Acme PNETLab packages"
```

`release.key` is unencrypted and mode 0600 — **keep it off the build host**.
`release.pub` is what every box that should trust you installs:

```bash
sudo install -m 0644 -o root -g root release.pub /opt/unetlab/data/packages/trusted.d/acme.pub
```

### 2. Lay out the source

```
vios/
  manifest.json          without a payload map; the tool computes it
  payload/
    images/virtioa.qcow2
    templates/vios.yml
    icons/Router.png
```

Write `manifest.json` with `id`, `version`, `kind`, the `install` list and an
`uninstall` list. Give it a `device_id` if it corresponds to a marketplace
listing — that is what makes the store screen show it as installed.

### 3. Build and sign

```bash
pnet-package build --source vios --out vios-1.0.0.pnetpkg --key release.key
```

The tool computes every digest, writes a canonical manifest (fixed key order,
stable formatting, so two builds of the same inputs are byte-identical), and
**parses it exactly as the appliance will** — so a manifest the appliance would
refuse fails on your machine instead of on somebody's box.

To keep the key off the build host, build unsigned first, sign the emitted
`manifest.built.json` elsewhere with the stock tool, and rebuild:

```bash
pnet-package build --source vios --out draft.pnetpkg --unsigned
minisign -S -s release.key -m manifest.built.json          # on the offline machine
pnet-package build --source vios --out vios-1.0.0.pnetpkg \
                   --signature manifest.built.json.minisig
```

### 4. Check it before anyone else does

```bash
pnet-package verify  --package vios-1.0.0.pnetpkg --trust ./trust
pnet-package inspect --package vios-1.0.0.pnetpkg
```

`inspect` prints the manifest digest, every payload member with its size and
digest, and the exact operation list that will run. Read it. It is short by
design, and that is the review that a shell script never permitted.

### 5. Serve it

Any static HTTP server. Set the repository on the box:

```bash
PNET_PACKAGE_CENTER=https://packages.example.com
```

The box resolves a device to a package in this order:

1. `device_package` in the device's index record, if it is an http(s) URL;
2. otherwise `${PNET_PACKAGE_CENTER}/devices/<device_id>.pnetpkg`;
3. otherwise it reports that no package exists and installs nothing.

An optional `device_package_sha256` in the index is checked after download.
That is a transport check only — it catches a truncated or swapped file early.
**The signature inside the package is what decides whether the contents are
believed**, and it is checked by root, after the web layer has stopped being
able to touch the file.

### 6. The index

`${PNET_PACKAGE_CENTER}/index.json` is how the box learns what the repository
serves. It replaced two upstream calls — the device listing and the update
check — when Phase 05 severed them. One document, two keys, both optional:

```json
{
  "devices": [
    {
      "device_id": "vios",
      "device_name": "Cisco vIOS",
      "device_des": "IOSv 15.9, L3 image",
      "device_img": "https://packages.example.com/img/vios.png",
      "device_package": "https://packages.example.com/devices/vios.pnetpkg",
      "device_package_sha256": "<64 hex characters>",
      "device_guide": "https://packages.example.com/guides/vios.html"
    }
  ],
  "appliance": {
    "version": "5.3.14",
    "package": "https://packages.example.com/pnetlab-5.3.14.pnetpkg",
    "sha256": "<64 hex characters>",
    "note": "What changed, as plain text."
  }
}
```

`devices` is what the device store lists; `device_id` is the only required
field and must match `^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`. `appliance` is what
the version dialog compares against `ctrl_version`; it needs `version` and
`package` or it is ignored.

The box treats the index as data from the network, because it is. It is
capped at 1 MiB; every URL must be http(s) with a host, so a `javascript:` or
`data:` image never reaches the page; digests must be 64 hex characters; text
is trimmed and bounded; a record without a valid id is dropped, and a
duplicate id keeps the first record. `note` is rendered as text, not HTML.
None of this decides what an install does — that is the signed manifest
inside the package — but it is what stops a hostile index from being a
cross-site scripting vector. `tests/Security/PackageIndexTest.php` feeds it
the hostile shapes.

**It is fetched only when `PNET_PACKAGE_CENTER` is set, and only from the two
screens that ask** — the device store and the version dialog. With no
repository configured, nothing is contacted and the store says so. This is
the one outbound request the admin UI still makes, and it is an opt-in.

---

## How the box uses it

```
  browser ── /admin/devices/get ──▶ DevicesController
                                        │  writes a job file, creates the
                                        │  process_device row, returns at once
                                        ▼
                                   php artisan pnet:package-run <device-id>
                                        │  downloads as www-data into
                                        │  /opt/unetlab/data/packages/incoming
                                        ▼
                    sudo unl_wrapper -a package -P <path>
                                        │  verifies, extracts, applies, journals
                                        ▼
                              /opt/unetlab/addons, html/templates, …
```

The web layer can say exactly one thing to the privileged side — *apply this
file* — and be told whether it worked. It cannot name an operation, a
destination or an argument. Removal is `-a packageremove -I <package-id>`, which
replays the uninstall plan recorded when the package was installed; nothing is
re-downloaded and no new instructions are accepted at removal time.

The HTTP contract is unchanged: `/admin/devices/{filter,get,delete,process}`
take and return what they did, `process_device` rows still drive the progress
dialog, and `process` still returns the log text it shows. The log moved from
`/tmp/pnet_device_factory_<id>_log` to
`/opt/unetlab/data/Logs/packages/<id>.log`, because a predictable filename in a
world-writable directory is a symlink target.

### On-disk layout

```
/opt/unetlab/data/packages/
  trusted.d/*.pub        trusted publishers        root:root 0644, dir 0755
  installed/<id>.json    what is installed, and its uninstall plan
  staging/<id>-<pid>/    extraction, rollback backups, the journal   0700
  incoming/              web-layer downloads       www-data
  version                set by set_version
  ALLOW_UNSIGNED         absent unless unsigned mode is deliberately on
```

### Installation requirements

`install/lib/platform.sh` installs `unl_wrapper`; it must also install the
applier, which the wrapper looks for at `../packages/` relative to itself:

```bash
install -d -m 0755 -o root -g root /opt/unetlab/packages
install -m 0644 -o root -g root platform/packages/PnetPackage.php        /opt/unetlab/packages/
install -m 0644 -o root -g root platform/packages/PnetPackageApplier.php /opt/unetlab/packages/
install -d -m 0755 -o root -g root /opt/unetlab/data/packages/trusted.d
install -d -m 0755 -o root -g root /opt/unetlab/data/Logs/packages
install -d -m 0755 -o www-data -g www-data /opt/unetlab/data/packages/incoming
```

`ext-sodium` is required and is enabled by default in Ubuntu's PHP packages.

### Effect on the sudo policy

`www-data ALL=(root) NOPASSWD: /usr/bin/dos2unix` becomes removable — the two
`sudo dos2unix` call sites in `DevicesController` were the only ones in the
tree, and `SudoersPolicyTest` reports it as unused the moment this change lands.
No new grant is needed: `unl_wrapper` is already permitted, and the new actions
are actions on it.

---

## What is deliberately not here yet

* **Version constraints.** `requires` is accepted in the manifest schema and not
  enforced. A package cannot yet say "PNETLab ≥ 5.1".
* **Downgrade protection and revocation.** See the trust model.
* **Dependencies between packages.** There is no dependency graph and no solver.
* **Delta updates.** An update package carries whole files.
* **A signed index.** Package *discovery* is unauthenticated; only package
  *contents* are signed. A repository that lies about which version is current
  can withhold an update, though it cannot forge one.
* ~~**Removing the legacy path.**~~ Done in Phase 05: the box no longer asks
  upstream for device records or versions at all. It reads the index above.
