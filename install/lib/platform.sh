# shellcheck shell=bash
#
# install/lib/platform.sh — the emulation layer.
#
# Everything here used to arrive only inside the PNETLab ISO. Almost all of it is
# in Ubuntu's archive; what is not is either PHP (unl_wrapper, which now lives in
# this repository) or a small compiled binary that only certain node types need.
#
# What this step gives you: VPCS nodes, and QEMU nodes with a VNC console.
# What it cannot give you, because the binaries are not published anywhere:
#   qemu_wrapper_telnet   QEMU nodes with a *telnet* console
#   docker_wrapper        Docker-backed nodes
#   iol_wrapper           Cisco IOL (whose images are licensed regardless)
# Those three still require the upstream appliance. Everything else does not.

readonly PLATFORM_GROUP='unl'
readonly PLATFORM_GID=32768

# ---------------------------------------------------------------------------
# Docker
# ---------------------------------------------------------------------------
# Container-backed nodes (devices/docker/device_docker.php) shell out to the
# `docker` CLI about thirty times. Until now every one of those calls named
# `-H=tcp://127.0.0.1:4243`, and NOTHING in this installer ever configured that
# endpoint — Docker listens on /var/run/docker.sock out of the box and needs an
# explicit -H on the daemon to listen on 4243 as well. So on a clean install
# every docker command in the tree failed to connect, and Docker nodes could not
# work at all, wrapper or no wrapper.
#
# It was also the single worst grant in the appliance. 4243 is unauthenticated:
# `POST /containers/create` with Binds: ["/:/host"] is a root shell on the host,
# and it is reachable by every local user and by any request the web layer can be
# talked into making. The call sites now use the unix socket, which is
# root:docker 0660 — access by group membership rather than by an open port —
# and none of them use sudo any more.
#
# Two traps, both of which have bitten this project before:
#
#   1. Supplementary groups are resolved when a process starts. Adding www-data
#      to `docker` does nothing for a php-fpm pool that is already running. This
#      function therefore runs BEFORE the confinement drop-in below, whose
#      `systemctl restart php<v>-fpm` is what actually picks the group up. If you
#      reorder these, node consoles will work on a fresh boot and not after an
#      install, which is a miserable thing to debug.
#   2. The php-fpm unit is confined (see install/systemd/php-fpm-pnetlab.conf).
#      A unit that cannot see a path cannot see a socket on it either. /run is
#      not hidden by any of ProtectSystem=true, ProtectKernelTunables or
#      PrivateDevices, and PrivateTmp only moves /tmp and /var/tmp, so the socket
#      is visible — but the drop-in adds After/Wants=docker.service so the daemon
#      is up (and the socket exists) before the pool that talks to it.
step_platform_docker() {
	if ! have docker; then
		note "installing docker.io for container-backed nodes"
		apt_install docker.io
	fi

	if ! have docker; then
		warn "docker is not installed; Docker-backed nodes will not start"
		return 0
	fi
	ok "docker: $(command -v docker)"

	# docker.io creates this; a hand-built daemon may not have.
	if getent group docker >/dev/null; then
		ok "group docker exists"
	else
		run groupadd -r docker
		ok "created group docker"
	fi

	if id -nG "$WEB_USER" 2>/dev/null | tr ' ' '\n' | grep -qx docker; then
		ok "${WEB_USER} is already in the docker group"
	else
		run usermod -aG docker "$WEB_USER"
		ok "${WEB_USER} added to the docker group (takes effect on the php-fpm restart below)"
	fi

	# Being in the docker group is root-equivalent by design: the daemon will
	# happily bind-mount / into a container for anyone who can talk to it. This
	# is not a smaller privilege than the sudo grant it replaces, it is the same
	# privilege named honestly and reachable by one fewer path — the
	# unauthenticated TCP port is gone. Say so rather than let it read as a win.
	note "${WEB_USER} is in the docker group, which is root-equivalent on this host."
	note "         It replaces a sudo grant for /usr/bin/docker and an unauthenticated"
	note "         daemon socket on 127.0.0.1:4243, both of which were worse."

	if have systemctl; then
		if ! systemctl is-enabled --quiet docker.service 2>/dev/null; then
			run_ok systemctl enable docker.service
		fi
		if ! systemctl is-active --quiet docker.service; then
			run_ok systemctl start docker.service
		fi
		if systemctl is-active --quiet docker.service; then
			ok "docker.service is running"
		else
			warn "docker.service is not running; Docker-backed nodes will not start"
		fi
	fi

	if [[ -S /var/run/docker.sock ]]; then
		ok "/var/run/docker.sock present ($(stat -c '%U:%G %a' /var/run/docker.sock))"
	else
		warn "/var/run/docker.sock is missing; every docker call in the web layer
      will fail. Check 'systemctl status docker'."
	fi
}

step_platform() {
	step "Emulation platform"

	# --- emulators and host tooling ------------------------------------
	local pkgs=(
		vpcs dynamips qemu-system-x86 qemu-utils
		bridge-utils uml-utilities net-tools iproute2 psmisc
	)
	note "installing emulators and host tools"
	apt_install "${pkgs[@]}"

	for b in vpcs dynamips qemu-system-x86_64; do
		if have "$b"; then
			ok "$b: $(command -v "$b")"
		else
			warn "$b is not installed; nodes of that type will not start"
		fi
	done

	# --- the paths the application hardcodes ----------------------------
	# devices/qemu/*.php looks for /opt/qemu/bin/qemu-system-<arch> and
	# devices/vpcs/device_vpcs.php for /opt/vpcsu/bin/vpcs. Those are the
	# appliance's layout, not the distribution's, and the application does not
	# make them configurable — so symlink rather than patch.
	run install -d -m 0755 /opt/qemu/bin /opt/vpcsu/bin
	if have qemu-system-x86_64; then
		run ln -sfn "$(command -v qemu-system-x86_64)" /opt/qemu/bin/qemu-system-x86_64
		have qemu-img && run ln -sfn "$(command -v qemu-img)" /opt/qemu/bin/qemu-img
		ok "/opt/qemu/bin wired to the packaged QEMU"
	fi
	if have vpcs; then
		run ln -sfn "$(command -v vpcs)" /opt/vpcsu/bin/vpcs
		ok "/opt/vpcsu/bin/vpcs wired to the packaged VPCS"
		# Stock VPCS 0.5b2 has no -N. The appliance ships an EVE-NG build that
		# adds it. device_vpcs.php probes for it, so this is informational.
		if vpcs -h 2>&1 | grep -qE '^\s*-N\s'; then
			ok "this VPCS supports -N (node names appear in the prompt)"
		else
			note "this VPCS has no -N; node names will not appear in the VPCS prompt"
		fi
	fi

	# --- image directories ----------------------------------------------
	# includes/api_nodes.php scandir()s /opt/unetlab/addons/<type>/ when listing
	# templates. A missing directory is not treated as "no images available" —
	# it raises, and the whole template list endpoint returns 400, which makes
	# the node-add dialog empty. Create them whether or not images are present.
	for _dir in qemu iol dynamips docker; do
		run install -d -m 0755 -o root -g root "/opt/unetlab/addons/${_dir}"
	done
	run install -d -m 0755 -o root -g root /opt/unetlab/addons/iol/bin /opt/unetlab/addons/iol/lib
	ok "addons directories created (qemu, iol, dynamips, docker)"

	# --- the tenant group node start needs ------------------------------
	# unl_wrapper runs useradd -g unl for every node session. Without the group
	# that fails and no node starts.
	if getent group "$PLATFORM_GROUP" >/dev/null; then
		ok "group ${PLATFORM_GROUP} exists"
	else
		run groupadd -g "$PLATFORM_GID" "$PLATFORM_GROUP"
		ok "created group ${PLATFORM_GROUP} (gid ${PLATFORM_GID})"
	fi
	run install -d -m 2775 -o root -g "$PLATFORM_GROUP" /opt/unetlab/users

	# --- wrappers -------------------------------------------------------
	local wsrc="${SRC_DIR}/platform/wrappers"
	if [[ -d "$wsrc" ]]; then
		run install -m 0755 -o root -g root "${wsrc}/unl_wrapper" /opt/unetlab/wrappers/unl_wrapper
		run install -m 0755 -o root -g root "${wsrc}/unl_profile" /opt/unetlab/wrappers/unl_profile
		ok "unl_wrapper and unl_profile deployed from the repository"
	else
		die "platform/wrappers is missing from the source tree"
	fi

	if have nsenter; then
		run ln -sfn "$(command -v nsenter)" /opt/unetlab/wrappers/nsenter
		ok "nsenter wired to util-linux"
	fi

	# --- console wrappers, built from source ----------------------------
	# These used to be the fork's last dependency on the upstream appliance
	# image: compiled binaries published nowhere, without which QEMU telnet
	# consoles, Docker nodes and IOL nodes could not start. They are now
	# reimplemented in platform/wrappers/src and compiled here, so a clean
	# download builds a complete system.
	#
	# Not built: iol_wrapper_telnet, because nothing calls it — the only
	# reference in the tree is commented out. qemu_wrapper and
	# dynamips_wrapper are not built either and are not missing: QEMU's own
	# -vnc is the console listener, dynamips has -T, and neither has a live
	# call site. See platform/wrappers/src/README.md.
	local csrc="${SRC_DIR}/platform/wrappers/src"
	if [[ -d "$csrc" ]]; then
		if have cc || have gcc; then
			if run make -C "$csrc" clean && run make -C "$csrc" all; then
				run make -C "$csrc" install WRAPPERDIR=/opt/unetlab/wrappers
				ok "console wrappers built and installed from source"
			else
				die "the console wrappers failed to build; see the output above"
			fi
		else
			die "no C compiler found: install build-essential, or the console wrappers cannot be built"
		fi
	else
		die "platform/wrappers/src is missing from the source tree"
	fi

	local missing=()
	for w in qemu_wrapper_telnet docker_wrapper iol_wrapper; do
		[[ -x "/opt/unetlab/wrappers/$w" ]] || missing+=("$w")
	done
	if (( ${#missing[@]} )); then
		die "wrappers missing after build: ${missing[*]}"
	fi
	ok "qemu_wrapper_telnet, docker_wrapper and iol_wrapper are in place"

	# --- signed package machinery ---------------------------------------
	# unl_wrapper looks for the applier at ../packages/ relative to itself.
	# This is what replaced running a shell script downloaded from upstream
	# as root; see docs/PACKAGES.md.
	local psrc="${SRC_DIR}/platform/packages"
	if [[ -d "$psrc" ]]; then
		run install -d -m 0755 -o root -g root /opt/unetlab/packages
		run install -m 0644 -o root -g root "${psrc}/PnetPackage.php"        /opt/unetlab/packages/
		run install -m 0644 -o root -g root "${psrc}/PnetPackageApplier.php" /opt/unetlab/packages/
		run install -d -m 0755 -o root -g root /opt/unetlab/data/packages/trusted.d
		run install -d -m 0755 -o root -g root /opt/unetlab/data/Logs/packages
		run install -d -m 0755 -o www-data -g www-data /opt/unetlab/data/packages/incoming
		ok "package applier and trust store installed"
	else
		die "platform/packages is missing from the source tree"
	fi

	# ext-sodium carries the Ed25519 verification. Ubuntu enables it by
	# default, so its absence means someone disabled it deliberately — and
	# without it no package can be verified at all.
	if "php${PHP_VERSION}" -r 'exit(extension_loaded("sodium") ? 0 : 1);' 2>/dev/null; then
		ok "ext-sodium present; package signatures can be verified"
	else
		die "ext-sodium is missing; signed packages cannot be verified without it"
	fi

	# --- Docker, and the socket the web layer talks to it over -----------
	step_platform_docker

	# --- PHP-FPM confinement --------------------------------------------
	# The appliance ran mod_php inside Apache, which is unconfined. Ubuntu's
	# php-fpm unit is not: ProtectSystem=full mounts /etc read-only, so useradd
	# cannot take its lock and NO node can start. ProtectKernelTunables blocks
	# the sysctl write on each new tap, and PrivateDevices hides /dev/kvm and
	# /dev/net/tun. The drop-in relaxes exactly those; read its header before
	# changing it, including why ReadWritePaths=/etc does not work.
	local dropin_src="${SRC_DIR}/install/systemd/php-fpm-pnetlab.conf"
	local dropin_dir="/etc/systemd/system/php${PHP_VERSION}-fpm.service.d"
	if [[ -f "$dropin_src" ]]; then
		run install -d -m 0755 "$dropin_dir"
		run install -m 0644 -o root -g root "$dropin_src" "${dropin_dir}/pnetlab.conf"
		run systemctl daemon-reload
		run systemctl restart "php${PHP_VERSION}-fpm"
		local ps
		ps=$(systemctl show "php${PHP_VERSION}-fpm" -p ProtectSystem --value 2>/dev/null)
		if [[ "$ps" == "full" ]]; then
			warn "ProtectSystem is still 'full'; /etc will be read-only and nodes will not start"
		else
			ok "php${PHP_VERSION}-fpm confinement adjusted (ProtectSystem=${ps:-unset})"
		fi
	else
		warn "systemd drop-in missing: ${dropin_src}"
		warn "      Without it node start fails with 'useradd: cannot lock /etc/passwd'."
	fi

	# --- KVM ------------------------------------------------------------
	if [[ -e /dev/kvm ]]; then
		ok "/dev/kvm present; QEMU nodes will use hardware acceleration"
	else
		warn "/dev/kvm is absent. QEMU will fall back to TCG emulation, which works"
		warn "      but is far slower. On a VM, enable nested virtualisation on the host."
	fi
}
