# Docs

Two kinds of document live under `docs/`.

**Active docs are tracked.** They are the standing reference for how this fork
is built, what it supports, and what its security and licence positions are.
They are expected to stay current; when one goes stale, it is corrected, not
deleted.

**Finished docs move to `docs/inactive/`.** When a document describes work
that is complete — an exit gate that has been cleared, a migration that has
landed — it is moved there rather than deleted. It stays tracked, so it travels
with every clone and archive and `git log --follow` reaches its whole history,
but it is out of the way of greps and reviews of live work and nothing in it is
guaranteed to still be accurate: it is a record of a decision at a point in
time, and it is not updated.

To retire a doc: `git mv docs/<name>.md docs/inactive/`, update anything that
links to it, and note it here. To bring one back, `git mv` it out.

## Active

| File | What it is |
|---|---|
| `HANDOVER.md` | state at the end of the most recent session, and the running record of what was done and why |
| `ROADMAP-STATUS.md` | bullet-by-bullet state of the roadmap phases, audited against the tree |
| `BUILD.md` | how to build the frontend bundles and the composer side |
| `PLATFORM-SUPPORT.md` | the supported platform, and what other releases risk |
| `REFERENCE-ENVIRONMENT.md` | how the verification host is built |
| `OFFLINE-FIRST.md` | the accepted offline-only architectural direction |
| `PACKAGES.md` | the signed-package format, trust model and threat model |
| `DOCKER-IMAGES.md` | seeding Docker images onto an offline host |
| `LICENSING.md` | the licence position and the pre-public checklist |
| `APPARMOR.md` | why no profile ships, and what stops AppArmor being disabled |

`THIRD-PARTY.md` and `LICENSE` are at the repository root, and
`platform/wrappers/src/README.md` documents the wrapper core.

## Inactive (under `docs/inactive/`)

| File | What it was | Retired |
|---|---|---|
| `PHASE-04-EXIT-FIXES.md` | the Phase 04 exit gate — fifteen defects found by reviewing the work that closed Phase 04, each fixed and verified before the branch merged into `main` | 2026-09-03, gate cleared and merged (`5e2bb03`) |
