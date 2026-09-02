<?php
/**
 * Compute a dynamips idle-PC value, without installing a root SSH key to do it.
 *
 * WHAT THIS REPLACES
 *
 * store/app/Console/Commands/idlepc: a 9.4 MB stripped PyInstaller bundle,
 * committed with no source, no build recipe and no licence, invoked under sudo
 * from Admin/DefaultController::idlepc() when an admin presses the calculator
 * button on the node form. Its archive was unpacked and the entry script read,
 * and what it does is worse than what it is. In order:
 *
 *     ssh-keygen -t rsa -N '' -f /root/.ssh/id_rsa_dy 2>&1 > /dev/null
 *     cat /root/.ssh/id_rsa_dy.pub >> /root/.ssh/authorized_keys 2>&1 > /dev/null
 *
 * then paramiko-connect to root@127.0.0.1 with that key, run dynamips inside
 * that session, send Ctrl-] then 'i', scrape the answer, and `pkill -9 -f` on a
 * command-line pattern. So pressing "calculate Idle-PC" PERMANENTLY INSTALLED A
 * PASSWORDLESS ROOT KEY on the appliance. It is the same standing root key this
 * fork already deleted from docker_wrapper by allocating a PTY instead of
 * shelling out to root@localhost, reintroduced by an admin button.
 *
 * WHY IT DID THAT, AND WHY NOTHING HERE HAS TO
 *
 * The computation is dynamips' own. The blob contributes no algorithm; it
 * exists to obtain a TTY, because it ran dynamips with NO -T option, and
 * dynamips with no -T puts the console on stdin as VTTY_TYPE_TERM. exec() from
 * PHP has no terminal, so the blob borrowed one over SSH.
 *
 * dynamips 0.2.14 was read to settle whether a terminal is needed at all, and
 * it is not. In common/dev_vtty.c the TCP console listener created by
 * `-T <port>` feeds the SAME input state machine as the terminal one:
 *
 *     vtty_tcp_input()  ->  vtty_read_and_store()
 *       case VTTY_INPUT_TEXT:
 *         case 0x1d:  if (ctrl_code_ok == 1) input_state = VTTY_INPUT_REMOTE;
 *       case VTTY_INPUT_REMOTE:  remote_control(vtty, c);
 *           case 'i':  cpu0->get_idling_pc(cpu0);
 *
 * vtty_create() sets terminal_support = 1 for every type except SERIAL, and
 * ctrl_code_ok is 1 unless --noctrl is passed. So a PLAIN TCP CONNECTION to the
 * console port drives the computation. No PTY, no SSH, no key.
 *
 * The result does NOT come back on that socket. stable/mips64.c and
 * stable/ppc32.c print it with printf():
 *
 *     printf("Restart the emulator with \"--idle-pc=0x%llx\" (for example)\n", ...)
 *
 * i.e. on dynamips' own stdout, which is a pipe here. That is why this class
 * reads two streams: the console socket to know when IOS has finished booting,
 * and stdout to collect the answer.
 *
 * WHAT CROSSES THE BOUNDARY
 *
 * Two names, and nothing else: a template name and an image filename, each a
 * strict slug with no separator in it. No path is ever received. Both roots are
 * constants here, every path is built here, and each must be a real file that
 * is not a symlink and resolves to exactly the path that was built.
 *
 * THE OPTION STRING IS READ HERE, NOT SENT. The old call site pulled
 * `dynamips_options` out of the template YAML in the web layer and passed it
 * across sudo. Template option strings are the `sweep-exempt` "argument
 * injection by design" surface named in docs/HANDOVER.md item 4: they exist to
 * expand into several arguments, so an operator who can edit a template can
 * choose dynamips' argv. On the node-start path that is bounded by the emulator
 * running as the tenant. Here it would be bounded by nothing, because this runs
 * as root — so this class reads the string from the template file itself and
 * accepts only an ALLOWLIST of options, by name, each with its own value
 * pattern. Anything else is refused, loudly, naming the token.
 *
 * What the allowlist deliberately excludes, and why:
 *   -l, -C, --startup-config, --private-config, -R, -G, -g, -a, -f, -E, -b,
 *   --filepid   every one takes a filesystem path, and this runs as root
 *   -T, -A, -U, -B                console redirection; it would take away the
 *                                 console this class needs
 *   --noctrl                      disables the Ctrl-] monitor console, i.e.
 *                                 the whole mechanism
 *   --idle-pc                     mips64_get_idling_pc() refuses to calibrate
 *                                 when an idle PC is already set, and says so
 *   -H                            hypervisor mode; a different program
 *   -i, -r, -n                    set here, from the template's own keys
 *   -p, -s                        network modules and NIO bindings; a bare
 *                                 router boots without them, and a NIO can
 *                                 name a tap or a UDP socket
 *
 * BOUNDED, AND KILLED BY PID
 *
 * dynamips is started with proc_open() and an argv ARRAY, so there is no shell
 * and the pid proc_open() returns IS dynamips rather than an intervening sh.
 * That is what makes terminating by pid possible; the blob's `pkill -9 -f
 * "dynamips ..."` matched any process whose command line happened to contain
 * the string, which on this appliance is every other running node. Every exit
 * path goes through terminate(), and a shutdown function catches the paths PHP
 * does not give us — a fatal, or the wrapper's own time limit.
 *
 * NOT VERIFIED END TO END, AND SAID SO PLAINLY
 *
 * The computation needs a real Cisco IOS image for dynamips, and this project
 * deliberately carries none — the same position as iol_wrapper and for the same
 * reason. Everything up to the point where an image would be needed is tested
 * in tests/Security/IdlePcTest.php: the validators, the template reader, the
 * option allowlist, the argv, the result parser, and the clean failure when no
 * image is present. The console conversation itself — boot banner, Ctrl-] i,
 * the printf on stdout — is derived from dynamips 0.2.14's source as quoted
 * above and has NOT been run against an image. Do not describe it as proven.
 */

class UnlIdlePc
{
    /** A template name. No separator, no '..', no leading dash. */
    const TEMPLATE_RE = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/';
    /** An image filename inside the dynamips addons root. */
    const IMAGE_RE = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/';
    /** What dynamips prints when the calibration finishes. */
    const RESULT_RE = '/"--idle-pc=(0x[0-9a-fA-F]{1,16})"/';
    /** What IOS prints when it has finished booting. */
    const READY_RE = '/Press RETURN to get started/i';
    /** The initial-configuration dialog, which must be declined first. */
    const DIALOG_RE = '/\[yes\/no\]/i';
    /** At most this many 'no' answers before giving up on the dialog. */
    const MAX_DIALOG_ANSWERS = 3;

    /**
     * A console port range that collides with nothing.
     *
     * Node console ports are 30000-40000 (apiEditNodePort() enforces it) and
     * their secondary ports are 10000 above that. Linux hands out ephemeral
     * ports up to 60999 by default. 61000-61999 is clear of both.
     */
    const PORT_MIN = 61000;
    const PORT_MAX = 61999;
    const PORT_TRIES = 24;

    /**
     * The options a template's `dynamips_options` may contain.
     *
     * true means the option takes no value; a string is the pattern its value
     * must match. Read the class header for what is missing from this list and
     * why — the exclusions are the point of it, not an oversight.
     */
    const OPTIONS = array(
        '-P'            => '/^[A-Za-z0-9-]{1,16}\z/',      // platform
        '-t'            => '/^[A-Za-z0-9-]{1,16}\z/',      // chassis or NPE type
        '-o'            => '/^[0-9]{1,4}\z/',              // ROM size, Mb
        '-c'            => '/^(0x[0-9A-Fa-f]{1,8}|[0-9]{1,6})\z/',  // config register
        '-k'            => '/^[0-9]{1,4}\z/',              // clock divisor
        '-m'            => '/^[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}\z/', // chassis MAC
        '--disk0'       => '/^[0-9]{1,6}\z/',
        '--disk1'       => '/^[0-9]{1,6}\z/',
        '--iomem-size'  => '/^[0-9]{1,3}\z/',
        '--timer-itv'   => '/^[0-9]{1,6}\z/',
        '-X'            => true,                           // no RAM file
        '-j'            => true,                           // no JIT
        '--sparse-mem'  => true,
    );

    /** Bounds on the two numbers this class reads out of the template. */
    const RAM_MIN = 16;
    const RAM_MAX = 8192;
    const NVRAM_MIN = 8;
    const NVRAM_MAX = 8192;

    /** The only three keys read out of a template file. */
    const TEMPLATE_KEYS = array('dynamips_options', 'ram', 'nvram');
    /** A template file larger than this is not one of ours. */
    const TEMPLATE_MAX_BYTES = 262144;

    private $addonsRoot;
    private $templatesRoot;
    private $tmpRoot;
    private $dynamips;
    /** Seconds allowed for the console listener to accept a connection. */
    private $connectTimeout;
    /** Seconds allowed from spawn to the IOS boot banner. */
    private $bootTimeout;
    /** Seconds to let IOS settle after the banner, per dynamips(1). */
    private $settle;
    /** Seconds allowed for the calibration itself. It samples for 10. */
    private $computeTimeout;
    /** False in tests: record the argv instead of starting anything. */
    private $runCommands;

    /** Recorded rather than run when run_commands is false. */
    public $commands = array();

    /** Set while a dynamips process of ours exists. See guard(). */
    private $childPid = null;

    public function __construct(array $options = array())
    {
        $prefix = isset($options['prefix']) ? rtrim($options['prefix'], '/') : '';
        $this->addonsRoot = isset($options['addons_root'])
            ? rtrim($options['addons_root'], '/') : $prefix . '/opt/unetlab/addons/dynamips';
        $this->templatesRoot = isset($options['templates_root'])
            ? rtrim($options['templates_root'], '/') : $prefix . '/opt/unetlab/html/templates';
        $this->tmpRoot = isset($options['tmp_root'])
            ? rtrim($options['tmp_root'], '/') : $prefix . '/opt/unetlab/tmp';
        $this->dynamips = isset($options['dynamips']) ? $options['dynamips'] : '/usr/bin/dynamips';
        $this->connectTimeout = isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : 20;
        $this->bootTimeout    = isset($options['boot_timeout'])    ? (int) $options['boot_timeout']    : 120;
        $this->settle         = isset($options['settle'])          ? (int) $options['settle']          : 5;
        $this->computeTimeout = isset($options['compute_timeout']) ? (int) $options['compute_timeout'] : 60;
        $this->runCommands = array_key_exists('run_commands', $options)
            ? (bool) $options['run_commands'] : true;
    }

    // ------------------------------------------------------------- validation

    /**
     * A template name, or null.
     *
     * Public because it is the invariant the call site needs to state too: the
     * controller writes <template>.yml back after this returns, and it should
     * build that path from the name the privileged side accepted rather than
     * from the one the request supplied.
     *
     * \z, not $: in PCRE without /D, '$' also matches immediately before a
     * trailing newline, so '/^[A-Za-z0-9_.-]+$/' accepts "c3725\n/../etc" -- no,
     * it accepts "c3725\n", which is enough. A name that survives this cannot
     * contain '/', so no path built from it can leave its root by construction;
     * the realpath checks below are the second, independent answer to that.
     */
    public static function templateName($value)
    {
        if (!is_string($value) || !preg_match(self::TEMPLATE_RE, $value)) return null;
        if ($value === '.' || $value === '..') return null;
        return $value;
    }

    /** An image filename, or null. Same rules, longer. */
    public static function imageName($value)
    {
        if (!is_string($value) || !preg_match(self::IMAGE_RE, $value)) return null;
        if ($value === '.' || $value === '..') return null;
        return $value;
    }

    /** The idle-PC value in a stream of dynamips output, or null. */
    public static function parseIdlePc($text)
    {
        if (!is_string($text)) return null;
        if (!preg_match(self::RESULT_RE, $text, $m)) return null;
        return strtolower($m[1]);
    }

    /**
     * A regular file at exactly $path, not a symlink, or null.
     *
     * realpath() equality is the check that matters: it refuses a path that
     * reached its target through a symlinked PARENT, which is_link() on the
     * leaf does not see. /opt/unetlab/tmp is mode 777 and the addons tree is
     * handed to www-data by `fixperms addons`, so both roots are writable by
     * something less privileged than this process.
     */
    private static function realFile($path)
    {
        if (is_link($path) || !is_file($path)) return null;
        $real = realpath($path);
        if ($real === false || $real !== $path) return null;
        return $real;
    }

    // -------------------------------------------------------- template reader

    /**
     * The three scalar keys this class needs, read out of a template file.
     *
     * NOT yaml_parse_file(). Three reasons, in order of weight:
     *
     *   - ext-yaml is not installed on the CI interpreter, and the whole point
     *     of tests/bootstrap.php is that these tests run against a bare PHP. A
     *     privileged path whose validation cannot be tested is not validated.
     *   - a full parse accepts far more than three scalars, and this file is
     *     read as root. Anchors, merge keys and multi-document streams are
     *     input this class has no use for.
     *   - a duplicate top-level key is ambiguous, and a parser silently picks
     *     one. Here it is refused.
     *
     * Only top-level `key: value` at column 0 is considered; anything indented
     * belongs to a nested structure and is skipped. A value may be wrapped in
     * matching quotes. '#' anywhere in a value is refused rather than guessed
     * at, because YAML's comment rule depends on the preceding character and
     * this reader is not going to litigate it.
     *
     * @return array|null  key => string, missing keys absent; null on refusal
     */
    public static function readTemplateKeys($path, &$error = null)
    {
        $error = null;
        $size = @filesize($path);
        if ($size === false || $size > self::TEMPLATE_MAX_BYTES) {
            $error = 'the template file is missing or implausibly large';
            return null;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $error = 'the template file could not be read';
            return null;
        }

        $found = array();
        foreach ($lines as $line) {
            if (!preg_match('/^([A-Za-z0-9_]+)\s*:\s?(.*)$/', $line, $m)) continue;
            $key = $m[1];
            if (!in_array($key, self::TEMPLATE_KEYS, true)) continue;
            if (isset($found[$key])) {
                $error = 'the template defines ' . $key . ' more than once';
                return null;
            }
            $value = rtrim($m[2], " \t\r");
            if (strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"')
                 || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }
            if (strpos($value, '#') !== false) {
                $error = 'the value of ' . $key . ' contains a #, which this reader will not guess at';
                return null;
            }
            $found[$key] = $value;
        }
        return $found;
    }

    // ------------------------------------------------------------- the argv

    /**
     * A template's dynamips_options as an argv fragment, or null.
     *
     * Split on whitespace and matched against the allowlist option by option.
     * There is no shell here, so quoting is not the risk; the risk is that one
     * option names a file this process would then read or write as root, and
     * that is what an allowlist answers and a blocklist does not.
     */
    public static function tokeniseOptions($raw, &$error = null)
    {
        $error = null;
        if ($raw === null || $raw === '') return array();
        if (!is_string($raw)) { $error = 'dynamips_options is not a string'; return null; }
        if (strlen($raw) > 512) { $error = 'dynamips_options is too long'; return null; }

        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) { $error = 'dynamips_options could not be split'; return null; }

        $argv = array();
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (!array_key_exists($token, self::OPTIONS)) {
                $error = 'dynamips_options carries ' . $token
                    . ', which this action does not allow; see the allowlist in UnlIdlePc';
                return null;
            }
            $spec = self::OPTIONS[$token];
            if ($spec === true) { $argv[] = $token; continue; }
            if (!isset($tokens[$i + 1])) {
                $error = 'dynamips_options ends with ' . $token . ', which needs a value';
                return null;
            }
            $value = $tokens[++$i];
            if (!preg_match($spec, $value)) {
                $error = 'the value of ' . $token . ' in dynamips_options is not acceptable';
                return null;
            }
            $argv[] = $token;
            $argv[] = $value;
        }
        return $argv;
    }

    /** A bounded integer out of a template value, or the default. */
    private static function boundedInt($value, $min, $max, $default)
    {
        if ($value === null) return $default;
        if (!is_string($value) || !preg_match('/^[0-9]+\z/', trim($value))) return null;
        $n = (int) trim($value);
        return ($n < $min || $n > $max) ? null : $n;
    }

    /**
     * A free console port in the dedicated range, or null.
     *
     * The port is claimed by binding it, then released immediately for dynamips
     * to take. That is a race, and it is a small one: the range is used by
     * nothing else on this appliance and the window is microseconds. Picking a
     * port from the ephemeral range instead would trade this race for a
     * collision with an outbound connection, which is worse.
     */
    private function freePort()
    {
        for ($i = 0; $i < self::PORT_TRIES; $i++) {
            $port = random_int(self::PORT_MIN, self::PORT_MAX);
            $errno = 0;
            $errstr = '';
            $sock = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
            if ($sock === false) continue;
            fclose($sock);
            return $port;
        }
        return null;
    }

    // ------------------------------------------------------------------ entry

    /**
     * @return array ['ok'=>bool,'error'=>string|null,'idlepc'=>string|null,
     *                'template'=>string|null,'image'=>string|null,'port'=>int|null]
     */
    public function run($templateRaw, $imageRaw)
    {
        $fail = function ($why) {
            return array('ok' => false, 'error' => $why, 'idlepc' => null,
                         'template' => null, 'image' => null, 'port' => null);
        };

        $template = self::templateName($templateRaw);
        if ($template === null) return $fail('template name must match ' . self::TEMPLATE_RE);
        $image = self::imageName($imageRaw);
        if ($image === null) return $fail('image name must match ' . self::IMAGE_RE);

        $templateFile = $this->templatesRoot . '/' . $template . '.yml';
        if (self::realFile($templateFile) === null) {
            return $fail('no template called ' . $template . ' under ' . $this->templatesRoot);
        }

        // THE ONLY FAILURE THIS PROJECT CAN ACTUALLY EXERCISE. It carries no
        // Cisco IOS image and never will, so this is the branch a maintainer
        // will see; it names the file and the root rather than saying "failed".
        $imageFile = $this->addonsRoot . '/' . $image;
        if (self::realFile($imageFile) === null) {
            return $fail('no dynamips image called ' . $image . ' under ' . $this->addonsRoot
                . '; this fork ships none, so one has to be installed there first');
        }

        if (!is_file($this->dynamips) || !is_executable($this->dynamips)) {
            return $fail($this->dynamips . ' is not installed; the dynamips package provides it');
        }

        $keys = self::readTemplateKeys($templateFile, $readError);
        if ($keys === null) return $fail($readError);

        $optionArgv = self::tokeniseOptions(
            isset($keys['dynamips_options']) ? $keys['dynamips_options'] : null, $optionError);
        if ($optionArgv === null) return $fail($optionError);

        $ram = self::boundedInt(isset($keys['ram']) ? $keys['ram'] : null,
            self::RAM_MIN, self::RAM_MAX, 256);
        if ($ram === null) return $fail('the template\'s ram is not a plausible size in Mb');
        $nvram = self::boundedInt(isset($keys['nvram']) ? $keys['nvram'] : null,
            self::NVRAM_MIN, self::NVRAM_MAX, 128);
        if ($nvram === null) return $fail('the template\'s nvram is not a plausible size in Kb');

        $port = $this->freePort();
        if ($port === null) {
            return $fail('no free console port between ' . self::PORT_MIN . ' and ' . self::PORT_MAX);
        }

        // A private working directory, because dynamips writes its log file and
        // any ghost/RAM files into the cwd. mkdir() fails if the name exists at
        // all -- including as a symlink someone planted -- which is what makes
        // this safe under a 0777 /opt/unetlab/tmp.
        $workspace = null;
        if ($this->runCommands) {
            $workspace = $this->tmpRoot . '/idlepc-' . getmypid() . '-' . bin2hex(random_bytes(6));
            if (!@mkdir($workspace, 0700, true)) {
                return $fail('could not create a working directory under ' . $this->tmpRoot);
            }
            if (realpath($workspace) !== $workspace) {
                @rmdir($workspace);
                return $fail('the working directory is not where it should be');
            }
        }

        // NO -l. The log file goes where the cwd is, which is a directory this
        // action owns; letting the template name it was one of the paths the
        // allowlist above exists to refuse.
        //
        // NO --idle-pc. mips64_get_idling_pc() prints "You already use an idle
        // PC, using the calibration would give incorrect results" and returns
        // -1 when one is set, so passing the template's stale value would make
        // this action silently never work.
        $argv = array_merge(
            array($this->dynamips, '-T', (string) $port),
            $optionArgv,
            array('-r', (string) $ram, '-n', (string) $nvram, $imageFile)
        );

        if (!$this->runCommands) {
            $this->commands[] = $argv;
            return array('ok' => true, 'error' => null, 'idlepc' => null,
                         'template' => $template, 'image' => $image, 'port' => $port);
        }

        $result = $this->drive($argv, $port, $workspace);
        $this->cleanup($workspace);

        $result['template'] = $template;
        $result['image'] = $image;
        $result['port'] = $port;
        return $result;
    }

    // ------------------------------------------------------------- the drive

    /**
     * Start dynamips, talk to its console, and bring back the idle-PC value.
     *
     * @param  array $argv  program and arguments; an ARRAY, so no shell exists
     * @return array ['ok'=>bool,'error'=>string|null,'idlepc'=>string|null]
     */
    private function drive(array $argv, $port, $cwd)
    {
        $fail = function ($why) {
            return array('ok' => false, 'error' => $why, 'idlepc' => null);
        };

        // proc_open() with an ARRAY execs the binary directly and never builds a
        // command string. That is the shape tests/Security/ShellEscapingTest.php
        // can prove is not a shell, and it is also what makes the pid below the
        // pid of dynamips rather than of an intervening sh -- which is the whole
        // reason this class can terminate by pid instead of by `pkill -f`.
        $desc = array(0 => array('file', '/dev/null', 'r'),
                      1 => array('pipe', 'w'),
                      2 => array('pipe', 'w'));
        $pipes = array();
        $proc = @proc_open($argv, $desc, $pipes, $cwd);
        if (!is_resource($proc)) return $fail('could not start ' . $argv[0]);

        $status = proc_get_status($proc);
        $this->childPid = isset($status['pid']) ? (int) $status['pid'] : null;
        $this->guard();

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $console = '';
        $sock = null;

        try {
            // --- 1. the console listener accepts ----------------------------
            $deadline = microtime(true) + $this->connectTimeout;
            while ($sock === null) {
                if (!$this->alive($proc)) {
                    return $fail('dynamips exited before its console opened: '
                        . $this->tail($stdout . $this->read($pipes)));
                }
                $errno = 0;
                $errstr = '';
                $candidate = @stream_socket_client('tcp://127.0.0.1:' . $port,
                    $errno, $errstr, 1.0);
                if ($candidate !== false) { $sock = $candidate; break; }
                if (microtime(true) > $deadline) {
                    return $fail('the dynamips console on port ' . $port
                        . ' did not accept a connection within ' . $this->connectTimeout . 's');
                }
                $stdout .= $this->read($pipes);
                usleep(200000);
            }
            stream_set_blocking($sock, false);

            // --- 2. IOS boots -----------------------------------------------
            // dynamips greets a TCP console with telnet IAC negotiation. None of
            // it is answered and none of it needs to be: the bytes below are
            // 0x1d, 'i' and "no\r", none of which is IAC (0xff), so nothing this
            // class writes can be read as an option negotiation.
            $answers = 0;
            $deadline = microtime(true) + $this->bootTimeout;
            while (!preg_match(self::READY_RE, $console)) {
                if (!$this->alive($proc)) {
                    return $fail('dynamips exited while booting the image: '
                        . $this->tail($stdout . $this->read($pipes)));
                }
                if (microtime(true) > $deadline) {
                    return $fail('the image did not reach the IOS prompt within '
                        . $this->bootTimeout . 's; console tail: ' . $this->tail($console));
                }
                $stdout .= $this->read($pipes);
                $fresh = $this->readSocket($sock);
                $console .= $fresh;
                // "Would you like to enter the initial configuration dialog?"
                // dynamips(1) is explicit that the calibration must not be run
                // at that prompt, so decline it. This is the one thing the blob
                // did that was not about obtaining a TTY -- its '[yes/no]'
                // string constant is this.
                if ($fresh !== '' && preg_match(self::DIALOG_RE, $fresh)
                    && $answers < self::MAX_DIALOG_ANSWERS) {
                    $answers++;
                    @fwrite($sock, "no\r");
                }
                usleep(200000);
            }

            // dynamips(1): "wait for the 'Press RETURN to get started!' message
            // prompt, but do not press Enter. Wait about 5 seconds, then press
            // Ctrl-] + i". Pressing Enter is what starts the autoconfiguration
            // the manual then warns against calibrating under.
            $settleUntil = microtime(true) + $this->settle;
            while (microtime(true) < $settleUntil) {
                if (!$this->alive($proc)) {
                    return $fail('dynamips exited before the calibration started');
                }
                $stdout .= $this->read($pipes);
                $console .= $this->readSocket($sock);
                usleep(200000);
            }

            // --- 3. Ctrl-] i, and read the answer off STDOUT ----------------
            if (@fwrite($sock, "\x1d") === false || @fwrite($sock, 'i') === false) {
                return $fail('could not write the monitor escape to the console');
            }

            $deadline = microtime(true) + $this->computeTimeout;
            while (true) {
                $stdout .= $this->read($pipes);
                $console .= $this->readSocket($sock);
                $value = self::parseIdlePc($stdout);
                if ($value !== null) {
                    return array('ok' => true, 'error' => null, 'idlepc' => $value);
                }
                if (!$this->alive($proc)) {
                    return $fail('dynamips exited during the calibration: ' . $this->tail($stdout));
                }
                if (microtime(true) > $deadline) {
                    return $fail('no idle-PC value within ' . $this->computeTimeout
                        . 's of the monitor escape; dynamips said: ' . $this->tail($stdout));
                }
                usleep(200000);
            }
        } finally {
            if (is_resource($sock)) fclose($sock);
            $this->terminate($proc, $pipes);
        }
    }

    /** Whatever is waiting on dynamips' stdout and stderr. Never blocks. */
    private function read(array $pipes)
    {
        $out = '';
        foreach (array(1, 2) as $i) {
            if (!isset($pipes[$i]) || !is_resource($pipes[$i])) continue;
            $chunk = @stream_get_contents($pipes[$i]);
            if (is_string($chunk)) $out .= $chunk;
        }
        return $out;
    }

    /** Whatever is waiting on the console socket. Never blocks. */
    private function readSocket($sock)
    {
        if (!is_resource($sock)) return '';
        $chunk = @fread($sock, 65536);
        return is_string($chunk) ? $chunk : '';
    }

    /** The last 400 bytes of a stream, printable, for an error message. */
    private function tail($text)
    {
        $text = preg_replace('/[^\x20-\x7e\n]/', '.', (string) $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        return $text === '' ? '(no output)' : substr($text, -400);
    }

    private function alive($proc)
    {
        if (!is_resource($proc)) return false;
        $status = proc_get_status($proc);
        return is_array($status) && !empty($status['running']);
    }

    /**
     * End the dynamips process this class started, and only that one.
     *
     * proc_terminate() signals the pid proc_open() returned. Because the argv
     * was an array there is no shell in between, so that pid is dynamips.
     * SIGTERM first, then SIGKILL if it is still there five seconds later; the
     * blob's `pkill -9 -f "dynamips ..."` would have taken every running node
     * on the appliance with it.
     */
    private function terminate($proc, array $pipes)
    {
        if (is_resource($proc)) {
            $status = proc_get_status($proc);
            if (is_array($status) && !empty($status['running'])) {
                @proc_terminate($proc, 15);
                for ($i = 0; $i < 50; $i++) {
                    usleep(100000);
                    $status = proc_get_status($proc);
                    if (!is_array($status) || empty($status['running'])) break;
                }
                $status = proc_get_status($proc);
                if (is_array($status) && !empty($status['running'])) @proc_terminate($proc, 9);
            }
        }
        foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
        if (is_resource($proc)) @proc_close($proc);
        $this->childPid = null;
    }

    /**
     * The last resort, for the exits PHP does not let us unwind.
     *
     * finally covers a return and an exception. It does not cover a fatal error
     * or the wrapper hitting its own time limit, and either of those would leave
     * a dynamips running at 100% of a core until the appliance is rebooted --
     * which is precisely the condition idle-PC exists to prevent.
     */
    private function guard()
    {
        static $registered = false;
        if ($registered) return;
        $registered = true;
        $self = $this;
        register_shutdown_function(function () use ($self) {
            $self->killOrphan();
        });
    }

    /**
     * Public only because register_shutdown_function() needs to reach it.
     *
     * childPid is cleared by terminate(), so this fires only when the process
     * was never reaped. The pid could in principle have been recycled by then;
     * the window is between PHP deciding to die and the shutdown function
     * running, which is why this is the fallback and terminate() is the path.
     */
    public function killOrphan()
    {
        if ($this->childPid === null) return;
        if (function_exists('posix_kill')) @posix_kill($this->childPid, 9);
        $this->childPid = null;
    }

    /**
     * Remove the working directory, one level deep.
     *
     * One level, and only entries that are not directories, for the same reason
     * UnlTenantAccount::removeHome() does it that way: /opt/unetlab/tmp is mode
     * 777, and a recursive root delete driven by what it finds there is the
     * primitive this whole phase has been removing.
     */
    private function cleanup($workspace)
    {
        if (!is_string($workspace) || $workspace === '') return;
        if (!is_dir($workspace) || is_link($workspace)) return;
        if (realpath($workspace) !== $workspace) return;
        if (strpos($workspace, $this->tmpRoot . '/idlepc-') !== 0) return;
        foreach (scandir($workspace) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $workspace . '/' . $entry;
            if (is_dir($path) && !is_link($path)) continue;   // never created here
            @unlink($path);
        }
        @rmdir($workspace);
    }
}
