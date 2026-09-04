# Direction: offline-only

**Status: accepted, 2026-08-29. Applies to all future development in this fork.**

This fork targets **fully offline operation**. A PNETLab install must need no
external communication of any kind to be installed, licensed, logged into, or
used. Any feature that requires reaching a server outside the appliance is either
removed, made strictly optional and non-blocking, or reimplemented locally.

## Why

Three reasons, in order of weight.

**It is what the product is for.** PNETLab is deployed on isolated lab networks —
frequently air-gapped by policy. External dependencies are not a convenience
there; they are a defect.

**The upstream services are not ours and are already decaying.** The shipped code
points at five upstream hosts plus a sixth, `secure.pnet-lab.com`, which
**already fails to resolve**. Upstream has been quiet since 2023. Building on
infrastructure we neither control nor can repair is not a foundation.

**The current failure mode is severe.** Upstream calls were issued with no curl
timeout, so an unreachable server did not degrade the UI — it hung the request
for up to five minutes and pinned an Apache worker. On an isolated network,
routine actions could exhaust the worker pool. Fixed in this branch, but it
illustrates the cost of treating a remote service as reliably present.

## What this means concretely

### Removed

- **The online authentication path.** `APP_AUTHEN`, the JWT guard, and
  `user.pnetlab.com` as a cookie domain. Offline auth is already a complete,
  first-class local system and is what every self-hosted install uses.
- **Licence keepalive.** `License::keepalive()` calls
  `APP_CENTER/api/offboxs/box/relicense` — including from the *offline* mode
  setup path. A self-hosted appliance does not relicense against a third party.
- **The installation fingerprint.** `Query::boxCenter()` attaches a `license` key
  and an encrypted machine UUID to every upstream request. Both go.
- **`CorsMidware`**, which grants credentialed cross-origin access to a
  hardcoded third-party collector domain, and the blanket
  `Access-Control-Allow-Origin: *` in `store/public/index.php`.
- **`APP_SECURE`** — the host no longer exists.

### Replaced with local equivalents

- **Device templates and images.** The store/marketplace becomes a local import
  path: a documented offline procedure plus a directory the appliance reads.
- **Docker node images.** Today an air-gapped install cannot run Docker-backed
  nodes at all — the appliance ships no images and cannot reach a registry.
  Needs pre-seeded images or an offline import step.
- **Update checks.** Version information is local. If an update mechanism exists
  later it is opt-in, clearly labelled, and never on a request path.

### Rules for new code

1. **No network call on a request path.** If something must reach outside, it
   runs out-of-band and its absence is not an error.
2. **Every outbound call sets a connect timeout and a total or stall timeout.**
   No exceptions. See `store/app/Helpers/Request/Query.php`.
3. **Absent upstream is the default state, not the error state.** Features
   degrade visibly and cheaply; they do not hang, retry indefinitely, or block
   login.
4. **No telemetry, no fingerprinting, no phone-home** — not opt-out, not
   anonymous, not "just a version check".
5. **New third-party service dependencies need an explicit decision** recorded
   here, with an offline fallback.

## Consequences for the roadmap

This *reduces* scope. Whole subsystems get deleted rather than migrated: the
online auth path, the licence machinery, the CORS middleware and the upstream
store client. It also settles the fork-independence question — core emulation was
verified working with upstream unreachable, so nothing load-bearing is lost.

It has one cost worth stating plainly: features that genuinely depended on
upstream infrastructure (the shared lab marketplace, in particular) do not come
back as-is. They become local import/export, which is less convenient and more
honest.

## Status, 2026-09-04: done

Phase 05 carried this out (branch `phase-05-sever-upstream`, one commit per
surface; `docs/ROADMAP-STATUS.md` has the table). Against the lists above:

**Removed** — every item. The online authentication path, the licence
keep-alive, the installation fingerprint, `CorsMidware` (Phase 02) and
`APP_SECURE` are gone, and so are the four things the list did not name: the
lab marketplace, the notice bell, the multi-access licences, and the upstream
domain on the session cookie and the token.

**Replaced with local equivalents** — device templates and images arrive as
signed packages from a repository the owner runs, discovered through that
repository's own `index.json` (`docs/PACKAGES.md`); Docker images are seeded
(`docs/DOCKER-IMAGES.md`); the update check reads the same index. The lab
marketplace was *not* replaced: a lab is a file, and moving one is a copy.

**Rule 1** holds with one deliberate, documented exception: the package
repository index is fetched on the device-store and version-dialog request
paths, only when `PNET_PACKAGE_CENTER` is set, bounded by the standard
timeouts, and absent-by-default. Rules 2–5 hold with no exception.

`tests/Security/UpstreamSeveredTest.php` fails if any of it comes back.

## Deferred: the committed `store/.env`

The tracked `store/.env` and its live `APP_KEY` are **deliberately left in place
for now**. Purging it from this fork's history would not contain the exposure:
upstream `pnetlab/pnetlab_main` still serves the identical file publicly, and
this fork currently sits at the identical commit. The key must be treated as
burned regardless.

The real fixes belong with the install path — generate a unique `APP_KEY` per
installation and stop shipping a default — and are tracked there rather than
being addressed by a history rewrite. Revisit before any public promotion of this
fork.
