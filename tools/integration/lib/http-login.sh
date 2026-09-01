# shellcheck shell=bash
#
# Logging in to the Laravel layer, now that it verifies CSRF tokens.
#
# VerifyCsrfToken is enabled in store/app/Http/Kernel.php, so
# POST /auth/login/login needs an X-XSRF-TOKEN header whose value decrypts to
# the session's token. In a browser axios does this without being asked: it
# reads the XSRF-TOKEN cookie the middleware sets and copies it into the header
# on same-origin requests. curl does not, so these suites have to do it by hand.
#
# A bare `curl -X POST .../auth/login/login` now returns 419. That is the
# middleware working, not a broken login -- if you are reading this because the
# authentication section started failing, that is why.
#
# Usage:
#     source "$(dirname "$0")/lib/http-login.sh"
#     csrf_session_start "$B"          # sets CSRF_JAR and CSRF_TOKEN
#     csrf_post "$B/auth/login/login" --data-urlencode 'username=admin' ...
#
# The jar it creates also carries the `token` cookie after a successful login,
# so the legacy /api/* calls can keep using -b "token=$TOK" exactly as before.

csrf_refresh() {
	CSRF_TOKEN=$(python3 - "$CSRF_JAR" <<'PY'
import sys, urllib.parse
for line in open(sys.argv[1]):
    f = line.rstrip('\n').split('\t')
    # Netscape cookie jar: domain, flag, path, secure, expires, name, value.
    if len(f) >= 7 and f[5] == 'XSRF-TOKEN':
        print(urllib.parse.unquote(f[6]))
PY
)
}

csrf_session_start() {
	local base="$1" i
	CSRF_JAR="$(mktemp)"
	CSRF_TOKEN=''
	# The first request only establishes the session. The XSRF-TOKEN cookie
	# comes back on a request made *with* that session cookie, so fetch the
	# login page twice; looping rather than hardcoding two, because getting an
	# empty token here would show up as an unexplained 419 later.
	for i in 1 2 3; do
		curl -s -m 25 -b "$CSRF_JAR" -c "$CSRF_JAR" -o /dev/null "$base/auth/login/offline"
		csrf_refresh
		[ -n "$CSRF_TOKEN" ] && return 0
	done
	echo "  warning: no XSRF-TOKEN cookie from $base/auth/login/offline;" >&2
	echo "           every POST below will 419. Is the app serving?" >&2
	return 1
}

# POST through the session, with the token attached.
csrf_post() {
	local url="$1"; shift
	curl -s -m 25 -b "$CSRF_JAR" -c "$CSRF_JAR" -H "X-XSRF-TOKEN: $CSRF_TOKEN" -X POST "$url" "$@"
}
