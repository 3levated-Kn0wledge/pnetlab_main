# shellcheck shell=bash
#
# install/lib/apache.sh — Apache, PHP-FPM and the virtual host.

readonly APACHE_SITE='/etc/apache2/sites-available/pnetlab.conf'

fpm_socket() {
	printf '/run/php/php%s-fpm.sock\n' "$PHP_VERSION"
}

step_apache() {
	step "Apache and PHP-FPM"

	have a2enmod || die "apache2 does not appear to be installed"

	# proxy_fcgi + setenvif: hand .php to the FPM pool.
	# rewrite:              the application's routing is all mod_rewrite.
	# headers:              used by the API responses.
	# access_compat:        includes/.htaccess (and others in the tree) use the
	#                       Apache 2.2 'deny from all' spelling. Without
	#                       access_compat that is an "Invalid command" 500 for
	#                       any request that reaches those directories.
	# proxy, proxy_http:    the /html5/ reverse proxy to Jetty. proxy_fcgi
	#                       already pulls in mod_proxy, but naming it is what
	#                       makes the dependency visible.
	# proxy_wstunnel:       the Guacamole console data plane is a WebSocket.
	#                       mod_proxy_http will not perform the Upgrade, so
	#                       without this consoles open and immediately die.
	#                       Enabled unconditionally, even when the guacamole
	#                       step is skipped: the vhost template names ws:// and
	#                       Apache refuses to start on an unknown ProxyPass
	#                       scheme, so this and the vhost are one change.
	local m
	for m in proxy_fcgi proxy proxy_http proxy_wstunnel setenvif rewrite headers access_compat; do
		if [[ -e "/etc/apache2/mods-enabled/${m}.load" || -e "/etc/apache2/mods-enabled/${m}.conf" ]]; then
			dim "mod_${m} already enabled"
		else
			run a2enmod "$m"
		fi
	done

	# The distribution's php<v>-fpm.conf provides the global handler. The
	# vhost repeats it explicitly; both being present is harmless and the
	# vhost is what documents the intent.
	if [[ -f "/etc/apache2/conf-available/php${PHP_VERSION}-fpm.conf" ]]; then
		if [[ -e "/etc/apache2/conf-enabled/php${PHP_VERSION}-fpm.conf" ]]; then
			dim "php${PHP_VERSION}-fpm conf already enabled"
		else
			run a2enconf "php${PHP_VERSION}-fpm"
		fi
	fi

	# --- the pool ----------------------------------------------------------
	if have systemctl; then
		if ! systemctl is-enabled --quiet "php${PHP_VERSION}-fpm" 2>/dev/null; then
			run systemctl enable "php${PHP_VERSION}-fpm"
		fi
		if ! systemctl is-active --quiet "php${PHP_VERSION}-fpm"; then
			run systemctl start "php${PHP_VERSION}-fpm"
		else
			dim "php${PHP_VERSION}-fpm is running"
		fi
	fi

	local sock; sock="$(fpm_socket)"
	if [[ ! -S "$sock" ]]; then
		warn "${sock} does not exist. The vhost points at it, so PHP will 503.
             Check 'systemctl status php${PHP_VERSION}-fpm' and the pool's
             'listen =' in /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf."
	fi

	# --- the vhost ---------------------------------------------------------
	local rendered
	rendered="$(render_template "${SRC_DIR}/install/apache/pnetlab.conf.in" \
		"PHP_VERSION=${PHP_VERSION}" \
		"FPM_SOCKET=${sock}" \
		"WEB_ROOT=${WEB_ROOT}" \
		"SERVER_NAME=${SERVER_NAME}")"
	install_file "$rendered" "$APACHE_SITE" 0644 root:root
	rm -f "$rendered"

	if [[ -e /etc/apache2/sites-enabled/pnetlab.conf ]]; then
		dim "site pnetlab already enabled"
	else
		run a2ensite pnetlab
	fi

	# The stock default site also claims *:80 and, being alphabetically first,
	# wins as the default vhost for requests that carry no matching ServerName
	# — i.e. plain http://<ip>/, which is how this appliance is used.
	if [[ -e /etc/apache2/sites-enabled/000-default.conf ]]; then
		run a2dissite 000-default
	else
		dim "default site already disabled"
	fi

	# --- commit ------------------------------------------------------------
	# configtest before restart: a bad vhost otherwise takes the web server
	# down and leaves it down.
	local configtest_out configtest_rc=0
	configtest_out="$(apache2ctl configtest 2>&1)" || configtest_rc=$?
	printf '    %s\n' "$configtest_out"
	if [[ $configtest_rc -ne 0 ]]; then
		die "apache configuration test failed; NOT restarting, so the running
    server is untouched. The vhost is at ${APACHE_SITE}; the template it came
    from is install/apache/pnetlab.conf.in."
	fi

	if have systemctl; then
		run systemctl enable apache2
		run systemctl restart apache2
	fi
	ok "apache serving ${WEB_ROOT} as ${SERVER_NAME}, PHP via ${sock}"
}
