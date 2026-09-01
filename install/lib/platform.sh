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

	local missing=()
	for w in qemu_wrapper_telnet docker_wrapper iol_wrapper iol_wrapper_telnet; do
		[[ -e "/opt/unetlab/wrappers/$w" ]] || missing+=("$w")
	done
	if (( ${#missing[@]} )); then
		note "not present: ${missing[*]}"
		note "         These are compiled binaries that ship only in the upstream"
		note "         appliance. Without them: QEMU nodes with a *telnet* console,"
		note "         Docker-backed nodes and IOL nodes will not start. VPCS nodes"
		note "         and QEMU nodes with a VNC console are unaffected."
	fi

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
