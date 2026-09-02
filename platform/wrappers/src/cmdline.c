#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#include "cmdline.h"
#include "log.h"

int cmdline_init_cap(cmdline_t *c, size_t cap)
{
	memset(c, 0, sizeof(*c));

	if (cap < 2)
		cap = 2;
	c->cap = cap;
	c->buf = malloc(cap);
	if (c->buf == NULL) {
		log_errno("malloc(command buffer)");
		return -1;
	}
	c->buf[0] = '\0';
	return 0;
}

int cmdline_init(cmdline_t *c)
{
	long arg_max = sysconf(_SC_ARG_MAX);

	/* _SC_ARG_MAX is allowed to be indeterminate. POSIX guarantees at least
	 * _POSIX_ARG_MAX (4096); fall back to something a real command fits in. */
	if (arg_max <= 0)
		arg_max = 131072;

	return cmdline_init_cap(c, (size_t) arg_max);
}

void cmdline_free(cmdline_t *c)
{
	free(c->buf);
	c->buf = NULL;
	c->len = c->cap = 0;
}

static int cmdline_room(cmdline_t *c, size_t need)
{
	if (c->overflow)
		return -1;

	/* need excludes the NUL; cap includes it. */
	if (c->len + need + 1 > c->cap) {
		c->overflow = 1;
		log_err("command line exceeds the %zu byte limit (ARG_MAX); refusing to "
		        "start a truncated child", c->cap);
		return -1;
	}
	return 0;
}

int cmdline_append(cmdline_t *c, const char *s)
{
	size_t n;

	if (s == NULL)
		return 0;
	n = strlen(s);
	if (cmdline_room(c, n) != 0)
		return -1;

	memcpy(c->buf + c->len, s, n + 1);
	c->len += n;
	return 0;
}

int cmdline_append_word(cmdline_t *c, const char *word)
{
	if (cmdline_append(c, " ") != 0)
		return -1;
	return cmdline_append(c, word);
}

int cmdline_appendf(cmdline_t *c, const char *fmt, ...)
{
	va_list ap;
	int     n;

	if (c->overflow)
		return -1;

	va_start(ap, fmt);
	n = vsnprintf(c->buf + c->len, c->cap - c->len, fmt, ap);
	va_end(ap);

	if (n < 0) {
		c->overflow = 1;
		return -1;
	}
	if ((size_t) n >= c->cap - c->len) {
		c->buf[c->len] = '\0';
		c->overflow = 1;
		log_err("command line exceeds the %zu byte limit (ARG_MAX); refusing to "
		        "start a truncated child", c->cap);
		return -1;
	}
	c->len += (size_t) n;
	return 0;
}

int cmdline_find_separator(int argc, char *const argv[])
{
	int i;

	for (i = 1; i < argc; i++) {
		if (strcmp(argv[i], "--") == 0)
			return i;
	}
	return -1;
}

int cmdline_append_tail(cmdline_t *c, int argc, char *const argv[], int from)
{
	int i;

	if (from < 0)
		return 0;

	for (i = from; i < argc; i++) {
		if (cmdline_append_word(c, argv[i]) != 0)
			return -1;
	}
	return 0;
}

const char *cmdline_str(const cmdline_t *c)
{
	return (c->buf != NULL) ? c->buf : "";
}
