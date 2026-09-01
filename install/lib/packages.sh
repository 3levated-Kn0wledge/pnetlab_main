# shellcheck shell=bash
#
# install/lib/packages.sh — APT repositories and packages.
#
# Network use: the distro archives and ppa:ondrej/php, and nothing else. See
# docs/OFFLINE-FIRST.md. If the host is air-gapped, pre-stage the packages and
# run with --skip packages.

readonly PHP_PPA='ppa:ondrej/php'

# The package set from docs/REFERENCE-ENVIRONMENT.md, verified on the reference
# host. php8.4-fpm rather than mod_php is deliberate and load-bearing: mod_php
# no longer ships on a current LTS, and the .user.ini migration only means
# anything under FPM.
php_packages() {
	local v="$PHP_VERSION"
	printf '%s\n' \
		"php${v}-fpm" "php${v}-cli" "php${v}-mysql" "php${v}-xml" \
		"php${v}-mbstring" "php${v}-curl" "php${v}-gd" "php${v}-zip" \
		"php${v}-sqlite3" "php${v}-intl" "php${v}-bcmath" "php${v}-yaml"
}

base_packages() {
	# curl is here for the loopback verification step, not for the
	# application: nothing in this installer fetches anything with it.
	printf '%s\n' apache2 mariadb-server mariadb-client rsync ca-certificates curl
}

# Host tooling the web layer shells out to when it drives real nodes. Not
# required to serve the web layer, so it is opt-in: --with-node-tools. Every
# binary here corresponds to an entry in install/sudoers.d/pnetlab.
#
# Deliberately not here, though the policy allowlists them: openvswitch-switch
# (ovs-vsctl), docker and ntpdate. Each is a substantial decision about what the
# appliance is, not a dependency of the web layer, and the sudo policy grants
# them whether or not they are installed. Add them when the node layer lands.
node_tool_packages() {
	printf '%s\n' \
		iproute2 bridge-utils uml-utilities net-tools iptables \
		psmisc procps dmidecode netcat-openbsd dos2unix qemu-utils
}

apt_update_if_needed() {
	local stamp='/var/lib/apt/periodic/update-success-stamp'
	if [[ -n "${APT_UPDATED:-}" ]]; then
		return 0
	fi
	# Refresh if we have never seen a list, or the lists are over a day old.
	if [[ ! -d /var/lib/apt/lists ]] || \
	   [[ -z "$(find /var/lib/apt/lists -maxdepth 1 -name '*Packages*' -mmin -1440 2>/dev/null | head -1)" ]] || \
	   [[ ! -e "$stamp" ]]; then
		run apt-get update
	else
		dim "apt lists are recent; skipping apt-get update"
	fi
	APT_UPDATED=1
}

ppa_present() {
	# add-apt-repository writes either a .list or a deb822 .sources file
	# depending on release; check both rather than guessing.
	grep -rqs 'ondrej' /etc/apt/sources.list.d/ 2>/dev/null
}

install_php_ppa() {
	if ppa_present; then
		dim "${PHP_PPA} already configured"
		return 0
	fi
	info "adding ${PHP_PPA} (Ubuntu ${VERSION_ID:-?} ships PHP 8.3; we pin ${PHP_VERSION})"
	if ! have add-apt-repository; then
		apt_update_if_needed
		run apt-get install -y software-properties-common
	fi
	run add-apt-repository -y "$PHP_PPA"
	# Unconditionally, not via apt_update_if_needed: the lists were almost
	# certainly refreshed a moment ago, and that helper would decide they are
	# recent enough and skip the one refresh that actually matters here.
	run apt-get update
	APT_UPDATED=1
}

# apt_install <pkg>... — installs only what is missing, so re-runs are quick
# and do not trigger service restarts.
apt_install() {
	local -a missing=()
	local p
	for p in "$@"; do
		if ! dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q '^install ok installed'; then
			missing+=("$p")
		fi
	done
	if [[ ${#missing[@]} -eq 0 ]]; then
		dim "already installed: $*"
		return 0
	fi
	apt_update_if_needed
	# Recommends are left on: this is the package set the reference host
	# was built with (docs/REFERENCE-ENVIRONMENT.md), and deviating from it
	# would mean the install path no longer matches what was verified.
	run env DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"
}

step_packages() {
	step "Packages"

	export DEBIAN_FRONTEND=noninteractive

	local -a base php tools
	mapfile -t base < <(base_packages)
	mapfile -t php  < <(php_packages)

	apt_install "${base[@]}"
	install_php_ppa
	apt_install "${php[@]}"

	if [[ "${WITH_NODE_TOOLS:-0}" == 1 ]]; then
		mapfile -t tools < <(node_tool_packages)
		apt_install "${tools[@]}"
	else
		dim "node/emulator host tools not installed (--with-node-tools to add them)"
	fi

	# Fail loudly here rather than three steps later with a confusing error.
	have "php${PHP_VERSION}" || die "php${PHP_VERSION} not on PATH after install"
	[[ -d "/etc/php/${PHP_VERSION}/fpm" ]] || die "php${PHP_VERSION}-fpm did not install"
	ok "packages present (PHP ${PHP_VERSION}, FPM)"
}
