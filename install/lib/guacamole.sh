# shellcheck shell=bash
#
# install/lib/guacamole.sh — HTML5 consoles.
#
# What this wires together, and why it is smaller than it looks
# -------------------------------------------------------------
# The web layer already speaks stock Apache Guacamole and has done since before
# this fork existed. includes/functions.php mints a token with
# POST http://127.0.0.1/html5/api/tokens, LoginController writes the user rows
# straight into guacdb, html5AddSession() writes the connection rows, and
# devices/device.php hands the browser /html5/#/client/<b64>?token=<t>. There is
# no PNETLab-specific Guacamole build, no patched war and no custom extension —
# the appliance runs unmodified upstream Guacamole driven entirely through its
# database and its REST API. So this step installs and configures software; it
# does not integrate anything. No PHP, JavaScript or SQL changes accompany it.
#
# Three facts that constrain every decision below
# -----------------------------------------------
# 1. The context path is /html5, not /guacamole. The PHP hardcodes it on both
#    the token-mint and the client-URL side. The war is therefore deployed AS
#    html5.war so Jetty's context path and the browser's path agree; the
#    appliance renames it in the proxy instead, which works but leaves absolute
#    redirects, cookie paths and WebSocket upgrade URLs permanently disagreeing.
# 2. The auth data source identifier must be the literal string "mysql". It is
#    the third \0-separated field of the base64 client identifier the PHP
#    builds. guacamole-auth-jdbc-mysql registers exactly that; any other auth
#    extension registers something else and every console URL becomes a 404.
# 3. Guacamole is javax.servlet, at every release through 1.6.0 (measured: all
#    three wars declare web.xml version="2.5" and contain zero jakarta.servlet
#    references). It cannot deploy on noble's tomcat10, and noble has no
#    tomcat9 server package. jetty9 is the servlet container that exists.
# 4. The servlet container is NOT the fragile half of this step, which is the
#    opposite of what was expected. jetty9 is 9.4 and EOL upstream, so it looked
#    like the thing that would disappear first — but it is still published in
#    26.04 (9.4.58-1, universe), while guacamole-server, which nothing here can
#    substitute, stopped at 24.04. The availability guard below is aimed at
#    guacd for that reason. docs/PLATFORM-SUPPORT.md carries the archive
#    queries.
#
# This step is optional by design. The war is not in any Ubuntu archive and is
# staged out of band (see install/vendor/guacamole/README.md); if it is absent
# the step SKIPS and the install still succeeds. updateUserToken() already
# treats an unreachable console service as a warning rather than a login
# failure, and turning "consoles not staged" into a failed install would
# contradict that.

# The version to install. One variable, deliberately: the schema, the
# properties, the Apache glue and the PHP are identical for 1.3.0 and 1.5.5, so
# walking the pairing back is this and nothing else.
#
# 1.5.5 over 1.3.0: five years of fixes on the session/auth/REST layer, the same
# byte-identical database schema (verified: 001-create-schema.sql is unchanged
# between 1.2.0, 1.3.0 and 1.5.5), and the ?token= URL scheme still honoured.
# The cost is that noble's guacd is 1.3.0, i.e. one minor version behind the web
# app. That is the safe direction — 1.5.0 introduced a version handshake so a
# newer client negotiates down — and it is verified rather than assumed:
# tools/integration/guacamole-console.sh drives a real tunnel end to end.
GUAC_VERSION="${GUAC_VERSION:-1.5.5}"

readonly GUAC_HOME='/etc/guacamole'
readonly JETTY_WEBAPPS='/var/lib/jetty9/webapps'
readonly JETTY_DROPIN_DIR='/etc/systemd/system/jetty9.service.d'
readonly JETTY_START_D='/etc/jetty9/start.d'
readonly MARIADB_JDBC='/usr/share/java/mariadb-java-client.jar'

# Set whenever this run actually changed something Jetty reads, so that a
# second run is quiet and does not bounce a service that is serving consoles.
GUAC_CHANGED=0

# The protocol client libraries, by protocol.
#
# The name carries the 64-bit time_t suffix on 24.04: the transition renamed
# every library package whose ABI changed, so libguac-client-telnet0 became
# libguac-client-telnet0t64. The suffix is not a property of Ubuntu 24.04 that
# will be reverted — it is part of the binary package name until the SONAME
# moves again — but it is also not something to assert about a release we have
# never seen. Resolve it: prefer the t64 name, fall back to the plain one, and
# let the availability guard below speak if neither exists.
#
# These MUST be named. Each is only a Suggests: of guacd, and telnet is
# PNETLab's default console protocol, so an absent client does not look like a
# missing package — it looks like "consoles are broken".
GUAC_PROTOCOLS='vnc rdp ssh telnet'

guac_client_package() {
	local proto="$1" name
	for name in "libguac-client-${proto}0t64" "libguac-client-${proto}0"; do
		if apt_available "$name"; then
			printf '%s\n' "$name"
			return 0
		fi
	done
	# Nothing installable under either spelling. Return the name this installer
	# was written against so the guard has something to report.
	printf 'libguac-client-%s0t64\n' "$proto"
	return 1
}

# openjdk-17 is pinned rather than taking default-jre: noble's default is 21 and
# Jetty 9.4 claims support only through 17.
guacamole_packages() {
	local proto
	printf '%s\n' guacd
	for proto in $GUAC_PROTOCOLS; do
		guac_client_package "$proto" || true
	done
	printf '%s\n' jetty9 libmariadb-java openjdk-17-jre-headless
}

# Everything this step apt-installs, checked before a single package is touched.
#
# guacamole-server is the one that matters. It is in 24.04 and was NOT carried
# forward: neither guacd nor any libguac-client-* is published for a later
# release (see docs/PLATFORM-SUPPORT.md for the archive query). Without this
# guard, an install on such a host runs into `apt-get install guacd` and dies
# with a package-resolution error two hundred lines into a transcript, having
# already changed the host. With it, the step behaves the way it already does
# for a missing .war: say exactly what is absent and what to do, skip, and let
# the rest of the install succeed. Consoles are optional by design; the PHP
# treats an unreachable console service as a warning, not a login failure.
guac_unavailable_packages() {
	local p
	local -a pkgs missing=()
	mapfile -t pkgs < <(guacamole_packages)
	for p in "${pkgs[@]}"; do
		apt_available "$p" || missing+=("$p")
	done
	if (( ${#missing[@]} )); then
		printf '%s\n' "${missing[@]}"
	fi
}

guac_war_path()  { printf '%s/guacamole-%s.war\n' "$GUACAMOLE_DIR" "$GUAC_VERSION"; }
guac_jar_name()  { printf 'guacamole-auth-jdbc-mysql-%s.jar\n' "$GUAC_VERSION"; }
guac_jar_path()  { printf '%s/%s\n' "$GUACAMOLE_DIR" "$(guac_jar_name)"; }

# Verify a staged artefact against install/vendor/guacamole/SHA512SUMS.
#
# A file in the vendor directory arrived from somewhere this installer cannot
# see — a maintainer's laptop, a mirror, a USB stick. The manifest is committed
# and reviewed, so it is the only thing here that has been looked at by a human.
# A mismatch is fatal, not a skip: "an unexpected binary is present" is a
# different situation from "no binary is present", and only the second one is
# benign.
guac_verify_artefact() {
	local path="$1" name expected actual
	name="$(basename "$path")"
	local manifest="${GUACAMOLE_DIR}/SHA512SUMS"

	# The manifest is committed beside the artefacts. Its absence is not a
	# degraded install, it is a tree that has been tampered with or truncated,
	# and the artefact it would have pinned is a jar that Jetty runs as root's
	# peer on the console path. Fail closed; this used to warn and install.
	if [[ ! -f "$manifest" ]]; then
		die "${name} is staged but ${manifest} is missing.
    That file is the reviewed pin for every artefact in this directory, and
    nothing is deployed out of it unverified. Restore the manifest from the
    repository, or remove the staged file to install without Guacamole."
	fi

	expected="$(awk -v f="$name" '$2 == f || $2 == "*" f { print $1; exit }' "$manifest")"
	if [[ -z "$expected" ]]; then
		die "${name} is staged but is not listed in ${manifest}.
    Nothing gets deployed out of this directory unless a reviewed hash says
    what it should be. Stage it with tools/vendor-guacamole.sh, which adds the
    line for you, and commit that line."
	fi

	actual="$(sha512sum "$path" | awk '{print $1}')"
	if [[ "${actual,,}" != "${expected,,}" ]]; then
		die "${name} does not match its hash in ${manifest}.
    manifest  ${expected}
    on disk   ${actual}
    Do not edit the manifest to make this pass."
	fi
	dim "${name} matches SHA512SUMS"
}

# install_file, but it tells us whether it changed anything.
guac_install_file() {
	local src="$1" dst="$2" mode="${3:-0644}" owner="${4:-root:root}"
	if [[ ! -f "$dst" ]] || ! cmp -s "$src" "$dst"; then
		GUAC_CHANGED=1
	fi
	install_file "$src" "$dst" "$mode" "$owner"
}

step_guacamole() {
	step "HTML5 consoles (Guacamole)"

	local war jar
	war="$(guac_war_path)"
	jar="$(guac_jar_path)"

	# --- the guard ------------------------------------------------------
	# Mirrors how the database step handles a missing schema dump: say exactly
	# what is missing and exactly how to get it, then carry on.
	if [[ ! -f "$war" || ! -f "$jar" ]]; then
		skip "HTML5 consoles: the Guacamole artefacts are not staged
             Looked for: $(basename "$war")
                         $(basename "$jar")
             in:          ${GUACAMOLE_DIR}
             Neither is packaged by Ubuntu — Debian dropped the Guacamole web
             application from the archive years ago — so they are staged out of
             band, the same way install/sql/schema/ stages the database dumps:
                 bash tools/vendor-guacamole.sh ${GUAC_VERSION}
             then re-run:  sudo install/install.sh --only guacamole
             The rest of the install is unaffected. Logins still work; the
             console tabs in the UI will not open."
		return 0
	fi

	guac_verify_artefact "$war"
	guac_verify_artefact "$jar"

	# --- packages -------------------------------------------------------
	# apt lists first: apt_available answers from the cache, and an empty cache
	# would make every package look absent and skip a step that would have
	# worked. apt_install would refresh them a moment later anyway.
	apt_update_if_needed

	local -a unavailable
	mapfile -t unavailable < <(guac_unavailable_packages)
	if (( ${#unavailable[@]} )); then
		skip "HTML5 consoles: this host's archives do not carry ${unavailable[*]}
             The artefacts ARE staged, so this is not a staging problem: the
             packages the console service is built out of are not published for
             this release. guacamole-server (guacd and the libguac-client-*
             protocol libraries) is in Ubuntu ${SUPPORTED_RELEASE:-24.04} and
             was not carried forward past it; see docs/PLATFORM-SUPPORT.md.
             Options, in the order they cost you least:
               - install on Ubuntu ${SUPPORTED_RELEASE:-24.04}, which is the
                 verified platform;
               - build guacamole-server from source, matching guacd's version to
                 the staged web application, then re-run:
                     sudo install/install.sh --only guacamole
             The rest of the install is unaffected. Logins still work; the
             console tabs in the UI will not open."
		return 0
	fi

	local -a pkgs
	mapfile -t pkgs < <(guacamole_packages)
	apt_install "${pkgs[@]}"

	# guacd's Ubuntu packaging already is what this needs, with no
	# configuration: /etc/default/guacd sets LISTEN_ADDRESS=127.0.0.1 and
	# LISTEN_PORT=4822, which are Guacamole's own defaults for guacd-hostname
	# and guacd-port. That is why guacamole.properties names neither.
	local p pkg
	for p in $GUAC_PROTOCOLS; do
		pkg="$(guac_client_package "$p" || true)"
		if dpkg_installed "$pkg"; then
			dim "protocol client: ${p} (${pkg})"
		else
			warn "${pkg} is not installed; ${p} consoles will fail
             to connect with an unhelpful error rather than a missing-package one."
		fi
	done

	getent group jetty >/dev/null ||
		die "the jetty group does not exist after installing jetty9;
    guacamole.properties is installed root:jetty and cannot be written."

	# --- GUACAMOLE_HOME -------------------------------------------------
	ensure_dir "$GUAC_HOME"                root:root 0755
	ensure_dir "${GUAC_HOME}/extensions"   root:root 0755
	ensure_dir "${GUAC_HOME}/lib"          root:root 0755

	# Exactly one JDBC auth extension. Two versions of it in the same directory
	# is a startup failure, and an old one left behind after a version change is
	# the most likely way to get one.
	local stale
	for stale in "${GUAC_HOME}"/extensions/guacamole-auth-jdbc-mysql-*.jar; do
		[[ -e "$stale" ]] || continue
		if [[ "$(basename "$stale")" != "$(guac_jar_name)" ]]; then
			run rm -f "$stale"
			GUAC_CHANGED=1
			info "removed a superseded auth extension: $(basename "$stale")"
		fi
	done
	guac_install_file "$jar" "${GUAC_HOME}/extensions/$(guac_jar_name)" 0644 root:root

	# A symlink, not a copy: the driver is a packaged dependency and apt's
	# security updates should take effect without re-running this installer.
	[[ -e "$MARIADB_JDBC" ]] ||
		die "${MARIADB_JDBC} is missing after installing libmariadb-java"
	if [[ "$(readlink -f "${GUAC_HOME}/lib/mariadb-java-client.jar" 2>/dev/null)" != \
	      "$(readlink -f "$MARIADB_JDBC")" ]]; then
		run ln -sfn "$MARIADB_JDBC" "${GUAC_HOME}/lib/mariadb-java-client.jar"
		GUAC_CHANGED=1
	else
		dim "unchanged ${GUAC_HOME}/lib/mariadb-java-client.jar"
	fi

	# --- guacamole.properties -------------------------------------------
	# 0640 root:jetty. It carries the guacdb password and the appliance shipped
	# it 0755, which handed the credential to every account on the box.
	local rendered
	rendered="$(render_template "${SRC_DIR}/install/guacamole/guacamole.properties.in" \
		"GUAC_DB=${GUAC_DB}" \
		"GUAC_DB_USER=${GUAC_DB_USER}" \
		"GUAC_DB_PASS=${GUAC_DB_PASS}")"
	guac_install_file "$rendered" "${GUAC_HOME}/guacamole.properties" 0640 root:jetty
	rm -f "$rendered"

	# --- the web application --------------------------------------------
	[[ -d "$JETTY_WEBAPPS" ]] || die "${JETTY_WEBAPPS} does not exist; jetty9 did not install"
	guac_install_file "$war" "${JETTY_WEBAPPS}/html5.war" 0644 root:root

	# Ubuntu's jetty9 ships webapps/root/ — a directory context, not a war —
	# which serves a Jetty demo page. This server exists to serve /html5 and
	# nothing else, and an unexpected default context on a port that is about to
	# be proxied is a needless surface.
	local d
	for d in "${JETTY_WEBAPPS}/root" "${JETTY_WEBAPPS}/root.war" "${JETTY_WEBAPPS}/ROOT"; do
		if [[ -e "$d" ]]; then
			run rm -rf "$d"
			GUAC_CHANGED=1
			info "removed Jetty's default context ${d}"
		fi
	done

	# --- Jetty configuration --------------------------------------------
	ensure_dir "$JETTY_DROPIN_DIR" root:root 0755
	# Tested before the copy, not by watching GUAC_CHANGED: that flag is
	# monotonic, so by this point it may already be 1 for an unrelated reason
	# and a daemon-reload would be skipped exactly when it was needed.
	local dropin_changed=0
	cmp -s "${SRC_DIR}/install/systemd/jetty9-pnetlab.conf" \
	       "${JETTY_DROPIN_DIR}/pnetlab.conf" 2>/dev/null || dropin_changed=1
	guac_install_file "${SRC_DIR}/install/systemd/jetty9-pnetlab.conf" \
		"${JETTY_DROPIN_DIR}/pnetlab.conf" 0644 root:root

	ensure_dir "$JETTY_START_D" root:root 0755
	guac_install_file "${SRC_DIR}/install/guacamole/jetty-http-loopback.ini" \
		"${JETTY_START_D}/10-pnetlab-loopback.ini" 0644 root:root

	if have systemctl; then
		if [[ $dropin_changed -eq 1 ]]; then
			run systemctl daemon-reload
		fi

		# guacd first: Jetty connects to it lazily, per console, so the order
		# does not strictly matter, but a guacd that failed to start is much
		# easier to see here than three checks later.
		if ! systemctl is-enabled --quiet guacd 2>/dev/null; then
			run systemctl enable guacd
		fi
		if ! systemctl is-active --quiet guacd; then
			run systemctl start guacd
		else
			dim "guacd is running"
		fi

		if ! systemctl is-enabled --quiet jetty9 2>/dev/null; then
			run systemctl enable jetty9
		fi
		if [[ $GUAC_CHANGED -eq 1 ]] || ! systemctl is-active --quiet jetty9; then
			run systemctl restart jetty9
			guac_wait_for_deploy
		else
			dim "jetty9 is running and nothing changed; not restarting"
		fi
	fi

	guac_report
}

# Jetty expands a 17 MB war on first deployment and the JDBC extension opens a
# database connection while doing it. On a cold VM that is comfortably more than
# the couple of seconds a naive check would allow, and the verify step would
# report a failure that fixes itself thirty seconds later.
guac_wait_for_deploy() {
	local i
	info "waiting for Jetty to deploy /html5 (up to 90s)"
	for i in $(seq 1 45); do
		if curl -sf -o /dev/null --max-time 4 http://127.0.0.1:8080/html5/ 2>/dev/null; then
			ok "the console web application is answering on 127.0.0.1:8080/html5/"
			return 0
		fi
		sleep 2
	done
	warn "Jetty did not serve /html5/ within 90 seconds.
             Look at:  journalctl -u jetty9 -n 100
             The usual causes, in order: guacamole.properties cannot reach
             ${GUAC_DB} (the log says so explicitly), two JDBC auth extensions
             in ${GUAC_HOME}/extensions, or a war/extension version mismatch."
}

guac_report() {
	local guacd_v jetty_v
	guacd_v="$(dpkg-query -W -f='${Version}' guacd 2>/dev/null || echo '?')"
	jetty_v="$(dpkg-query -W -f='${Version}' jetty9 2>/dev/null || echo '?')"
	ok "guacd ${guacd_v}, jetty9 ${jetty_v}, Guacamole ${GUAC_VERSION} at /html5"

	note "HTML5 consoles are served by Jetty on 127.0.0.1:8080 and proxied by
             Apache at /html5/. Two pieces of standing debt worth knowing about:
             jetty9 is 9.4.53 and Jetty 9.4 is EOL upstream — it is loopback-only
             behind Apache, which is what contains it — and guacd is 1.3.0 while
             the web application is ${GUAC_VERSION}. Both are recorded in
             install/lib/guacamole.sh. The escape hatch if Jetty ever becomes
             untenable is libtomcat9-java plus a hand-built CATALINA_BASE;
             tomcat10 is not an option at any Guacamole version."
}
