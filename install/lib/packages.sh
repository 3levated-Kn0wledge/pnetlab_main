# shellcheck shell=bash
#
# install/lib/packages.sh — APT repositories and packages.
#
# Network use: the distro archives and ppa:ondrej/php, and nothing else. See
# docs/OFFLINE-FIRST.md. If the host is air-gapped, pre-stage the packages and
# run with --skip packages.

readonly PHP_PPA='ppa:ondrej/php'

# The PHP version the fork is developed and verified against, and the oldest one
# the code will run on.
#
# PHP_VERSION is a *preference*, not a pin. 24.04 carries 8.3 and the PPA
# carries 8.4, so on the supported platform the preference is always satisfiable
# and nothing below changes what gets installed. On a release where it is not —
# 26.04 ships 8.5 in the archive and ppa:ondrej/php has no pocket for it — the
# resolution below picks the newest FPM the host can actually install and says
# so, rather than adding a repository that 404s and dying in the middle of apt.
#
# The floor is 8.2 because store/ is Laravel 10, whose own floor is 8.1, and the
# legacy tree was moved to 8.x idioms wholesale (see the php8.4 commits). Below
# 8.2 nothing here has ever been linted, so refuse rather than half-work.
readonly PHP_MIN_VERSION='8.2'

# The package set from docs/REFERENCE-ENVIRONMENT.md, verified on the reference
# host. php<v>-fpm rather than mod_php is deliberate and load-bearing: mod_php
# no longer ships on a current LTS, and the .user.ini migration only means
# anything under FPM.
#
# Every name is built from PHP_VERSION, which php_resolve_version() has settled
# by the time this is expanded. The names are the same shape in the archive and
# in the PPA — Ubuntu's php-yaml, for instance, is a thin package whose real
# payload is php<default>-yaml — so nothing here is PPA-specific.
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
	# composer and unzip are needed by the store step, which is no longer optional
	printf '%s\n' apache2 mariadb-server mariadb-client rsync ca-certificates curl composer unzip
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

# version_ge <a> <b> — true when dotted version a >= b. sort -V, so 8.10 > 8.9.
version_ge() {
	[[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -1)" == "$2" ]]
}

# dpkg_installed <pkg> — is this package installed (not merely known)?
dpkg_installed() {
	dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q '^install ok installed'
}

# apt_candidate <pkg> — print the version apt would install, or fail.
# apt-cache policy exits 0 for a package it has never heard of, printing
# nothing, so the emptiness of the output is the only usable signal.
apt_candidate() {
	local v
	v="$(apt-cache policy "$1" 2>/dev/null | sed -n 's/^ *Candidate: //p')"
	[[ -n "$v" && "$v" != '(none)' ]] || return 1
	printf '%s\n' "$v"
}

# apt_available <pkg> — installed, or installable from the configured sources.
apt_available() {
	dpkg_installed "$1" || apt_candidate "$1" >/dev/null
}

# The newest phpX.Y-fpm this host could install, at or above the floor.
php_newest_available() {
	local v
	while read -r v; do
		[[ -n "$v" ]] || continue
		version_ge "$v" "$PHP_MIN_VERSION" || continue
		apt_candidate "php${v}-fpm" >/dev/null || continue
		printf '%s\n' "$v"
		return 0
	done < <(apt-cache pkgnames php 2>/dev/null |
		sed -n 's/^php\([0-9][0-9]*\.[0-9][0-9]*\)-fpm$/\1/p' | sort -Vr -u)
	return 1
}

php_version_floor_check() {
	version_ge "$1" "$PHP_MIN_VERSION" ||
		die "PHP ${1} is below the ${PHP_MIN_VERSION} floor this tree requires.
    store/ is Laravel 10 and the legacy tree was moved to 8.x idioms wholesale;
    nothing here has been linted below ${PHP_MIN_VERSION}. Pass --php-version
    with a version the host can install, or install a newer PHP first."
}

# Used when the packages step is NOT running (--only apache, --skip packages,
# --only verify). In that case nothing is going to make the preferred version
# exist, so follow the host instead of asserting a version that is not there and
# then reporting a dead FPM socket for it. When the packages step DOES run it
# resolves the version properly, from apt, and this is never consulted.
php_version_from_installed() {
	[[ -d "/etc/php/${PHP_VERSION}/fpm" ]] && return 0

	local d v newest=''
	for d in /etc/php/*/fpm; do
		[[ -d "$d" ]] || continue
		v="${d#/etc/php/}"; v="${v%/fpm}"
		[[ "$v" =~ ^[0-9]+\.[0-9]+$ ]] || continue
		if [[ -z "$newest" ]] || version_ge "$v" "$newest"; then newest="$v"; fi
	done
	[[ -n "$newest" ]] || return 0

	warn "php${PHP_VERSION}-fpm is not installed on this host, and the packages
             step is not running. Using php${newest}, which is. Pass
             --php-version to override, or run the packages step to install
             php${PHP_VERSION}."
	PHP_VERSION="$newest"
	php_version_floor_check "$PHP_VERSION"
}

ppa_present() {
	# add-apt-repository writes either a .list or a deb822 .sources file
	# depending on release; check both rather than guessing.
	grep -rqs 'ondrej' /etc/apt/sources.list.d/ 2>/dev/null
}

# Does ppa:ondrej/php publish for the release we are running on?
#
# A PPA has one pocket per Ubuntu series. add-apt-repository does not check:
# it writes the sources entry for whatever codename this host is, and the cost
# lands on the next `apt-get update`, which 404s on the Release file and then
# makes every subsequent apt call in the install fail. That is a wall of apt
# output for a fact we can establish with one HEAD request.
#
# Only a definite 404 counts. No curl, a proxy, a transient failure — all
# "unknown", and unknown must behave exactly as this function did before it
# existed: add the PPA. This probe exists to produce a better message on a
# release the PPA has not built for, never to gate the install.
ppa_publishes_for_release() {
	local codename="${UBUNTU_CODENAME:-}"
	[[ -n "$codename" ]] || return 0
	have curl || return 0

	local code
	code="$(curl -sI -o /dev/null -w '%{http_code}' --max-time 10 \
		"https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${codename}/Release" \
		2>/dev/null)" || return 0
	[[ "$code" == 404 ]] && return 1
	return 0
}

# Returns non-zero if the PPA is not usable on this host. The caller falls back
# to what the distribution archive carries rather than failing the install.
install_php_ppa() {
	if ppa_present; then
		dim "${PHP_PPA} already configured"
		return 0
	fi

	if ! ppa_publishes_for_release; then
		warn "${PHP_PPA} has no packages for Ubuntu ${VERSION_ID:-?}
             (${UBUNTU_CODENAME:-unknown}); not adding it. Adding it anyway
             would make every later apt call fail on a missing Release file."
		return 1
	fi

	info "adding ${PHP_PPA} (this host's archive does not carry php${PHP_VERSION})"
	if ! have add-apt-repository; then
		apt_update_if_needed
		run apt-get install -y software-properties-common
	fi
	if ! run_ok add-apt-repository -y "$PHP_PPA"; then
		warn "add-apt-repository ${PHP_PPA} failed; continuing with what the
             distribution archive carries."
		return 1
	fi
	# Unconditionally, not via apt_update_if_needed: the lists were almost
	# certainly refreshed a moment ago, and that helper would decide they are
	# recent enough and skip the one refresh that actually matters here.
	#
	# If this fails the sources entry has already been written, and leaving it in
	# place breaks every apt call for the rest of the install — so name the file
	# to remove rather than letting the next step fail for an unrelated-looking
	# reason.
	if ! run_ok apt-get update; then
		die "apt-get update failed after adding ${PHP_PPA}.
    The sources entry is written and every later apt call will fail until it is
    gone. Remove it (look for 'ondrej' under /etc/apt/sources.list.d/), run
    'apt-get update', and re-run this installer."
	fi
	APT_UPDATED=1
}

# Decide which PHP the rest of the install will talk about: the fpm socket, the
# systemd unit, its drop-in directory, the a2enconf name and every verify check
# all derive from PHP_VERSION.
#
# The order is a preference, then the PPA, then whatever the host has. On 24.04
# the first two steps are exactly what this installer has always done: noble has
# no php8.4, the PPA does, so the PPA is added and PHP_VERSION stays 8.4.
php_resolve_version() {
	php_version_floor_check "$PHP_VERSION"
	apt_update_if_needed

	if apt_available "php${PHP_VERSION}-fpm"; then
		dim "php${PHP_VERSION}-fpm is available from the configured sources"
		return 0
	fi

	if install_php_ppa && apt_available "php${PHP_VERSION}-fpm"; then
		return 0
	fi

	local newest
	if ! newest="$(php_newest_available)"; then
		die "no phpX.Y-fpm at or above ${PHP_MIN_VERSION} is installable on this
    host. php${PHP_VERSION}-fpm is not in the archive and ${PHP_PPA} did not
    provide it either. Check 'apt-cache policy php${PHP_VERSION}-fpm' and
    'apt-cache pkgnames php'."
	fi

	if [[ "${PHP_VERSION_EXPLICIT:-0}" == 1 ]]; then
		die "php${PHP_VERSION}-fpm cannot be installed on this host, and it was
    asked for explicitly. The newest installable version is ${newest}; drop
    --php-version to use it, or make php${PHP_VERSION} available first."
	fi

	warn "php${PHP_VERSION}-fpm cannot be installed on this host. Falling back to
             php${newest}, the newest version this host's archives carry.
             ${PHP_VERSION} is what this fork is verified on; ${newest} is not.
             See docs/PLATFORM-SUPPORT.md."
	PHP_VERSION="$newest"
}

# apt_install <pkg>... — installs only what is missing, so re-runs are quick
# and do not trigger service restarts.
apt_install() {
	local -a missing=()
	local p
	for p in "$@"; do
		if ! dpkg_installed "$p"; then
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

	apt_install "${base[@]}"

	# After the base set, because this needs curl and current apt lists, and
	# before php_packages is expanded, because every name in it is built from
	# PHP_VERSION.
	php_resolve_version
	mapfile -t php < <(php_packages)

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
