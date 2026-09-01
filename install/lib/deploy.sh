# shellcheck shell=bash
#
# install/lib/deploy.sh — the filesystem layout and the web layer itself.
#
# The web layer goes to /opt/unetlab/html. That path is not a preference:
# includes/init.php hardcodes BASE_DIR as /opt/unetlab and every require in the
# tree resolves against it.

# Repository furniture that is not part of what ships. Kept in step with the
# exclusion list in docs/REFERENCE-ENVIRONMENT.md.
deploy_excludes() {
	printf '%s\n' \
		'/.git/' '/.github/' '/.gitignore' \
		'/docs/' '/install/' '/tests/' '/tools/' \
		'/node_modules/' '/store/node_modules/' \
		'/package.json' '/package-lock.json' '/webpack.mix.js' \
		'/mix-manifest.json' '/README.md' '/SECURITY.md' \
		'/store/vendor/' \
		'/store/.env'
}

# Runtime state that lives inside the deploy tree and must survive a re-run.
# rsync protects excluded paths from --delete, which is exactly what we want.
deploy_runtime_excludes() {
	printf '%s\n' \
		'/store/storage/framework/cache/' \
		'/store/storage/framework/sessions/' \
		'/store/storage/framework/views/' \
		'/store/storage/logs/' \
		'/store/bootstrap/cache/'
}

step_deploy() {
	step "Filesystem layout and web layer"

	[[ "$SRC_DIR" != "$WEB_ROOT" ]] || \
		die "source tree is the deploy target (${WEB_ROOT}); nothing to do, and
    rsync would be copying a directory onto itself"

	# --- the platform directories ------------------------------------------
	# data, labs and tmp are written by the web user. The rest is code and
	# read-only content and stays root-owned.
	ensure_dir "$BASE_DIR"                  root:root                 0755
	ensure_dir "${BASE_DIR}/addons"         root:root                 0755
	ensure_dir "${BASE_DIR}/scripts"        root:root                 0755
	ensure_dir "${BASE_DIR}/wrappers"       root:root                 0755
	ensure_dir "${BASE_DIR}/labs"           "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${BASE_DIR}/tmp"            "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${BASE_DIR}/data"           "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${BASE_DIR}/data/Logs"      "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${BASE_DIR}/data/Exports"   "${WEB_USER}:${WEB_GROUP}" 0755

	# .user.ini points error_log here. PHP-FPM will not create the file if the
	# directory is not writable by the pool user, and a silent logging failure
	# is exactly the thing you need when something else breaks.
	local errlog="${BASE_DIR}/data/Logs/php_errors.txt"
	if [[ ! -e "$errlog" ]]; then
		install -o "$WEB_USER" -g "$WEB_GROUP" -m 0640 /dev/null "$errlog"
		info "created ${errlog}"
	else
		chown "${WEB_USER}:${WEB_GROUP}" "$errlog"
	fi

	ensure_dir "$WEB_ROOT" root:root 0755

	# --- the web layer -----------------------------------------------------
	have rsync || die "rsync not found (it is in the base package set)"

	# --no-owner/--no-group: files land root-owned (we are root) rather than
	# carrying the uid of whoever cloned the repository.
	local -a rsync_args=(-a --no-owner --no-group --chmod=D755,F644)
	local pattern
	while IFS= read -r pattern; do
		rsync_args+=(--exclude "$pattern")
	done < <(deploy_excludes)
	while IFS= read -r pattern; do
		rsync_args+=(--exclude "$pattern")
	done < <(deploy_runtime_excludes)

	if [[ "${PRUNE:-0}" == 1 ]]; then
		rsync_args+=(--delete)
		info "pruning: files under ${WEB_ROOT} with no counterpart in the source will be removed"
	fi

	run rsync "${rsync_args[@]}" "${SRC_DIR}/" "${WEB_ROOT}/"

	# --- ownership ---------------------------------------------------------
	# The code tree is root-owned and not writable by the web user. That is a
	# deliberate change from the appliance, where www-data owns its own code:
	# combined with the shell interpolation this tree still has, a writable
	# docroot turns any file-write bug into persistence.
	#
	# It has a consequence worth knowing about: any admin-UI feature that
	# expects to write into the docroot (template or addon upload) will now go
	# through the sudo policy or fail. Those paths are not exercised today
	# because the Laravel half does not run.
	run chown -R root:root "$WEB_ROOT"

	# ...including store/.env, which the recursive chown above reaches even
	# though rsync excludes it. It has to stay root:www-data 0640 or Laravel
	# cannot read the APP_KEY, and every request 500s with "No application
	# encryption key has been specified" — which presents as "the login page is
	# broken" and, through the API, as a session timeout on every call.
	#
	# A full install does not notice, because the store step re-applies the mode
	# afterwards. `--only deploy` does, and that is the invocation anyone
	# iterating uses. Restore it here rather than making the store step a
	# prerequisite of the deploy step.
	if [[ -f "${WEB_ROOT}/store/.env" ]]; then
		run chown "root:${WEB_GROUP}" "${WEB_ROOT}/store/.env"
		run chmod 0640 "${WEB_ROOT}/store/.env"
	fi

	# Laravel needs these two writable whether or not it currently boots.
	ensure_dir "${WEB_ROOT}/store/storage"                    "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${WEB_ROOT}/store/storage/framework"          "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${WEB_ROOT}/store/storage/framework/cache"    "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${WEB_ROOT}/store/storage/framework/sessions" "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${WEB_ROOT}/store/storage/framework/views"    "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${WEB_ROOT}/store/storage/logs"               "${WEB_USER}:${WEB_GROUP}" 0755
	ensure_dir "${WEB_ROOT}/store/bootstrap/cache"            "${WEB_USER}:${WEB_GROUP}" 0755
	run chown -R "${WEB_USER}:${WEB_GROUP}" "${WEB_ROOT}/store/storage" "${WEB_ROOT}/store/bootstrap/cache"

	# --- things that must have arrived --------------------------------------
	local f
	for f in api.php .htaccess .user.ini includes/init.php includes/functions.php; do
		[[ -e "${WEB_ROOT}/${f}" ]] || die "deploy incomplete: ${WEB_ROOT}/${f} missing"
	done

	# The frontend build output is gitignored in part and produced by
	# `npm run production` from the repository root (docs/BUILD.md). Its
	# absence is not fatal — the legacy API and themes do not need it — but a
	# missing bundle is a confusing blank admin UI, so say so.
	if [[ ! -f "${WEB_ROOT}/store/public/react/js/app.js" ]]; then
		warn "store/public/react/js/app.js is absent: the React bundle was never built.
             Run 'npm install && NODE_OPTIONS=--openssl-legacy-provider npm run production'
             from the repository root before deploying. See docs/BUILD.md."
	fi

	ok "web layer deployed to ${WEB_ROOT}"
}
