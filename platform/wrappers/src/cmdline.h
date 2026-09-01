/*
 * cmdline.{c,h} — WRAPPER-SPEC §2.6, assembling the child's shell command.
 *
 * Every wrapper builds exactly one command string and hands it to /bin/sh -c:
 *
 *   [fixed prefix][executable][fixed flags][ argv after a literal "--" ][suffix]
 *
 * The string is capped at sysconf(_SC_ARG_MAX); overflowing it is fatal rather
 * than silently truncating, because a truncated command line would start a child
 * that looks plausible and is wired to the wrong thing.
 *
 * Why a shell at all: the strings the PHP produces contain escapeshellarg()
 * quoting, and the IOL and Docker front-ends prepend "VAR=value" assignments
 * that only a shell interprets. Front-ends that can set the environment
 * themselves should do so (child_spec_t::env_extra) rather than relying on that
 * prefix, but the quoting still needs a shell to undo.
 */
#ifndef WRAPPER_CMDLINE_H
#define WRAPPER_CMDLINE_H

#include <stddef.h>

typedef struct {
	char  *buf;
	size_t len;
	size_t cap;   /* hard limit, ARG_MAX in production */
	int    overflow;
} cmdline_t;

/* cap = sysconf(_SC_ARG_MAX). Returns 0, or -1 on allocation failure. */
int cmdline_init(cmdline_t *c);

/* Explicit cap. Used by the unit tests, which cannot afford a 2 MB allocation
 * per case and need to exercise the overflow path deterministically. */
int cmdline_init_cap(cmdline_t *c, size_t cap);

void cmdline_free(cmdline_t *c);

/* Append verbatim, no separator. Returns 0, or -1 once the cap is exceeded. */
int cmdline_append(cmdline_t *c, const char *s);

/* Append " " then the word. This is the §2.6 separator rule. */
int cmdline_append_word(cmdline_t *c, const char *word);

int cmdline_appendf(cmdline_t *c, const char *fmt, ...)
	__attribute__((format(printf, 2, 3)));

/*
 * Index of the first literal "--" in argv, scanning from index 1, or -1.
 *
 * Call this BEFORE getopt(). GNU getopt permutes argv in place, so a scan
 * afterwards is reading a rearranged array. The intended use is:
 *
 *   int dd = cmdline_find_separator(argc, argv);
 *   int opt_argc = (dd < 0) ? argc : dd;      // hide the tail from getopt
 *   ... getopt(opt_argc, argv, ":P:d:t:x") ...
 *
 * which also means getopt can never mistake a child-command word for an option.
 */
int cmdline_find_separator(int argc, char *const argv[]);

/*
 * Append argv[from .. argc-1], each preceded by one space. Per §2.6 every
 * remaining element is appended including any further "--"; the separator is
 * only special the first time. Returns 0 or -1 on overflow.
 */
int cmdline_append_tail(cmdline_t *c, int argc, char *const argv[], int from);

/* Never NULL; empty string before anything is appended. */
const char *cmdline_str(const cmdline_t *c);

#endif /* WRAPPER_CMDLINE_H */
