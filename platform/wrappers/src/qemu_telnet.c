/*
 * qemu_wrapper_telnet — WRAPPER-SPEC §3.
 *
 * A QEMU node whose console type is `telnet` gets these QEMU flags from
 * devices/qemu/device_qemu.php:
 *
 *   -chardev socket,id=serial0,path=<running path>/console.sock,server,nowait
 *   -serial chardev:serial0
 *
 * so QEMU's serial port is a UNIX socket and NOTHING is listening on the node's
 * TCP console port. getNodeStatus() only ever looks for a TCP listener, so
 * without this program the node reads as stopped however well QEMU is running,
 * and there is no way to reach its console. That is the entire job.
 *
 * How it is invoked (device_qemu::start(), inside a four-iteration retry loop
 * that runs only once <running path>/console.sock exists):
 *
 *   /opt/unetlab/wrappers/qemu_wrapper_telnet -P <port> -t <node name> \
 *       -- nc -U <running path>/console.sock > <running path>/wrapper.txt 2>&1 &
 *
 * as root, with cwd already set to the node's running directory, with no
 * environment set, and with the shell — not us — doing the backgrounding.
 */
#include <errno.h>
#include <getopt.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#include "child.h"
#include "cmdline.h"
#include "console.h"
#include "log.h"
#include "loop.h"
#include "qemu_telnet.h"
#include "version.h"

#define DEFAULT_TITLE "Terminal Server"

void qt_usage(const char *progname)
{
	/*
	 * §10.3 #7: the original's usage text advertises -T <tenant>, -D <device>
	 * and -F <executable>, none of which it implements — the text was copied
	 * from qemu_wrapper and never updated, so anyone who believed it got a
	 * usage message and exit 1. This text lists what is actually here.
	 *
	 * Usage goes to stdout, not stderr (§2.4): the caller has redirected both
	 * into wrapper.txt, but keeping them on the same stream keeps the file in
	 * order.
	 */
	printf("Usage: %s -P <port> [-d <seconds>] [-t <title>] -- <command> [args...]\n"
	       "       %s -x [-d <seconds>] -- <command> [args...]\n"
	       "       %s -v\n"
	       "\n"
	       "  -P <port>     TCP port to serve the telnet console on. Mandatory\n"
	       "                unless -x is given.\n"
	       "  -d <seconds>  Wait this long before starting the command, printing\n"
	       "                one dot per second to the console. Default 0.\n"
	       "  -t <title>    Window title sent to a client on connect, as an xterm\n"
	       "                OSC sequence. Default \"%s\".\n"
	       "  -x            Do not open a console at all; just run the command and\n"
	       "                wait for it.\n"
	       "  -v            Print the version and exit.\n"
	       "\n"
	       "Everything after -- is the command, run through /bin/sh -c. PNETLab\n"
	       "passes `nc -U <running path>/console.sock`.\n",
	       progname, progname, progname, DEFAULT_TITLE);
}

/* strtol with the checks that matter: fully numeric, non-negative, in range. */
static int parse_nonneg(const char *s, int *out)
{
	char *end = NULL;
	long  v;

	if (s == NULL || *s == '\0')
		return -1;

	errno = 0;
	v = strtol(s, &end, 10);
	if (errno != 0 || end == s || *end != '\0' || v < 0 || v > INT_MAX)
		return -1;

	*out = (int) v;
	return 0;
}

qt_parse_t qt_parse(int argc, char *const argv[], qt_opts_t *opts)
{
	int sep, opt_argc, c;

	memset(opts, 0, sizeof(*opts));
	opts->port     = -1;
	opts->delay    = 0;
	opts->cmd_from = -1;
	snprintf(opts->title, sizeof(opts->title), "%s", DEFAULT_TITLE);

	/*
	 * Find "--" before getopt runs, and hide everything from it onwards from
	 * getopt entirely. GNU getopt permutes argv, so a scan afterwards is
	 * reading a rearranged array; and hiding the tail means a child command
	 * that happens to start with a dash (`nc -U ...` does not, but IOL's
	 * `-n <nvram> -q -m <ram>` very much does) can never be mistaken for one
	 * of our own options.
	 */
	sep = cmdline_find_separator(argc, argv);
	opt_argc = (sep < 0) ? argc : sep;
	if (sep >= 0 && sep + 1 < argc)
		opts->cmd_from = sep + 1;

	/*
	 * optind = 0 rather than 1: glibc treats zero as "reinitialise", which
	 * also clears the internal permutation bookkeeping. Setting it to 1 leaves
	 * that state behind, which the unit tests would trip over on the second
	 * call.
	 */
	optind = 0;
	opterr = 0;   /* leading colon in the option string: we report, not getopt */

	while ((c = getopt(opt_argc, argv, ":P:d:t:xv")) != -1) {
		switch (c) {
		case 'P':
			if (parse_nonneg(optarg, &opts->port) != 0) {
				log_err("-P: '%s' is not a valid port number", optarg);
				return QT_USAGE;
			}
			if (opts->port > 65535) {
				log_err("-P: port %d is out of range", opts->port);
				return QT_USAGE;
			}
			break;
		case 'd':
			if (parse_nonneg(optarg, &opts->delay) != 0) {
				log_err("-d: '%s' is not a valid number of seconds",
				        optarg);
				return QT_USAGE;
			}
			break;
		case 't':
			snprintf(opts->title, sizeof(opts->title), "%s", optarg);
			break;
		case 'x':
			opts->no_console = 1;
			break;
		case 'v':
			return QT_VERSION;
		case ':':
			log_err("-%c requires an argument", optopt);
			return QT_USAGE;
		default:
			log_err("unknown option -%c", optopt);
			return QT_USAGE;
		}
	}

	if (optind < opt_argc) {
		log_err("unexpected argument '%s' (the child command goes after --)",
		        argv[optind]);
		return QT_USAGE;
	}

	/*
	 * §10.3 #6: the original does not require -P. Without it the port stays at
	 * its -1 sentinel, bind() fails and the wrapper exits — so the node simply
	 * never starts, and the reason is three levels down in wrapper.txt. Say so
	 * up front instead.
	 */
	if (!opts->no_console && opts->port < 0) {
		log_err("-P is required: without a console port the node can never "
		        "read as running");
		return QT_USAGE;
	}

	if (opts->cmd_from < 0) {
		log_err("no command given; everything after -- is the command to run");
		return QT_USAGE;
	}

	return QT_OK;
}

#ifndef WRAPPER_NO_MAIN
int main(int argc, char *argv[])
{
	qt_opts_t    opts;
	cmdline_t    cmd;
	console_t    con;
	child_t      ch;
	child_spec_t spec;
	int          rc;

	/*
	 * R3: this program's basename must stay `qemu_wrapper_telnet`. Nothing
	 * pgreps it today (unlike iol_wrapper), but device_qemu.php invokes it by
	 * that path and includes/api_status.php's process counting is written in
	 * terms of these names.
	 */
	log_init("qemu_wrapper_telnet");

	/* R4, and it has to be before the fork. */
	child_become_group_leader();

	/*
	 * R2: no chdir(), anywhere, ever. device::start() chdir()s to the node's
	 * running directory before exec'ing us, and device::stop() tears the node
	 * down with `sudo fuser -k -TERM <running path>` — which finds processes
	 * by the directories they hold open, cwd included. A wrapper that tidily
	 * chdir()s to / becomes unkillable by the only mechanism the UI has.
	 *
	 * R5: no daemon(), no double fork, no setsid. The shell backgrounded us
	 * and exited, so we are already reparented to PID 1, which is what
	 * `pgrep -f -c -P 1 <wrapper>` in includes/api_status.php counts. Forking
	 * a long-lived child that outlives us would break that count and orphan
	 * the emulator.
	 */

	switch (qt_parse(argc, argv, &opts)) {
	case QT_VERSION:
		printf("%s\n", WRAPPER_VERSION_BLURB);
		return 0;
	case QT_USAGE:
		qt_usage("qemu_wrapper_telnet");
		return 1;
	case QT_OK:
		break;
	}

	/*
	 * §2.6/§3.3: qemu_wrapper_telnet has no executable prefix of its own. The
	 * command is exactly the words after "--", space separated, with the PHP's
	 * escapeshellarg() quoting left intact for /bin/sh to undo.
	 */
	if (cmdline_init(&cmd) != 0)
		return 1;
	if (cmdline_append_tail(&cmd, argc, argv, opts.cmd_from) != 0) {
		cmdline_free(&cmd);
		return 1;
	}

	console_init(&con, opts.title);

	/*
	 * R1, and the ordering matters: open the listener BEFORE forking the
	 * child. device_qemu::start() retries for four seconds and then gives up;
	 * if we bound the port only after nc had connected we would be racing that
	 * for no reason. Binding first also means a port collision is reported
	 * before anything is started, rather than leaving a stray nc behind.
	 */
	if (!opts.no_console) {
		if (console_open(&con, opts.port) != 0) {
			cmdline_free(&cmd);
			return 1;
		}
	} else {
		log_inf("-x given: running without a console");
	}

	memset(&spec, 0, sizeof(spec));
	spec.mode      = CHILD_PIPES;   /* nc is happy on pipes; only docker needs a pty */
	spec.command   = cmdline_str(&cmd);
	spec.delay     = (unsigned int) opts.delay;
	spec.env_extra = NULL;

	if (child_spawn(&ch, &spec) != 0) {
		console_close(&con);
		cmdline_free(&cmd);
		return 1;
	}

	if (opts.no_console) {
		/* §3.5: pure babysitter. */
		int status = child_wait_blocking(&ch);

		log_inf("child finished (raw status 0x%x)", (unsigned) status);
		child_close(&ch);
		cmdline_free(&cmd);
		return 0;
	}

	rc = loop_run(&con, &ch, NULL);
	cmdline_free(&cmd);
	return rc;
}
#endif /* WRAPPER_NO_MAIN */
