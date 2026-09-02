<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/includes/cli.php
 *
 * Various functions for UNetLab CLI handler.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

/**
 * Validate a Linux network-interface name.
 *
 * The kernel allows 1-15 characters (IFNAMSIZ is 16 including the terminator)
 * and forbids '/' and whitespace. The class below is stricter still, and every
 * name this application generates satisfies it: vnet{tenant}_{id} for bridges,
 * vunl{session}_{interface} for taps, plus pnet0-9, nat0 and docker0.
 *
 * This is an allowlist, and it is the control. secureCmd() is a blocklist with
 * verified bypasses — backtick, $( ), newline and redirect all pass it — so it
 * cannot be relied on. Call sites additionally pass every value through
 * escapeshellarg(), so a name reaches the shell as one literal argument even if
 * this check is ever bypassed.
 *
 * @param   string  $name               Candidate interface name
 * @return  bool                        True if the name is safe to use
 */
function unl_valid_ifname($name)
{
	// \z, not $. Without /D, PCRE's `$` also matches immediately before a
	// trailing newline, so '/...$/ ' accepted "vnet1_1\n" — a name that passes
	// the validator and is then two words to anything that reads it line by
	// line. The same trap is recorded in platform/wrappers/actions/.
	return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,14}\z/', $name) === 1;
}

/**
 * Write one bridge sysfs tunable.
 *
 * This replaces three `sudo echo N > /sys/.../bridge/<knob>` calls. sudo
 * applied to echo; the REDIRECTION ran in the calling shell as whoever that
 * was, so the write was never privileged at all. They worked only because
 * addBridge() is reached from inside unl_wrapper, which is already root.
 *
 * Both halves are closed: the interface name goes through unl_valid_ifname()
 * and the knob has to be one of three literals, so no caller can name a file
 * even if one day a caller gets to choose part of the path.
 *
 * @return int 0 on success, 1 on refusal or failure — the shape the call sites
 *             already test, since they used to read an exit status.
 */
function unl_write_sysfs($path, $value)
{
	$knobs = array('group_fwd_mask', 'multicast_snooping', 'multicast_router');
	if (!is_string($path) || !preg_match('#^/sys/(?:class|devices/virtual)/net/([^/]+)/bridge/([a-z_]+)\z#', $path, $m)) {
		return 1;
	}
	if (!unl_valid_ifname($m[1]) || !in_array($m[2], $knobs, true)) return 1;
	if (!is_string($value) || !preg_match('/^[0-9]{1,10}\z/', $value)) return 1;
	if (is_link($path) || !is_file($path)) return 1;
	return @file_put_contents($path, $value) === false ? 1 : 0;
}

/**
 * The memory-deduplication control: mainline KSM, not UKSM.
 *
 * WHAT THIS ACTUALLY CONTROLS, measured on the reference host (6.8.0-138,
 * QEMU 8.2.2), because the honest answer is narrower than the button suggests.
 *
 * The kernel scans a mapping only if the owning process has asked it to with
 * madvise(MADV_MERGEABLE); ksmd never touches anything else, and neither the
 * `smart_scan` heuristic nor the `advisor_mode` scan-time governor changes
 * that — they tune how hard ksmd works on the set it already has, not what is
 * in the set. QEMU asks: `mem-merge` defaults to on, so every guest RAM block
 * is advised at startup. Measured directly — a 512 MB guest's RAM mapping
 * carries the `mg` VmFlag in /proc/<pid>/smaps with no configuration at all.
 *
 * So for QEMU nodes this toggle is the whole control, and it works: three
 * CirrOS guests at 512 MB each, ksm/run 0 -> 1, reached pages_sharing 22900 /
 * pages_shared 9111 within one full scan — about 89 MB of guest RAM collapsed,
 * general_profit 85,590,848 bytes.
 *
 * What it does NOT do, and what no amount of sysfs will make it do:
 *
 *   - VPCS, dynamips and IOL processes never call madvise(MADV_MERGEABLE), so
 *     none of their memory is scanned however this reads. A lab of VPCS nodes
 *     gets nothing from turning this on.
 *   - Docker-backed nodes are the container's own processes; same rule.
 *   - A template whose qemu_options carry `-machine ...,mem-merge=off` opts
 *     that node out, and this toggle cannot override it.
 *
 * A template could be opted in wholesale with prctl(PR_SET_MEMORY_MERGE) — 6.4
 * and later, per process, no madvise needed. That is a real option for the
 * emulators above and is deliberately NOT done here: it would be a per-node
 * memory-behaviour change made from a host-wide button.
 *
 * ON THE THREE VALUES. run is 0 stop, 1 run, 2 stop-and-unmerge, and it reads
 * back what was written, so 2 is a state and not an edge. 'off' writes 0, not
 * 2, deliberately: 2 unmerges immediately, and unmerging N shared pages needs N
 * free pages *now* — on the dense host that is the only reason to have had KSM
 * on, that is where the OOM killer lives. 0 stops the scanner and leaves the
 * existing merges in place to break up under ordinary copy-on-write. Both 0 and
 * 2 therefore report 'disabled', which is true of both: nothing is being
 * deduplicated. Verified end to end: writing 2 took pages_sharing 22900 -> 0.
 */
define('UNL_KSM_RUN', '/sys/kernel/mm/ksm/run');

/**
 * Read the memory-dedup state.
 *
 * @return  string                      'enabled', 'disabled' or 'unsupported'
 */
function unl_ksm_state()
{
	// Not exec('cat ...'). The old readers spawned a shell per status poll and
	// the status page polls; more to the point, `cat` of a missing path is a
	// non-zero exit that reads identically to a permissions problem, and this
	// has to tell "no KSM in this kernel" apart from "cannot read it".
	if (!is_file(UNL_KSM_RUN)) return 'unsupported';
	$v = @file_get_contents(UNL_KSM_RUN);
	if ($v === false) return 'unsupported';
	return trim($v) === '1' ? 'enabled' : 'disabled';
}

/**
 * Turn memory dedup on or off.
 *
 * Root only — the sysfs file is 0644 root:root. Every caller reaches this from
 * inside unl_wrapper, which refuses to run as anything else.
 *
 * @param   bool    $on                 True to scan, false to stop scanning
 * @return  int                         0 on success, 13 on failure
 */
function unl_ksm_set($on)
{
	$want = $on ? '1' : '0';

	if (unl_ksm_state() === 'unsupported') {
		error_log(date('M d H:i:s ') . 'ERROR: ' . UNL_KSM_RUN . ' does not exist; '
			. 'this kernel has no KSM (CONFIG_KSM). Nothing to enable.');
		return 13;
	}

	// No shell. What was here was `exec('echo 1 > /sys/...')`, which worked only
	// because unl_wrapper is root, and which reported the *shell's* status.
	if (@file_put_contents(UNL_KSM_RUN, $want) === false) {
		error_log(date('M d H:i:s ') . 'ERROR: cannot write ' . UNL_KSM_RUN);
		return 13;
	}

	// Read it back. sysfs store handlers reject out-of-range values by returning
	// -EINVAL from write(2), and a short write is not an exception here, so the
	// only trustworthy confirmation is the value the kernel now reports.
	$got = trim((string) @file_get_contents(UNL_KSM_RUN));
	if ($got !== $want) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . UNL_KSM_RUN . ' reads ' . var_export($got, true)
			. ' after writing ' . $want);
		return 13;
	}

	return 0;
}

/**
 * Function to create a bridge
 *
 * @param   string  $s                  Bridge name
 * @return  int                         0 means ok
 */
function addBridge($s)
{

	if (!unl_valid_ifname($s['name'])) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80099] . ' ' . $s['name']);
		return 80099;
	}
	$esc = escapeshellarg($s['name']);

	if (!isBridge($s['name']) || !isInterface($s['name'])) {
		// Bridge already present
		error_log(date('M d H:i:s ') . 'INFO: Add network bridge - bridge present ' . $s['name']);
		$cmd = 'brctl addbr ' . $esc . ' 2>&1';
		error_log(date('M d H:i:s ') . 'INFO: create bridge  ' . $cmd);
		exec($cmd, $o, $rc);
		if ($rc != 0) {
			// Failed to add the bridge
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80026]);
			error_log(date('M d H:i:s ') . implode("\n", $o));
			return 80026;
		}

		$cmd = 'sysctl -w ' . escapeshellarg('net.ipv6.conf.' . $s['name'] . '.disable_ipv6=1');
		error_log(date('M d H:i:s ') . 'INFO: ' . $cmd);
		exec($cmd, $o, $rc);
		if ($rc != 0) {
			// Failed to disable IPV6
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80089]);
			error_log(date('M d H:i:s ') . implode("\n", $o));
			return 80089;
		}
	}

	$cmd = 'ip link set dev ' . $esc . ' up 2>&1';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		// Failed to activate it
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80027]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80027;
	}

	if (!preg_match('/^pnet[\d\w]+$/', $s['name'])) {
		// Forward all frames on non-cloud bridges
		// 0xFFF8, not 0xFFFF. The kernel refuses to forward the three reserved
		// group addresses (STP, MAC pause, LACP — BR_GROUPFWD_RESTRICTED = 0x0007)
		// and returns EINVAL for any value that includes them, so writing 65535
		// fails outright. Measured on kernel 6.8: 65535 -> EINVAL, 65528 -> ok.
		// The shipped 4.15 appliance shows 0x8 on its bridges, so the 65535 write
		// never took effect there either.
		// file_put_contents(), not `sudo echo N > path`. sudo applied to echo
		// while the REDIRECTION ran in the calling shell as whoever that was,
		// so the write was never privileged: this worked only because
		// addBridge() is reached from inside unl_wrapper, which is already
		// root. Anyone "fixing" it by moving the redirect under sudo — a tee,
		// say — would be adding a genuine arbitrary-write primitive where
		// there was a silent no-op. It is one sysfs write, so it is written.
		$rc = unl_write_sysfs('/sys/class/net/' . $s['name'] . '/bridge/group_fwd_mask', '65528');
		if ($rc != 0) {
			// Failed to configure forward mask
			error_log(date('M d H:i:s ') . 'ERROR: group_fwd_mask --- ' . $GLOBALS['messages'][80028]);
			return 80028;
		}

		// Disable multicast_snooping
		$rc = unl_write_sysfs('/sys/devices/virtual/net/' . $s['name'] . '/bridge/multicast_snooping', '0');
		if ($rc != 0) {
			// Failed to configure multicast_snooping
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80071]);
			return 80071;
		}
	}

	if ($s['count'] == 2) {

		$cmd = 'brctl setageing ' . $esc . ' 0 2>&1';
		exec($cmd, $o, $rc);
		if ($rc != 0) {
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80055]);
			error_log(date('M d H:i:s ') . implode("\n", $o));
			return 80055;
		}
		
		$rc = unl_write_sysfs('/sys/class/net/' . $s['name'] . '/bridge/multicast_router', '2');
		if ($rc != 0) {
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80055]);
			return 80055;
		}
	}

	return 0;
}

/**
 * Function to stop a node.
 *
 * @param   Array   $p                  Parameters
 * @return  int                         0 means ok
 */
function addNetwork($p)
{
	if (!isset($p['name']) || !isset($p['type'])) {
		// Missing mandatory parameters
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80021]);
		return 80021;
	}

	switch ($p['type']) {
		default:
			if (in_array($p['type'], listClouds())) {
				// Cloud already exists
			} else if (preg_match('/^pnet[\d\w]+$/', $p['type'])) {
				// Cloud does not exist
				error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80056]);
				return 80056;
			} else {
				// Should not be here
				error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80020]);
				return 80020;
			}
			break;
		case 'bridge':
			error_log(date('M d H:i:s ') . 'INFO: Add network bridge ' . $p['name']);
			if (isOvs($p['name'])) {
				// OVS exists -> delete it and add bridge
				$rc = delOvs($p['name']);
				if ($rc != 0) {
					return $rc;
				}
			} 
			// OVS deleted, create the bridge
			return addBridge($p);
			break;
		case 'ovs':
			if (!isInterface($p['name'])) {
				// Interface does not exist -> create OVS
				return addOvs($p['name']);
			} else if (isOvs($p['name'])) {
				// OVS already present
				return 0;
			} else if (isBridge($p['name'])) {
				// Bridge exists -> delete it and add OVS
				$rc = delBridge($p['name']);
				if ($rc == 0) {
					// Bridge deleted, create the OVS
					return addOvs($p['name']);
				} else {
					// Failed to delete Bridge
					return $rc;
				}
			} else {
				// Non bridge/OVS interface exist -> cannot create
				error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80022]);
				return 80022;
			}
			break;
	}
	return 0;
}

/*
 * Function to create an OVS
 *
 * @param   string  $s                  OVS name
 * @return  int                         0 means ok
 */
function addOvs($s)
{
	$s = secureCmd($s);
	$cmd = 'ovs-vsctl add-br ' . escapeshellarg($s) . ' 2>&1';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		// Failed to add the OVS
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80023]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80023;
	}
	// ADD BPDU CDP option
	$cmd = "ovs-vsctl set bridge " . escapeshellarg($s) . " other-config:forward-bpdu=true";
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		return 0;
	} else {
		// Failed to add  OVS OPTION
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80023]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80023;
	}
}

/**
 * Function to create a TAP interface
 *
 * @param   string  $s                  Network name
 * @return  int                         0 means ok
 */
function addTap($s, $u)
{
	$s = secureCmd($s);
	$u = secureCmd($u);
	// TODO if already exist should fail?
	//
	// -g unl, NOT -g root. This one word decides whether an emulator can run as
	// its own tenant, and the kernel's rule is not the obvious one:
	//
	//     tun_not_capable() = ((owner set && euid != owner)
	//                       || (group set && !in_egroup_p(group))) && !CAP_NET_ADMIN
	//
	// so being the tap's OWNER is not sufficient — both clauses have to be
	// satisfied. With -g root, unl<N> owns the tap and still cannot open it,
	// because it is not in group root, and the failure is a bare EPERM from
	// TUNSETIFF with no diagnostic anywhere. The node starts, its console works,
	// and no frame ever moves. That is what it looked like here.
	//
	// It does NOT widen access to other tenants. Measured on the reference host:
	// with -g unl, the owning tenant opens the tap; a different unl account, in
	// the same group, is still refused, because the owner clause binds it.
	// Root is unaffected either way — it has CAP_NET_ADMIN.
	$cmd = 'sudo tunctl -u ' . escapeshellarg($u) . ' -g unl -t ' . escapeshellarg($s) . ' 2>&1';
	error_log(date('M d H:i:s ') . 'INFO: ' . $cmd);
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		// Failed to add the TAP interface
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80032]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80032;
	}

	$cmd = 'sudo sysctl -w ' . escapeshellarg('net.ipv6.conf.' . $s . '.disable_ipv6=1');
	error_log(date('M d H:i:s ') . 'INFO: ' . $cmd);
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		// Failed to disable IPV6 
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80089]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80089;
	}

	$cmd = 'sudo ip link set dev ' . escapeshellarg($s) . ' up 2>&1';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		// Failed to activate the TAP interface
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80033]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80033;
	}

	$cmd = 'sudo ip link set dev ' . escapeshellarg($s) . ' mtu 9000';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		// Failed to activate the TAP interface
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80085]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80085;
	}

	return 0;
}

/**
 * Function to check if a tenant has a valid username.
 *
 * @param   int     $i                  Tenant ID
 * @return  bool                        True if valid
 */
function checkUsername($i)
{
	$i = secureCmd($i);
	if ((int) $i < 0) {
		// Tenand ID is not valid
		return False;
	} else {
		// Just to be sure
		$i = (int) $i;
	}

	if (!is_dir('/opt/unetlab/users')) {
		$cmd = 'mkdir /opt/unetlab/users > /dev/null 2>&1';
		exec($cmd, $o, $rc);
		$cmd = '/bin/chown -R root:unl /opt/unetlab/users > /dev/null 2>&1';
		exec($cmd, $o, $rc);
		$cmd = '/bin/chmod -R 2775 /opt/unetlab/users > /dev/null 2>&1';
		exec($cmd, $o, $rc);
	}

	$path = '/opt/unetlab/users/' . $i;

	// Creating the account is UnlTenantAccount::create(), which is also where
	// reaping it lives. Keeping the two in one file is the point: the name, the
	// uid and the group have to agree between them, and when they did not the
	// account outlived the session id that named it and the next session handed
	// that id inherited the previous tenant's home directory.
	//
	// It also runs useradd DIRECTLY, with no sudo. checkUsername() is reached
	// only from a device's prepare(), which is reached only from
	// `unl_wrapper -a start` — already root — so the `sudo` that used to be on
	// that call site was root running sudo to become root. Removing it retired
	// /usr/sbin/useradd from install/sudoers.d/pnetlab, and
	// tests/Security/SudoersPolicyTest.php will fail if either half comes back
	// alone.
	$accountAction = __DIR__ . '/../platform/wrappers/actions/UnlTenantAccount.php';
	if (!is_file($accountAction)) {
		$accountAction = '/opt/unetlab/wrappers/actions/UnlTenantAccount.php';
	}
	require_once($accountAction);

	$account = new UnlTenantAccount();
	$accountResult = $account->create($i);
	if (!$accountResult['ok']) {
		// Failed to add the username
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80009]);
		error_log(date('M d H:i:s ') . 'ERROR: ' . $accountResult['error']);
		return False;
	}

	// Now check if the home directory exists
	if (!is_dir($path) && !mkdir($path, 2755, true)) {
		// Failed to create the home directory
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80010]);
		return False;
	}

	// The "be sure of the setgid bit" check that used to be here tested $rc,
	// which by then held the exit status of `id unl<N>` and had nothing to do
	// with any setgid bit — the chmod it was written for is not in this
	// function. With the `id` call gone it would have been reading an undefined
	// variable, so it is deleted rather than given a fresh value to ignore.

	// Set permissions
	if (!chown($path, 'unl' . $i)) {
		// Failed to set owner and/or group
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80012]);
		return False;
	}

	// Last, link the profile
	if (!file_exists($path . '/.profile') && !symlink('/opt/unetlab/wrappers/unl_profile', $path . '/.profile')) {
		// Failed to link the profile
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80013]);
		return False;
	}

	return True;
}

/**
 * Function to connect an interface (TAP) to a network (Bridge/OVS)
 *
 * @param   string  $n                  Network name
 * @param   string  $p                  Interface name
 * @return  int                         0 means ok
 */
function connectInterface($n, $p)
{
	$n = secureCmd($n);
	$p = secureCmd($p);
	if (isBridge($n)) {
		$cmd = 'sudo brctl addif ' . escapeshellarg($n) . ' ' . escapeshellarg($p) . ' 2>&1';
		error_log(date('M d H:i:s ') . $cmd);
		exec($cmd, $o, $rc);
		if ($rc == 0) {
			return 0;
		} else {
			// Failed to add interface to Bridge
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80030]);
			error_log(date('M d H:i:s ') . implode("\n", $o));
			return 80030;
		}
	} else if (isOvs($n)) {
		$cmd = 'sudo ovs-vsctl add-port ' . escapeshellarg($n) . ' ' . escapeshellarg($p) . ' 2>&1';
		exec($cmd, $o, $rc);
		if ($rc == 0) {
			return 0;
		} else {
			// Failed to add interface to OVS
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80031]);
			error_log(date('M d H:i:s ') . implode("\n", $o));
			return 80031;
		}
	} else {
		// Network not found
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80029]);
		return 80029;
	}
}


function disconnectInterface($n, $p)
{
	$n = secureCmd($n);
	$p = secureCmd($p);
	if (isBridge($n)) {
		$cmd = 'sudo brctl delif ' . escapeshellarg($n) . ' ' . escapeshellarg($p) . ' 2>&1';
		error_log(date('M d H:i:s ') . $cmd);
		exec($cmd, $o, $rc);
		
		if ($rc == 0) {
			return 0;
		} else {
			// Failed to add interface to Bridge
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80030]);
			error_log(date('M d H:i:s ') . implode("\n", $o));
			return 80030;
		}
	} else if (isOvs($n)) {
		$cmd = 'sudo ovs-vsctl del-port ' . escapeshellarg($n) . ' ' . escapeshellarg($p) . ' 2>&1';
		exec($cmd, $o, $rc);
		if ($rc == 0) {
			return 0;
		} else {
			// Failed to add interface to OVS
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80031]);
			error_log(date('M d H:i:s ') . implode("\n", $o));
			return 80031;
		}
	} else {
		// Network not found
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80029]);
		return 80029;
	}
}

/**
 * Function to delete a bridge
 *
 * @param   string  $s                  Bridge name
 * @return  int                         0 means ok
 */
function delBridge($s)
{
	$s = secureCmd($s);
	// Need to deactivate it
	$cmd = 'sudo ip link set dev ' . escapeshellarg($s) . ' down 2>&1';
	exec($cmd, $o, $rc);

	$cmd = 'sudo brctl delbr ' . escapeshellarg($s) . ' 2>&1';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		return 0;
	} else {
		// Failed to delete the OVS
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80025]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80025;
	}
}

/**
 * Function to delete an OVS
 *
 * @param   string  $s                  OVS name
 * @return  int                         0 means ok
 */
function delOvs($s)
{
	$s = secureCmd($s);
	$cmd = 'sudo ovs-vsctl del-br ' . escapeshellarg($s) . ' 2>&1';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		return 0;
	} else {
		// Failed to delete the OVS
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80024]);
		error_log(date('M d H:i:s ') . implode("\n", $o));
		return 80024;
	}
}

/**
 * Function to delete a TAP interface
 *
 * @param   string  $s                  Interface name
 * @return  int                         0 means ok
 */
function delTap($s)
{
	$s = secureCmd($s);
	if (isInterface($s)) {
		// Remove interface from OVS switches
		$cmd = 'sudo ip link set dev ' . escapeshellarg($s) . ' down 2>&1';
		exec($cmd, $o, $rc);
		$cmd = 'sudo ip link delete ' . escapeshellarg($s) . ' 2>&1';
		exec($cmd, $o, $rc);
		$cmd = 'sudo ovs-vsctl del-port ' . escapeshellarg($s) . ' 2>&1';
		exec($cmd, $o, $rc);

		// Delete TAP (so it's removed from bridges too)
		$cmd = 'sudo tunctl -d ' . escapeshellarg($s) . ' 2>&1';
		error_log($cmd);
		exec($cmd, $o, $rc);
		
		if (isInterface($s)) {
			// Failed to delete the TAP interface
			error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80034]);
			error_log(date('M d H:i:s ') . implode("\n", $o));
			return 80034;
		} else {
			return 0;
		}
	} else {
		// Interface does not exist
		return 0;
	}
}

/**
 * Function to push startup-config to a file
 *
 * @param   string  $config_data        The startup-config
 * @param   string  $file_path          File with full path where config is stored
 * @return  bool                        true if config dumped
 */
function dumpConfig($config_data, $file_path)
{
	$fp = fopen($file_path, 'w');
	if (!isset($fp)) {
		// Cannot open file
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80068]);
		return False;
	}

	if (!fwrite($fp, $config_data)) {
		// Cannot write file
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80069]);
		return False;
	}

	return True;
}

/**
 * Function to export a node running-config.
 *
 * @param   int     $node_id            Node ID
 * @param   Node    $n                  Node
 * @param   Lab     $lab                Lab
 * @return  int                         0 means ok
 */
function export($n, $lab)
{
	
	$result = $n->export();
	if($result != 0) return $result;

	$lab->save();
	
	return 0;
}

/**
 * Function to check if a bridge exists
 *
 * @param   string  $s                  Bridge name
 * @return  bool                        True if exists
 */
function isBridge($s)
{
	$s = secureCmd($s);
	$o = array();
	$cmd = 'brctl show ' . escapeshellarg($s) . ' 2>&1';
	exec($cmd, $o, $rc);
	// brctl prints a header row and then one row per bridge, so a real bridge
	// puts its id on line 1. A missing bridge produces fewer lines; indexing
	// blindly raised "Undefined array key 1" on every call and passed null to
	// preg_match, which PHP 8.1+ deprecates.
	if (isset($o[1]) && preg_match('/8000/', $o[1])) {
		// "brctl show" on a ovs bridge or on a non-existent bridge return 0 -> check for 8000
		return True;
	} else {
		return False;
	}
}

/**
 * Every tap this node session owns, as the kernel currently sees it.
 *
 * Taps are vunl{session}_{interface}. Enumerating them from /sys/class/net
 * rather than from the node's interface list is the point: the leak this exists
 * to clean up happens when prepare() dies PART WAY through the interface loop,
 * and it also survives an interface being removed from the lab afterwards, so
 * a list derived from the node definition can be a subset of what is actually
 * on the host.
 *
 * The anchored `_[0-9]+` matters. Without it 'vunl1_' is a prefix of 'vunl12_0'
 * and stopping session 1 would tear down session 12's data plane.
 *
 * @param   int     $session            Node session id
 * @param   string  $dir                Where to look. Only the tests pass this,
 *                                      and they pass it because the anchoring
 *                                      above is the part worth a regression
 *                                      test and it cannot be exercised against
 *                                      the real /sys without creating taps.
 * @return  array                       Interface names, ascending
 */
function unl_session_taps($session, $dir = '/sys/class/net')
{
	$session = (int) $session;
	$names = @scandir($dir);
	if ($names === false) return array();
	$out = array();
	foreach ($names as $n) {
		if (preg_match('/^vunl' . $session . '_[0-9]+\z/', $n)) $out[] = $n;
	}
	sort($out);
	return $out;
}

/**
 * Function to check if a interface exists
 *
 * @param   string  $s                  Interface name
 * @return  bool                        True if exists
 */
function isInterface($s)
{
	$s = secureCmd($s);
	// No sudo: reading link state is unprivileged. `ip link show` needs root to
	// CHANGE a link, never to look at one.
	$cmd = 'ip link show ' . escapeshellarg($s) . ' 2>&1';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		return True;
	} else {
		return False;
	}
}

function isInterfaceUp($s)
{
	$s = secureCmd($s);
	// No sudo, for the same reason as isInterface() above.
	$cmd = 'ip link show ' . escapeshellarg($s) . ' | grep UP';
	exec($cmd, $o, $rc);
	if(count($o) > 0) return true;
	return false;
}

/**
 * Function to check if an OVS exists
 *
 * @param   string  $s                  OVS name
 * @return  bool                        True if exists
 */
function isOvs($s)
{
	$s = secureCmd($s);
	$cmd = 'ovs-vsctl br-exists ' . escapeshellarg($s) . ' 2>&1';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		return True;
	} else {
		return False;
	}
}

/**
 * Function to check if a node is running.
 *
 * @param   int     $p                  Port
 * @return  bool                        true if running
 */
function isRunning($p)
{
	$p = secureCmd($p);
	// If node is running, the console port is used
	$cmd = 'fuser -n tcp ' . escapeshellarg($p) . ' 2>&1';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		return True;
	} else {
		return False;
	}
}

/**
 * Function to check if a TAP interface exists
 *
 * @param   string  $s                  Interface name
 * @return  bool                        True if exists
 */
function isTap($s)
{
	$s = secureCmd($s);
	if (is_dir('/sys/class/net/' . $s)) {
		// TODO can be bridge or OVS
		return True;
	} else {
		return False;
	}
}


/**
 * Function to start a node.
 *
 * @param   Node    $n                  Node
 * @param   Int     $id                 Node ID
 * @param   Int     $t                  Tenant ID
 * @param   Array   $nets               Array of networks
 * @param   int     $scripttimeout      Config Script Timeout
 * @return  int                         0 means ok
 */
function start($lab, $id)
{
	
	$n = $lab->getNodes()[$id];
	$t = $lab->getHost();
	
	if($t === null) return 1;
	
	if ($n->getStatus() !== 0) {
		return 0;
	}
	
	return $n->start();

}

/**
 * Function to stop a node.
 *
 * @param   Node    $n                  Node
 * @return  int                         0 means ok
 */
function stop($n)
{
	$n->stop();
}


function wipe($n)
{
	$n->wipe();
}

/**
 * Function to print how to use the unl_wrapper
 *
 * @return  string                      usage output
 */
function usage()
{
	global $argv;
	$output = '';
	$output .= "Usage: " . $argv[0] . " -a <action> <options>\n";
	$output .= "-a <s>     Action can be:\n";
	// backupdb and restoredb are listed because, unlike every other action
	// here, they have no caller in the application at all: an operator runs
	// them by hand, so this text is the only place they are discoverable.
	$output .= "           - backupdb: dump pnetlab_db and guacdb to\n";
	$output .= "                     /opt/unetlab/backup_database (0700, root)\n";
	$output .= "           - restoredb: restore both schemas from that directory.\n";
	$output .= "                     DESTRUCTIVE; refuses while a lab is running.\n";
	$output .= "                     --source remote reads the remote/ subdirectory\n";
	$output .= "                     (what -a restoredb_remote used to do)\n";
	$output .= "           - delete: delete a lab file even if it's not valid\n";
	$output .= "                     requires -T, -F\n";
	$output .= "           - export: export a runnign-config to a file\n";
	$output .= "                     requires -T, -F, -D is optional\n";
	$output .= "           - fixpermissions: fix file/dir permissions\n";
	$output .= "           - platform: print the hardware platform\n";
	$output .= "           - start: start one or all nodes\n";
	$output .= "                     requires -T, -F, -D is optional\n";
	$output .= "           - stop: stop one or all nodes\n";
	$output .= "                     requires -T, -F, -D is optional\n";
	$output .= "           - wipe: wipe one or all nodes\n";
	$output .= "                     requires -T, -F, -D is optional\n";
	$output .= "Options:\n";
	$output .= "-F <n>     Lab file\n";
	$output .= "-T <n>     Tenant ID\n";
	$output .= "-D <n>     Device ID (if not used, all devices will be impacted)\n";
	$output .= "-S <n>     Lab Session\n";
	print($output);
}
