# shellcheck shell=bash
#
# install/lib/database.sh — MariaDB, the two schemas, and the offline seed.
#
# Two things about this step are worth reading before changing it.
#
# 1. The schema is not in this repository. It shipped inside the PNETLab
#    appliance image and was never published. This installer therefore creates
#    the databases and the users, and then either imports a dump you point it
#    at or tells you plainly that the tables are missing. It does not invent a
#    schema, and it does not pretend a tableless database is a working install.
#
# 2. Administration goes through the unix_socket root account (`sudo mysql`),
#    which is how MariaDB is configured out of the box on Ubuntu. It
#    deliberately does NOT set a root password of 'pnetlab'. The appliance did,
#    which handed database access to every local account on the box. See the
#    note about MysqlRecovery below.

# Resolved once, in step_database. Picking the binary per call would consume
# stdin on the first attempt and hand the fallback an empty redirect.
MYSQL_BIN=''

resolve_mysql_client() {
	local c
	for c in mariadb mysql; do
		if have "$c"; then MYSQL_BIN="$c"; return 0; fi
	done
	return 1
}

mysql_root() {
	"$MYSQL_BIN" --protocol=socket -uroot "$@"
}

# Run one statement as root, discarding output.
db_exec() {
	printf '%s\n' "$1" | mysql_root >/dev/null
}

# Run one query as root, returning a bare value.
db_query() {
	printf '%s\n' "$1" | mysql_root -N -B
}

db_exists() {
	local n; n="$(db_query "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$1';")"
	[[ "$n" == 1 ]]
}

db_table_count() {
	db_query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$1';"
}

db_has_table() {
	local n; n="$(db_query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$1' AND TABLE_NAME='$2';")"
	[[ "$n" == 1 ]]
}

# ensure_db_user <user> <password> <database>
# Grants on both 'localhost' and '127.0.0.1': checkDatabase() connects to
# host=localhost (a unix socket, matching 'localhost') and
# html5_checkDatabase() to host=127.0.0.1 (TCP, matching '127.0.0.1'). Both
# exist in the application, so both must exist here.
ensure_db_user() {
	local user="$1" pass="$2" db="$3" host
	for host in localhost 127.0.0.1; do
		db_exec "CREATE USER IF NOT EXISTS '${user}'@'${host}' IDENTIFIED BY '${pass}';"
		db_exec "ALTER USER '${user}'@'${host}' IDENTIFIED BY '${pass}';"
		db_exec "GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${user}'@'${host}';"
	done
	db_exec "FLUSH PRIVILEGES;"
	info "user ${user} can reach ${db} from localhost and 127.0.0.1"
}

# import_schema <database> <file>
import_schema() {
	local db="$1" file="$2"
	info "importing $(basename "$file") into ${db}"
	if ! mysql_root "$db" < "$file"; then
		die "schema import failed for ${db} from ${file}"
	fi
	ok "${db}: $(db_table_count "$db") tables"
}

# Look for a dump for <database> in the schema directory.
find_schema_file() {
	local db="$1" f
	for f in "${SCHEMA_DIR}/${db}.sql" "${SCHEMA_DIR}/${db}.sql.gz"; do
		if [[ -f "$f" ]]; then printf '%s\n' "$f"; return 0; fi
	done
	return 1
}

setup_one_database() {
	local db="$1" user="$2" pass="$3" expected_tables="$4"

	if db_exists "$db"; then
		dim "database ${db} exists"
	else
		db_exec "CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
		info "created database ${db}"
	fi
	ensure_db_user "$user" "$pass" "$db"

	local count; count="$(db_table_count "$db")"
	if [[ "$count" -gt 0 ]]; then
		dim "${db} already has ${count} tables; not importing (expected ~${expected_tables})"
		return 0
	fi

	local file=''
	if file="$(find_schema_file "$db")"; then
		if [[ "$file" == *.gz ]]; then
			local tmp; tmp="$(mktemp)"
			gunzip -c "$file" > "$tmp"
			import_schema "$db" "$tmp"
			rm -f "$tmp"
		else
			import_schema "$db" "$file"
		fi
	else
		warn "${db} is EMPTY and no schema dump was found.
             Looked in: ${SCHEMA_DIR} (for ${db}.sql or ${db}.sql.gz)
             The schema is not in this repository — it ships inside the PNETLab
             appliance image. Export it from an appliance with
                 mysqldump --databases ${db} > ${db}.sql
             put the file in ${SCHEMA_DIR}, and re-run with --only database.
             Until then the application will connect and fail on every query."
	fi
}

step_database() {
	step "Databases"

	resolve_mysql_client ||
		die "no mariadb/mysql client found; the packages step should have installed one"
	dim "using the ${MYSQL_BIN} client"

	# MariaDB must be up before anything else here means anything.
	if have systemctl; then
		if ! systemctl is-active --quiet mariadb; then
			run systemctl enable --now mariadb
		else
			dim "mariadb is running"
		fi
	fi

	if ! db_query 'SELECT 1;' >/dev/null 2>&1; then
		die "cannot connect to MariaDB as root over the unix socket.
    On a stock Ubuntu install 'sudo mysql' works out of the box. If this host
    has a root password set, the installer cannot proceed without a code
    change — it deliberately does not prompt for or store one."
	fi

	setup_one_database "$APP_DB"  "$APP_DB_USER"  "$APP_DB_PASS"  11
	setup_one_database "$GUAC_DB" "$GUAC_DB_USER" "$GUAC_DB_PASS" 23

	seed_offline_login

	# The appliance set the MariaDB root password to 'pnetlab' and the Laravel
	# recovery command still assumes it. Say so rather than quietly leaving a
	# broken command in the tree.
	note "MariaDB root is unix_socket-authenticated (no password), which is the
             Ubuntu default and better than what the appliance shipped.
             store/app/Console/Commands/MysqlRecovery.php still shells out to
             'mysql -uroot -ppnetlab' and will not work against this host. That
             command is Laravel-side and does not run today anyway."
}

# The minimum rows for an offline login, from docs/REFERENCE-ENVIRONMENT.md.
#
# The control rows are configuration and are applied on every run: they are the
# switch that keeps this install offline, and drift there is a bug.
#
# The admin user is not. Re-running an installer must not silently reset the
# administrator password on a system that has been in use, so the seed only
# runs when there is no admin user, or when --reset-admin says otherwise.
seed_offline_login() {
	if ! db_has_table "$APP_DB" control || ! db_has_table "$APP_DB" users; then
		skip "offline seed: ${APP_DB} has no control/users tables yet (schema not imported)"
		return 0
	fi

	mysql_root "$APP_DB" < "${SRC_DIR}/install/sql/seed-control.sql" ||
		die "failed to apply install/sql/seed-control.sql"
	ok "control rows set: offline mode on, online mode off"

	local admins; admins="$(db_query "SELECT COUNT(*) FROM \`${APP_DB}\`.users WHERE username='admin';")"
	if [[ "$admins" != 0 && "${RESET_ADMIN:-0}" != 1 ]]; then
		dim "admin user already exists; not touching it (--reset-admin to overwrite)"
		return 0
	fi

	mysql_root "$APP_DB" < "${SRC_DIR}/install/sql/seed-admin.sql" ||
		die "failed to apply install/sql/seed-admin.sql"

	warn "seeded the default administrator: admin / pnet
             CHANGE IT BEFORE THIS HOST IS REACHABLE BY ANYONE ELSE.
             The password is stored as an unsalted SHA-256 digest because that
             is what the application currently computes. That is a defect being
             fixed, not a design."
}
