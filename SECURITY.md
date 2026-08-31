# Security policy

## Reporting a vulnerability

Please report security issues privately, via GitHub's **Report a vulnerability**
button under this repository's Security tab. Do not open a public issue for an
unfixed vulnerability.

Include where you can: affected version or commit, reproduction steps, impact,
and whether the issue is reachable pre-authentication.

We aim to acknowledge within 7 days and to agree a disclosure timeline with you.

## Scope and current status

**This fork is under active remediation of inherited defects and should not yet
be treated as hardened.** It descends from an upstream project that has been
quiet since 2023. A live-install audit found issues that are documented, being
worked through in order, and in several cases still present.

Known and tracked, at time of writing:

- The web user's sudo policy grants unconditional root, so any command injection
  is a full host compromise.
- Shell commands are built by string interpolation; `escapeshellarg` is not used
  anywhere in the tree.
- Some SQL is built by interpolation and passed to `prepare()` already assembled,
  which provides no protection.
- Self-hosted account passwords are hashed with unsalted SHA-256.
- Database credentials are hardcoded and identical across installations.
- Upstream HTTP calls had no timeout, allowing worker-pool exhaustion.

Findings inherited from upstream are in scope for this fork's remediation, but
please also consider reporting them upstream where that project is reachable.

## Supported versions

No release has been made from this fork yet. Until one is, only the default
branch is supported.

## Deployment expectations

PNETLab runs emulators as root and manipulates host networking directly. It is
designed for an isolated lab network and should not be exposed to the public
internet or to an untrusted user population.
