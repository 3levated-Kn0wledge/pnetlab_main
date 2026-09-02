/*
 * qemu_telnet.h — the testable half of qemu_wrapper_telnet's front-end.
 *
 * Only option parsing lives here, because that is the part with branches worth
 * asserting on; everything else is the shared core. See qemu_telnet.c.
 */
#ifndef WRAPPER_QEMU_TELNET_H
#define WRAPPER_QEMU_TELNET_H

#include "console.h"

typedef struct {
	int  port;         /* -P, -1 if unset */
	int  delay;        /* -d */
	int  no_console;   /* -x */
	int  cmd_from;     /* argv index of the first child-command word, -1 if none */
	char title[CONSOLE_TITLE_MAX];   /* -t */
} qt_opts_t;

typedef enum {
	QT_OK      = 0,
	QT_USAGE   = 1,   /* caller prints usage and exits 1 */
	QT_VERSION = 2    /* caller prints the version and exits 0 */
} qt_parse_t;

/*
 * Parse argv into opts. Does not print anything except errors; the caller owns
 * the usage text so the unit tests do not have to swallow it.
 */
qt_parse_t qt_parse(int argc, char *const argv[], qt_opts_t *opts);

void qt_usage(const char *progname);

#endif /* WRAPPER_QEMU_TELNET_H */
