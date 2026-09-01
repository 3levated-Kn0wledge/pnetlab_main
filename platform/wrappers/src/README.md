# platform/wrappers/src — the console wrappers

PNETLab's node consoles depend on four compiled binaries that upstream ships
only inside its appliance image and has never published:
`qemu_wrapper_telnet`, `docker_wrapper`, `iol_wrapper` and
`iol_wrapper_telnet`. Without them a QEMU node with a *telnet* console never
reads as running, a Docker node's console never opens, and IOL does not work at
all. This directory is their replacement.

What is here today:

| Binary | Status |
|---|---|
| `qemu_wrapper_telnet` | implemented (`qemu_telnet.c`) |
| `docker_wrapper` | not yet — `docker.c`, against the API below |
| `iol_wrapper` | implemented (`iol.c`) — **but see the caveat below: no IOL image exists to verify it against** |
| `iol_wrapper_telnet` | not yet; it is `iol_wrapper` with the data plane deleted |
| `qemu_wrapper`, `dynamips_wrapper` | **deliberately not implemented** — nothing invokes them; QEMU-with-VNC and dynamips each open their own console port |

`nsenter` in the same appliance directory is stock util-linux, already symlinked
by `install/lib/platform.sh`. Nothing to write.

**`iol_wrapper` is implemented and tested, not verified.** IOL images are
licensed Cisco binaries; this repository ships none and the reference appliance
has none, so nobody here has ever seen this code drive a real IOL instance. Its
frame layouts come from the specification, not from observation. Everything that
can be proved without an image is proved — option parsing, `NETMAP`, the
AF_UNIX bus, TAP and UDP forwarding, and every bounds check, against a stand-in
IOL that binds the same socket a real one would — and the gap that remains is
written down at the top of `iol.c`. Do not tell a user that IOL nodes work until
somebody with a licence has run two of them.

## Provenance — read this before touching the code

This is a **clean-room reimplementation**. It was written from a behavioural
specification produced by a separate investigator from observed behaviour and
from this repository's own PHP call sites, plus Linux man pages. No upstream
wrapper source, and no upstream wrapper binary, was read, disassembled or
searched for while writing it.

Public source for the original wrappers does exist under a permissive licence.
Vendoring it was considered and rejected; that decision is what makes this
directory clean-room, and it only stays clean-room if it is not contaminated
later. **Do not consult the original sources, in any repository, fork or mirror,
when extending this code.** If you find yourself reading prior-art C for a
PNETLab, UNetLab or EVE-NG wrapper, stop and say so.

## Build

```
make            # every wrapper
make test       # the unit tests (assert-based, no dependencies)
make install    # into $(DESTDIR)/opt/unetlab/wrappers
```

Flags are `-std=gnu11 -O2 -Wall -Wextra -Werror -fstack-protector-strong
-D_FORTIFY_SOURCE=2`. Nothing is linked statically and nothing outside libc is
linked at all. Built and tested on Ubuntu 24.04 / GCC 13.3.

Two flag choices that will look wrong and are not:

* `-std=gnu11`, not `-std=c11`. Strict ISO mode defines `__STRICT_ANSI__`, and
  glibc then hides every POSIX declaration the code is built from —
  `sigaction`, `kill`, `nanosleep`, `gmtime_r`.
* `-U_FORTIFY_SOURCE` before `-D_FORTIFY_SOURCE=2`. Ubuntu's GCC injects
  `-D_FORTIFY_SOURCE=3` of its own, and redefining a macro on the command line
  is a warning, which `-Werror` promotes to a build failure.

The binaries are not committed, and the intent is that `install/lib/platform.sh`
runs `make install` here so the repository keeps shipping no binaries — the same
arrangement as `install/sql`. That wiring is not in place yet: the installer
still reports `qemu_wrapper_telnet` as unavailable. Until it is, build and
install by hand.

## Tests

* `make test` — `wrapper_test.c`. Unit tests for the parts that are pure logic:
  the telnet IAC state machine, command assembly and its ARG_MAX cap, and option
  parsing. Assert-based and dependency-free, so it runs anywhere, the same
  contract as `tools/run-tests.sh`.
* `tools/integration/iol-dataplane.sh` — `iol_wrapper` end to end against a
  stand-in IOL (`tools/integration/iol_fake.c`) that binds the same AF_UNIX
  socket a real instance would and speaks the same 8-byte bus header. Real TAP
  devices on a real bridge, real UDP datagrams, real unix datagram sockets; the
  only thing simulated is IOL itself. Needs passwordless `sudo` for `ip` and
  `fuser`, and cleans up every interface and socket it makes.
* `tools/integration/wrapper-console.sh` — builds the wrapper, starts it the way
  `device_qemu::start()` does, and checks R1–R6 end to end: the port LISTENs,
  bytes relay in both directions, several simultaneous viewers all see the
  output, `fuser -k -TERM <running path>` kills the wrapper *and* its child, and
  the port is released. It runs the real pipeline too — `nc -U` against a unix
  socket, with `socat` standing in for QEMU's serial port. Needs no PNETLab
  install; needs `socat`, `nc`, `python3` and passwordless `sudo` for `fuser`.

## The five requirements everything else serves

From §1 of the specification. These are not style points; each one is the
difference between a node that works and a node that does not, and each is
commented at the point in the code where it is satisfied.

* **R1 — the port must LISTEN.** `getNodeStatus()` in `includes/functions.php`
  runs `netstat -a -t -n | grep LISTEN | grep ':<console port>'` and *nothing
  else*. No pid file, no health check, no connect. If the listener is not up the
  node reads as stopped however healthy the emulator is, and
  `device_qemu::start()`'s four one-second retries give up. `console_open()`,
  called before the child is forked.
* **R2 — never `chdir()`.** `device::start()` chdir()s to the node's running
  directory before exec'ing the wrapper, and `device::stop()` tears the node
  down with `sudo fuser -k -TERM <running path>`, which finds processes by the
  directories they hold open — cwd included. A wrapper that tidies up after
  itself by chdir'ing to `/` becomes unkillable by the only mechanism the UI
  has.
* **R3 — the basename is the contract.** `pkill -TERM iol_wrapper` and
  `pgrep -f -c -P 1 iol_wrapper` are written against these names. The `Makefile`
  targets are them.
* **R4 — SIGTERM must take the child too.** `child_become_group_leader()` puts
  the wrapper at the head of a process group the child inherits;
  `loop_teardown()` signals the group, waits, then escalates to `SIGKILL`. An
  orphaned QEMU keeps the console port bound, so the node reads as running
  forever and the UI will not restart it.
* **R5 — stay a child of PID 1.** No `daemon()`, no double fork, no `setsid()`.
  The caller backgrounds us with a shell `&` and that shell exits, so we are
  reparented to init already, which is what `pgrep -f -c -P 1` counts.

## The core API — what `docker.c` and `iol.c` code against

Five components. A front-end is option parsing plus a few dozen lines of glue;
everything below is shared and should not need changing to add one.

### `log.h`

```c
void log_init(const char *progname);        /* first call in main() */
void log_set_level(log_level_t level);
log_write(level, fmt, ...)                  /* and log_err/wrn/inf/dbg/vrb */
void log_errno(const char *what);           /* "<what> failed: <strerror>" */
```

Everything goes to stdout, which the caller has redirected into
`<running path>/wrapper.txt`. Nothing parses that file, so the format is free;
the *level* discipline is not. An operator debugging a dead console must see the
assembled child command line and the listener state at the default `INF`.
`log_init()` also makes stdout line-buffered — without that a wrapper that dies
leaves an empty `wrapper.txt`, which is exactly when someone goes looking at it.

### `cmdline.h`

```c
int  cmdline_init(cmdline_t *c);                 /* cap = sysconf(_SC_ARG_MAX) */
int  cmdline_init_cap(cmdline_t *c, size_t cap); /* for tests */
int  cmdline_append(cmdline_t *c, const char *s);
int  cmdline_append_word(cmdline_t *c, const char *word);   /* prepends one space */
int  cmdline_appendf(cmdline_t *c, const char *fmt, ...);
int  cmdline_find_separator(int argc, char *const argv[]);  /* index of "--", or -1 */
int  cmdline_append_tail(cmdline_t *c, int argc, char *const argv[], int from);
const char *cmdline_str(const cmdline_t *c);
void cmdline_free(cmdline_t *c);
```

Overflowing the cap is fatal, not truncating: a truncated command line starts a
child that looks plausible and is wired to the wrong thing.

**Call `cmdline_find_separator()` before `getopt()`, and hide the tail from
getopt.** GNU getopt permutes argv, so a scan afterwards reads a rearranged
array; and hiding the tail means a child command full of dashed words — IOL's
`-n <nvram> -q -m <ram>` — can never be mistaken for one of your own options.
`qt_parse()` in `qemu_telnet.c` is the pattern:

```c
int sep      = cmdline_find_separator(argc, argv);
int opt_argc = (sep < 0) ? argc : sep;
optind = 0;                     /* glibc: zero means reinitialise */
while ((c = getopt(opt_argc, argv, ":P:d:t:x")) != -1) { ... }
```

### `console.h`

```c
void   console_init(console_t *c, const char *title);
int    console_open(console_t *c, int port);              /* R1; -1 is fatal */
void   console_close(console_t *c);
void   console_drop_all_clients(console_t *c);            /* SIGHUP */
int    console_prepare(const console_t *c, fd_set *rfds, fd_set *wfds);  /* -> max fd */
size_t console_service(console_t *c, const fd_set *rfds, const fd_set *wfds,
                       unsigned char *buf, size_t buflen);
void   console_broadcast(console_t *c, const void *buf, size_t len);
```

`console_service()` does everything console-related that `select` reported
ready — flush stalled viewers, accept a new one, read keystrokes — and returns
the telnet-filtered keystrokes in `buf` for the caller to write to the child.
`console_broadcast()` sends node output to every viewer.

The telnet input filter is exposed separately so it can be tested without a
socket:

```c
void   telnet_filter_init(telnet_filter_t *f);
size_t telnet_filter(telnet_filter_t *f, const unsigned char *in, size_t inlen,
                     unsigned char *out);   /* out needs inlen bytes */
```

### `child.h`

```c
void child_become_group_leader(void);        /* R4; first thing in main() */
int  child_spawn(child_t *ch, const child_spec_t *spec);
int  child_reap(child_t *ch, int *status);   /* WNOHANG; 1 = gone */
int  child_wait_blocking(child_t *ch);       /* the -x babysitter path */
void child_close(child_t *ch);
```

```c
typedef struct {
        child_mode_t       mode;        /* CHILD_PIPES or CHILD_PTY */
        const char        *command;     /* run as /bin/sh -c <command> */
        unsigned int       delay;       /* -d: dots to the console, one a second */
        const char *const *env_extra;   /* NULL-terminated "NAME=value" */
} child_spec_t;
```

`child_t` exposes `pid`, `in_fd` and `out_fd`; the poll loop reads and writes
those without caring whether they are pipes or a PTY master.

`CHILD_PTY` exists for `docker_wrapper`. `docker exec -ti` refuses to run
without a TTY, which is the only reason the original shelled out to `ssh -tt` —
and that required a standing passwordless root SSH key on the appliance. R7 says
allocate the terminal locally instead; `child_open_pty()` does, in raw mode at
80x24, and `child_spec_t::env_extra` is where `TERM=ansi` goes.

Prefer `env_extra` to a `NAME=value ` prefix on the command string generally.
`iol_wrapper`'s `LD_LIBRARY_PATH=/opt/unetlab/addons/iol/lib` is configuration,
and it survives however the shell feels about quoting if it is set as
configuration. The shell is still needed for the PHP's `escapeshellarg()`
quoting.

### `loop.h`

```c
int  loop_run(console_t *con, child_t *ch, const loop_hooks_t *hooks);
void loop_teardown(console_t *con, child_t *ch, const char *why);
```

`loop_run` installs the signal disposition, reaps, selects, relays in both
directions and tears down. It returns only when the wrapper is finished; on
return the listener is closed and the child is gone.

`loop_hooks_t` is how a front-end joins its own descriptors to the same
`select`:

```c
typedef struct {
        int  (*prepare)(void *ud, fd_set *rfds, fd_set *wfds);   /* -> max fd, or -1 */
        void (*service)(void *ud, const fd_set *rfds, const fd_set *wfds);
        void *ud;
} loop_hooks_t;
```

`qemu_wrapper_telnet` passes `NULL`. `iol_wrapper` will pass hooks so its TAP
descriptors, its UDP data-plane socket and its AF_UNIX bus socket are polled in
the same pass as the console, and its frame forwarding runs from `service`.
Neither hook may block: everything in the set is non-blocking, and a front-end
that blocks stalls the console for every viewer.

### A front-end, in full

`qemu_telnet.c` is the worked example, and it is about sixty lines once the
comments are removed:

```c
log_init("qemu_wrapper_telnet");
child_become_group_leader();                 /* R4 */
qt_parse(argc, argv, &opts);                 /* your options */
cmdline_init(&cmd);
cmdline_append_tail(&cmd, argc, argv, opts.cmd_from);
console_init(&con, opts.title);
console_open(&con, opts.port);               /* R1, BEFORE the fork */
child_spawn(&ch, &spec);
return loop_run(&con, &ch, NULL);
```

Open the listener before forking the child. `device_qemu::start()` retries for
four seconds and then gives up; there is no reason to race it. Binding first
also means a port collision is reported before anything is started, instead of
leaving a stray child behind.

## Deliberate differences from the original

These are in the specification as §10.3 and are requirements, not preferences.
They are commented where they occur, so that nobody "restores" them later.

1. **Block relay, not byte-at-a-time.** The original reads one byte from the
   child and issues one `send()` per client per byte. We use 4 KB buffers. The
   bytes on the wire are identical; the syscall count during a boot log drops by
   three orders of magnitude.
2. **The SIGHUP client-close loop.** The original's increments where it should
   decrement, walks off the end of the client array and closes arbitrary
   descriptors. `console_drop_all_clients()` closes each registered client once
   and zeroes the count.
3. **`docker_wrapper`'s SSH hop** becomes a local PTY (`CHILD_PTY`), removing a
   standing root SSH key from the appliance.
4. **`docker_wrapper`'s vendor licence check** — a `stat()` of a file under
   `/opt/unetlab/html/store/app/` that exits 1 if absent — is omitted. It has no
   functional purpose.
5. **`-l` order dependence in `iol_wrapper`**: process the link maps after the
   option loop, not inside it.
6. **Missing mandatory-option checks.** The original does not require `-P` on
   `qemu_wrapper_telnet` or `-p` on `docker_wrapper`; both then fail several
   steps later in a way that reads as "the node will not start" rather than "you
   forgot an argument". Both are required here.
7. **Stale usage text.** The originals advertise `-T`, `-D` and `-F` on the
   telnet wrappers and then reject them. Ours documents what it implements.
8. **`IAC IAC` is unescaped** to a single `0xFF`. The original loses the byte
   and the two behind it.

Beyond that list, and in the same spirit:

* **Telnet subnegotiation is consumed properly.** The original treats every
  `IAC` as the start of exactly three bytes, so an `IAC SB ... IAC SE` from a
  client leaks its body into the node's console as keystrokes. We consume to
  `IAC SE`. No well-behaved client should send one — we never offer an option
  that invites it — but "should" is doing a lot of work in a protocol this old.
* **Viewer sockets are non-blocking, with a bounded output queue.** With
  blocking sockets one viewer whose TCP window has closed — a laptop that slept
  with a console open — stalls the select loop, freezing the console for
  everybody and eventually blocking the emulator on its own stdout. We queue up
  to 256 KB per viewer and drop the viewer past that.
* **`IPV6_V6ONLY` is cleared explicitly** rather than trusting
  `net.ipv6.bindv6only=0`, and there is an IPv4 fallback if `socket(AF_INET6)`
  fails outright (`ipv6.disable=1`). Both protect R1: a v6-only listener still
  satisfies `netstat`, so the node would read as running while refusing every
  connection — the worst kind of failure, because the UI then believes it is up.
* **A client whose fd is at or above `FD_SETSIZE` is refused.** `select()`
  indexes `fd_set` by descriptor number, so the specification's 1023-client
  capacity is not actually reachable and `FD_SET` on a high descriptor corrupts
  memory past the end of the set.
* **`WUNTRACED` is dropped from the reap.** With it, a child merely stopped by
  `SIGSTOP` reports as gone and the wrapper tears down a node that was paused.
* **`/bin/sh` is exec'd, not `system()`ed.** One less process in the group, and
  the pid we hold is the shell that runs the command, so R6 — `nc` exits when
  QEMU drops its unix socket, the wrapper exits, the port is released — reports
  promptly rather than one hop late.
