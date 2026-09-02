# shellcheck shell=bash
#
# install/lib/sudoers.sh — the privilege policy for the web user.
#
# A malformed file in /etc/sudoers.d locks sudo out of the machine entirely, so
# every path through this file validates before it commits and rolls back if the
# resulting configuration does not parse. Nothing here writes into
# /etc/sudoers.d without a preceding `visudo -cf` on the exact bytes, and a
# whole-tree `visudo -c` afterwards.

readonly SUDOERS_TARGET='/etc/sudoers.d/pnetlab'
readonly SUDOERS_UPSTREAM='/etc/sudoers.d/unetlab'

step_sudoers() {
	step "Sudo policy"

	local src="${SRC_DIR}/install/sudoers.d/pnetlab"
	[[ -f "$src" ]] || die "policy file missing: ${src}"
	have visudo || die "visudo not found; refusing to touch /etc/sudoers.d without it"

	# --- 1. validate the candidate in isolation ----------------------------
	local staged
	staged="$(mktemp)"
	chmod 0440 "$staged"
	cat "$src" > "$staged"

	if ! visudo -cf "$staged" >/dev/null; then
		rm -f "$staged"
		die "install/sudoers.d/pnetlab does not parse. Nothing was installed.
    Run 'visudo -cf install/sudoers.d/pnetlab' to see the error."
	fi
	dim "policy parses (visudo -cf)"

	# --- 2. install it -----------------------------------------------------
	local replaced_backup=''
	if [[ -f "$SUDOERS_TARGET" ]] && cmp -s "$staged" "$SUDOERS_TARGET"; then
		dim "unchanged ${SUDOERS_TARGET}"
		rm -f "$staged"
	else
		if [[ -f "$SUDOERS_TARGET" ]]; then
			backup_file "$SUDOERS_TARGET"
			replaced_backup="$LAST_BACKUP"
		fi
		install -o root -g root -m 0440 "$staged" "$SUDOERS_TARGET"
		rm -f "$staged"
		info "installed ${SUDOERS_TARGET} (0440 root:root)"

		# --- 3. validate the whole configuration, not just our file --------
		# visudo -cf on one file cannot see a conflict with /etc/sudoers or
		# another drop-in. If the tree as a whole no longer parses, back the
		# change out immediately: a broken sudoers is unrecoverable without
		# console access.
		if ! visudo -c >/dev/null; then
			rm -f "$SUDOERS_TARGET"
			if [[ -n "$replaced_backup" && -f "$replaced_backup" ]]; then
				install -o root -g root -m 0440 "$replaced_backup" "$SUDOERS_TARGET"
			fi
			die "the sudo configuration as a whole failed to parse after installing
    ${SUDOERS_TARGET}. The file has been removed and any previous version
    restored. Run 'visudo -c' to see which file is at fault."
		fi
		ok "sudo configuration parses with the new policy in place"
	fi

	# --- 4. remove the upstream grant --------------------------------------
	# Upstream's /etc/sudoers.d/unetlab ends in a blanket NOPASSWD:ALL. sudo
	# takes the last match across all files, so leaving it in place makes the
	# new policy decorative.
	if [[ -e "$SUDOERS_UPSTREAM" ]]; then
		backup_file "$SUDOERS_UPSTREAM"
		rm -f "$SUDOERS_UPSTREAM"
		info "removed ${SUDOERS_UPSTREAM} (upstream blanket grant)"
		visudo -c >/dev/null || die "sudo configuration broken after removing ${SUDOERS_UPSTREAM};
    restore it from ${BACKUP_DIR} before logging out."
	else
		dim "no ${SUDOERS_UPSTREAM} present"
	fi

	# --- 5. anything else still granting the web user everything -----------
	check_residual_blanket_grants
}

# Upstream ships the blanket grants across /etc/sudoers *and* the drop-in. This
# installer will not rewrite /etc/sudoers by default — editing the file that
# governs your own ability to fix the machine is not something to do
# unprompted — but it will not stay quiet about it either.
check_residual_blanket_grants() {
	local -a offenders=() candidates=()
	local f
	# sudo ignores drop-ins whose name contains a '.' or ends in '~', so only
	# the files it will actually read are worth warning about.
	mapfile -t candidates < <(find /etc/sudoers.d -maxdepth 1 -type f \
		! -name '*.*' ! -name '*~' 2>/dev/null | sort)
	candidates=(/etc/sudoers "${candidates[@]}")
	for f in "${candidates[@]}"; do
		[[ -r "$f" ]] || continue
		if grep -Eq '^[[:space:]]*(%?www-data|%unl)[[:space:]]+ALL=.*NOPASSWD:[[:space:]]*ALL' "$f"; then
			offenders+=("$f")
		fi
	done

	if [[ ${#offenders[@]} -eq 0 ]]; then
		ok "no blanket NOPASSWD:ALL grant for www-data or %unl remains"
		return 0
	fi

	if [[ "${STRIP_SUDOERS_GRANTS:-0}" != 1 ]]; then
		warn "a blanket sudo grant for www-data or %unl is still present in:
             ${offenders[*]}
             The new policy is an allowlist and sudo takes the LAST match, so
             this makes it decorative. Re-run with --strip-sudoers-grants, or
             comment those lines out by hand with visudo."
		return 0
	fi

	for f in "${offenders[@]}"; do
		strip_blanket_grants_from "$f"
	done
}

# Comment out the blanket grants in one file, validating before and after and
# restoring the original on any failure.
strip_blanket_grants_from() {
	local f="$1" tmp restore
	tmp="$(mktemp)"
	restore="$(mktemp)"
	cp -a "$f" "$restore"

	# '@' as the delimiter: the pattern needs '|' for alternation and contains
	# '%', and the replacement contains '#'. None of them contain '@'.
	sed -E 's@^([[:space:]]*(%?www-data|%unl)[[:space:]]+ALL=.*NOPASSWD:[[:space:]]*ALL.*)$@# disabled by the PNETLab installer: \1@' \
		"$f" > "$tmp"

	chmod 0440 "$tmp"
	if ! visudo -cf "$tmp" >/dev/null; then
		rm -f "$tmp" "$restore"
		warn "could not safely strip blanket grants from ${f}: the edited file does
             not parse. ${f} is unchanged. Edit it by hand with visudo."
		return 0
	fi

	backup_file "$f"
	cat "$tmp" > "$f"
	chown root:root "$f"
	chmod 0440 "$f"
	rm -f "$tmp"

	if ! visudo -c >/dev/null; then
		cat "$restore" > "$f"
		rm -f "$restore"
		die "sudo configuration broke while editing ${f}; the original has been
    restored. Do not log out until 'visudo -c' passes."
	fi
	rm -f "$restore"
	info "commented out blanket grants in ${f}"
}
