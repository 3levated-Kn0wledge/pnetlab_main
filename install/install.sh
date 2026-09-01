#!/usr/bin/env bash
#
# PNETLab — install the web layer on a clean Ubuntu 24.04 server.
#
#   sudo ./install/install.sh
#
# What this is
# ------------
# The platform layer of PNETLab shipped inside an ISO and was never published.
# This is the fork's own replacement for it, covering what the reference host in
# docs/REFERENCE-ENVIRONMENT.md was built by hand to prove: packages, the
# /opt/unetlab layout, the web layer, Apache under PHP-FPM, the two databases,
# the offline seed, and the sudo policy.
#
# What it is not
# --------------
# It does not build an appliance. There are no emulators, no wrappers and no
# vendor images here — those are separate, later work, and this script does not
# pretend to do them. HTML5 consoles ARE installed, but only if the Guacamole
# artefacts have been staged first; see install/vendor/guacamole/README.md.
#
# It is idempotent: every step checks the state of the host before changing it,
# and a second run should be quiet. It has NOT been run end to end on a fresh
# machine; see install/README.md, which says so plainly.
#
set -Eeuo pipefail

SRC_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SRC_DIR
readonly LIB_DIR="${SRC_DIR}/install/lib"

# --- defaults --------------------------------------------------------------
PHP_VERSION="${PHP_VERSION:-8.4}"
SERVER_NAME=''
SCHEMA_DIR="${SRC_DIR}/install/sql/schema"
GUACAMOLE_DIR="${SRC_DIR}/install/vendor/guacamole"
PRUNE=0
RESET_ADMIN=0
WITH_NODE_TOOLS=0
WITHOUT_STORE_VENDOR=0
STRIP_SUDOERS_GRANTS=0
ONLY=''
SKIP=''

# guacamole sits after apache because it needs the vhost and the proxy modules
# in place, and after database because guacdb must already have its schema.
readonly ALL_STEPS='preflight packages deploy sudoers database apache platform guacamole store verify'

usage() {
	cat <<'EOF'
Usage: sudo install/install.sh [options]

Steps, in order:
  preflight   sanity-check the host and the source tree
  packages    apt: apache2, mariadb, PHP 8.4 from ppa:ondrej/php (FPM)
  deploy      create /opt/unetlab/... and rsync the web layer into html/
  sudoers     validate and install /etc/sudoers.d/pnetlab; remove the
              upstream blanket grant
  database    create pnetlab_db and guacdb, their users, import a schema if
              one is available, and apply the offline seed
  apache      enable the modules, render and enable the vhost, restart
  platform    emulators, the /opt layout the code hardcodes, the unl group
  guacamole   HTML5 consoles: guacd, jetty9, the Guacamole web app at /html5.
              Skipped, without failing the install, if the artefacts have not
              been staged by tools/vendor-guacamole.sh
  store       store/.env and APP_KEY; report the Laravel situation honestly
  verify      read-only checks, including GET /api/auth over the loopback

Options:
  --only  a,b,c            run only these steps
  --skip  a,b,c            run everything except these steps
  --php-version X.Y        default 8.4; must be a version the PPA carries
  --server-name NAME       vhost ServerName (default: this host's FQDN)
  --schema-dir DIR         where to look for pnetlab_db.sql and guacdb.sql
                           (default: install/sql/schema)
  --guacamole-dir DIR      where to look for the staged Guacamole .war and
                           JDBC extension (default: install/vendor/guacamole)
  --guacamole-version V    which staged version to install (default: 1.5.5).
                           1.3.0 is the documented fallback: it is exact version
                           parity with the guacd Ubuntu ships, and nothing else
                           about the install changes.
  --prune                  let rsync delete files under /opt/unetlab/html that
                           are not in the source tree. Off by default because
                           it will also remove anything you put there by hand.
  --reset-admin            re-seed the admin user even if one exists. This
                           RESETS the administrator password to 'pnet'.
  --with-node-tools        also install the host binaries the sudo policy
                           allowlists (iproute2, bridge-utils, qemu-utils, ...)
  --without-store-vendor   skip composer install for store/. The admin UI will
                           not work, but the legacy API will. Use this for an
                           air-gapped build where vendor/ arrives another way;
                           it is the only step that reaches Packagist.
  --strip-sudoers-grants   comment out any surviving blanket NOPASSWD:ALL for
                           www-data or %unl, validating before and after
  -h, --help               this text

Network: the distro archives and ppa:ondrej/php. Nothing else, unless you pass
--with-store-vendor. See docs/OFFLINE-FIRST.md.
EOF
}

# --- argument parsing ------------------------------------------------------
while [[ $# -gt 0 ]]; do
	case "$1" in
		--only)                 ONLY="${2:?--only needs a value}"; shift 2 ;;
		--skip)                 SKIP="${2:?--skip needs a value}"; shift 2 ;;
		--php-version)          PHP_VERSION="${2:?--php-version needs a value}"; shift 2 ;;
		--server-name)          SERVER_NAME="${2:?--server-name needs a value}"; shift 2 ;;
		--schema-dir)           SCHEMA_DIR="${2:?--schema-dir needs a value}"; shift 2 ;;
		--guacamole-dir)        GUACAMOLE_DIR="${2:?--guacamole-dir needs a value}"; shift 2 ;;
		--guacamole-version)    GUAC_VERSION="${2:?--guacamole-version needs a value}"; shift 2 ;;
		--prune)                PRUNE=1; shift ;;
		--reset-admin)          RESET_ADMIN=1; shift ;;
		--with-node-tools)      WITH_NODE_TOOLS=1; shift ;;
		--without-store-vendor) WITHOUT_STORE_VENDOR=1; shift ;;
		--strip-sudoers-grants) STRIP_SUDOERS_GRANTS=1; shift ;;
		-h|--help)              usage; exit 0 ;;
		*) printf 'unknown option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
	esac
done

# --- libraries -------------------------------------------------------------
# shellcheck source=lib/common.sh
. "${LIB_DIR}/common.sh"
# shellcheck source=lib/packages.sh
. "${LIB_DIR}/packages.sh"
# shellcheck source=lib/deploy.sh
. "${LIB_DIR}/deploy.sh"
# shellcheck source=lib/sudoers.sh
. "${LIB_DIR}/sudoers.sh"
. "${LIB_DIR}/platform.sh"
# shellcheck source=lib/database.sh
. "${LIB_DIR}/database.sh"
# shellcheck source=lib/apache.sh
. "${LIB_DIR}/apache.sh"
# shellcheck source=lib/guacamole.sh
. "${LIB_DIR}/guacamole.sh"
# shellcheck source=lib/store.sh
. "${LIB_DIR}/store.sh"
# shellcheck source=lib/verify.sh
. "${LIB_DIR}/verify.sh"

# --- step selection --------------------------------------------------------
in_list() {
	local needle="$1" list="${2//,/ }" item
	for item in $list; do
		if [[ "$item" == "$needle" ]]; then return 0; fi
	done
	return 1
}

should_run() {
	local s="$1"
	if [[ -n "$ONLY" ]]; then
		in_list "$s" "$ONLY"
		return $?
	fi
	if [[ -n "$SKIP" ]] && in_list "$s" "$SKIP"; then
		return 1
	fi
	return 0
}

validate_step_names() {
	local list s
	for list in "$ONLY" "$SKIP"; do
		if [[ -z "$list" ]]; then continue; fi
		for s in ${list//,/ }; do
			in_list "$s" "$ALL_STEPS" || die "unknown step: ${s}
    known steps: ${ALL_STEPS}"
		done
	done
}

# --- preflight -------------------------------------------------------------
step_preflight() {
	# A half-finished dpkg transaction makes every apt call fail with exit 100 and
	# a message about running `dpkg --configure -a`. It is common after a VM
	# snapshot rollback or a killed apt run. Catch it here rather than three
	# minutes into the package step.
	if compgen -G '/var/lib/dpkg/updates/*' >/dev/null 2>&1; then
		die "dpkg has an interrupted transaction; run: sudo dpkg --configure -a"
	fi
	if have fuser && fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; then
		die "another package manager holds the dpkg lock; wait for it to finish"
	fi

	step "Preflight"

	if [[ -r /etc/os-release ]]; then
		# shellcheck disable=SC1091
		. /etc/os-release
		info "host: ${PRETTY_NAME:-unknown} (kernel $(uname -r))"
		if [[ "${ID:-}" != 'ubuntu' ]]; then
			warn "this installer targets Ubuntu. ${ID:-this distribution} is
             untested: the PPA, the package names and the Apache layout are all
             Debian-family assumptions and only Ubuntu 24.04 has been verified."
		elif [[ "${VERSION_ID:-}" != '24.04' ]]; then
			warn "verified on Ubuntu 24.04; this is ${VERSION_ID:-unknown}.
             It may well work — nothing here is 24.04-specific — but it has not
             been tried."
		fi
	else
		warn "no /etc/os-release; cannot tell what this host is"
	fi

	verify_base_dir_is_hardcoded
	verify_credentials_match_source

	dim "vhost ServerName will be ${SERVER_NAME}"
	dim "schema dumps will be looked for in ${SCHEMA_DIR}"
	dim "Guacamole artefacts will be looked for in ${GUACAMOLE_DIR}"

	# The one thing that is genuinely non-negotiable, stated once.
	info "BASE_DIR is ${BASE_DIR}; the web layer goes to ${WEB_ROOT}"
}

# --- summary ---------------------------------------------------------------
print_summary() {
	local item
	printf '\n%s%s%s\n' "$C_BOLD" '============================================================' "$C_RESET"
	printf '%sInstall finished%s\n' "$C_BOLD" "$C_RESET"
	printf '%s%s%s\n' "$C_BOLD" '============================================================' "$C_RESET"

	if [[ ${#SKIPPED[@]} -gt 0 ]]; then
		printf '\n%sSkipped:%s\n' "$C_BOLD" "$C_RESET"
		for item in "${SKIPPED[@]}"; do printf '  - %s\n' "$item"; done
	fi

	if [[ ${#NOTES[@]} -gt 0 ]]; then
		printf '\n%sRead this:%s\n' "$C_BOLD" "$C_RESET"
		for item in "${NOTES[@]}"; do printf '  - %s\n' "$item"; done
	fi

	if [[ ${#WARNINGS[@]} -gt 0 ]]; then
		printf '\n%sWarnings (%d):%s\n' "${C_BOLD}${C_YELLOW}" "${#WARNINGS[@]}" "$C_RESET"
		for item in "${WARNINGS[@]}"; do printf '  - %s\n' "$item"; done
	fi

	printf '\n%sWhere things are:%s\n' "$C_BOLD" "$C_RESET"
	printf '  web layer      %s\n' "$WEB_ROOT"
	printf '  vhost          /etc/apache2/sites-available/pnetlab.conf\n'
	printf '  sudo policy    /etc/sudoers.d/pnetlab\n'
	printf '  php error log  %s/data/Logs/php_errors.txt\n' "$BASE_DIR"
	printf '  apache logs    /var/log/apache2/pnetlab-{access,error}.log\n'
	printf '  backups        %s\n' "$BACKUP_DIR"
	printf '\n  re-run any part with:  sudo install/install.sh --only <step>\n'
	printf '  re-check anything with: sudo install/install.sh --only verify\n\n'
}

on_error() {
	local rc=$? line=${BASH_LINENO[0]:-?}
	printf '\n%sThe install failed (exit %s, near line %s of %s).%s\n' \
		"$C_RED" "$rc" "$line" "${BASH_SOURCE[1]:-install.sh}" "$C_RESET" >&2
	printf '%sSteps already completed are not rolled back; they are idempotent, so\n' "$C_RED" >&2
	printf 'fixing the cause and re-running is the intended recovery.%s\n' "$C_RESET" >&2
	exit "$rc"
}

main() {
	require_root
	validate_step_names

	# Resolved here rather than in preflight so that `--only apache` still
	# renders a vhost with a ServerName.
	if [[ -z "$SERVER_NAME" ]]; then
		SERVER_NAME="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo pnetlab)"
	fi

	local s
	for s in $ALL_STEPS; do
		if should_run "$s"; then
			"step_${s}"
		else
			dim "(skipping ${s})"
		fi
	done

	print_summary
}

trap on_error ERR
main "$@"
