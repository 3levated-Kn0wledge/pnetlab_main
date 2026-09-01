# platform/wrappers/src/Makefile
#
#   make            build every wrapper
#   make test       build and run the unit tests
#   make install    install into $(DESTDIR)$(WRAPPERDIR)
#
# The binaries are NOT committed to the repository — the fork ships source and
# builds during install, the same arrangement as the SQL schema. install/lib/
# is what calls this.
#
# R3: the basename of each target is the name devices/*.php invokes and the name
# `pkill -TERM iol_wrapper` / `pgrep -f -c -P 1 iol_wrapper` in
# includes/api_status.php matches on. Renaming a target breaks node teardown and
# the status counters, silently. Do not.

CC ?= cc

# §10.2. -O2 is not a taste question here: _FORTIFY_SOURCE is a no-op without
# optimisation and GCC emits a #warning saying so, which -Werror then turns into
# a build failure. -U first because Ubuntu's GCC already injects
# -D_FORTIFY_SOURCE=3 and redefining it on the command line is a warning too.
WARN     = -Wall -Wextra -Werror
HARDEN   = -fstack-protector-strong -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=2
# gnu11 rather than c11: strict ISO mode defines __STRICT_ANSI__, which makes
# glibc hide every POSIX declaration this code is built out of — sigaction,
# kill, nanosleep, gmtime_r. The alternative is a _POSIX_C_SOURCE define at the
# top of every file, which is the same thing spelled five times.
# -MMD -MP makes the compiler emit its own header dependencies into *.d, which
# is why there is no hand-written dependency list at the foot of this file any
# more. Three people are adding front-ends here in parallel; a list everyone has
# to remember to edit is a merge conflict waiting to happen, and a stale entry
# fails silently as a stale object file.
CFLAGS  += -std=gnu11 -O2 $(WARN) $(HARDEN) -MMD -MP
LDFLAGS +=

PREFIX     ?= /opt/unetlab
WRAPPERDIR ?= $(PREFIX)/wrappers

CORE_SRC = console.c child.c loop.c log.c cmdline.c
CORE_OBJ = $(CORE_SRC:.c=.o)

# Front-ends appear as their source files land. The $(wildcard) guards mean an
# incomplete tree still builds and still passes `make test`, so nobody is
# blocked on anybody else's file existing.
WRAPPERS = qemu_wrapper_telnet \
           $(if $(wildcard docker.c),docker_wrapper) \
           $(if $(wildcard iol.c),iol_wrapper)

TEST_NOMAIN = qemu_telnet_nomain.o \
              $(if $(wildcard docker.c),docker_nomain.o) \
              $(if $(wildcard iol.c),iol_nomain.o)

all: $(WRAPPERS)

qemu_wrapper_telnet: qemu_telnet.o $(CORE_OBJ)
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ $^

docker_wrapper: docker.o $(CORE_OBJ)
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ $^

iol_wrapper: iol.o $(CORE_OBJ)
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ $^

# Each front-end is compiled a second time with its main() suppressed, so the
# test binary can call its option parser directly.
%_nomain.o: %.c
	$(CC) $(CFLAGS) -DWRAPPER_NO_MAIN -c -o $@ $<

wrapper_test: wrapper_test.o $(TEST_NOMAIN) $(CORE_OBJ)
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ $^

test: wrapper_test
	./wrapper_test

%.o: %.c
	$(CC) $(CFLAGS) -c -o $@ $<

install: all
	install -d -m 0755 $(DESTDIR)$(WRAPPERDIR)
	install -m 0755 $(WRAPPERS) $(DESTDIR)$(WRAPPERDIR)/

clean:
	rm -f $(WRAPPERS) wrapper_test *.o *.d

.PHONY: all test install clean

-include $(wildcard *.d)
