/*
 * wrapper_test — unit tests for the parts of the wrappers that are pure logic:
 * the telnet input filter, command assembly, and option parsing.
 *
 *     make test
 *
 * Deliberately assert-based and dependency-free, to match tools/run-tests.sh:
 * a test is a program that exits non-zero, and needs nothing installed to run.
 * Anything involving a socket, a fork or a signal is tested from the outside by
 * tools/integration/wrapper-console.sh instead — there is no value in mocking a
 * kernel we are going to have to test against anyway.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <arpa/inet.h>
#include <errno.h>
#include <ifaddrs.h>
#include <netinet/in.h>
#include <poll.h>
#include <sys/socket.h>

#include "cmdline.h"
#include "console.h"
#include "docker.h"
#include "iol.h"
#include "log.h"
#include "qemu_telnet.h"

static int passed;
static int failed;

static void ok(const char *what)
{
	printf("  ok   %s\n", what);
	passed++;
}

static void bad(const char *what, const char *detail)
{
	printf("  FAIL %s\n", what);
	if (detail != NULL)
		printf("       %s\n", detail);
	failed++;
}

static void chk(const char *what, int cond)
{
	if (cond)
		ok(what);
	else
		bad(what, NULL);
}

static void chk_str(const char *what, const char *got, const char *want)
{
	if (strcmp(got, want) == 0) {
		ok(what);
	} else {
		char detail[512];

		snprintf(detail, sizeof(detail), "expected '%s', got '%s'", want, got);
		bad(what, detail);
	}
}

static void chk_bytes(const char *what, const unsigned char *got, size_t gotlen,
                      const char *want, size_t wantlen)
{
	if (gotlen == wantlen && memcmp(got, want, wantlen) == 0) {
		ok(what);
	} else {
		char   detail[512];
		size_t i, n = 0;

		n += (size_t) snprintf(detail, sizeof(detail),
		                       "expected %zu bytes, got %zu:", wantlen, gotlen);
		for (i = 0; i < gotlen && n + 5 < sizeof(detail); i++)
			n += (size_t) snprintf(detail + n, sizeof(detail) - n,
			                       " %02x", got[i]);
		bad(what, detail);
	}
}

/* ------------------------------------------------------------------ telnet */

static size_t filter_all(const char *in, size_t inlen, unsigned char *out)
{
	telnet_filter_t f;

	telnet_filter_init(&f);
	return telnet_filter(&f, (const unsigned char *) in, inlen, out);
}

static void test_telnet(void)
{
	unsigned char   out[64];
	telnet_filter_t f;
	size_t          n;

	printf("\n=============== TELNET INPUT FILTER ===============\n");

	n = filter_all("hello", 5, out);
	chk_bytes("plain data passes through untouched", out, n, "hello", 5);

	n = filter_all("a\xff\xfd\x01" "b", 5, out);
	chk_bytes("IAC DO ECHO is swallowed, data around it survives",
	          out, n, "ab", 2);

	n = filter_all("\xff\xfb\x01\xff\xfd\x03", 6, out);
	chk_bytes("back-to-back three-byte commands leave nothing", out, n, "", 0);

	/* §10.3 #8. The original loses the 0xFF and eats the next two bytes with
	 * it, so a binary paste containing 0xFF corrupts three bytes. */
	n = filter_all("a\xff\xff" "b", 4, out);
	chk_bytes("IAC IAC unescapes to one literal 0xFF", out, n, "a\xff" "b", 3);

	/* A two-byte command: IAC NOP. The original would eat the byte after it. */
	n = filter_all("a\xff\xf1" "b", 4, out);
	chk_bytes("two-byte IAC command consumes exactly two bytes",
	          out, n, "ab", 2);

	/* Subnegotiation. The original has no notion of one, so the body would
	 * arrive at the node as keystrokes. */
	n = filter_all("a\xff\xfa\x1f\x00\x50\x00\x18\xff\xf0" "b", 11, out);
	chk_bytes("IAC SB ... IAC SE is consumed whole", out, n, "ab", 2);

	n = filter_all("\xff\xfa\x1f\xff\xff\xff\xf0z", 8, out);
	chk_bytes("escaped 0xFF inside a subnegotiation does not end it",
	          out, n, "z", 1);

	/* The reason this is a state machine and not a three-byte skip: TCP does
	 * not respect our framing. */
	telnet_filter_init(&f);
	n  = telnet_filter(&f, (const unsigned char *) "x\xff", 2, out);
	n += telnet_filter(&f, (const unsigned char *) "\xfd", 1, out + n);
	n += telnet_filter(&f, (const unsigned char *) "\x01y", 2, out + n);
	chk_bytes("a command split across three reads is still swallowed",
	          out, n, "xy", 2);

	telnet_filter_init(&f);
	n  = telnet_filter(&f, (const unsigned char *) "\xff", 1, out);
	n += telnet_filter(&f, (const unsigned char *) "\xff", 1, out + n);
	chk_bytes("IAC IAC split across two reads still yields one 0xFF",
	          out, n, "\xff", 1);

	n = filter_all("", 0, out);
	chk("an empty read produces no output", n == 0);
}

/* ----------------------------------------------------------------- cmdline */

static void test_cmdline(void)
{
	cmdline_t c;
	char     *argv1[] = { "qemu_wrapper_telnet", "-P", "32769", "-t", "R1",
	                      "--", "nc", "-U", "/opt/unetlab/tmp/1/2/console.sock" };
	char     *argv2[] = { "w", "-P", "1" };
	char     *argv3[] = { "w", "--", "a", "--", "b" };
	size_t    i;

	printf("\n=============== COMMAND ASSEMBLY ===============\n");

	chk("-- is found by position", cmdline_find_separator(9, argv1) == 5);
	chk("no -- reports -1", cmdline_find_separator(3, argv2) == -1);
	chk("only the first -- is the separator",
	    cmdline_find_separator(5, argv3) == 1);

	chk("cmdline_init succeeds", cmdline_init(&c) == 0);
	chk_str("a fresh command line is empty", cmdline_str(&c), "");
	chk("the tail assembles", cmdline_append_tail(&c, 9, argv1, 6) == 0);
	/* §2.6: each element preceded by a single space, so the string opens with
	 * one. /bin/sh does not care and the original does the same. */
	chk_str("the child command is exactly the words after --",
	        cmdline_str(&c), " nc -U /opt/unetlab/tmp/1/2/console.sock");
	cmdline_free(&c);

	/* §2.6 again: a second -- is data, not a separator. */
	chk("init for the double-separator case", cmdline_init(&c) == 0);
	cmdline_append_tail(&c, 5, argv3, 2);
	chk_str("a second -- is appended as an ordinary word",
	        cmdline_str(&c), " a -- b");
	cmdline_free(&c);

	/* The ARG_MAX cap. Tested with a small explicit cap because allocating
	 * and filling the real 2 MB per case is pointless. */
	chk("init with an explicit cap", cmdline_init_cap(&c, 16) == 0);
	chk("appending within the cap succeeds", cmdline_append(&c, "0123456789") == 0);
	chk("appending past the cap fails", cmdline_append(&c, "abcdef") != 0);
	chk_str("an overflowing append leaves the buffer intact",
	        cmdline_str(&c), "0123456789");
	chk("the buffer stays poisoned after an overflow",
	    cmdline_append(&c, "x") != 0);
	cmdline_free(&c);

	chk("init for the appendf cap case", cmdline_init_cap(&c, 8) == 0);
	chk("appendf within the cap succeeds", cmdline_appendf(&c, "%d", 123) == 0);
	chk("appendf past the cap fails", cmdline_appendf(&c, "%s", "abcdefgh") != 0);
	chk_str("an overflowing appendf leaves the buffer intact",
	        cmdline_str(&c), "123");
	cmdline_free(&c);

	/* Nothing above should have left the real cap unreasonable. */
	chk("init uses a cap large enough for a real QEMU command line",
	    cmdline_init(&c) == 0 && c.cap >= 4096);
	for (i = 0; i < 100; i++)
		cmdline_append_word(&c, "-device");
	chk("a hundred appends stay within ARG_MAX", c.overflow == 0);
	cmdline_free(&c);
}

/* ------------------------------------------------------------ qt_parse */

static void test_qt_parse(void)
{
	qt_opts_t o;

	printf("\n=============== qemu_wrapper_telnet OPTIONS ===============\n");

	{
		char *argv[] = { "qemu_wrapper_telnet", "-P", "32769", "-t", "R1",
		                 "--", "nc", "-U", "/tmp/console.sock" };

		chk("the PHP's exact invocation parses",
		    qt_parse(9, argv, &o) == QT_OK);
		chk("port is taken from -P", o.port == 32769);
		chk_str("title is taken from -t", o.title, "R1");
		chk("delay defaults to 0", o.delay == 0);
		chk("the console is enabled by default", o.no_console == 0);
		chk("the command starts after --", o.cmd_from == 6);
	}

	{
		char *argv[] = { "w", "-P", "9", "--", "cat" };

		chk("defaults apply when -t is absent", qt_parse(5, argv, &o) == QT_OK);
		chk_str("the default title is the original's", o.title,
		        "Terminal Server");
	}

	/*
	 * §10.3 #6. The original accepts a missing -P, leaves the port at -1, and
	 * dies in bind() several steps later — so the operator's evidence that the
	 * node will not start is a failed bind on port 65535 in wrapper.txt.
	 */
	{
		char *argv[] = { "w", "-t", "R1", "--", "cat" };

		chk("a missing -P is a usage error, not a late bind failure",
		    qt_parse(5, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-x", "--", "cat" };

		chk("-x does not require -P", qt_parse(4, argv, &o) == QT_OK);
		chk("-x suppresses the console", o.no_console == 1);
	}

	{
		char *argv[] = { "w", "-P", "32769" };

		chk("no -- at all is a usage error", qt_parse(3, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "32769", "--" };

		chk("-- with nothing after it is a usage error",
		    qt_parse(4, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "abc", "--", "cat" };

		chk("a non-numeric port is rejected", qt_parse(5, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "32769x", "--", "cat" };

		chk("a port with trailing junk is rejected",
		    qt_parse(5, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "-1", "--", "cat" };

		chk("a negative port is rejected", qt_parse(5, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "70000", "--", "cat" };

		chk("a port above 65535 is rejected", qt_parse(5, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "9", "-d", "-3", "--", "cat" };

		chk("a negative delay is rejected", qt_parse(7, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "9", "-d", "5", "--", "cat" };

		chk("a valid delay parses", qt_parse(7, argv, &o) == QT_OK);
		chk("delay is taken from -d", o.delay == 5);
	}

	{
		char *argv[] = { "w", "-v" };

		chk("-v asks for the version", qt_parse(2, argv, &o) == QT_VERSION);
	}

	/*
	 * §10.3 #7: the original's usage text advertises these three and then
	 * rejects them. We reject them too — the fork never passes them — but the
	 * usage text no longer claims otherwise.
	 */
	{
		char *argv[] = { "w", "-P", "9", "-T", "0", "--", "cat" };

		chk("-T is rejected (advertised by the original, never implemented)",
		    qt_parse(7, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "--", "cat" };

		chk("-P with no argument is a usage error",
		    qt_parse(4, argv, &o) == QT_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "9", "stray", "--", "cat" };

		chk("a stray word before -- is a usage error",
		    qt_parse(6, argv, &o) == QT_USAGE);
	}

	/*
	 * The reason the "--" scan happens before getopt: IOL's tail is full of
	 * things that look exactly like options, and getopt would consume them.
	 */
	{
		char *argv[] = { "w", "-P", "9", "--", "-n", "nvram", "-q", "-m", "512" };

		chk("a child command made entirely of dashed words parses",
		    qt_parse(9, argv, &o) == QT_OK);
		chk("the whole dashed tail belongs to the child", o.cmd_from == 4);
	}

	/* getopt state is global; a front-end is only called once, but the tests
	 * are not, and a stale optind would make every case after the first lie. */
	{
		char *argv[] = { "w", "-P", "1234", "--", "cat" };

		chk("parsing is repeatable (getopt state is reset)",
		    qt_parse(5, argv, &o) == QT_OK && o.port == 1234);
	}
}

/* ------------------------------------------------------------- dk_parse */

/*
 * docker_wrapper. The interesting cases are the two mandatory options (§10.3 #6
 * — the original requires neither) and the assembled command, which is where the
 * ssh hop used to be and where the daemon endpoint is chosen.
 */
static void test_dk_parse(void)
{
	dk_opts_t o;

	printf("\n=============== docker_wrapper OPTIONS ===============\n");

	/* Exactly what devices/docker/device_docker.php builds. */
	{
		char *argv[] = { "docker_wrapper", "-P", "32769", "-t", "R1",
		                 "-p", "42", "-c", "/bin/bash" };

		chk("the PHP's own invocation parses", dk_parse(9, argv, &o) == DK_OK);
		chk("the port is taken from -P", o.port == 32769);
		chk("the session is taken from -p", o.session == 42);
		chk_str("the title is taken from -t", o.title, "R1");
		chk_str("the attach command is taken from -c", o.attach, "/bin/bash");
		chk("there is no child command tail", o.cmd_from == -1);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "0" };

		chk("-c defaults to sh", dk_parse(5, argv, &o) == DK_OK);
		chk_str("the default attach command is sh", o.attach, "sh");
		chk_str("the default title is the original's", o.title,
		        "Terminal Server");
		chk("the default delay is zero", o.delay == 0);
	}

	/*
	 * §10.3 #6. Without -p the original attaches to a container called
	 * "docker-1", which has never existed, and reports nothing.
	 */
	{
		char *argv[] = { "w", "-P", "9" };

		chk("-p is required (the original defaults it to -1)",
		    dk_parse(3, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-p", "7" };

		chk("-P is required", dk_parse(3, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-x", "1", "-p", "7" };

		chk("-x suppresses the console, so -P is not required",
		    dk_parse(5, argv, &o) == DK_OK);
		chk("-x sets no_console", o.no_console == 1);
	}

	/* §4.2: -x takes an argument in this wrapper and the argument is ignored.
	 * That is the original's contract, not a typo in ours. */
	{
		char *argv[] = { "w", "-p", "7", "-x" };

		chk("-x without its argument is a usage error", dk_parse(4, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "-3" };

		chk("a negative session is rejected", dk_parse(5, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "4x" };

		chk("a session with trailing junk is rejected",
		    dk_parse(5, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "70000", "-p", "1" };

		chk("a port above 65535 is rejected", dk_parse(5, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "1", "-c", "" };

		chk("an empty -c is rejected", dk_parse(7, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "1", "-T", "0" };

		chk("-T is rejected (advertised by the original, never implemented)",
		    dk_parse(7, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-v" };

		chk("-v asks for the version", dk_parse(2, argv, &o) == DK_VERSION);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "1", "stray" };

		chk("a stray word is a usage error", dk_parse(6, argv, &o) == DK_USAGE);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "1", "--", "-l" };

		chk("a dashed word after -- belongs to the child",
		    dk_parse(7, argv, &o) == DK_OK && o.cmd_from == 6);
	}
}

/* --------------------------------------------------------- dk_build_command */

static void test_dk_command(void)
{
	dk_opts_t o;
	cmdline_t c;

	printf("\n=============== docker_wrapper CHILD COMMAND ===============\n");

	/*
	 * The whole point of the file. What must NOT appear here is the original's
	 *
	 *   ssh root@localhost -i /root/.ssh/id_rsa_dy -o StrictHostKeyChecking=no -tt ...
	 *
	 * which existed only to obtain a TTY (§4.3) and required a standing
	 * passwordless root key on the appliance; the TTY comes from CHILD_PTY
	 * now. And the endpoint must be the unix socket, not the unauthenticated
	 * tcp://127.0.0.1:4243 the original pinned.
	 */
	{
		char *argv[] = { "docker_wrapper", "-P", "32769", "-t", "R1",
		                 "-p", "42", "-c", "/bin/bash" };

		chk("options parse", dk_parse(9, argv, &o) == DK_OK);
		chk("cmdline_init", cmdline_init_cap(&c, 4096) == 0);
		chk("the command assembles", dk_build_command(&o, &c, 9, argv) == 0);
		chk_str("docker exec -ti on the unix socket, no ssh hop",
		        cmdline_str(&c),
		        "docker -H=unix:///var/run/docker.sock exec -ti docker42 '/bin/bash'");
		chk("the command names no ssh",
		    strstr(cmdline_str(&c), "ssh") == NULL);
		chk("the command names no root ssh key",
		    strstr(cmdline_str(&c), "id_rsa") == NULL);
		chk("the command does not reach for the tcp docker socket",
		    strstr(cmdline_str(&c), "4243") == NULL);
		cmdline_free(&c);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "0" };

		chk("options parse", dk_parse(5, argv, &o) == DK_OK);
		chk("cmdline_init", cmdline_init_cap(&c, 4096) == 0);
		chk("the command assembles", dk_build_command(&o, &c, 5, argv) == 0);
		chk_str("session 0 is docker0, and sh is the default command",
		        cmdline_str(&c),
		        "docker -H=unix:///var/run/docker.sock exec -ti docker0 'sh'");
		cmdline_free(&c);
	}

	/*
	 * -c is the one value device_docker.php does NOT escapeshellarg(), and the
	 * assembled string goes to /bin/sh. Quoting it here is what keeps that from
	 * being an injection the day the attach command comes from a template.
	 */
	{
		char *argv[] = { "w", "-P", "9", "-p", "1", "-c", "sh; id" };

		chk("options parse", dk_parse(7, argv, &o) == DK_OK);
		chk("cmdline_init", cmdline_init_cap(&c, 4096) == 0);
		chk("the command assembles", dk_build_command(&o, &c, 7, argv) == 0);
		chk_str("a shell metacharacter in -c is quoted, not interpreted",
		        cmdline_str(&c),
		        "docker -H=unix:///var/run/docker.sock exec -ti docker1 'sh; id'");
		cmdline_free(&c);
	}

	{
		char *argv[] = { "w", "-P", "9", "-p", "1", "-c", "it's" };

		chk("options parse", dk_parse(7, argv, &o) == DK_OK);
		chk("cmdline_init", cmdline_init_cap(&c, 4096) == 0);
		chk("the command assembles", dk_build_command(&o, &c, 7, argv) == 0);
		chk_str("a single quote in -c survives quoting",
		        cmdline_str(&c),
		        "docker -H=unix:///var/run/docker.sock exec -ti docker1 "
		        "'it'\\''s'");
		cmdline_free(&c);
	}

	/* §4.5: everything after -- is appended, as arguments to the command that
	 * runs in the container. */
	{
		char *argv[] = { "w", "-P", "9", "-p", "1", "-c", "/bin/bash",
		                 "--", "-l" };

		chk("options parse", dk_parse(9, argv, &o) == DK_OK);
		chk("cmdline_init", cmdline_init_cap(&c, 4096) == 0);
		chk("the command assembles", dk_build_command(&o, &c, 9, argv) == 0);
		chk_str("the tail after -- is appended verbatim", cmdline_str(&c),
		        "docker -H=unix:///var/run/docker.sock exec -ti docker1 "
		        "'/bin/bash' -l");
		cmdline_free(&c);
	}

	/* §2.6: overflowing the cap is fatal, not truncating — a truncated command
	 * would attach to a plausible-looking wrong thing. */
	{
		char *argv[] = { "w", "-P", "9", "-p", "1" };

		chk("options parse", dk_parse(5, argv, &o) == DK_OK);
		chk("cmdline_init", cmdline_init_cap(&c, 16) == 0);
		chk("a command that will not fit is refused, not truncated",
		    dk_build_command(&o, &c, 5, argv) == -1);
		cmdline_free(&c);
	}
}

/* ---------------------------------------------------------- iol_wrapper */

/*
 * iol_wrapper is the one wrapper that cannot be proved end to end here: it
 * drives a licensed Cisco IOL image, this repository ships none, and neither
 * does the reference appliance. What follows is therefore deliberately
 * exhaustive about the parts that CAN be proved without one — and in particular
 * about every place where a byte that arrived from a socket becomes an array
 * index. tools/integration/iol-dataplane.sh takes the rest as far as it can go,
 * with a stand-in IOL that speaks the same bus.
 */

/* A scratch path for the NETMAP cases. Per-pid so a parallel `make test` in two
 * checkouts does not have two tests writing the same file. */
static const char *netmap_tmp(void)
{
	static char path[64];

	snprintf(path, sizeof(path), "/tmp/wrapper_test_netmap.%d", (int) getpid());
	return path;
}

static char *slurp(const char *path, size_t *len)
{
	FILE  *f = fopen(path, "rb");
	char  *buf;
	long   n;

	*len = 0;
	if (f == NULL)
		return NULL;
	if (fseek(f, 0, SEEK_END) != 0 || (n = ftell(f)) < 0) {
		fclose(f);
		return NULL;
	}
	rewind(f);
	buf = malloc((size_t) n + 1);
	if (buf == NULL) {
		fclose(f);
		return NULL;
	}
	*len = fread(buf, 1, (size_t) n, f);
	buf[*len] = '\0';
	fclose(f);
	return buf;
}

static void test_iol_parse(void)
{
	iol_opts_t o;
	char       img[64];

	printf("\n=============== iol_wrapper OPTIONS ===============\n");

	/* -F is checked with access(), so the tests need a file that exists. */
	snprintf(img, sizeof(img), "/tmp/wrapper_test_image.%d", (int) getpid());
	{
		FILE *f = fopen(img, "w");

		if (f != NULL)
			fclose(f);
	}

	/*
	 * devices/iol/device_iol.php::command(), verbatim in shape: -D -S -P -t -F
	 * -d -e -s, then one -l per connected serial interface, then the tail.
	 */
	{
		char *argv[] = { "iol_wrapper", "-D", "3", "-S", "12", "-P", "30012",
		                 "-t", "R1", "-F", img, "-d", "0", "-e", "2", "-s", "2",
		                 "-l", "2:localhost:7:2:30013",
		                 "-l", "18:localhost:7:18:30013",
		                 "--", "-n", "1024", "-q", "-m", "512" };

		chk("the PHP's exact invocation parses",
		    iol_parse(27, argv, &o) == IOL_OK);
		chk("device id comes from -D", o.device == 3);
		chk("session comes from -S", o.session == 12);
		chk("port comes from -P", o.port == 30012);
		chk_str("title comes from -t", o.title, "R1");
		chk_str("image comes from -F", o.image, img);
		chk("ethernet groups come from -e", o.eth == 2);
		chk("serial groups come from -s", o.ser == 2);
		chk("both link maps were taken", o.nlinks == 2);
		chk("a map is indexed by its local interface",
		    o.links[2].used == 1 && o.links[18].used == 1);
		chk("the map's remote device is parsed", o.links[2].remote_dev == 7);
		chk("the map's remote interface is parsed", o.links[18].remote_if == 18);
		chk("the map's remote port is parsed", o.links[2].remote_port == 30013);
		chk_str("the map's host is parsed", o.links[2].host, "localhost");
		chk("unmapped interfaces stay unmapped", o.links[0].used == 0);
		chk("the child command starts after --", o.cmd_from == 22);
		chk("tenant defaults to 0, which is what the fork runs with",
		    o.tenant == 0);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img };

		chk("the defaults apply", iol_parse(7, argv, &o) == IOL_OK);
		chk("-e defaults to 2", o.eth == 2);
		chk("-s defaults to 2", o.ser == 2);
		chk_str("the default title is the original's", o.title,
		        "Terminal Server");
		chk("no -- means no child arguments", o.cmd_from == -1);
		chk("no -S is not fatal, only inert TAPs", o.session == -1);
	}

	/*
	 * §10.3 #5 / R8. The original's -l handler runs inside the option loop and
	 * needs -T, -D and -P to have been seen already; its usage text says "use
	 * the above parameter order". Here the maps are collected and parsed after
	 * the loop, so this — every option in the wrong order — is fine.
	 */
	{
		char *argv[] = { "w", "-l", "2:localhost:7:2:30013", "-s", "2",
		                 "-e", "2", "-P", "9", "-F", img, "-D", "1" };

		chk("-l before -D, -P, -e and -s parses (§10.3 #5)",
		    iol_parse(13, argv, &o) == IOL_OK);
		chk("the map survives the reordering", o.links[2].remote_dev == 7);
	}

	/*
	 * The reason "--" is found before getopt runs, and the single most
	 * dangerous argument the PHP emits: when a node has keepalive enabled,
	 * device_iol.php appends a BARE `-l` to IOL's own flags. If getopt saw the
	 * tail, that -l would swallow "-c" as a link map, the map would be
	 * rejected, and the node would not start — for a reason nothing in the log
	 * would connect to the keepalive checkbox.
	 */
	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img,
		                 "--", "-n", "1024", "-q", "-m", "512", "-l",
		                 "-c", "startup-config" };

		chk("IOL's own bare -l in the tail is not mistaken for a link map",
		    iol_parse(16, argv, &o) == IOL_OK);
		chk("...and no link map was invented", o.nlinks == 0);
		chk("...and the whole tail belongs to the child", o.cmd_from == 8);
	}

	{
		char *argv[] = { "w", "-P", "9", "-F", img };

		chk("a missing -D is a usage error", iol_parse(5, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "0", "-P", "9", "-F", img };

		chk("device id 0 is rejected", iol_parse(7, argv, &o) == IOL_USAGE);
	}

	/*
	 * R9. The wrapper impersonates instance <id>+512, so an id above 512 would
	 * answer to a bus address that belongs to a real node. This is the same
	 * bound getIolId() enforces in includes/__node.php, restated where it can
	 * actually be checked.
	 */
	{
		char *argv[] = { "w", "-D", "513", "-P", "9", "-F", img };

		chk("device id 513 is rejected (R9: it would collide with <id>+512)",
		    iol_parse(7, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "512", "-P", "9", "-F", img };

		chk("device id 512 is accepted", iol_parse(7, argv, &o) == IOL_OK);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9" };

		chk("a missing -F is a usage error", iol_parse(5, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F",
		                 "/nonexistent/iol/image" };

		chk("an -F that does not exist is a usage error, not a dead node",
		    iol_parse(7, argv, &o) == IOL_USAGE);
	}

	/* §10.3 #6 in spirit: without -P nothing ever LISTENs, and R1 says the node
	 * then reads as stopped however healthy IOL is. */
	{
		char *argv[] = { "w", "-D", "1", "-F", img };

		chk("a missing -P is a usage error, not a late bind failure",
		    iol_parse(5, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img,
		                 "-e", "9", "-s", "8" };

		chk("-e + -s above 16 is rejected (§5.2 validation 4)",
		    iol_parse(11, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img,
		                 "-e", "8", "-s", "8" };

		chk("-e + -s of exactly 16 is accepted",
		    iol_parse(11, argv, &o) == IOL_OK);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img, "-T", "256" };

		chk("a tenant id above 255 is rejected (it is one header byte)",
		    iol_parse(9, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img, "-T", "255" };

		chk("tenant 255 is accepted", iol_parse(9, argv, &o) == IOL_OK);
		chk("tenant comes from -T", o.tenant == 255);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "70000", "-F", img };

		chk("a port above 65535 is rejected", iol_parse(7, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "abc", "-P", "9", "-F", img };

		chk("a non-numeric device id is rejected",
		    iol_parse(7, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img, "-Q" };

		chk("an unknown option is a usage error",
		    iol_parse(8, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img, "stray" };

		chk("a stray word before -- is a usage error",
		    iol_parse(8, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "9", "-F", img,
		                 "-l", "2:localhost:7:64:30013" };

		chk("one bad -l fails the whole invocation rather than silently "
		    "half-wiring the lab", iol_parse(9, argv, &o) == IOL_USAGE);
	}

	{
		char *argv[] = { "w", "-v" };

		chk("-v asks for the version", iol_parse(2, argv, &o) == IOL_VERSION);
	}

	{
		char *argv[] = { "w", "-D", "1", "-P", "1234", "-F", img };

		chk("parsing is repeatable (getopt state is reset)",
		    iol_parse(7, argv, &o) == IOL_OK && o.port == 1234);
	}

	unlink(img);
}

static void test_iol_link_parse(void)
{
	iol_link_t l;

	printf("\n=============== iol_wrapper LINK MAPS ===============\n");

	chk("a well-formed map parses",
	    iol_link_parse("18:localhost:7:34:30013", 2, 2, &l) == 0);
	chk("field 1 is the local interface", l.local_if == 18);
	chk_str("field 2 is the host", l.host, "localhost");
	chk("field 3 is the remote device id", l.remote_dev == 7);
	chk("field 4 is the remote interface", l.remote_if == 34);
	chk("field 5 is the remote node's console port", l.remote_port == 30013);
	chk("a parsed map has no socket yet", l.fd == -1);

	chk("an IPv4 literal host works too",
	    iol_link_parse("2:127.0.0.1:7:2:30013", 2, 2, &l) == 0 &&
	    strcmp(l.host, "127.0.0.1") == 0);

	/*
	 * THE BOUNDS. links[] is IOL_MAX_IFACES long and is indexed by the local
	 * interface at every frame that comes off the bus; the remote interface is
	 * written into a header byte and indexes an array at the far end. Both are
	 * checked once, here, at parse time — which is the only place they can be
	 * checked once.
	 */
	chk("local interface 63 is the last valid one",
	    iol_link_parse("63:localhost:7:2:30013", 0, 0, &l) == 0);
	chk("local interface 64 is rejected",
	    iol_link_parse("64:localhost:7:2:30013", 0, 0, &l) != 0);
	chk("a wildly out-of-range local interface is rejected",
	    iol_link_parse("99999:localhost:7:2:30013", 0, 0, &l) != 0);
	chk("remote interface 63 is the last valid one",
	    iol_link_parse("2:localhost:7:63:30013", 0, 0, &l) == 0);
	chk("remote interface 64 is rejected",
	    iol_link_parse("2:localhost:7:64:30013", 0, 0, &l) != 0);

	chk("remote device 0 is rejected (no IOL instance is 0)",
	    iol_link_parse("2:localhost:0:2:30013", 0, 0, &l) != 0);
	chk("remote device 65536 is rejected (it is a 16-bit field)",
	    iol_link_parse("2:localhost:65536:2:30013", 0, 0, &l) != 0);
	chk("remote device 65535 is accepted",
	    iol_link_parse("2:localhost:65535:2:30013", 0, 0, &l) == 0);

	chk("port 0 is rejected", iol_link_parse("2:localhost:7:2:0", 0, 0, &l) != 0);
	chk("port 65536 is rejected",
	    iol_link_parse("2:localhost:7:2:65536", 0, 0, &l) != 0);

	chk("four fields are not enough",
	    iol_link_parse("2:localhost:7:2", 0, 0, &l) != 0);
	chk("six fields are too many",
	    iol_link_parse("2:localhost:7:2:30013:9", 0, 0, &l) != 0);
	chk("an IPv6 literal host is refused rather than mis-split",
	    iol_link_parse("2:::1:7:2:30013", 0, 0, &l) != 0);
	chk("an empty host is rejected",
	    iol_link_parse("2::7:2:30013", 0, 0, &l) != 0);
	chk("an empty map is rejected", iol_link_parse("", 0, 0, &l) != 0);
	chk("a non-numeric interface is rejected",
	    iol_link_parse("x:localhost:7:2:30013", 0, 0, &l) != 0);
	chk("a negative interface is rejected",
	    iol_link_parse("-1:localhost:7:2:30013", 0, 0, &l) != 0);
	chk("an interface with trailing junk is rejected",
	    iol_link_parse("2x:localhost:7:2:30013", 0, 0, &l) != 0);
	chk("a numeric field longer than any number is rejected",
	    iol_link_parse("00000000000000000000000000000002:localhost:7:2:30013",
	                   0, 0, &l) != 0);

	{
		char big[IOL_HOST_MAX + 64];
		char spec[sizeof(big) + 64];
		size_t i;

		for (i = 0; i < sizeof(big) - 1; i++)
			big[i] = 'h';
		big[sizeof(big) - 1] = '\0';
		snprintf(spec, sizeof(spec), "2:%s:7:2:30013", big);
		chk("an over-long host name is rejected, not truncated into the "
		    "buffer", iol_link_parse(spec, 0, 0, &l) != 0);
	}

	/* Consistency with -e/-s is a warning, not an error: the map is inert
	 * because iol_if_kind() sends that interface's frames to a TAP, and
	 * refusing to start a node over an unused map would be a bad trade. */
	chk("a map naming an Ethernet interface still parses (it is merely inert)",
	    iol_link_parse("0:localhost:7:2:30013", 2, 2, &l) == 0);
}

static void test_iol_netmap(void)
{
	const char *path = netmap_tmp();
	char       *body;
	size_t      len;
	int         lines, i;

	printf("\n=============== iol_wrapper NETMAP ===============\n");

	unlink(path);
	chk("NETMAP is written", iol_netmap_write(path, 1) == 0);

	body = slurp(path, &len);
	if (body == NULL) {
		bad("NETMAP is readable", "could not read it back");
		return;
	}

	/*
	 * §5.4, asserted byte for byte. This file is IOL's own wiring table and
	 * nothing else validates it: a wrong separator or a missing line is a node
	 * whose interfaces are silently dead, with no error anywhere.
	 */
	chk("the first line wires interface 0 to the pseudo-instance",
	    strncmp(body, "1:0 513:0\n", 10) == 0);
	chk("the last line wires interface 63",
	    len >= 12 && strcmp(body + len - 12, "1:63 513:63\n") == 0);

	lines = 0;
	for (i = 0; body[i] != '\0'; i++)
		if (body[i] == '\n')
			lines++;
	chk("there are exactly 64 lines, one per interface", lines == 64);
	chk("the file ends with a newline", len > 0 && body[len - 1] == '\n');

	{
		/* Every line, in full, for a two-digit device id — the +512 offset is
		 * the whole mechanism (R9) and an off-by-one in it would wire the node
		 * to a different node's wrapper. */
		char        expect[2048];
		size_t      n = 0;
		char       *body2;
		size_t      len2;

		for (i = 0; i < 64; i++)
			n += (size_t) snprintf(expect + n, sizeof(expect) - n,
			                       "42:%d 554:%d\n", i, i);
		unlink(path);
		chk("NETMAP is written for device 42", iol_netmap_write(path, 42) == 0);
		body2 = slurp(path, &len2);
		if (body2 != NULL) {
			chk_str("every one of the 64 lines is exactly <id>:<i> "
			        "<id+512>:<i>", body2, expect);
			free(body2);
		} else {
			bad("NETMAP for device 42 is readable", NULL);
		}
	}

	/*
	 * §5.4 opens the file for APPEND, so an existing file has to be removed
	 * first or the second run of a node doubles it. A stale NETMAP is not a
	 * cosmetic problem: it can wire this node's interfaces to an instance that
	 * is not its wrapper.
	 */
	{
		char  *first, *again;
		size_t len_a = 0, len_b = 0;

		unlink(path);
		chk("NETMAP is written once", iol_netmap_write(path, 42) == 0);
		first = slurp(path, &len_a);
		chk("writing NETMAP a second time succeeds",
		    iol_netmap_write(path, 42) == 0);
		again = slurp(path, &len_b);
		chk("a second write replaces the file rather than appending to it",
		    first != NULL && again != NULL && len_a == len_b &&
		    strcmp(first, again) == 0);
		free(first);
		free(again);
	}

	free(body);
	unlink(path);
}

static void test_iol_if_kind(void)
{
	printf("\n=============== iol_wrapper INTERFACE CLASSIFICATION ===============\n");

	/*
	 * §5.6 and devices/iol/device_iol.php: an interface number is
	 * 16 * unit + group, the low nibble is the port group, and the serial
	 * groups follow the ethernet ones in one flat space. With -e 2 -s 2:
	 * groups 0 and 1 are e0/x and e1/x, groups 2 and 3 are s2/x and s3/x.
	 */
	chk("interface 0 (e0/0) is ethernet", iol_if_kind(2, 0) == IOL_IF_ETH);
	chk("interface 1 (e1/0) is ethernet", iol_if_kind(2, 1) == IOL_IF_ETH);
	chk("interface 2 (s2/0) is serial", iol_if_kind(2, 2) == IOL_IF_SER);
	chk("interface 16 (e0/1) is ethernet — the high nibble is the unit",
	    iol_if_kind(2, 16) == IOL_IF_ETH);
	chk("interface 18 (s2/1) is serial", iol_if_kind(2, 18) == IOL_IF_SER);
	chk("interface 48 (e0/3) is ethernet", iol_if_kind(2, 48) == IOL_IF_ETH);
	chk("interface 63 (s15/3) is serial", iol_if_kind(2, 63) == IOL_IF_SER);

	chk("with -e 0 everything is serial", iol_if_kind(0, 0) == IOL_IF_SER);
	chk("with -e 16 everything is ethernet", iol_if_kind(16, 15) == IOL_IF_ETH);

	/*
	 * The bound that protects tap_fd[] and links[]. IOL has four interfaces per
	 * group, so a unit above 3 cannot exist and any byte >= 64 is nonsense —
	 * and this byte arrives from a socket.
	 */
	chk("interface 64 is rejected: the array is 64 long",
	    iol_if_kind(2, 64) == IOL_IF_BAD);
	chk("interface 200 is rejected", iol_if_kind(2, 200) == IOL_IF_BAD);
	chk("interface 255 — a whole byte of ones — is rejected",
	    iol_if_kind(2, 255) == IOL_IF_BAD);
}

static void test_iol_frames(void)
{
	unsigned char frame[64];
	unsigned char out[IOL_FRAME_MAX];
	unsigned char pkt[64];
	size_t        outlen = 0;
	iol_link_t    l;

	printf("\n=============== iol_wrapper FRAME ENCODING ===============\n");

	memset(&l, 0, sizeof(l));
	l.used       = 1;
	l.local_if   = 18;
	l.remote_dev = 7;
	l.remote_if  = 34;
	l.remote_port = 30013;

	/* The bus header: dst id, src id, dst interface, src interface. */
	memset(frame, 0xaa, sizeof(frame));
	iol_hdr_build(frame, 3, 515, 18, 18);
	chk_bytes("a bus header is <dst id BE> <src id BE> <dst if> <src if> 00 00",
	          frame, 8, "\x00\x03\x02\x03\x12\x12\x00\x00", 8);
	chk("the source interface is read from offset 5",
	    iol_hdr_src_if(frame, 8) == 18);
	chk("the destination device is read from offsets 0-1",
	    iol_hdr_dst_dev(frame, 8) == 3);
	chk("a frame with no room for a header reports -1",
	    iol_hdr_src_if(frame, 7) == -1 && iol_hdr_dst_dev(frame, 7) == -1);

	/* A 16-bit id that needs both bytes: 513 = 0x0201. */
	iol_hdr_build(frame, 513, 1025, 0, 0);
	chk_bytes("a device id above 255 uses both header bytes",
	          frame, 4, "\x02\x01\x04\x01", 4);

	/*
	 * §5.6, the rewrite. The UDP header is NOT the bus header: it carries the
	 * tenant in the first two bytes and shifts the ids and interfaces along by
	 * two. The source interface therefore has to be read out of offset 5
	 * before anything is written, because the rewrite is in place — getting
	 * that wrong sends every frame back out of the interface it arrived on.
	 */
	memset(frame, 0, sizeof(frame));
	iol_hdr_build(frame, 515, 3, 18, 18);   /* IOL -> the pseudo-instance */
	memcpy(frame + 8, "PAYLOAD", 7);
	chk("a bus frame is rewritten for the tunnel",
	    iol_to_udp(frame, 15, 0, 3, &l) == 0);
	chk_bytes("the tunnel header is <tenant> <tenant> <dst id BE> <src id BE> "
	          "<dst if> <src if>",
	          frame, 8, "\x00\x00\x00\x07\x00\x03\x22\x12", 8);
	chk_bytes("the payload is untouched", frame + 8, 7, "PAYLOAD", 7);

	{
		unsigned char t[16];

		memset(t, 0, sizeof(t));
		iol_hdr_build(t, 515, 3, 18, 18);
		chk("a non-zero tenant reaches both tenant bytes",
		    iol_to_udp(t, 8, 200, 3, &l) == 0);
		chk_bytes("...as 0xc8 0xc8", t, 2, "\xc8\xc8", 2);
	}

	chk("a frame too short to hold a header is not rewritten",
	    iol_to_udp(frame, 7, 0, 3, &l) != 0);
	{
		iol_link_t unused;

		memset(&unused, 0, sizeof(unused));
		chk("an unmapped link is not rewritten",
		    iol_to_udp(frame, 15, 0, 3, &unused) != 0);
	}

	printf("\n=============== iol_wrapper FRAME VALIDATION ===============\n");

	/*
	 * The receive path. This UDP socket is bound to a wildcard address, so
	 * every one of these checks stands between something anybody on the
	 * network can send and a byte that indexes a 64-entry array. Each has its
	 * own return code so a failure here says WHICH check stopped firing.
	 */
	memset(pkt, 0, sizeof(pkt));
	pkt[0] = 0; pkt[1] = 0;              /* tenant */
	pkt[2] = 0; pkt[3] = 3;              /* destination device: us */
	pkt[4] = 0; pkt[5] = 7;              /* source device */
	pkt[6] = 18;                         /* destination interface */
	pkt[7] = 34;                         /* source interface */
	memcpy(pkt + 8, "PAYLOAD", 7);

	chk("a well-formed datagram is accepted",
	    iol_from_udp(pkt, 15, 0, 3, out, sizeof(out), &outlen) == IOL_UDP_OK);
	chk("the result is the same length", outlen == 15);
	/* Addressed to the real instance, from the pseudo-instance, on the
	 * interface the header named — NETMAP wires i to i, so both ends of the
	 * bus header carry the same interface number. */
	chk_bytes("it becomes a bus frame addressed to the real instance",
	          out, 8, "\x00\x03\x02\x03\x12\x12\x00\x00", 8);
	chk_bytes("with the payload intact", out + 8, 7, "PAYLOAD", 7);

	chk("a datagram shorter than a header is dropped",
	    iol_from_udp(pkt, 7, 0, 3, out, sizeof(out), &outlen)
	    == IOL_UDP_ERR_SHORT);
	chk("a header with no payload is still valid",
	    iol_from_udp(pkt, 8, 0, 3, out, sizeof(out), &outlen) == IOL_UDP_OK);
	chk("a datagram longer than the output buffer is dropped",
	    iol_from_udp(pkt, 15, 0, 3, out, 14, &outlen) == IOL_UDP_ERR_LONG);
	chk("a datagram longer than the 10000-byte receive buffer is dropped",
	    iol_from_udp(pkt, IOL_FRAME_MAX + 1, 0, 3, out, sizeof(out), &outlen)
	    == IOL_UDP_ERR_LONG);

	chk("a datagram for another tenant is dropped",
	    iol_from_udp(pkt, 15, 1, 3, out, sizeof(out), &outlen)
	    == IOL_UDP_ERR_TENANT);
	chk("a datagram for another node is dropped",
	    iol_from_udp(pkt, 15, 0, 4, out, sizeof(out), &outlen)
	    == IOL_UDP_ERR_DEVICE);

	/* The one that matters most: offset 6 becomes an array index. */
	pkt[6] = 64;
	chk("a destination interface of 64 is dropped, not indexed",
	    iol_from_udp(pkt, 15, 0, 3, out, sizeof(out), &outlen)
	    == IOL_UDP_ERR_IFACE);
	pkt[6] = 255;
	chk("a destination interface of 255 is dropped, not indexed",
	    iol_from_udp(pkt, 15, 0, 3, out, sizeof(out), &outlen)
	    == IOL_UDP_ERR_IFACE);
	pkt[6] = 63;
	chk("a destination interface of 63 is accepted",
	    iol_from_udp(pkt, 15, 0, 3, out, sizeof(out), &outlen) == IOL_UDP_OK);

	/* A device id that needs both bytes, to prove the comparison is 16-bit and
	 * not "the low byte matched". */
	pkt[2] = 0x02; pkt[3] = 0x01;
	chk("a 16-bit destination device id is compared in full",
	    iol_from_udp(pkt, 15, 0, 513, out, sizeof(out), &outlen) == IOL_UDP_OK);
	chk("...and a matching low byte alone is not enough",
	    iol_from_udp(pkt, 15, 0, 1, out, sizeof(out), &outlen)
	    == IOL_UDP_ERR_DEVICE);

	chk("every rejection has a message", iol_udp_strerror(IOL_UDP_ERR_IFACE)
	    != NULL && *iol_udp_strerror(IOL_UDP_ERR_IFACE) != '\0');
}

/* ------------------------------------------------- iol: the data-plane bind */

/* One datagram to `to`, then up to `wait_ms` for it to show up on `fd`. */
static int udp_arrives(int fd, const struct sockaddr *to, socklen_t tolen,
                       int wait_ms)
{
	struct pollfd pfd;
	unsigned char buf[64];
	int           s, got = 0;

	s = socket(to->sa_family, SOCK_DGRAM, 0);
	if (s < 0)
		return -1;
	(void) sendto(s, "probe", 5, 0, to, tolen);
	close(s);

	pfd.fd = fd;
	pfd.events = POLLIN;
	if (poll(&pfd, 1, wait_ms) > 0 && (pfd.revents & POLLIN))
		got = recv(fd, buf, sizeof(buf), 0) == 5;
	return got;
}

static void test_iol_udp_bind(void)
{
	struct sockaddr_storage ss;
	struct sockaddr_in      a4, *b4;
	struct sockaddr_in6     a6;
	struct ifaddrs         *ifa = NULL, *cur;
	socklen_t               len;
	int                     fd, port = 0, found = 0;

	printf("\n-- iol: the serial data plane binds loopback\n");

	/* The address classifier, on its own. */
	memset(&a4, 0, sizeof(a4));
	a4.sin_family = AF_INET;
	inet_pton(AF_INET, "127.0.0.1", &a4.sin_addr);
	chk("127.0.0.1 is loopback",
	    iol_sockaddr_is_loopback((struct sockaddr *) &a4, sizeof(a4)));
	inet_pton(AF_INET, "127.200.3.4", &a4.sin_addr);
	chk("all of 127/8 is loopback",
	    iol_sockaddr_is_loopback((struct sockaddr *) &a4, sizeof(a4)));
	inet_pton(AF_INET, "10.0.0.1", &a4.sin_addr);
	chk("10.0.0.1 is not",
	    !iol_sockaddr_is_loopback((struct sockaddr *) &a4, sizeof(a4)));
	inet_pton(AF_INET, "0.0.0.0", &a4.sin_addr);
	chk("0.0.0.0 is not",
	    !iol_sockaddr_is_loopback((struct sockaddr *) &a4, sizeof(a4)));
	memset(&a6, 0, sizeof(a6));
	a6.sin6_family = AF_INET6;
	inet_pton(AF_INET6, "::1", &a6.sin6_addr);
	chk("::1 is loopback",
	    iol_sockaddr_is_loopback((struct sockaddr *) &a6, sizeof(a6)));
	inet_pton(AF_INET6, "::ffff:127.0.0.1", &a6.sin6_addr);
	chk("::ffff:127.0.0.1 is loopback",
	    iol_sockaddr_is_loopback((struct sockaddr *) &a6, sizeof(a6)));
	inet_pton(AF_INET6, "::ffff:10.0.0.1", &a6.sin6_addr);
	chk("::ffff:10.0.0.1 is not",
	    !iol_sockaddr_is_loopback((struct sockaddr *) &a6, sizeof(a6)));
	inet_pton(AF_INET6, "2001:db8::1", &a6.sin6_addr);
	chk("2001:db8::1 is not",
	    !iol_sockaddr_is_loopback((struct sockaddr *) &a6, sizeof(a6)));
	inet_pton(AF_INET6, "::", &a6.sin6_addr);
	chk(":: is not",
	    !iol_sockaddr_is_loopback((struct sockaddr *) &a6, sizeof(a6)));
	chk("a NULL address is not",
	    !iol_sockaddr_is_loopback(NULL, 0));
	chk("a truncated address is not",
	    !iol_sockaddr_is_loopback((struct sockaddr *) &a4, 2));

	/* The default bind: a real socket, on an ephemeral port. */
	fd = iol_udp_open(0, 0);
	chk("iol_udp_open(port, remote=0) opens a socket", fd >= 0);
	if (fd >= 0) {
		len = sizeof(ss);
		memset(&ss, 0, sizeof(ss));
		chk("getsockname", getsockname(fd, (struct sockaddr *) &ss, &len) == 0);
		chk("it is an IPv4 socket", ss.ss_family == AF_INET);
		b4 = (struct sockaddr_in *) &ss;
		chk("bound to 127.0.0.1, not INADDR_ANY",
		    ss.ss_family == AF_INET
		    && ntohl(b4->sin_addr.s_addr) == INADDR_LOOPBACK);
		port = ntohs(b4->sin_port);
		chk("on a real port", port > 0);

		/* Loopback delivers. */
		memset(&a4, 0, sizeof(a4));
		a4.sin_family = AF_INET;
		a4.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
		a4.sin_port = htons((unsigned short) port);
		chk("a datagram sent to 127.0.0.1:<port> arrives",
		    udp_arrives(fd, (struct sockaddr *) &a4, sizeof(a4), 500) == 1);

		/* The same port on every non-loopback IPv4 address this host has
		 * does not: that is the whole point. If the host has none (a bare
		 * container), there is nothing to send to and the check is moot. */
		if (getifaddrs(&ifa) == 0) {
			for (cur = ifa; cur != NULL; cur = cur->ifa_next) {
				struct sockaddr_in to;

				if (cur->ifa_addr == NULL || cur->ifa_addr->sa_family != AF_INET)
					continue;
				if (iol_sockaddr_is_loopback(cur->ifa_addr, sizeof(struct sockaddr_in)))
					continue;
				memcpy(&to, cur->ifa_addr, sizeof(to));
				to.sin_port = htons((unsigned short) port);
				found++;
				{
					char name[INET_ADDRSTRLEN + 64];
					char addr[INET_ADDRSTRLEN];

					inet_ntop(AF_INET, &to.sin_addr, addr, sizeof(addr));
					snprintf(name, sizeof(name),
					         "the same port on %s (%s) does not deliver", addr,
					         cur->ifa_name);
					chk(name, udp_arrives(fd, (struct sockaddr *) &to,
					                      sizeof(to), 300) == 0);
				}
			}
			freeifaddrs(ifa);
		}
		if (found == 0)
			printf("   (no non-loopback IPv4 address on this host; the "
			       "reachability check has nothing to send to)\n");
		close(fd);
	}

	/* -R: the old wildcard bind, kept for a cross-host link that opts in. */
	fd = iol_udp_open(0, 1);
	chk("iol_udp_open(port, remote=1) opens a socket", fd >= 0);
	if (fd >= 0) {
		len = sizeof(ss);
		memset(&ss, 0, sizeof(ss));
		chk("getsockname", getsockname(fd, (struct sockaddr *) &ss, &len) == 0);
		if (ss.ss_family == AF_INET6) {
			struct sockaddr_in6 *b6 = (struct sockaddr_in6 *) &ss;

			chk("with -R it is bound to [::]",
			    IN6_IS_ADDR_UNSPECIFIED(&b6->sin6_addr));
		} else {
			b4 = (struct sockaddr_in *) &ss;
			chk("with -R (no IPv6 here) it is bound to 0.0.0.0",
			    ss.ss_family == AF_INET && b4->sin_addr.s_addr == htonl(INADDR_ANY));
		}
		close(fd);
	}

	/* And the flag itself. */
	{
		iol_opts_t o;
		char *argv_def[] = { "iol_wrapper", "-D", "1", "-P", "30001", "-F", "/bin/true", NULL };
		char *argv_rem[] = { "iol_wrapper", "-R", "-D", "1", "-P", "30001", "-F", "/bin/true", NULL };

		chk("no -R parses", iol_parse(7, argv_def, &o) == IOL_OK);
		chk("and leaves remote off -- the fork never passes it", o.remote == 0);
		chk("-R parses", iol_parse(8, argv_rem, &o) == IOL_OK);
		chk("and turns remote on", o.remote == 1);
	}
}

static void test_iol_command(void)
{
	iol_opts_t o;
	cmdline_t  c;
	char       img[64];
	char       quoted[64];

	printf("\n=============== iol_wrapper CHILD COMMAND ===============\n");

	chk("a plain word quotes to itself in quotes",
	    iol_sh_quote("/opt/unetlab/tmp/1/2/iol.bin", quoted,
	                 sizeof(quoted)) == 0);
	chk_str("...like this", quoted, "'/opt/unetlab/tmp/1/2/iol.bin'");
	chk("an embedded single quote is escaped, not swallowed",
	    iol_sh_quote("a'b", quoted, sizeof(quoted)) == 0);
	chk_str("...by closing, escaping and reopening", quoted, "'a'\\''b'");
	chk("a path that will not fit is refused rather than truncated",
	    iol_sh_quote("aaaaaaaaaaaaaaaa", quoted, 8) != 0);
	/* One quote expands to '\'' inside the outer quotes: 6 bytes plus NUL.
	 * Exactly 7 fits; 6 must be refused, not written one byte past the
	 * end. This was a one-byte heap overflow, and the buffers here are
	 * malloc'd at exactly the size under test so that, under ASan, the
	 * old code fails this rather than scribbling inside a larger array. */
	{
		char *exact = malloc(7);
		char *short1 = malloc(6);
		char *exact2 = malloc(9);
		char *short2 = malloc(8);

		chk("a quote in the last usable position fits exactly",
		    exact != NULL && iol_sh_quote("'", exact, 7) == 0);
		chk_str("...as the six-byte form", exact, "''\\'''");
		chk("and one byte less is refused rather than overrun",
		    short1 != NULL && iol_sh_quote("'", short1, 6) != 0);
		chk("a quote after a run of plain bytes is bounded too",
		    short2 != NULL && iol_sh_quote("ab'", short2, 8) != 0);
		chk("...and fits with one more byte",
		    exact2 != NULL && iol_sh_quote("ab'", exact2, 9) == 0);
		free(exact);
		free(short1);
		free(exact2);
		free(short2);
	}

	snprintf(img, sizeof(img), "/tmp/wrapper_test_image.%d", (int) getpid());
	{
		FILE *f = fopen(img, "w");

		if (f != NULL)
			fclose(f);
	}

	/*
	 * §5.3. The shape that matters:
	 *
	 *   <image> -e <eth> -s <ser> <everything after --> <device id>
	 *
	 * IOL takes its instance number as the TRAILING positional argument, so
	 * the caller's -n/-q/-m/-c land in the middle. Get the order wrong and IOL
	 * either refuses to start or starts as the wrong instance — and the wrong
	 * instance is a node wired to somebody else's NETMAP.
	 */
	{
		char *argv[] = { "iol_wrapper", "-D", "3", "-S", "12", "-P", "30012",
		                 "-t", "R1", "-F", img, "-d", "0", "-e", "2", "-s", "2",
		                 "--", "-n", "1024", "-q", "-m", "512",
		                 "-c", "startup-config" };
		char  want[256];

		chk("options parse", iol_parse(25, argv, &o) == IOL_OK);
		chk("cmdline_init", cmdline_init(&c) == 0);
		chk("the command assembles", iol_build_command(&o, 25, argv, &c) == 0);
		snprintf(want, sizeof(want),
		         "'%s' -e 2 -s 2 -n 1024 -q -m 512 -c startup-config 3", img);
		chk_str("image, -e/-s, the caller's tail, then the instance number",
		        cmdline_str(&c), want);
		cmdline_free(&c);
	}

	/* No tail at all: the instance number must still be last. */
	{
		char *argv[] = { "w", "-D", "7", "-P", "9", "-F", img, "-e", "1",
		                 "-s", "0" };
		char  want[256];

		chk("options parse without a tail", iol_parse(11, argv, &o) == IOL_OK);
		chk("cmdline_init", cmdline_init(&c) == 0);
		chk("the command assembles without a tail",
		    iol_build_command(&o, 11, argv, &c) == 0);
		snprintf(want, sizeof(want), "'%s' -e 1 -s 0 7", img);
		chk_str("the instance number is still the last word",
		        cmdline_str(&c), want);
		cmdline_free(&c);
	}

	/* §2.6: overflowing the cap is fatal, not truncating. A truncated IOL
	 * command line would start a node with the wrong instance number, because
	 * the instance number is the part that falls off the end. */
	{
		char *argv[] = { "w", "-D", "7", "-P", "9", "-F", img };

		chk("options parse", iol_parse(7, argv, &o) == IOL_OK);
		chk("cmdline_init with a tiny cap", cmdline_init_cap(&c, 16) == 0);
		chk("a command that will not fit is refused, not truncated",
		    iol_build_command(&o, 7, argv, &c) == -1);
		cmdline_free(&c);
	}

	unlink(img);
}

int main(void)
{
	/* Keep the log out of the assertion output; the error paths above log at
	 * ERR by design and we are asserting on return values, not on text. */
	log_init("wrapper_test");
	log_set_level(0);

	test_telnet();
	test_cmdline();
	test_qt_parse();
	test_dk_parse();
	test_dk_command();
	test_iol_parse();
	test_iol_link_parse();
	test_iol_netmap();
	test_iol_if_kind();
	test_iol_frames();
	test_iol_udp_bind();
	test_iol_command();

	printf("\n============================================\n");
	printf("  unit assertions: %d passed, %d failed\n", passed, failed);
	printf("============================================\n");

	return (failed == 0) ? 0 : 1;
}
