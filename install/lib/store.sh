# shellcheck shell=bash
#
# install/lib/store.sh — the Laravel half, and what it cannot do.
#
# Read this before assuming the step is broken. It is not: the application is.
#
#   store/ is Laravel 10 running on PHP 8.4. It used to be Laravel 5.5, which
#   could not run on 8.x at all — Illuminate's container called
#   ReflectionParameter::getClass() (removed behaviour in 8.0) and
#   Collection::offsetExists() violated ArrayAccess's 8.1 return type. That was
#   fixed by the Laravel 10 upgrade, so composer install is now part of a normal
#   install rather than an opt-in that buys you nothing.
#
#   It is still the only step that reaches Packagist. --skip store leaves the
#   admin UI unavailable but the legacy API fully working, which is a reasonable
#   choice for an air-gapped build where vendor/ is delivered another way.

step_store() {
	step "Laravel application (store/)"

	prepare_store_env

	local autoload="${WEB_ROOT}/store/vendor/autoload.php"

	if [[ -f "$autoload" ]]; then
		ok "store/vendor is present"
		ok "store/vendor is present"
		return 0
	fi

	if [[ "${WITHOUT_STORE_VENDOR:-0}" == 1 ]]; then
		skip "composer install (--without-store-vendor)"
		note "The Laravel admin UI at / will NOT work on this install.
             store/vendor is absent, so store/public/index.php fatals on its
             require of vendor/autoload.php. This is expected and known:
             Laravel 5.5 does not run on PHP 8.4 even once vendor/ exists.
             What DOES work: the legacy API (/api/...), the themes, and the
             platform layer — which is most of what this fork currently
             touches. See docs/REFERENCE-ENVIRONMENT.md.
             --with-store-vendor forces the composer run anyway; it will not
             make the UI work, and it reaches Packagist, which the rest of this
             installer does not."
		return 0
	fi

	attempt_composer_install
}

# The committed store/.env carries an APP_KEY that is public in upstream's
# repository and must be treated as burned (docs/OFFLINE-FIRST.md). Generating
# a per-installation key is listed in install/README.md as work the install
# path owes; this is it.
#
# The deployed .env is excluded from rsync, so a re-run does not clobber the
# generated key.
prepare_store_env() {
	local src="${SRC_DIR}/store/.env"
	local dst="${WEB_ROOT}/store/.env"

	if [[ ! -f "$dst" ]]; then
		[[ -f "$src" ]] || { warn "no store/.env in the source tree and none deployed"; return 0; }
		install -o root -g "$WEB_GROUP" -m 0640 "$src" "$dst"
		info "created ${dst} from the source tree"
	else
		dim "keeping the existing ${dst}"
	fi

	local key
	key="$(sed -n 's/^APP_KEY=//p' "$dst" | head -1)"
	if [[ -z "$key" || "$key" == 'base64:2sDMGZ3AnxMS4ydnMHaj9jpd/g140lMDfm21SmZdRSA=' ]]; then
		local new
		new="base64:$(head -c 32 /dev/urandom | base64)"
		env_set "$dst" APP_KEY "$new"
		info "generated a per-installation APP_KEY"
		if [[ -n "$key" ]]; then
			note "the committed APP_KEY was replaced. It is published in upstream's
             repository and must be considered burned wherever it is still in use."
		fi
	else
		dim "APP_KEY is already installation-specific"
	fi

	# The shipped .env is a developer's: APP_ENV=local, APP_DEBUG=true. Debug
	# output on a deployed appliance leaks paths, configuration and query
	# fragments to anyone who can trigger an error.
	env_set "$dst" APP_ENV production
	env_set "$dst" APP_DEBUG false
	env_set "$dst" APP_LOG_LEVEL warning

	chown "root:${WEB_GROUP}" "$dst"
	chmod 0640 "$dst"
	ok "store/.env: production, debug off, installation-specific key"
}

# env_set <file> <key> <value> — set or append a KEY=value line.
env_set() {
	local file="$1" key="$2" value="$3"
	if grep -qE "^${key}=" "$file"; then
		local current
		current="$(sed -n "s/^${key}=//p" "$file" | head -1)"
		if [[ "$current" == "$value" ]]; then
			return 0
		fi
		# '|' delimiter: base64 keys contain '/' and '+', never '|'.
		sed -i "s|^${key}=.*|${key}=${value}|" "$file"
	else
		printf '%s=%s\n' "$key" "$value" >> "$file"
	fi
}

attempt_composer_install() {
	if ! have composer; then
		warn "--with-store-vendor was given but composer is not installed.
             Install it yourself (this installer will not pipe an installer
             script from the internet into a shell) and re-run with
             --only store."
		return 0
	fi

	note "running composer install. This is the only step that reaches Packagist,
             rather than the distribution archives and the PHP PPA."

	local rc=0
	( cd "${WEB_ROOT}/store" && \
	  COMPOSER_ALLOW_SUPERUSER=1 composer install \
		--ignore-platform-reqs --no-plugins --no-scripts --no-interaction --no-progress ) || rc=$?

	if [[ $rc -ne 0 ]]; then
		warn "composer install failed (exit ${rc}). Nothing else in the install
             depends on it; the legacy API and themes are unaffected."
		return 0
	fi

	run chown -R root:root "${WEB_ROOT}/store/vendor"
	ok "composer install completed; store/vendor is in place"
}
