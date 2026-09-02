/*
 * docker.h — the testable half of docker_wrapper's front-end.
 *
 * Option parsing and command assembly, which is where all the decisions are;
 * everything else is the shared core. See docker.c.
 */
#ifndef WRAPPER_DOCKER_H
#define WRAPPER_DOCKER_H

#include "cmdline.h"
#include "console.h"

/*
 * Where the Docker daemon is.
 *
 * The original — and, until this change, every `docker` invocation in the PHP —
 * used `-H=tcp://127.0.0.1:4243`: an unauthenticated TCP socket on which
 * `POST /containers/create` with `Binds: ["/:/host"]` is a root shell on the
 * host, reachable by anything that can open a loopback connection. Nothing in
 * install/ ever configured that socket either, so on a clean install it does not
 * exist and Docker nodes cannot work at all.
 *
 * The unix socket is the daemon's default endpoint, is root:docker 0660, and is
 * therefore gated by group membership rather than by a listening port. It is
 * named explicitly rather than left to the CLI's default so that a stray
 * DOCKER_HOST in the environment cannot redirect us.
 */
#define DOCKER_ENDPOINT "unix:///var/run/docker.sock"

/* -c. Long enough for any path; the fork passes "sh" or "/bin/bash". */
#define DOCKER_ATTACH_MAX 256

typedef struct {
	int  port;         /* -P, -1 if unset */
	int  session;      /* -p, -1 if unset; the container is docker<session> */
	int  delay;        /* -d */
	int  no_console;   /* -x */
	int  cmd_from;     /* argv index of the first word after "--", -1 if none */
	char title[CONSOLE_TITLE_MAX];      /* -t */
	char attach[DOCKER_ATTACH_MAX];     /* -c */
} dk_opts_t;

typedef enum {
	DK_OK      = 0,
	DK_USAGE   = 1,   /* caller prints usage and exits 1 */
	DK_VERSION = 2    /* caller prints the version and exits 0 */
} dk_parse_t;

/*
 * Parse argv into opts. Prints nothing but errors; the caller owns the usage
 * text so the unit tests do not have to swallow it.
 */
dk_parse_t dk_parse(int argc, char *const argv[], dk_opts_t *opts);

/*
 * Assemble the child command:
 *
 *   docker -H=<endpoint> exec -ti docker<session> <attach> [words after --]
 *
 * Returns 0, or -1 after logging (the ARG_MAX cap, §2.6). `cmd` must already be
 * cmdline_init()ed; the caller owns it either way.
 */
int dk_build_command(const dk_opts_t *opts, cmdline_t *cmd,
                     int argc, char *const argv[]);

void dk_usage(const char *progname);

#endif /* WRAPPER_DOCKER_H */
