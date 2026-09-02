#!/usr/bin/env bash
#
# Stage Docker images for an offline PNETLab host.
#
#   tools/docker-images.sh save  alpine:3.20 nginx:1.27   # on a CONNECTED box
#   tools/docker-images.sh load                           # on the APPLIANCE
#   tools/docker-images.sh list                           # what is staged
#
# Why this exists: a Docker-backed node starts with `docker create <image>`,
# which resolves locally and then falls back to a registry. An air-gapped
# appliance has no registry, so the image has to already be in its daemon.
# `docker save` on one machine and `docker load` on the other is the whole
# mechanism; this script is that pair with the directory and the naming fixed
# so the installer can find the result.
#
# `load` here does the same thing as `sudo install/install.sh --only
# docker-images`, which is what runs during a normal install. This script is
# for the operator staging images afterwards, and for the `save` half, which
# happens on a different machine entirely.
#
# See docs/DOCKER-IMAGES.md.

set -uo pipefail

DIR="${PNET_DOCKER_IMAGE_DIR:-/opt/unetlab/addons/docker}"

usage() {
	sed -n '3,22p' "$0" | sed 's/^# \{0,1\}//'
	exit "${1:-0}"
}

have() { command -v "$1" >/dev/null 2>&1; }
have docker || { echo "error: docker is not on PATH" >&2; exit 127; }

# One file per image, named after the image, so `list` and the installer's
# output can be read against a template's `image:` field without a lookup.
# Slashes become double underscores because a repository name can carry them
# (library/alpine) and a filename cannot.
archive_name() {
	local ref="$1"
	printf '%s.tar.gz' "$(printf '%s' "$ref" | tr '/' '~' | tr ':' '-')"
}

cmd_save() {
	[[ $# -gt 0 ]] || { echo "error: name at least one image" >&2; usage 2; }
	mkdir -p "$DIR" || exit 1
	local ref out rc=0
	for ref in "$@"; do
		# Pull first. Saving an image that is not present fails with a message
		# about the reference, which reads like a typo rather than "you have
		# not pulled it".
		if ! docker image inspect "$ref" >/dev/null 2>&1; then
			echo "pulling $ref"
			docker pull "$ref" || { echo "error: cannot pull $ref" >&2; rc=1; continue; }
		fi
		out="${DIR}/$(archive_name "$ref")"
		echo "saving $ref -> $out"
		# gzip because these are large and the appliance is usually reached
		# over something slow. docker load reads compressed archives directly.
		if docker save "$ref" | gzip > "$out"; then
			printf '  %s (%s)\n' "$(basename "$out")" "$(du -h "$out" | cut -f1)"
		else
			echo "error: docker save failed for $ref" >&2
			rm -f "$out"
			rc=1
		fi
	done
	echo
	echo "Copy ${DIR} to the appliance and run:"
	echo "    sudo install/install.sh --only docker-images"
	return $rc
}

cmd_load() {
	[[ -d "$DIR" ]] || { echo "nothing staged: $DIR does not exist" >&2; exit 1; }
	shopt -s nullglob
	local archives=("$DIR"/*.tar "$DIR"/*.tar.gz "$DIR"/*.tgz "$DIR"/*.tar.xz "$DIR"/*.tar.zst)
	if [[ ${#archives[@]} -eq 0 ]]; then
		echo "nothing staged in $DIR"
		exit 1
	fi
	local f rc=0
	for f in "${archives[@]}"; do
		echo "loading $(basename "$f")"
		docker load -i "$f" | sed 's/^/  /' || rc=1
	done
	echo
	echo "In the local daemon now:"
	docker image ls --format '  {{.Repository}}:{{.Tag}}  {{.Size}}' | sort
	echo
	# The name is the contract: getTemplates() in includes/functions.php runs
	# `docker images | grep <template name>` to decide whether a docker node
	# type is selectable, so an image whose repository does not contain the
	# template's name leaves that type greyed out however well it loaded.
	echo "A docker template is selectable only when its name matches a"
	echo "repository above. templates/*.yml with 'type: docker' are:"
	local t
	for t in templates/*.yml "$(dirname "$0")"/../templates/*.yml; do
		[[ -f "$t" ]] || continue
		grep -lq '^type: docker' "$t" 2>/dev/null && printf '  %s\n' "$(basename "$t" .yml)"
	done | sort -u
	return $rc
}

cmd_list() {
	if [[ ! -d "$DIR" ]]; then echo "$DIR does not exist"; exit 0; fi
	echo "staged in $DIR:"
	ls -lh "$DIR" 2>/dev/null | tail -n +2 | sed 's/^/  /'
	echo
	echo "in the local daemon:"
	docker image ls --format '  {{.Repository}}:{{.Tag}}  {{.Size}}' 2>/dev/null | sort
}

case "${1:-}" in
	save) shift; cmd_save "$@" ;;
	load) shift; cmd_load ;;
	list) shift; cmd_list ;;
	-h|--help|'') usage 0 ;;
	*) echo "error: unknown command '$1'" >&2; usage 2 ;;
esac
