/*
 * log.{c,h} — WRAPPER-SPEC §2.5, logging to stdout.
 *
 * The caller (devices/device.php) redirects the wrapper's stdout and stderr into
 * <running path>/wrapper.txt. Nothing in PNETLab parses that file, so the format
 * is ours to choose; what matters is that an operator debugging a console that
 * will not open can see the assembled child command line and the listener state
 * without turning anything on. Those are logged at INF, which is the default.
 */
#ifndef WRAPPER_LOG_H
#define WRAPPER_LOG_H

typedef enum {
	LOG_LEVEL_ERR = 1,
	LOG_LEVEL_WRN = 2,
	LOG_LEVEL_INF = 3,
	LOG_LEVEL_DBG = 4,
	LOG_LEVEL_VRB = 5
} log_level_t;

/*
 * Call once, first thing in main(). Sets the default threshold (INF), raises it
 * if the field-debugging marker files exist (§2.5), and — the part that actually
 * matters — puts stdout in line-buffered mode.
 *
 * The trap: stdout here is a redirected file, so libc block-buffers it. A
 * wrapper that dies before flushing leaves an EMPTY wrapper.txt, which is
 * precisely the situation in which someone goes looking at it. Line buffering
 * costs nothing at these volumes.
 */
void log_init(const char *progname);

void        log_set_level(log_level_t level);
log_level_t log_get_level(void);

void log_write(log_level_t level, const char *fmt, ...)
	__attribute__((format(printf, 2, 3)));

#define log_err(...) log_write(LOG_LEVEL_ERR, __VA_ARGS__)
#define log_wrn(...) log_write(LOG_LEVEL_WRN, __VA_ARGS__)
#define log_inf(...) log_write(LOG_LEVEL_INF, __VA_ARGS__)
#define log_dbg(...) log_write(LOG_LEVEL_DBG, __VA_ARGS__)
#define log_vrb(...) log_write(LOG_LEVEL_VRB, __VA_ARGS__)

/* Log "<what> failed: <strerror(errno)>" at ERR. */
void log_errno(const char *what);

#endif /* WRAPPER_LOG_H */
