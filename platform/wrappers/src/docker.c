/*
 * docker_wrapper — WRAPPER-SPEC §4.
 *
 * A telnet console attached to a running Docker container. devices/docker/
 * device_docker.php starts the container itself and then, for a node whose
 * console type is `telnet`, runs:
 *
 *   sudo /opt/unetlab/wrappers/docker_wrapper -P <port> -t <node name> \
 *       -p <session> -c <sh|/bin/bash> > <running path>/wrapper.txt 2>&1 &
 *
 * with cwd already set to the node's running directory, no environment, and the
 * shell — not us — doing the backgrounding.
 *
 * Docker nodes are the one node type whose status does NOT come from the console
 * port: getNodeStatus() asks `docker inspect --format "{{ .State.Running }}"`
 * instead (§1.2, §4.6). So a broken docker_wrapper does not make the node read
 * as stopped; it makes a node that reads as perfectly healthy and whose console
 * will not open. R1 still applies — the port has to LISTEN for anyone to reach
 * the console at all — it is just not the thing that lights the node up green.
 *
 * ---------------------------------------------------------------------------
 * The PTY, which is the whole point of this file
 * ---------------------------------------------------------------------------
 * `docker exec -ti` refuses to run unless its stdin is a terminal, and the
 * wrapper's child normally has pipes. The original solved that by shelling out
 * over SSH to itself:
 *
 *   ssh root@localhost -i /root/.ssh/id_rsa_dy -o StrictHostKeyChecking=no -tt \
 *       'export TERM=ansi&&docker -H=tcp://127.0.0.1:4243 exec -ti dockerN sh'
 *
 * `ssh -tt` forces pseudo-terminal allocation, so `docker exec` got its TTY and
 * the ssh client's own pipes carried the bytes back. The cost of that trick is
 * that the appliance must run an SSH daemon accepting root logins with a
 * passwordless key sitting on the same disk — a standing root-equivalent
 * credential, present on every install, usable by anything that can read
 * /root/.ssh or reach port 22.
 *
 * R7 / §10.3 #3: we allocate the terminal locally instead. child_spawn() in
 * CHILD_PTY mode does posix_openpt/grantpt/unlockpt, puts the child on the slave
 * in raw mode at 80x24 (the original never negotiated a window size either), and
 * hands us the master, which the poll loop reads and writes exactly where it
 * would have read and written pipes. `docker exec` sees a real terminal, and the
 * SSH server, the root login and the standing key are all deleted from the
 * appliance's threat model.
 *
 * §10.3 #4: the original stat()s a file under /opt/unetlab/html/store/app/ before
 * parsing any options and exits 1 with a "download PNETLab from our site" message
 * if it is absent. That is a vendor distribution check with no functional
 * purpose. It is omitted deliberately; do not restore it.
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
#include "docker.h"
#include "log.h"
#include "loop.h"
#include "version.h"

#define DEFAULT_TITLE  "Terminal Server"
#define DEFAULT_ATTACH "sh"

void dk_usage(const char *progname)
{
	/*
	 * §10.3 #7: the original's usage text advertises -T, -D and -F, which it
	 * does not implement — it was copied from qemu_wrapper and never updated.
	 * This text lists what is actually here. Usage goes to stdout (§2.4),
	 * which the caller has redirected into wrapper.txt along with everything
	 * else, so the file reads in order.
	 */
	printf("Usage: %s -P <port> -p <session> [-c <command>] [-d <seconds>] "
	       "[-t <title>] [-- <args...>]\n"
	       "       %s -v\n"
	       "\n"
	       "  -P <port>     TCP port to serve the telnet console on. Mandatory\n"
	       "                unless -x is given.\n"
	       "  -p <session>  Node session id. The container attached to is\n"
	       "                docker<session>. Mandatory.\n"
	       "  -c <command>  Command to run inside the container. Default \"%s\";\n"
	       "                device_docker.php passes /bin/bash when the image\n"
	       "                has one.\n"
	       "  -d <seconds>  Wait this long before attaching, printing one dot per\n"
	       "                second to the console. Default 0.\n"
	       "  -t <title>    Window title sent to a client on connect, as an xterm\n"
	       "                OSC sequence. Default \"%s\".\n"
	       "  -x <ignored>  Do not open a console at all; just attach and wait.\n"
	       "  -v            Print the version and exit.\n"
	       "\n"
	       "Runs `docker -H=%s exec -ti docker<session> <command>` on a locally\n"
	       "allocated pseudo-terminal. Anything after -- is passed to that command.\n",
	       progname, progname, DEFAULT_ATTACH, DEFAULT_TITLE, DOCKER_ENDPOINT);
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

dk_parse_t dk_parse(int argc, char *const argv[], dk_opts_t *opts)
{
	int sep, opt_argc, c;

	memset(opts, 0, sizeof(*opts));
	opts->port     = -1;
	opts->session  = -1;
	opts->delay    = 0;
	opts->cmd_from = -1;
	snprintf(opts->title, sizeof(opts->title), "%s", DEFAULT_TITLE);
	snprintf(opts->attach, sizeof(opts->attach), "%s", DEFAULT_ATTACH);

	/* Find "--" before getopt runs and hide the tail from it: GNU getopt
	 * permutes argv, so a scan afterwards reads a rearranged array. */
	sep = cmdline_find_separator(argc, argv);
	opt_argc = (sep < 0) ? argc : sep;
	if (sep >= 0 && sep + 1 < argc)
		opts->cmd_from = sep + 1;

	/* glibc: zero means reinitialise, which also clears the permutation
	 * bookkeeping. 1 does not, and the unit tests call this repeatedly. */
	optind = 0;
	opterr = 0;   /* leading colon: we report the error, not getopt */

	/*
	 * "P:d:t:x:p:c:v" — note that -x takes an argument here and is then
	 * ignored, which is the original's contract (§4.2) and not a typo. The
	 * fork never passes -x to this wrapper; the option exists so a caller
	 * written against the original still parses.
	 *
	 * -v is ours: the original has no version flag at all. It costs nothing
	 * and makes the deployed binary identifiable, which matters for a program
	 * that otherwise only ever runs backgrounded with its output in a file.
	 */
	while ((c = getopt(opt_argc, argv, ":P:d:t:x:p:c:v")) != -1) {
		switch (c) {
		case 'P':
			if (parse_nonneg(optarg, &opts->port) != 0) {
				log_err("-P: '%s' is not a valid port number", optarg);
				return DK_USAGE;
			}
			if (opts->port > 65535) {
				log_err("-P: port %d is out of range", opts->port);
				return DK_USAGE;
			}
			break;
		case 'p':
			if (parse_nonneg(optarg, &opts->session) != 0) {
				log_err("-p: '%s' is not a valid session id", optarg);
				return DK_USAGE;
			}
			break;
		case 'd':
			if (parse_nonneg(optarg, &opts->delay) != 0) {
				log_err("-d: '%s' is not a valid number of seconds",
				        optarg);
				return DK_USAGE;
			}
			break;
		case 't':
			snprintf(opts->title, sizeof(opts->title), "%s", optarg);
			break;
		case 'c':
			if (*optarg == '\0') {
				log_err("-c: the command to run in the container cannot "
				        "be empty");
				return DK_USAGE;
			}
			if (strlen(optarg) >= sizeof(opts->attach)) {
				log_err("-c: command is too long (max %zu characters)",
				        sizeof(opts->attach) - 1);
				return DK_USAGE;
			}
			snprintf(opts->attach, sizeof(opts->attach), "%s", optarg);
			break;
		case 'x':
			opts->no_console = 1;
			break;
		case 'v':
			return DK_VERSION;
		case ':':
			log_err("-%c requires an argument", optopt);
			return DK_USAGE;
		default:
			log_err("unknown option -%c", optopt);
			return DK_USAGE;
		}
	}

	if (optind < opt_argc) {
		log_err("unexpected argument '%s' (extra arguments go after --)",
		        argv[optind]);
		return DK_USAGE;
	}

	/*
	 * §10.3 #6, both of them. The original requires neither -P nor -p:
	 * without -P it builds a listener on port -1 and dies in bind(), and
	 * without -p the session stays at its sentinel and it cheerfully attaches
	 * to a container called "docker-1" that has never existed. Both failures
	 * surface several steps later as "the console does not open". Say which
	 * argument is missing instead.
	 */
	if (!opts->no_console && opts->port < 0) {
		log_err("-P is required: without a console port there is nothing for "
		        "a client to connect to");
		return DK_USAGE;
	}
	if (opts->session < 0) {
		log_err("-p is required: it names the container (docker<session>) to "
		        "attach to");
		return DK_USAGE;
	}

	return DK_OK;
}

/*
 * Append " '<word>'" with any embedded single quote escaped.
 *
 * The assembled string is run by /bin/sh (§2.2), and -c arrives unquoted: the
 * PHP builds `-c ' . $attachCmd` with no escapeshellarg() around it, unlike
 * every other value on that command line. Today $attachCmd is one of two
 * literals, so nothing is reachable — but a wrapper that pastes an unquoted
 * caller-supplied word into a shell command is one template field away from
 * being a command injection, and quoting it here costs nothing. A command that
 * genuinely needs arguments passes them after --.
 */
static int append_quoted(cmdline_t *c, const char *word)
{
	const char *p;

	if (cmdline_append(c, " '") != 0)
		return -1;
	for (p = word; *p != '\0'; p++) {
		if (*p == '\'') {
			if (cmdline_append(c, "'\\''") != 0)
				return -1;
		} else {
			char one[2];

			one[0] = *p;
			one[1] = '\0';
			if (cmdline_append(c, one) != 0)
				return -1;
		}
	}
	return cmdline_append(c, "'");
}

int dk_build_command(const dk_opts_t *opts, cmdline_t *cmd,
                     int argc, char *const argv[])
{
	int rc = 0;

	/*
	 * §2.6's shape, with the ssh hop of §4.3 removed:
	 *
	 *   docker -H=<endpoint> exec -ti docker<session> <attach> [tail]
	 *
	 * TERM is NOT set here as a "TERM=ansi " prefix the way the original did
	 * it inside its ssh command; it goes in the child's environment
	 * (child_spec_t::env_extra), where no amount of shell quoting can lose it.
	 */
	rc |= cmdline_append(cmd, "docker -H=" DOCKER_ENDPOINT " exec -ti");
	rc |= cmdline_appendf(cmd, " docker%d", opts->session);
	rc |= append_quoted(cmd, opts->attach);

	/* §4.5: anything after -- is appended. The fork passes none. */
	if (opts->cmd_from >= 0)
		rc |= cmdline_append_tail(cmd, argc, argv, opts->cmd_from);

	if (rc != 0) {
		log_err("the child command exceeded ARG_MAX");
		return -1;
	}
	return 0;
}

#ifndef WRAPPER_NO_MAIN
int main(int argc, char *argv[])
{
	/* TERM=ansi, as §4.3 requires. The original exported it inside the ssh
	 * command string; env_extra puts it in the child's environment directly.
	 * §1.1: the wrapper inherits no useful environment, so without this the
	 * container's shell comes up with TERM unset and line editing misbehaves. */
	static const char *const env_extra[] = { "TERM=ansi", NULL };

	dk_opts_t    opts;
	cmdline_t    cmd;
	console_t    con;
	child_t      ch;
	child_spec_t spec;
	int          rc;

	/* R3: the basename is the contract — device_docker.php invokes this exact
	 * path, and install/sudoers.d/pnetlab allowlists it by it. */
	log_init("docker_wrapper");

	/* R4, and it must happen before anything is forked. */
	child_become_group_leader();

	/*
	 * R2: no chdir(), ever. device::stop() tears a node down with
	 * `sudo fuser -k -TERM <running path>`, which finds processes by the
	 * directories they hold open, cwd included.
	 *
	 * R5: no daemon(), no double fork, no setsid() here. The shell that
	 * backgrounded us has already exited, so we are reparented to PID 1.
	 */

	switch (dk_parse(argc, argv, &opts)) {
	case DK_VERSION:
		printf("%s\n", WRAPPER_VERSION_BLURB);
		return 0;
	case DK_USAGE:
		dk_usage("docker_wrapper");
		return 1;
	case DK_OK:
		break;
	}

	if (cmdline_init(&cmd) != 0)
		return 1;
	if (dk_build_command(&opts, &cmd, argc, argv) != 0) {
		cmdline_free(&cmd);
		return 1;
	}

	console_init(&con, opts.title);

	/* R1, before the fork: the port is what a client connects to, and binding
	 * first means a collision is reported before we have attached to anything. */
	if (!opts.no_console) {
		if (console_open(&con, opts.port) != 0) {
			cmdline_free(&cmd);
			return 1;
		}
	} else {
		log_inf("-x given: running without a console");
	}

	memset(&spec, 0, sizeof(spec));
	spec.mode      = CHILD_PTY;   /* R7 — the reason this mode exists */
	spec.command   = cmdline_str(&cmd);
	spec.delay     = (unsigned int) opts.delay;
	spec.env_extra = env_extra;

	if (child_spawn(&ch, &spec) != 0) {
		console_close(&con);
		cmdline_free(&cmd);
		return 1;
	}

	if (opts.no_console) {
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
