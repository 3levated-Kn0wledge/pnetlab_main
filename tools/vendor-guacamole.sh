#!/usr/bin/env bash
#
# Stage the two Apache Guacamole binary artefacts the installer needs.
#
#     bash tools/vendor-guacamole.sh              # the pinned version
#     bash tools/vendor-guacamole.sh 1.3.0        # the documented fallback
#
# Why this exists as a separate script rather than a step in install.sh
# ---------------------------------------------------------------------
# Guacamole's web application is not in any Debian or Ubuntu archive — Debian
# dropped the client years ago and packages only guacamole-server. The .war and
# the JDBC extension therefore cannot come from apt, and docs/OFFLINE-FIRST.md
# says the installer does not reach the network for anything the distro
# archives do not provide.
#
# So this is a MAINTAINER action, run once on a connected host. It is the same
# arrangement install/sql/schema/ already uses for the database dumps: a
# pre-staged artefact directory the installer consumes and never fills. An
# air-gapped target gets the files by whatever means moves files onto it —
# rsync, a USB stick, a mirror — and installs consoles fine.
#
# Trust
# -----
# Apache publishes a .sha256 beside each artefact (and a .asc; verifying that
# needs the project KEYS file and is worth doing later). A checksum fetched
# from the same host as the file it describes proves only that the download was
# not truncated, so it is not the trust anchor. The anchor is
# install/vendor/guacamole/SHA512SUMS, which is committed to this repository
# and reviewed like code. Both are checked, and a mismatch against either is
# fatal — this script never overwrites a staged file with something that does
# not verify. Note the asymmetry is deliberate: Apache publishes SHA-256, we
# pin SHA-512 that we computed ourselves, so the two digests are independent.
#
# Licence
# -------
# Both artefacts are Apache License 2.0, (c) The Apache Software Foundation.
# The LICENSE and NOTICE files travel inside them (META-INF/ in the .jar,
# WEB-INF/ and the archive root in the .war) and must not be stripped. See
# install/vendor/guacamole/README.md for the attribution this repository makes.
#
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-1.5.5}"
DEST="${GUACAMOLE_VENDOR_DIR:-${ROOT}/install/vendor/guacamole}"
MIRROR="${GUACAMOLE_MIRROR:-https://archive.apache.org/dist/guacamole}"
MANIFEST="${DEST}/SHA512SUMS"

die()  { printf '\033[31mFATAL: %s\033[0m\n' "$*" >&2; exit 1; }
info() { printf '  %s\n' "$*"; }
ok()   { printf '  \033[32mok\033[0m   %s\n' "$*"; }

case "$VERSION" in
	[0-9]*.[0-9]*.[0-9]*) ;;
	*) die "not a Guacamole version: ${VERSION}" ;;
esac

command -v curl >/dev/null      || die "curl is required"
command -v sha512sum >/dev/null || die "sha512sum is required"
command -v sha256sum >/dev/null || die "sha256sum is required"
command -v tar >/dev/null       || die "tar is required"

WAR="guacamole-${VERSION}.war"
TARBALL="guacamole-auth-jdbc-${VERSION}.tar.gz"
JAR="guacamole-auth-jdbc-mysql-${VERSION}.jar"

mkdir -p "$DEST"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# expected_sum <filename> — the committed hash for this artefact, or empty if
# the manifest does not carry this version.
expected_sum() {
	[[ -f "$MANIFEST" ]] || return 0
	awk -v f="$1" '$2 == f || $2 == "*" f { print $1; exit }' "$MANIFEST"
}

# verify_pin <file on disk> <name it is known by in SHA512SUMS>
# The committed manifest is the authority. An artefact the manifest does not
# carry is refused rather than trusted: staging an unreviewed binary is exactly
# the thing this directory exists to prevent.
verify_pin() {
	local path="$1" name="$2" actual expected
	actual="$(sha512sum "$path" | awk '{print $1}')"
	expected="$(expected_sum "$name")"

	if [[ -z "$expected" ]]; then
		printf '\n\033[33m%s is not listed in %s.\033[0m\n' "$name" "$MANIFEST"
		printf 'Review the artefact, add this line, and commit it:\n\n'
		printf '  %s  %s\n\n' "$actual" "$name"
		die "refusing to stage an artefact that is not pinned"
	fi
	if [[ "${actual,,}" != "${expected,,}" ]]; then
		die "checksum mismatch against the committed SHA512SUMS for ${name}.
    The artefact is not the one this repository was reviewed against. Do NOT
    edit the manifest to make this pass; find out why the bytes changed.
    manifest  ${expected}
    got       ${actual}"
	fi
	ok "${name} matches the committed SHA512SUMS"
}

# fetch_and_verify <remote name>
# Downloads into $TMP and verifies it twice. It deliberately does NOT place the
# file: the war is staged, the tarball is only a container we extract from, and
# an earlier version of this that took a destination happily "installed" the
# tarball over itself.
fetch_and_verify() {
	local name="$1" url="${MIRROR}/${VERSION}/binary/$1"
	local actual published

	info "fetching ${url}"
	curl -fsSL --proto '=https' --tlsv1.2 -o "${TMP}/${name}" "$url" ||
		die "download failed: ${url}"
	curl -fsSL --proto '=https' --tlsv1.2 -o "${TMP}/${name}.sha256" "${url}.sha256" ||
		die "download failed: ${url}.sha256
    Apache publishes .sha256 and .asc beside each artefact — NOT .sha512,
    which is what this script looked for first and is why it is worth
    stating here."

	actual="$(sha256sum "${TMP}/${name}" | awk '{print $1}')"
	# Apache's checksum files have used both "<hash>  <name>" and a form whose
	# filename does not match ours. Take the first 64-hex token, ignore the rest.
	published="$(tr -d '\n ' < "${TMP}/${name}.sha256" | grep -oE '[0-9a-fA-F]{64}' | head -1)"
	[[ -n "$published" ]] || die "could not parse ${name}.sha256"

	if [[ "${actual,,}" != "${published,,}" ]]; then
		die "checksum mismatch against the published .sha256 for ${name}
    expected  ${published}
    got       ${actual}"
	fi
	ok "${name} matches the checksum Apache publishes"

	verify_pin "${TMP}/${name}" "$name"
}

printf '\nStaging Apache Guacamole %s into %s\n\n' "$VERSION" "$DEST"

fetch_and_verify "$WAR"
install -m 0644 "${TMP}/${WAR}" "${DEST}/${WAR}"
info "wrote ${DEST}/${WAR}"

# The extension ships inside a tarball with postgresql/ and sqlserver/
# siblings. Only the mysql jar is wanted: two JDBC auth providers loaded at
# once is a startup failure, and only "mysql" is the data source identifier the
# fork's console URLs name.
fetch_and_verify "$TARBALL"
tar -xzf "${TMP}/${TARBALL}" -C "$TMP" "guacamole-auth-jdbc-${VERSION}/mysql/${JAR}" ||
	die "${TARBALL} does not contain mysql/${JAR}"
verify_pin "${TMP}/guacamole-auth-jdbc-${VERSION}/mysql/${JAR}" "$JAR"
install -m 0644 "${TMP}/guacamole-auth-jdbc-${VERSION}/mysql/${JAR}" "${DEST}/${JAR}"
info "wrote ${DEST}/${JAR}"

# The tarball also carries the schema, which is how the claim in
# install/sql/schema/guacdb.sql — that it is byte-identical to what Guacamole
# ships — stays checkable rather than remembered.
if tar -xzf "${TMP}/${TARBALL}" -C "$TMP" \
	"guacamole-auth-jdbc-${VERSION}/mysql/schema/001-create-schema.sql" 2>/dev/null; then
	install -m 0644 "${TMP}/guacamole-auth-jdbc-${VERSION}/mysql/schema/001-create-schema.sql" \
		"${DEST}/001-create-schema-${VERSION}.sql"
	info "wrote ${DEST}/001-create-schema-${VERSION}.sql (reference only; the"
	info "      installer imports install/sql/schema/guacdb.sql, not this)"
fi

printf '\n'
ok "staged. install/lib/guacamole.sh will find these on the next install run:"
printf '       sudo install/install.sh --only guacamole --guacamole-version %s\n\n' "$VERSION"
