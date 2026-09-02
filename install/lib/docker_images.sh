# shellcheck shell=bash
#
# install/lib/docker_images.sh — seeding Docker images onto an offline host.
#
# THE PROBLEM, STATED PRECISELY
#
# A Docker-backed node starts with `docker create ... <image>`
# (devices/docker/device_docker.php). Docker resolves an image name locally
# first and only then goes to a registry. On an air-gapped host there is no
# registry, so an image that is not already in the local daemon means the node
# start returns 80083, "Failed to create docker container" — and nothing before
# that point warns anybody.
#
# WHAT THE UI DOES, MEASURED
#
# getTemplates() in includes/functions.php decides whether a node type is
# selectable. For docker types it runs `docker images | grep <template>` and
# marks the type DISABLED when there is no match — EXCEPT for the generic
# `docker` template, which is hardcoded `$found = 1`. So on a host with zero
# images the type list is not empty and it is not obviously wrong: "Docker" is
# offered, accepted, added to a lab, and fails at start. That is the gap this
# step closes, and it is why the step exists at install time rather than as a
# note in a README.
#
# THE SHAPE, AND WHY NOT A PACKAGE
#
# docs/PACKAGES.md defines the signed-package mechanism and is the right
# long-term home for shipping a device — it already has a `docker_pull` verb.
# `docker_pull` cannot work here: it is `docker pull <image>`, which needs a
# registry, which is the thing an offline host does not have. Closing that
# properly means a new verb (`install_docker_image`, payload → `docker load`),
# and that is a change to the package format, its applier, its build tool and
# its test suite. It is written up in docs/DOCKER-IMAGES.md as the next step
# rather than half-done here.
#
# What this is instead: `docker save` on a connected machine, `docker load` on
# the appliance, against one directory the installer knows about. No new format,
# no new trust story, nothing to verify that is not already a Docker layer
# digest. It is deliberately the smallest thing that makes an offline install
# able to run a Docker node at all.
#
# NO SIGNATURE CHECK, SAID PLAINLY. A tarball in this directory is loaded
# because root put it there. `docker load` is not a sandbox — it writes layers
# into /var/lib/docker as root — so the directory is root-owned and 0755, and
# the trust model is exactly "root chose this file". That is weaker than a
# signed package and is the reason a package verb is the eventual answer.

# Where staged tarballs live. Under addons/ with the emulator image trees,
# because that is what it is: /opt/unetlab/addons/{qemu,iol,dynamips} already
# hold images for the other three node types, and an operator looking for
# "where do images go" finds all four in one place.
docker_images_dir() { printf '%s/addons/docker' "$BASE_DIR"; }

# `docker load` prints "Loaded image: name:tag" or "Loaded image ID: sha256:…".
# Both are worth echoing; the second means the archive carried no repo tag, so
# the image cannot be named by a template and the operator has to tag it.
_load_one() {
	local f="$1" out rc
	out="$(docker load -i "$f" 2>&1)"; rc=$?
	if [[ $rc -ne 0 ]]; then
		warn "docker load failed for $(basename "$f")"
		printf '      %s\n' "$out" >&2
		return 1
	fi
	local line
	while IFS= read -r line; do
		case "$line" in
			'Loaded image: '*)    ok "${line#Loaded image: } (from $(basename "$f"))" ;;
			'Loaded image ID: '*) warn "$(basename "$f") carries an UNTAGGED image (${line#Loaded image ID: })."
			                      warn "      A template names an image by repository:tag, so tag it:"
			                      warn "      docker tag ${line#Loaded image ID: } <name>:<tag>" ;;
		esac
	done <<< "$out"
	return 0
}

step_docker_images() {
	step "Docker images"

	local dir; dir="$(docker_images_dir)"
	ensure_dir "$dir" root:root 0755

	if ! have docker; then
		skip "docker is not installed; nothing to load into"
		return 0
	fi
	if have systemctl && ! systemctl is-active --quiet docker.service 2>/dev/null; then
		warn "docker.service is not running; skipping the image load"
		return 0
	fi

	# nullglob so an empty directory is an empty list rather than a literal
	# glob, and restored afterwards because the installer runs with it off.
	local had_nullglob=0
	shopt -q nullglob && had_nullglob=1
	shopt -s nullglob
	local archives=("$dir"/*.tar "$dir"/*.tar.gz "$dir"/*.tgz "$dir"/*.tar.xz "$dir"/*.tar.zst)
	[[ $had_nullglob -eq 1 ]] || shopt -u nullglob

	if [[ ${#archives[@]} -eq 0 ]]; then
		# Not a warning. An install with no Docker nodes is a normal install,
		# and this is the normal state of a fresh one.
		info "no image archives in ${dir}"
		info "  Docker-backed nodes need an image in the local daemon; there is no"
		info "  registry to fall back on offline. To stage one, on a machine that"
		info "  does have a registry:"
		info "      docker pull alpine:3.20"
		info "      docker save alpine:3.20 | gzip > alpine-3.20.tar.gz"
		info "  copy it into ${dir} and re-run:"
		info "      sudo install/install.sh --only docker-images"
		info "  tools/docker-images.sh does both halves. See docs/DOCKER-IMAGES.md."
		return 0
	fi

	note "loading ${#archives[@]} image archive(s) from ${dir}"
	local f failed=0
	for f in "${archives[@]}"; do
		_load_one "$f" || failed=$((failed + 1))
	done

	if [[ $failed -gt 0 ]]; then
		warn "${failed} archive(s) failed to load; the images they carry are not available"
	fi

	local n
	n="$(docker image ls -q 2>/dev/null | wc -l)"
	ok "${n} image(s) now in the local daemon"

	# The name is the contract. A docker template's `image:` field is matched
	# against `docker images` output by getTemplates(), so an image loaded under
	# a name no template mentions is invisible in the UI even though it is on
	# the box. Print the list so that mismatch is visible here rather than as a
	# node type that stays greyed out.
	info "  templates match on repository name; what is loaded now is:"
	docker image ls --format '    {{.Repository}}:{{.Tag}}' 2>/dev/null | sort | head -40
}
