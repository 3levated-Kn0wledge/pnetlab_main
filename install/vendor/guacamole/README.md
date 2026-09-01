# install/vendor/guacamole — the two artefacts apt cannot supply

Everything else this installer needs comes from the Ubuntu archive. These two
files do not, because **Guacamole's web application is not packaged by Debian
or Ubuntu at any version** — the archive carries `guacamole-server` (guacd and
the protocol client libraries) and nothing else. Debian dropped the client
years ago.

They are therefore staged here out of band, exactly the way
`install/sql/schema/` stages the database dumps: a maintainer puts the files in
place on a connected machine, and the installer consumes what it finds. **The
installer never downloads them.** An air-gapped target gets this directory by
whatever means moves files onto it and installs consoles normally.

## Getting them

```bash
bash tools/vendor-guacamole.sh          # 1.5.5, the pinned version
bash tools/vendor-guacamole.sh 1.3.0    # the documented fallback
```

Expected filenames, for `<version>` = 1.5.5:

    guacamole-1.5.5.war                       ~17 MB   the web application
    guacamole-auth-jdbc-mysql-1.5.5.jar       ~5.7 MB  the JDBC auth extension

**The two versions must match each other.** `guacamole-ext` is a version-locked
API; a 1.5.5 extension in a 1.3.0 web application fails at startup, and the
symptom is a deployed context that 404s every console URL rather than an
obvious error.

## Why the files are not committed

They are release binaries. Committing 23 MB of them pins them into this
repository's history permanently and makes every clone pay for them, including
clones that will never install consoles. What *is* committed is `SHA512SUMS`,
which is the part that actually needs review: it is the pin, and
`tools/vendor-guacamole.sh` and `install/lib/guacamole.sh` both refuse to
proceed on a mismatch. Reproducing the artefacts from a URL plus a reviewed
checksum is not weaker than committing them; it is the same guarantee with the
bytes stored somewhere cheaper.

The trade-off this accepts: a fresh clone cannot install consoles until someone
runs the vendor script once. `install/lib/guacamole.sh` treats that as a
**skip**, not a failure — the install still exits 0 and the appliance still
serves. HTML5 consoles are a feature, not the product, and
`includes/functions.php updateUserToken()` already degrades gracefully when the
console service is absent.

## Licence and attribution

Both artefacts are **Apache Guacamole**, copyright The Apache Software
Foundation, distributed under the **Apache License, Version 2.0**:

    https://www.apache.org/licenses/LICENSE-2.0

They are redistributed here unmodified. Their `LICENSE` and `NOTICE` files
travel inside the archives — `META-INF/` in the `.jar`, the archive root and
`WEB-INF/` in the `.war` — and must not be stripped when the files are copied
onto a target. `install/lib/guacamole.sh` deploys the `.war` and the `.jar`
byte for byte and never repacks them, so the notices arrive intact on every
installed host.

This repository builds nothing from Guacamole source and patches nothing. The
integration is entirely through Guacamole's own database schema and REST API,
which is why no source-level derivative work exists here to attribute.
