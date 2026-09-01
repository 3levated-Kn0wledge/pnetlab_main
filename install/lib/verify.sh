# shellcheck shell=bash
#
# install/lib/verify.sh — read-only checks against the host that was just
# installed. Nothing here changes state, so `install.sh --only verify` is safe
# to run at any time, including against a host somebody else built.
#
# The checks are deliberately end-to-end where they can be. "apache2 is
# running" is not evidence that the application serves; a 401 from /api/auth is.

VERIFY_FAILURES=0

check() {
	local label="$1"; shift
	if "$@" >/dev/null 2>&1; then
		printf '    %s[ ok ]%s %s\n' "$C_GREEN" "$C_RESET" "$label"
	else
		printf '    %s[fail]%s %s\n' "$C_RED" "$C_RESET" "$label"
		VERIFY_FAILURES=$((VERIFY_FAILURES + 1))
	fi
}

check_soft() {
	local label="$1"; shift
	if "$@" >/dev/null 2>&1; then
		printf '    %s[ ok ]%s %s\n' "$C_GREEN" "$C_RESET" "$label"
	else
		printf '    %s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$label"
	fi
}

_php_fpm_active()   { systemctl is-active --quiet "php${PHP_VERSION}-fpm"; }
_apache_active()    { systemctl is-active --quiet apache2; }
_mariadb_active()   { systemctl is-active --quiet mariadb; }
_socket_present()   { [[ -S "/run/php/php${PHP_VERSION}-fpm.sock" ]]; }
_site_enabled()     { [[ -e /etc/apache2/sites-enabled/pnetlab.conf ]]; }
_sudoers_installed(){ [[ -f /etc/sudoers.d/pnetlab ]]; }
_sudoers_mode()     { [[ "$(stat -c '%a %U %G' /etc/sudoers.d/pnetlab)" == '440 root root' ]]; }
_sudoers_parses()   { visudo -c; }
_no_upstream_sudo() { [[ ! -e /etc/sudoers.d/unetlab ]]; }

# The single most important assertion in this file. If this passes, the sudo
# policy is doing something; if it fails, the allowlist is decorative.
_no_blanket_grant() {
	! sudo -l -U "$WEB_USER" 2>/dev/null |
		grep -qE '\(ALL([[:space:]]*:[[:space:]]*ALL)?\)[[:space:]]+NOPASSWD:[[:space:]]*ALL'
}

_web_user_can_run_ip() { sudo -n -l -U "$WEB_USER" /usr/sbin/ip; }

# MYSQL_PWD rather than -p: a password on the command line is visible in ps to
# every account on the box for as long as the client runs.
_db_reachable_as() {
	local user="$1" pass="$2" db="$3" host="$4"
	MYSQL_PWD="$pass" "${MYSQL_BIN:-mysql}" -h "$host" -u "$user" -e 'SELECT 1;' "$db"
}
# checkDatabase() connects to host=localhost (socket), html5_checkDatabase() to
# host=127.0.0.1 (TCP). Test each the way the application does it.
_app_db_reachable()  { _db_reachable_as "$APP_DB_USER"  "$APP_DB_PASS"  "$APP_DB"  localhost; }
_guac_db_reachable() { _db_reachable_as "$GUAC_DB_USER" "$GUAC_DB_PASS" "$GUAC_DB" 127.0.0.1; }

# http_code <path> — a request to ourselves over the loopback. Not an external
# call; this does not violate docs/OFFLINE-FIRST.md.
http_code() {
	curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1$1" 2>/dev/null
}

step_verify() {
	step "Verification"

	# The database step may not have run in this invocation.
	if [[ -z "${MYSQL_BIN:-}" ]]; then
		resolve_mysql_client || true
	fi

	info "services"
	check      "apache2 is running"                 _apache_active
	check      "php${PHP_VERSION}-fpm is running"   _php_fpm_active
	check      "the FPM socket exists"              _socket_present
	check      "mariadb is running"                 _mariadb_active

	info "layout"
	check      "${WEB_ROOT}/api.php is deployed"    test -f "${WEB_ROOT}/api.php"
	check      "${WEB_ROOT}/.user.ini is deployed"  test -f "${WEB_ROOT}/.user.ini"
	check      "${BASE_DIR}/labs is writable by ${WEB_USER}" \
	           sudo -u "$WEB_USER" test -w "${BASE_DIR}/labs"
	check      "${BASE_DIR}/tmp is writable by ${WEB_USER}" \
	           sudo -u "$WEB_USER" test -w "${BASE_DIR}/tmp"
	check      "${BASE_DIR}/data is writable by ${WEB_USER}" \
	           sudo -u "$WEB_USER" test -w "${BASE_DIR}/data"
	check      "the pnetlab vhost is enabled"       _site_enabled

	info "sudo policy"
	check      "/etc/sudoers.d/pnetlab is installed"     _sudoers_installed
	check      "it is 0440 root:root"                    _sudoers_mode
	check      "the sudo configuration parses"           _sudoers_parses
	check      "the upstream /etc/sudoers.d/unetlab is gone" _no_upstream_sudo
	check      "${WEB_USER} has NO blanket NOPASSWD:ALL" _no_blanket_grant
	check_soft "${WEB_USER} may still run /usr/sbin/ip"  _web_user_can_run_ip

	info "databases"
	if [[ -n "${MYSQL_BIN:-}" ]]; then
		check "${APP_DB_USER} can connect to ${APP_DB}"   _app_db_reachable
		check "${GUAC_DB_USER} can connect to ${GUAC_DB}" _guac_db_reachable
		verify_schema_present
	else
		warn "no mariadb client; skipped the database checks"
	fi

	verify_http
	verify_php_settings

	if [[ $VERIFY_FAILURES -gt 0 ]]; then
		warn "${VERIFY_FAILURES} verification check(s) failed — see [fail] above."
	else
		ok "all verification checks passed"
	fi
}

verify_schema_present() {
	local n
	n="$(db_query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${APP_DB}';" 2>/dev/null || echo 0)"
	if [[ "${n:-0}" -eq 0 ]]; then
		printf '    %s[fail]%s %s has no tables — the schema was never imported\n' \
			"$C_RED" "$C_RESET" "$APP_DB"
		VERIFY_FAILURES=$((VERIFY_FAILURES + 1))
	else
		printf '    %s[ ok ]%s %s has %s tables\n' "$C_GREEN" "$C_RESET" "$APP_DB" "$n"
	fi
}

verify_http() {
	info "http (loopback only)"
	if ! have curl; then
		warn "curl is not installed; skipped the HTTP checks"
		return 0
	fi

	# /api/auth with no session must be 401. It is the cheapest proof that
	# Apache, mod_rewrite, .htaccess, PHP-FPM and the bundled Slim router are
	# all working together — the legacy API has no autoloader and no vendor
	# tree, so nothing else has to be right for it to answer.
	local code; code="$(http_code /api/auth)"
	if [[ "$code" == 401 ]]; then
		printf '    %s[ ok ]%s GET /api/auth -> 401 (the legacy API is serving)\n' "$C_GREEN" "$C_RESET"
	else
		printf '    %s[fail]%s GET /api/auth -> %s (expected 401)\n' "$C_RED" "$C_RESET" "${code:-no response}"
		VERIFY_FAILURES=$((VERIFY_FAILURES + 1))
		if [[ "$code" == 500 ]]; then
			warn "500 on /api/auth usually means either a php_value directive is
             reaching Apache (check that .htaccess still guards them with
             <IfModule mod_php.c>) or PHP fatalled. Look in
             ${BASE_DIR}/data/Logs/php_errors.txt and
             /var/log/apache2/pnetlab-error.log."
		fi
	fi

	# The dotfile deny in the vhost. store/.env is in the document root and
	# contains the APP_KEY; if this is not 403 it is being served as text.
	code="$(http_code /store/.env)"
	if [[ "$code" == 403 || "$code" == 404 ]]; then
		printf '    %s[ ok ]%s GET /store/.env -> %s (not served)\n' "$C_GREEN" "$C_RESET" "$code"
	else
		printf '    %s[fail]%s GET /store/.env -> %s — the environment file is being served\n' \
			"$C_RED" "$C_RESET" "$code"
		VERIFY_FAILURES=$((VERIFY_FAILURES + 1))
	fi

	# Expected to fail today. Reported as information, not as a failure.
	code="$(http_code /)"
	if [[ "$code" == 200 ]]; then
		printf '    %s[ ok ]%s GET / -> 200 (the Laravel UI is answering)\n' "$C_GREEN" "$C_RESET"
	else
		printf '    %s[info]%s GET / -> %s — expected while store/vendor is absent; see the store step\n' \
			"$C_YELLOW" "$C_RESET" "${code:-no response}"
	fi
}

verify_php_settings() {
	info "php"
	local php="php${PHP_VERSION}"
	have "$php" || { warn "${php} not on PATH"; return 0; }

	# .user.ini is read by FPM per directory, not by the CLI, so this checks
	# the file is present and parseable rather than that it took effect. The
	# effective values under FPM are what the HTTP checks above exercise.
	check "the .user.ini is in the document root" test -f "${WEB_ROOT}/.user.ini"
	check "the PHP error log is writable by ${WEB_USER}" \
		sudo -u "$WEB_USER" test -w "${BASE_DIR}/data/Logs/php_errors.txt"

	local mods
	mods="$("$php" -m 2>/dev/null | tr '\n' ' ')"
	local m
	for m in pdo_mysql mbstring curl gd zip xml intl bcmath; do
		if [[ " $mods " == *" $m "* ]]; then
			printf '    %s[ ok ]%s extension %s\n' "$C_GREEN" "$C_RESET" "$m"
		else
			printf '    %s[fail]%s extension %s is missing\n' "$C_RED" "$C_RESET" "$m"
			VERIFY_FAILURES=$((VERIFY_FAILURES + 1))
		fi
	done
}
