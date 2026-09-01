#include <errno.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

#include "log.h"

/*
 * The two marker files are the appliance's field-debugging affordance and cost
 * nothing to keep. They are the ONLY way to raise the level: §1.1 establishes
 * that the PHP sets no environment variables at all and passes no verbosity
 * flag, so an environment variable would be unreachable in production.
 */
#define MARKER_DEBUG   "/tmp/unl_ll_debug"
#define MARKER_VERBOSE "/tmp/unl_ll_verbose"

static log_level_t g_level = LOG_LEVEL_INF;
static const char *g_prog  = "wrapper";

static const char *level_name(log_level_t l)
{
	switch (l) {
	case LOG_LEVEL_ERR: return "ERR";
	case LOG_LEVEL_WRN: return "WRN";
	case LOG_LEVEL_INF: return "INF";
	case LOG_LEVEL_DBG: return "DBG";
	case LOG_LEVEL_VRB: return "VRB";
	}
	return "???";
}

void log_init(const char *progname)
{
	struct stat st;

	if (progname != NULL && *progname != '\0')
		g_prog = progname;

	/* See the header: an unflushed buffer is an empty wrapper.txt. */
	setvbuf(stdout, NULL, _IOLBF, 0);

	if (stat(MARKER_VERBOSE, &st) == 0)
		g_level = LOG_LEVEL_VRB;
	else if (stat(MARKER_DEBUG, &st) == 0)
		g_level = LOG_LEVEL_DBG;
}

void log_set_level(log_level_t level)
{
	g_level = level;
}

log_level_t log_get_level(void)
{
	return g_level;
}

void log_write(log_level_t level, const char *fmt, ...)
{
	struct timespec ts;
	struct tm       tm;
	va_list         ap;

	if (level > g_level)
		return;

	/* UTC, per §2.5 — the appliance's local time is whatever the installer
	 * happened to set, and correlating a console failure with the web log is
	 * easier when both are in one timezone. */
	if (clock_gettime(CLOCK_REALTIME, &ts) != 0 || gmtime_r(&ts.tv_sec, &tm) == NULL) {
		/* Trigraph-safe: "??/" would otherwise be read as a backslash. */
		printf("--/-- --:--:--.--- %s\t", level_name(level));
	} else {
		printf("%02d/%02d %02d:%02d:%02d.%03ld %s\t",
		       tm.tm_mday, tm.tm_mon + 1,
		       tm.tm_hour, tm.tm_min, tm.tm_sec,
		       ts.tv_nsec / 1000000L,
		       level_name(level));
	}

	va_start(ap, fmt);
	vprintf(fmt, ap);
	va_end(ap);

	putchar('\n');
}

void log_errno(const char *what)
{
	int saved = errno;

	log_write(LOG_LEVEL_ERR, "%s: %s failed: %s", g_prog, what, strerror(saved));
	errno = saved;
}
