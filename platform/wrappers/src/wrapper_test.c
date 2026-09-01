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

#include "cmdline.h"
#include "console.h"
#include "docker.h"
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

	printf("\n============================================\n");
	printf("  unit assertions: %d passed, %d failed\n", passed, failed);
	printf("============================================\n");

	return (failed == 0) ? 0 : 1;
}
