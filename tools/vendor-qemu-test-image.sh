#!/usr/bin/env bash
#
# Stage the bootable QEMU image tools/integration/node-types.sh needs.
#
#     sudo bash tools/vendor-qemu-test-image.sh
#
# Why this exists
# ---------------
# node-types.sh drives a real QEMU node with a telnet console. Without a
# bootable disk under /opt/unetlab/addons/qemu/ there is nothing to boot, and
# the suite skips that whole section — which is honest, but it means the
# qemu_wrapper_telnet path is unproven on that host.
#
# The image is NOT in this repository and should not be: it is 21 MB of binary
# that is reproducible from a URL and a checksum, which is the same argument
# install/vendor/guacamole/.gitignore makes for the Guacamole artefacts.
#
# CirrOS is used because it is a public test image with no licence question,
# it boots in a couple of seconds, and it is 21 MB rather than a gigabyte.
# Nothing about the suite is CirrOS-specific: any bootable qcow2 laid out the
# way WHERE IT GOES describes will do.
#
# Trust
# -----
# Two independent digests, exactly as tools/vendor-guacamole.sh argues:
#
#   - cirros publishes MD5SUMS beside the image. Fetched from the same host as
#     the bytes it describes, so it proves the download was not truncated and
#     nothing more. It is a transport check, not the trust anchor.
#   - PINNED_SHA512 below is the anchor. It is committed to this repository and
#     reviewed like code, so a changed image has to get past a human.
#
# The hash is inline rather than in a SHA512SUMS file because there is one
# artefact at one version; a manifest here would be a file containing one line.
# If this ever grows a second image, move it to a manifest and follow
# install/vendor/guacamole/SHA512SUMS.
#
# Never edit PINNED_SHA512 to make a mismatch go away. A changed digest for a
# released CirrOS image means the bytes changed, and that needs explaining.
#
# WHERE IT GOES, AND WHY THE NAME MATTERS
# ---------------------------------------
# node-types.sh takes the FIRST directory under /opt/unetlab/addons/qemu/,
# derives the template name from the part before the first '-', and passes the
# directory name as the image. devices/qemu/device_qemu.php then matches disks
# in that directory against /^hd[a-z]+\.qcow2$/ to build its -drive flags.
#
# So the layout is load-bearing in two places at once:
#
#     /opt/unetlab/addons/qemu/linux-cirros/hda.qcow2
#                              ^^^^^        ^^^^
#                              |            the disk pattern device_qemu matches
#                              the template name (templates/linux.yml)
#
# Rename either half and the suite either skips or starts a node with no disk.

set -Eeuo pipefail

VERSION="${1:-0.6.2}"
ARCH="${CIRROS_ARCH:-x86_64}"
MIRROR="${CIRROS_MIRROR:-https://download.cirros-cloud.net}"
DEST_DIR="${QEMU_TEST_IMAGE_DIR:-/opt/unetlab/addons/qemu/linux-cirros}"
DEST="${DEST_DIR}/hda.qcow2"

# The anchor. CirrOS 0.6.2, x86_64, cirros-0.6.2-x86_64-disk.img.
PINNED_VERSION='0.6.2'
PINNED_SHA512='1103b92ce8ad966e41235a4de260deb791ff571670c0342666c8582fbb9caefe6af07ebb11d34f44f8414b609b29c1bdf1d72ffa6faa39c88e8721d09847952b'

die()  { printf '\033[31mFATAL: %s\033[0m\n' "$*" >&2; exit 1; }
info() { printf '  %s\n' "$*"; }
ok()   { printf '  \033[32mok\033[0m   %s\n' "$*"; }

case "$VERSION" in
	[0-9]*.[0-9]*.[0-9]*) ;;
	*) die "not a CirrOS version: ${VERSION}" ;;
esac

[[ "$VERSION" == "$PINNED_VERSION" ]] || die \
"this script pins CirrOS ${PINNED_VERSION}, and you asked for ${VERSION}.

    There is one committed hash and it describes ${PINNED_VERSION} only.
    Staging an unpinned version would be staging something nobody reviewed.
    To move the pin: download the image, check it against what cirros
    publishes, and replace PINNED_VERSION and PINNED_SHA512 in this file in a
    commit that says why."

command -v curl >/dev/null      || die "curl is required"
command -v sha512sum >/dev/null || die "sha512sum is required"
command -v md5sum >/dev/null    || die "md5sum is required"

[[ ${EUID} -eq 0 ]] || die "must run as root: ${DEST_DIR} is under /opt/unetlab
    try: sudo bash tools/vendor-qemu-test-image.sh"

NAME="cirros-${VERSION}-${ARCH}-disk.img"
URL="${MIRROR}/${VERSION}/${NAME}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

printf '\nStaging CirrOS %s (%s) into %s\n\n' "$VERSION" "$ARCH" "$DEST"

if [[ -f "$DEST" ]]; then
	if [[ "$(sha512sum "$DEST" | awk '{print $1}')" == "$PINNED_SHA512" ]]; then
		ok "already staged and matches the pin; nothing to do"
		exit 0
	fi
	die "${DEST} exists and does NOT match the pin.
    Something else put it there, or it is a different image. Move it aside
    and re-run rather than having this script overwrite it."
fi

info "fetching ${URL}"
curl -fsSL --proto '=https' --tlsv1.2 -o "${TMP}/${NAME}" "$URL" ||
	die "download failed: ${URL}"

# --- transport check: what cirros publishes beside the file ----------------
# cirros publishes MD5SUMS for 0.6.2 and NOT SHA256SUMS/SHA1SUMS -- checked,
# and worth stating so the next person does not go looking for a stronger one
# and conclude the mirror is broken. MD5 is fine for what this is used for:
# detecting a truncated or corrupted download. It is not the trust anchor.
if curl -fsSL --proto '=https' --tlsv1.2 -o "${TMP}/MD5SUMS" "${MIRROR}/${VERSION}/MD5SUMS" 2>/dev/null; then
	published="$(awk -v f="$NAME" '$2 == f || $2 == "*" f { print $1; exit }' "${TMP}/MD5SUMS")"
	if [[ -n "$published" ]]; then
		actual="$(md5sum "${TMP}/${NAME}" | awk '{print $1}')"
		[[ "${actual,,}" == "${published,,}" ]] || die \
"md5 mismatch against the MD5SUMS cirros publishes for ${NAME}
    expected  ${published}
    got       ${actual}"
		ok "${NAME} matches the md5 cirros publishes"
	else
		info "MD5SUMS does not list ${NAME}; relying on the committed pin alone"
	fi
else
	info "could not fetch MD5SUMS; relying on the committed pin alone"
fi

# --- the anchor: the hash committed to this repository ---------------------
actual="$(sha512sum "${TMP}/${NAME}" | awk '{print $1}')"
[[ "${actual,,}" == "${PINNED_SHA512,,}" ]] || die \
"${NAME} does not match the hash pinned in this script.
    pinned  ${PINNED_SHA512}
    on disk ${actual}
    Do not edit the pin to make this pass."
ok "${NAME} matches the committed pin"

install -d -m 0755 -o root -g root "$DEST_DIR"
install -m 0644 -o root -g root "${TMP}/${NAME}" "$DEST"
ok "staged ${DEST}"

# A last sanity check that this is the format device_qemu will hand to
# qemu-img: a raw image renamed to .qcow2 boots, but the linked-clone the node
# start makes would not be a clone of anything.
if command -v qemu-img >/dev/null; then
	# `qemu-img info --output=json` nests a second "format" for the protocol
	# layer ("file"), and a naive grep picks that one up -- which is what the
	# first version of this check did, and it reported a perfectly good qcow2
	# as format 'file'. The plain output has exactly one "file format:" line.
	fmt="$(qemu-img info "$DEST" 2>/dev/null | sed -n 's/^file format: *//p' | head -1)"
	[[ "$fmt" == "qcow2" ]] || die "staged image reports format '${fmt:-unknown}', not qcow2"
	ok "qemu-img agrees it is qcow2"
fi

printf '\n  %s\n\n' "done. tools/integration/node-types.sh will now exercise the QEMU telnet console."
