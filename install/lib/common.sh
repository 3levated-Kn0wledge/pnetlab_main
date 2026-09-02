# shellcheck shell=bash
#
# install/lib/common.sh — shared state, logging and idempotency helpers.
#
# Sourced by install.sh. Not executable on its own.

# --- configuration ---------------------------------------------------------
#
# BASE_DIR is NOT configurable. includes/init.php contains
#     define('BASE_DIR', '/opt/unetlab');
# and the whole tree resolves requires against it. Changing it here would
# produce an install that looks fine and fatals on the first include.
readonly BASE_DIR='/opt/unetlab'
readonly WEB_ROOT="${BASE_DIR}/html"
readonly WEB_USER='www-data'
readonly WEB_GROUP='www-data'

# Database credentials. These are read out of the application, not chosen here:
#
#   includes/functions.php  checkDatabase()        pnetlab_db  pnetlab/pnetlab
#   includes/functions.php  html5_checkDatabase()  guacdb      guacuser/pnetlab
#
# They are hardcoded in the application and cannot currently be rotated without
# a code change. verify_credentials_match_source() below fails the install if
# the application drifts away from these values.
readonly APP_DB='pnetlab_db'
readonly APP_DB_USER='pnetlab'
readonly APP_DB_PASS='pnetlab'
readonly GUAC_DB='guacdb'
readonly GUAC_DB_USER='guacuser'
readonly GUAC_DB_PASS='pnetlab'

# --- output ----------------------------------------------------------------
if [[ -t 1 && "${TERM:-dumb}" != 'dumb' ]]; then
	C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
	C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'
else
	C_RESET=''; C_BOLD=''; C_DIM=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''
fi

# Collected and reprinted at the end. An installer that scrolls a warning off
# the screen has not warned anybody.
declare -a WARNINGS=()
declare -a NOTES=()
declare -a SKIPPED=()

step()  { printf '\n%s==> %s%s\n' "${C_BOLD}${C_BLUE}" "$*" "$C_RESET"; }
info()  { printf '    %s\n' "$*"; }
ok()    { printf '    %s%s%s\n' "$C_GREEN" "$*" "$C_RESET"; }
dim()   { printf '    %s%s%s\n' "$C_DIM" "$*" "$C_RESET"; }
warn()  { printf '    %sWARNING: %s%s\n' "$C_YELLOW" "$*" "$C_RESET" >&2; WARNINGS+=("$*"); }
note()  { printf '    %s\n' "$*"; NOTES+=("$*"); }
skip()  { printf '    %sskipped: %s%s\n' "$C_YELLOW" "$*" "$C_RESET"; SKIPPED+=("$*"); }
die()   { printf '\n%sFATAL: %s%s\n' "$C_RED" "$*" "$C_RESET" >&2; exit 1; }

# Echo a command before running it. An installer should be auditable from its
# own transcript.
run() {
	printf '    %s$ %s%s\n' "$C_DIM" "$*" "$C_RESET"
	"$@"
}

# Same, but the command is allowed to fail; returns its status.
run_ok() {
	printf '    %s$ %s%s\n' "$C_DIM" "$*" "$C_RESET"
	"$@" || return $?
}

require_root() {
	[[ ${EUID} -eq 0 ]] || die "must run as root (try: sudo $0 ...)"
}

have() { command -v "$1" >/dev/null 2>&1; }

# --- what host is this -----------------------------------------------------
#
# /etc/os-release is the only thing here that identifies the release. It is
# sourced rather than parsed because that is what the file is specified for, and
# it defines ID, VERSION_ID, UBUNTU_CODENAME and PRETTY_NAME as globals for the
# rest of the install — the PPA probe in packages.sh needs the codename, and the
# supported-release check needs the version.
#
# Sourced once, from main(), so that the values are present whether or not the
# preflight step is in the selection: --only packages must still be able to tell
# which release it is adding a repository for. lsb_release is deliberately not
# used; it is not installed on a minimal server image.
OS_RELEASE_READ=0
detect_os_release() {
	[[ $OS_RELEASE_READ -eq 1 ]] && return 0
	OS_RELEASE_READ=1
	if [[ -r /etc/os-release ]]; then
		# shellcheck disable=SC1091
		. /etc/os-release
	fi
	return 0
}

# --- filesystem helpers (idempotent) ---------------------------------------

# ensure_dir <path> [owner:group] [mode]
ensure_dir() {
	local path="$1" owner="${2:-root:root}" mode="${3:-0755}"
	if [[ ! -d "$path" ]]; then
		mkdir -p "$path"
		info "created ${path}"
	fi
	chown "$owner" "$path"
	chmod "$mode" "$path"
}

# install_file <src> <dst> [mode] [owner:group]
# Copies only when the content differs, so re-runs are quiet and mtimes are
# not churned.
install_file() {
	local src="$1" dst="$2" mode="${3:-0644}" owner="${4:-root:root}"
	[[ -f "$src" ]] || die "source file missing: ${src}"
	if [[ -f "$dst" ]] && cmp -s "$src" "$dst"; then
		dim "unchanged ${dst}"
	else
		install -o "${owner%%:*}" -g "${owner##*:}" -m "$mode" "$src" "$dst"
		info "wrote ${dst}"
	fi
	chown "$owner" "$dst"
	chmod "$mode" "$dst"
}

# Render a template, substituting @NAME@ placeholders, into a temp file whose
# path is echoed. Caller owns the temp file.
render_template() {
	local src="$1"; shift
	local out; out="$(mktemp)"
	cp "$src" "$out"
	local pair name value
	for pair in "$@"; do
		name="${pair%%=*}"; value="${pair#*=}"
		# The value is the REPLACEMENT side of an s||| command, where '\' and
		# '&' are syntax ('&' is "the whole match") and '|' is the delimiter.
		# All three are escaped, in that order -- backslash first, or the
		# escapes just added would be escaped again. The guacamole database
		# password goes through here; a rotated one containing '&' would
		# otherwise have been written as the placeholder's own name.
		value="${value//\\/\\\\}"
		value="${value//&/\\&}"
		value="${value//|/\\|}"
		sed -i "s|@${name}@|${value}|g" "$out"
	done
	printf '%s\n' "$out"
}

# backup_file <path> — copies a file into /var/backups/pnetlab-install/ and
# sets LAST_BACKUP to where it landed.
BACKUP_DIR='/var/backups/pnetlab-install'
LAST_BACKUP=''
backup_file() {
	local path="$1" stamp
	stamp="$(date +%Y%m%d-%H%M%S)"
	mkdir -p "$BACKUP_DIR"
	chmod 0700 "$BACKUP_DIR"
	LAST_BACKUP="${BACKUP_DIR}/$(basename "$path").${stamp}"
	cp -a "$path" "$LAST_BACKUP"
	info "backed up ${path} -> ${LAST_BACKUP}"
}

# --- source-tree sanity ----------------------------------------------------

# The credentials above are transcribed from the application. If someone
# changes them in the code and not here, the installer would silently create
# the wrong database user and the app would fail to connect with a message that
# points nowhere useful. Check rather than assume.
verify_credentials_match_source() {
	local f="${SRC_DIR}/includes/functions.php"
	[[ -f "$f" ]] || die "not a PNETLab source tree: ${f} not found"

	local missing=0
	grep -qF "dbname=${APP_DB}', '${APP_DB_USER}', '${APP_DB_PASS}'" "$f" || missing=1
	grep -qF "dbname=${GUAC_DB}', '${GUAC_DB_USER}', '${GUAC_DB_PASS}'" "$f" || missing=1

	if [[ $missing -ne 0 ]]; then
		die "database credentials in includes/functions.php no longer match the
    values in install/lib/common.sh. The application hardcodes them; the
    installer must be updated to match, not the other way round.
    Check checkDatabase() and html5_checkDatabase()."
	fi
	dim "credentials match includes/functions.php"
}

verify_base_dir_is_hardcoded() {
	local f="${SRC_DIR}/includes/init.php"
	[[ -f "$f" ]] || die "not a PNETLab source tree: ${f} not found"
	grep -qE "define\('BASE_DIR', *'${BASE_DIR}'\)" "$f" ||
		die "includes/init.php no longer defines BASE_DIR as ${BASE_DIR};
    the deploy path is derived from it and the installer must be updated."
	dim "BASE_DIR is ${BASE_DIR} (from includes/init.php)"
}
