<?php

/**
 * 
 * @author LIN 
 * @copyright pnetlab.com
 * @link https://www.pnetlab.com/
 * 
 *
 * 
 * Device: device factory
 *  |
 *  |__ Device Type : extends device factory (device_iol.php, device_dynamips.php, device_qemu.php, device_docker.php, device_vpcs.php)
 *          |
 *          |__ Device Line: Extends device type (device_[device line name].php)
 *          |
 *          |__ Adapter : Line card, network module
 * 
 *       
 */


/**   
 * @property type $console protocol. It's optional.
 * @property type $config Filename for the startup configuration. It's optional.
 * @property type $config_data The full startup configuration. It's optional.
 * @property type $cpu CPUs configured on the node. It's optional.
 * @property type $delay Seconds before starting the node. It's optional.
 * @property type $id Device ID. It's mandatory and set during contruction phase.
 * @property type $ethernet Number of configured Ethernet interfaces/portgroups. It's optional.
 * @property type $ethernets Configured Ethernet interfaces/portgroups. It's optional.
 * @property type $icon Icon used on diagram. It's optional.
 * @property type $idlepc Idle PC for Dynamips nodes. It's optional.
 * @property type $image Image for the node. It's mandatory and automatically set to one of the available one.
 * @property type $lab_id Lab ID. It's mandatory and set during contruction phase.
 * @property type $left Left margin for visual position. It's optional.
 * @property type $name Name of the node. It's optional but suggested.
 * @property type $nvram RAM configured on the node. It's optional.
 * @property type $port Console port. It's mandatory and set during contruction phase.
 * @property type $ram NVRAM configured on the node. It's optional.
 * @property type $serial Number of configured Serial interfaces/porgroups. It's optional (IOL only)
 * @property type $serials Configured Serial interfaces/porgroups. It's optional (IOL only)
 * @property type $slots Array of configured slots. It's optional (Dynamips only)
 * @property type $template Template of the node. It's mandatory.
 * @property type $tenant Tenant ID. It's mandatory and set during contruction phase.
 * @property type $top Top margin for visual position.
 * @property type $type Type of the node. It's mandatory.

 */



class device
{
    public $console;
    public $config;
    public $config_data;
    public $multi_config = [];
    public $config_script;
    public $cpu;
    public $cpulimit = 1;
    public $delay;
    public $ethernet;
    public $firstmac;
    public $icon;
    public $idlepc;
    public $image;
    public $left;
    public $name;
    public $nvram;
    public $ram;
    public $serial;
    public $top;
    public $size = '';


    protected $node = null;
    protected $ethernets = [];
    protected $serials = [];
    protected $modules = [];
    protected $tpl = []; // save template value

    function __construct($node)
    {
        $this->node = $node;
        try {
            $this->tpl = yaml_parse_file(BASE_DIR . '/html/templates/' . $this->getTemplate() . '.yml');
        } catch (Exception $e) {
            throw new ResponseException('Can not load template file {data}', ['data' => $this->getTemplate()]);
        }
    }

    /**
     * Create and return an unique MAC address for Node
     */
    public function createNodeMac($id)
    {
        $session = $this->getSession();
        $session = sprintf('%06x', $session);
        $mac = '50:' . chunk_split($session, 2, ':') . '00:' . sprintf('%02x', $id);
        return $mac;
    }

    /**
     * Create and Return an unique the First MAC address for Node
     */
    private $createFirstMacResult = null;
    public function createFirstMac()
    {
        if (IsValidMac($this->firstmac)) return $this->firstmac;

        if (!$this->createFirstMacResult ||  $this->createFirstMacResult == '') {
            $session = $this->getSession();
            $session = sprintf('%04x', $session);
            $random = sprintf('%04x', rand(0, 16 * 16 * 16 * 16));
            $mac = '50:' . chunk_split($random, 2, ':') . chunk_split($session, 2, ':') . '00';
            $this->createFirstMacResult = $mac;
        }

        return $this->createFirstMacResult;
    }

    /**
     * Create ethernet interfaces for onboard card
     * @property quantity : number of ethernet interface
     */
    public function createEthernets($quantity)
    {
        return $this->ethernets;
    }

    /**
     * Create serial interfaces for onboard card
     * @property quantity : number of serial interface
     */
    public function createSerials($quantity)
    {
        return $this->serials;
    }

    /**
     * Add network module or card to device
     * @property slot: Slot id
     * @property subSlot: Sub-Slot id
     * @property nm: Network module name
     * 
     */
    public function createModule($slot, $subSlot, $nm)
    {
        return $this->modules;
    }

    /**
     * Return all ethernets interface instances of device.
     * all interfaces in onboard and network modules
     * 
     */
    public function getEthernets()
    {
        $ethernets = $this->ethernets;
        foreach ($this->modules as $module) {
            foreach ($module->getEthernets() as $ethernet) {
                $ethernets[$ethernet->getId()] = $ethernet;
            }
        }
        return $ethernets;
    }

    /**
     * Return all serials interface instances of device.
     * all interfaces in onboard and network modules
     * 
     */
    public function getSerials()
    {
        $serials = $this->serials;
        foreach ($this->modules as $module) {
            foreach ($module->getserials() as $serial) {
                $serials[$serial->getId()] = $serial;
            }
        }
        return $serials;
    }

    public function getInterfaces()
    {
        return $this->getEthernets() + $this->getSerials();
    }


    /**
     * 
     * Return Flag to setting all interfaces and modules
     * in comand 
     */

    public function getFlag()
    {

        $flag = '';
        foreach ($this->ethernets as $eth) {
            $flag .= ' ' . $eth->getFlag();
        }
        foreach ($this->serials as $serial) {
            $flag .= ' ' . $serial->getFlag();
        }
        foreach ($this->modules as $module) {
            $flag .= ' ' . $module->getFlag();
        }
        return preg_replace('/\s+/m', ' ', $flag);
    }

    /**
     * Return all Network Modules
     * 
     */
    public function getModules()
    {
        return $this->modules;
    }

    /**
     * Return session id of node. 
     * When a node is loaded system will create a unique id (session) and save in database
     * session id will be deleted when you destroy lab.
     */
    public function getSession()
    {
        return $this->node->getSession();
    }

    /**
     * Return Lab session id
     */

    public function getLabSession()
    {
        return $this->node->getLabSession();
    }


    /**
     * 
     * Get console port of Node
     */
    public function getPort()
    {
        return $this->node->getPort();
    }

    /**
     * 
     * Get secondary port of Node
     */
    public function getSecondPort()
    {
        return $this->node->getSecondPort();
    }

    /**
     * Get Remote node by id
     */
    public function getNode($id)
    {
        return $this->node->getNode($id);
    }

    /**
     * Get network by ID
     */
    public function getNetwork($id)
    {
        return $this->node->getNetwork($id);
    }

    /**
     * Return Running folder of Node
     */
    public function getRunningPath()
    {
        return $this->node->getRunningPath();
    }

    /**
     * Return Node type 
     */
    public function getNType()
    {
        return $this->node->getNType();
    }

    /**
     * Return Node template name
     */

    public function getTemplate()
    {
        return $this->node->getTemplate();
    }


    /**
     * Return pod of user who create node session
     */

    public function getHost()
    {
        return $this->node->getHost();
    }

    /**
     * @return Status of node
     */

    public function getStatus()
    {
        return $this->node->getStatus();
    }


    /** 
     * @return int User POD of current session
     */
    public function getTenant()
    {
        return $this->node->getTenant();
    }

    /**
     * @return int ID of node in lab
     */
    public function getId()
    {
        return $this->node->getId();
    }

    /**
     * 
     *@return ScriptTimeout: the time system will wait before running script to apply start-up configuration
     * The time wait = Script Timeout + Delay    
     */
    public function getScriptTimeout()
    {
        if ($this->script_timeout > 0) return $this->script_timeout;
        return $this->node->getScriptTimeout();
    }


    public function getIolId()
    {
        return $this->node->getIolId();
    }

    /**
     * Return parameters for a device.
     * The return data of this function will be used to save node's data to .unl file 
     * or show on edit node form
     */
    public function getParams()
    {




        return [
            'config' => $this->config,
            'config_script' => $this->config_script,
            'script_timeout' => $this->script_timeout,
            'config_data' => base64_encode($this->config_data),
            'multi_config' => base64_encode(json_encode($this->multi_config)),
            'delay' => (int) $this->delay,
            'icon' => $this->icon,
            'image' => $this->image,
            'left' => (int) $this->left,
            'name' => $this->name,
            'top' => (int) $this->top,
            'size' => (int) $this->size,
            'console' => $this->console,
            'ethernet' => (int) $this->ethernet,
            'nvram' => (int) $this->nvram,
            'ram' => (int) $this->ram,
            'serial' => (int) $this->serial,
            'idlepc' => $this->idlepc,
            'console' => $this->console,
            'cpu' => (int) $this->cpu,
            'cpulimit' => (int) $this->cpulimit,
        ];
    }

    /**
     * Using parameters get from .unl file or add edit node form to set value for device instance
     * @property p: node's params get from .unl file or add/edit node form
     * 
     */

    public function editParams($p)
    {

        if (isset($p['config'])) {
            $this->config = $p['config'];
        }

        if (isset($p['config_script']) && $p['config_script'] != '') {
            $this->config_script = $p['config_script'];
        }

        if (isset($p['script_timeout'])) {
            $this->script_timeout = $p['script_timeout'];
        }

        if (isset($p['config_data'])) {
            $this->config_data = base64_decode($p['config_data']);
        }

        if (isset($p['multi_config'])) {
            try {
                $this->multi_config = json_decode(base64_decode($p['multi_config']), true);
            } catch (Exception $th) {
                $this->multi_config = [];
            }
        }

        if (isset($p['delay'])) {
            $this->delay = (int) $p['delay'];
        }

        if (isset($p['icon'])) {
            $this->icon = $p['icon'] != '' ? $p['icon'] : 'Router.png';
        }

        if (isset($p['image'])) {
            $this->image = $p['image'];
        }

        if (isset($p['left'])) {
            $this->left = $p['left'] != '' ? $p['left'] : rand(100, 924);
        }

        if (isset($p['name'])) {
            $this->name = $p['name'];
        }

        if (isset($p['top'])) {
            $this->top = $p['top'] != '' ? $p['top'] : rand(100, 668);
        }

        if (isset($p['size'])) {
            $this->size = $p['size'];
        }

        if (isset($p['console'])) {
            $this->console = $p['console'];
            if (in_array($this->type, array('iol', 'dynamips'))) {
                $this->console = 'telnet';
            }
        }

        if (isset($p['ethernet']) && $this->ethernet !== (int) $p['ethernet']) {
            $this->ethernet = (int) $p['ethernet'];
            $this->createEthernets($this->ethernet);
        }

        if (isset($p['nvram'])) {
            $this->nvram = $p['nvram'] != '' ? (int) $p['nvram'] : 1024;
        }

        if (isset($p['ram'])) {
            $this->ram = $p['ram'] != '' ? (int) $p['ram'] : 1024;
        }

        if (isset($p['serial']) && $this->serial !== (int) $p['serial']) {
            $this->serial = (int) $p['serial'];
            $this->createSerials($this->serial);
        }

        if (isset($p['idlepc'])) {
            $this->idlepc = $p['idlepc'] != '' ? $p['idlepc'] : '0x0';
        }

        if (isset($p['console'])) {
            $this->console = htmlentities($p['console']);
        }

        if (isset($p['cpu'])) {
            $this->cpu = (int) $p['cpu'];
        }

        if (isset($p['cpulimit'])) {
            $this->cpulimit = (int) $p['cpulimit'];
        }
    }


    /**
     * Method to get node console URL.
     * 
     * @return	string                      Node console URL
     */
    public function getConsoleUrl($html5)
    {

        if ($html5 != 1) {
            switch ($this->console) {
                default:
                case 'telnet':
                    return 'telnet://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
                    break;;
                case 'ssh':
                    return 'ssh://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
                    break;;
                case 'vnc':
                    return 'vnc://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
                    break;;
                case 'rdp':
                    return '/rdp/?target=' . $_SERVER['SERVER_NAME'] . '&port=' . $this->getPort();
                    break;;
                case 'winbox':
                    return 'winbox://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
                    break;;
                case 'http':
                    return 'http://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
                    break;;
                case 'https':
                    return 'https://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
                    break;;
            }
        } else {
            if ($this->console == 'winbox') return 'winbox://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
            if ($this->console == 'http') return 'http://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
            if ($this->console == 'https') return 'https://' . $_SERVER['SERVER_NAME'] . ':' . $this->getPort();
            return 'guacamole';
        }
    }


    /**
     * Method to get node 2nd console URL.
     * 
     * @return	string                      Node 2nd console URL
     */
    public function getSecondConsoleUrl($html5)
    {
        if (!isset($this->console_2nd) || $this->console_2nd == '') {
            return '';
        }

        $secondPort = $this->getSecondPort();

        if ($html5 != 1) {
            switch ($this->console_2nd) {
                default:
                case 'telnet':
                    return 'telnet://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
                    break;;
                case 'ssh':
                    return 'ssh://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
                    break;;
                case 'vnc':
                    return 'vnc://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
                    break;;
                case 'rdp':
                    return '/rdp/?target=' . $_SERVER['SERVER_NAME'] . '&port=' . $secondPort;
                    break;;
                case 'winbox':
                    return 'winbox://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
                    break;;
                case 'http':
                    return 'http://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
                    break;;
                case 'https':
                    return 'https://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
                    break;;
            }
        } else {
            if ($this->console_2nd == 'winbox') return 'winbox://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
            if ($this->console_2nd == 'http') return 'http://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
            if ($this->console_2nd == 'https') return 'https://' . $_SERVER['SERVER_NAME'] . ':' . $secondPort;
            return 'guacamole';
        }
    }


    /**
     * 
     * Get Html console link
     * Called when user console to device in HTML console mode
     * @param int index: 1 for primary console; 2 for secondary console
     * @return string link to connect to guacamole.
     */

    public function getGuacConsoleLink($index)
    {
        $html5_db = html5_checkDatabase();
        $username = getUser()['username'];

        if ($index == 1) {
            $console = $this->console;
            $port = $this->getPort();
        } else {
            $console = $this->console_2nd;
            $port = $this->getSecondPort();
        }

        if (!isset($console) || $console == '') {
            $console = 'telnet';
        }

        if ($console == 'http'){
            return 'http://' . $_SERVER['SERVER_NAME'] . ':' . $port;
        }
        if ($console == 'https'){
            return 'https://' . $_SERVER['SERVER_NAME'] . ':' . $port;
        }

        if ($console == 'rdp' || $console == 'vnc') {
            html5AddSession($html5_db, $this->name . '_' . $this->getId() . '_' . $username, $console, $port, $this->getTenant(), null, null, $this->username, $this->password, 'reconnect');
        } else {
            html5AddSession($html5_db, $this->name . '_' . $this->getId() . '_' . $username, $console, $port, $this->getTenant());
        }

        $token = getHtml5Token($this->getTenant());
        $b64id = base64_encode($port . $this->getTenant() . "\0" . 'c' . "\0" . 'mysql');

        return '/html5/#/client/' . $b64id . '?token=' . $token;
    }


    /**
     * 
     * Build command to start device
     */
    public function command()
    {
        return '';
    }

    /**
     * Make device ready for starting
     */
    public function prepare()
    {

        posix_setsid();
        posix_setgid(32768);

        if (!is_file($this->getRunningPath() . '/.prepared') && !is_file($this->getRunningPath() . '/.lock')) {

            // Node is not prepared/locked
            if (!is_dir($this->getRunningPath()) && !mkdir($this->getRunningPath(), 0775, True)) {
                // Cannot create running directory
                error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80037]);
                return 80037;
            }


            if ($this->config == '1') {
                // Node should use saved startup-config

                $activeConfig = $this->getActiveConfig();
                if ($activeConfig == '') {
                    $startupCfg = $this->config_data;
                } else {
                    $startupCfg = get($this->multi_config[$activeConfig], '');
                }

                if ($startupCfg != '') {
                    if (!dumpConfig($startupCfg, $this->getRunningPath() . '/startup-config')) {
                        error_log(date('M d H:i:s ') . 'WARNING: ' . $GLOBALS['messages'][80067]);
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Make device ready for starting
     */
    public function start()
    {

        $result = $this->prepare();

        // EVERY FAILURE FROM HERE ON UNWINDS. prepare() creates the taps, and
        // it creates them FIRST -- before the linked clone, before .prepared,
        // before anything that can fail -- so each of its later error returns
        // used to leave one tap per interface on the host. See abandonStart().
        if ($result > 0) return $this->abandonStart($result);

        if (!chdir($this->getRunningPath())) {
            // Failed to change directory
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80047]);
            return $this->abandonStart(80047);
        }

        // TWO different things used to arrive here and be treated as one.
        //
        // command() returns '' for a node type that has no emulator command
        // line at all -- Docker, which does not override it, and whose
        // container is started by device_docker::start() after this returns.
        // That is SUCCESS and has to stay success.
        //
        // device_qemu::command() returns array(False, False) when it cannot
        // resolve the architecture or the binary. That is a failure, and it
        // never reached the `$cmd == ''` test below: secureCmd() runs first and
        // calls preg_match() on the array, which is a TypeError on PHP 8. So a
        // QEMU node with an unresolvable template took the whole request down
        // with a fatal, left its taps behind, and reported nothing.
        //
        // The type check therefore comes BEFORE secureCmd(), and the two cases
        // are separated. `return;` (NULL) was what the empty case did, and NULL
        // is not > 0, so callers read it as started either way -- 0 says the
        // same thing on purpose.
        $cmd = $this->command();
        if (!is_string($cmd)) {
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80046]);
            return $this->abandonStart(80046);
        }
        if ($cmd === '') return 0;
        // secureCmd() THROWS, and an uncaught throw from this point leaves the
        // taps behind exactly as the returns above did, so it stays wrapped.
        //
        // SECURE_LINE is the shape: this is a whole emulator command line. What
        // that shape proves is that the line cannot spawn a second command —
        // no $( ), no backtick, no ; | & newline, no unquoted glob. What it does
        // NOT prove is that the arguments are the intended ones, because the
        // line is NOT fully escaped: qemu_options, dynamips_options and
        // getFlag() are concatenated raw, and they exist to supply several
        // arguments (handover point 4, and the sweep-exempt markers in
        // devices/qemu/). An unquoted space is still a word separator here.
        //
        // So this is defence in depth over a surface the fork has decided to
        // keep. Two things contain it, and they are different in kind:
        //
        //   - device::spawnAsTenant() no longer runs a shell at all. It splits
        //     this line with unl_command_argv() and execs the program directly,
        //     so for VPCS and QEMU the option strings can add ARGUMENTS, which
        //     is the feature, and nothing else -- there is no interpreter left
        //     to act on a `;` or a `>` even if one reached here.
        //   - and that exec runs as unl<session>, not as root.
        //
        // The types that do NOT run as the tenant -- dynamips and IOL -- still
        // reach the `exec($cmd . ' &')` below, and for those this check is
        // still the only thing standing between an option string and a shell.
        // Converting them needs the background-and-reap that `&` currently
        // provides, and a licensed image to verify against; see ROADMAP-STATUS.
        try {
            $cmd = secureCmd($cmd, SECURE_LINE);
        } catch (Exception $e) {
            error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][80046] . ' ' . $e->getMessage());
            return $this->abandonStart(80046);
        }
        $cmd = preg_replace('/\s+/m', ' ', $cmd);

        error_log(date('M d H:i:s ') . 'INFO: CWD is ' . getcwd());
        error_log(date('M d H:i:s ') . 'INFO: starting ' . $cmd);
        // Clean TCP port
        exec("fuser -k -n tcp " . (int) $this->getPort());

        if ($this->runsAsTenant()) {
            $rcp = $this->spawnAsTenant($cmd . ' 2>&1');
        } else {
            exec($cmd . ' 2>&1 &', $o, $rcp);
        }

        // spawnAsTenant() returns 80036 for a missing ext-pcntl, a tenant
        // account that is absent or holds the wrong uid, and a failed fork.
        // All three are after the taps exist.
        if ($rcp != 0) return $this->abandonStart($rcp);

        if ($this->type != 'docker') {
            $ethernets = $this->getEthernets();
            foreach ($ethernets as $ethernet) {
                if (count($ethernet->getQuality()) > 0) $ethernet->applyQuality();
                if ($ethernet->getSuspendStatus() == 1) $ethernet->applySuspendStatus();
            }
        }

        return $rcp;
    }

    /**
     * Undo a start that got part way and then failed.
     *
     * WHY THIS IS WORSE THAN IT LOOKS, AND WHY IT IS HERE AND NOT IN prepare()
     *
     * The tap is created before the failure point, and neither stop nor delete
     * removed it: device::stopNode() did all of its work, tap teardown
     * included, inside `if ($this->getStatus() != 0)`, and a node whose start
     * failed has status 0. So `stop` was a no-op on exactly the node that
     * needed it -- one orphaned vunl<session>_* per failed start, permanently.
     *
     * Since tenant accounts are reaped, that also strands the ACCOUNT. The
     * reaper refuses to remove an account while a vunl<session>_* interface
     * exists (actions/UnlTenantAccount.php), which is the right refusal -- it
     * is what stops a running node losing its uid -- but it means one leaked
     * tap pins one Unix account for the life of the host. The two bugs
     * compound, and the second one arrived after the first was written down.
     *
     * It lives here rather than in each prepare() because device::start() is
     * the single funnel: every node type's start() calls parent::start(), so
     * one unwind covers vpcs, qemu (three variants), dynamips, iol and docker.
     * Putting it in prepare() would mean six near-identical edits and would
     * still miss the failures start() itself can have after prepare() returned.
     *
     * The tenant is reaped too. A start that produced nothing should leave
     * nothing, and the reaper does its own safety checks -- no process on the
     * uid, no surviving tap, no session reporting status 2 or 3 -- so this
     * cannot pull an account out from under a node that is actually running.
     *
     * @param   int     $code               The failure to report
     * @return  int                         $code, unchanged
     */
    protected function abandonStart($code)
    {
        error_log(date('M d H:i:s ') . 'INFO: start failed (' . $code . '); releasing taps for session '
            . (int) $this->getSession());
        $this->releaseTaps();
        $this->reapTenant();
        return $code;
    }

    /**
     * Tear down every tap belonging to this node session.
     *
     * Enumerated from the host, not from the node's interface list: a start
     * that died inside prepare()'s interface loop created a PREFIX of that
     * list, and an interface removed from the lab after a start leaves a tap
     * that the list no longer mentions. delTap() is a no-op for a name that is
     * not there, so this is safe to call on a node that never started.
     *
     * @return  int                         0
     */
    protected function releaseTaps()
    {
        foreach (unl_session_taps($this->getSession()) as $tap) {
            if (delTap($tap) !== 0) {
                error_log(date('M d H:i:s ') . 'ERROR: could not remove ' . $tap);
            }
        }
        return 0;
    }

    /**
     * Does this node type's emulator run as the tenant account?
     *
     * False here, and overridden to true only for the types that have been
     * driven end to end unprivileged on a real host. Adding a type to that list
     * is a claim about the whole start path, not a preference, so it is made per
     * class rather than by a flag.
     *
     * Note that only IOL dropped privileges before this, and it did so by
     * calling posix_setuid() in prepare() — inside the WRAPPER's own process.
     * That is why unl_wrapper's start-all loop postpones IOL nodes: once the
     * wrapper has setuid'd, it cannot start anything else, cannot create the
     * next tenant account, and cannot bring up the next tap. spawnAsTenant()
     * drops in a forked child instead, so the wrapper stays root and the
     * ordering constraint goes away.
     */
    protected function runsAsTenant()
    {
        return false;
    }

    /**
     * Start the emulator as the tenant account, in a forked child.
     *
     * WHY THE PIECES ARE ALREADY IN PLACE
     *
     * prepare() has, by this point, created the tap with `tunctl -u unl<N>` and
     * attached it to the bridge. A persistent tap can be opened by its owning
     * uid — that is what tunctl's -u means — so the emulator does not need
     * CAP_NET_ADMIN to attach to it, and /dev/net/tun is 0666. The running
     * directory and the disk images beneath it are root:unl 0775/0664, so the
     * tenant reaches them through the group. The console ports are 3xxxx and
     * the VNC ports 59xx, neither of which is privileged.
     *
     * ORDER IN THE CHILD MATTERS. Supplementary groups have to be set before the
     * uid is dropped, because setuid is one-way; kvm is what /dev/kvm needs, and
     * accel=kvm is in most of the shipped templates. stdio is replaced last, so
     * a redirect the command carries lands as the tenant and not as root.
     *
     * NO SHELL IS USED. An earlier revision of this method said one was, and
     * said an argv array "would break every template" — which was true of the
     * obvious approach, escaping the option strings as single arguments, and
     * false of the one taken. unl_command_argv() splits the assembled line the
     * way a shell would, so `-machine type=pc,accel=kvm -vga std` still becomes
     * four arguments, and then the program is exec'd directly.
     *
     * That distinction is the whole change. The template option strings remain
     * multi-argument, because that is the feature. What they lose is everything
     * a shell would have done afterwards: no redirection, and no second command
     * even if secureCmd()'s SECURE_LINE check were ever weakened or bypassed.
     * The line's own `> wrapper.txt 2>&1` is applied by the child below rather
     * than by an interpreter.
     *
     * @param   string  $cmd                The full command line
     * @return  int                         0 if the child was forked
     */
    protected function spawnAsTenant($cmd)
    {
        $session = (int) $this->getSession();
        $user = 'unl' . $session;

        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')
            || !function_exists('posix_setuid')) {
            error_log(date('M d H:i:s ') . 'ERROR: ext-pcntl and ext-posix are required to '
                . 'run a node as its tenant');
            return 80036;
        }

        // Computed, then CONFIRMED against the passwd database, exactly as
        // UnlIolKeepalive does it: an account called unl<session> that does not
        // hold uid 32768+session is not the platform's, and dropping to it would
        // put the node somewhere nobody expects. The uid is never taken from the
        // output of `id`, which is what device_iol::prepare() used to do.
        $entry = posix_getpwnam($user);
        $expected = 32768 + $session;
        if ($entry === false || (int) $entry['uid'] !== $expected) {
            error_log(date('M d H:i:s ') . 'ERROR: tenant account ' . $user
                . ' is missing or holds the wrong uid; not starting');
            return 80036;
        }
        $gid = (int) $entry['gid'];
        $cwd = $this->getRunningPath();

        // Tokenised BEFORE the fork, so a line this cannot split is an error
        // the caller sees rather than a silent exit(127) inside a child whose
        // stdio has already been replaced. There is deliberately no fallback
        // to /bin/sh: falling back would reinstate exactly the shell this is
        // here to remove, on the one input that confused the tokeniser.
        try {
            $parsed = unl_command_argv($cmd);
        } catch (Exception $e) {
            error_log(date('M d H:i:s ') . 'ERROR: cannot split the emulator command line into '
                . 'an argv array: ' . $e->getMessage());
            return 80046;
        }

        // SECURE_LINE permits `>`, because the call sites build their own
        // redirection -- so a template option string containing one passes the
        // guard, and tokenising alone would faithfully honour it. Both tenant
        // node types redirect to exactly one file inside the node's running
        // directory, so that is the rule, and anything else refuses to start.
        //
        // Without this, an operator-supplied qemu_options of `> /path` would
        // still choose where the emulator's stdout landed, as the tenant. The
        // tokeniser reports the count as well as the target because a shell
        // opens and TRUNCATES every redirection in a line, not just the last.
        if ($parsed['redirects'] > 1) {
            error_log(date('M d H:i:s ') . 'ERROR: the emulator command line carries '
                . $parsed['redirects'] . ' redirections; a call site builds one');
            return 80046;
        }
        if ($parsed['stdout'] !== null && strpos($parsed['stdout'], $cwd . '/') !== 0) {
            error_log(date('M d H:i:s ') . 'ERROR: the emulator command line redirects to '
                . $parsed['stdout'] . ', which is outside the node running directory');
            return 80046;
        }

        $pid = pcntl_fork();
        if ($pid < 0) {
            error_log(date('M d H:i:s ') . 'ERROR: fork failed; not starting ' . $user);
            return 80036;
        }

        if ($pid === 0) {
            // --- child ------------------------------------------------------
            posix_setsid();
            // Supplementary groups first: kvm is not the tenant's primary group,
            // and after setuid() there is no way back to set it.
            if (function_exists('posix_initgroups')) posix_initgroups($user, $gid);
            if (!posix_setgid($gid) || !posix_setuid($expected)) exit(126);
            @chdir($cwd);
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            @fopen('/dev/null', 'r');

            // The redirection the line carried, applied here instead of by a
            // shell. Both descriptors are opened APPEND rather than duplicated:
            // PHP has no dup2(), and two independent 'w' handles on one file do
            // not share a file offset, so the second would overwrite the first.
            // Appending cannot, and the file is truncated once beforehand so a
            // restart still starts from empty. This is a log; ordering within
            // it is not load-bearing.
            $out = $parsed['stdout'];
            if ($out !== null) {
                if (!$parsed['append']) @file_put_contents($out, '');
                if (@fopen($out, 'a') === false) @fopen('/dev/null', 'w');
            } else {
                @fopen('/dev/null', 'w');
            }
            if ($parsed['stderr_to_stdout'] && $out !== null) {
                if (@fopen($out, 'a') === false) @fopen('/dev/null', 'w');
            } else {
                @fopen('/dev/null', 'w');
            }

            // The program, executed directly. No shell exists in this process
            // tree from here on, so the template option strings can still add
            // arguments -- which is what they are for -- and can no longer add
            // a redirection or anything else a shell would have acted on.
            pcntl_exec($parsed['argv'][0], array_slice($parsed['argv'], 1));
            exit(127);   // only reached if exec failed
        }

        // --- parent ---------------------------------------------------------
        // Deliberately not waited on: the emulator is a long-lived daemon, and
        // the caller polls the console port for it. setsid() above means it
        // survives the wrapper and is reparented to init, which is what the old
        // `exec("... &")` achieved by letting sh fork and exit.
        error_log(date('M d H:i:s ') . 'INFO: started as ' . $user . ' (uid ' . $expected
            . '), pid ' . $pid);
        return 0;
    }

    /**
     * stop device
     *
     */
    public function stop()
    {
        $this->stopNode();
        $this->reapTenant();
        return 0;
    }

    /**
     * Reap the Unix account this node session manufactured.
     *
     * Node start runs `useradd ... unl<session>` once per node session and
     * nothing ever removed one, so the accounts grew without bound for the life
     * of the appliance. This is the ordinary end of a session, and therefore
     * where the ordinary reap belongs.
     *
     * ORDER MATTERS AND THIS CALL IS LAST ON PURPOSE. The account owns the tap
     * interfaces and the running directory, so it may only go after both are
     * finished with. It runs unconditionally — including when getStatus() said
     * the node was already stopped — because a half-torn-down session that
     * leaves an account behind is exactly the case that lets a later session's
     * id collide with it.
     *
     * Through sudo rather than in-process because this method has TWO callers
     * with different privileges: `unl_wrapper -a stop`, which is root, and
     * destroyLabSession()/stopLabSession() in includes/functions.php, which run
     * inside the web request as www-data. The wrapper decides for itself
     * whether the reap is safe; see actions/UnlTenantAccount.php.
     */
    protected function reapTenant()
    {
        $cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a reap-tenant'
            . ' -S ' . (int) $this->getSession() . ' > /dev/null 2>&1';
        exec($cmd, $o, $rc);
        return 0;
    }

    /**
     * THE STATUS GUARD IS THE OTHER HALF OF THE TAP LEAK.
     *
     * Everything below used to sit inside `if ($this->getStatus() != 0)`,
     * teardown included. A node whose start failed reports status 0, so stop
     * did nothing for it -- which is precisely the node with a stranded tap.
     * Delete behaves the same way, so nothing on the box ever collected one.
     *
     * Killing the emulator still depends on there being one. Releasing the taps
     * does not, and now happens either way, so a tap left by an older failed
     * start (or by a crash between prepare() and start()) is collected the next
     * time anything stops or deletes that node -- which also unpins the tenant
     * account the reaper was refusing to remove while the tap existed.
     */
    private function stopNode()
    {
        if ($this->getStatus() != 0) {
            if ($this->getNType() == 'docker') {
                $cmd = 'docker -H=unix:///var/run/docker.sock stop ' . escapeshellarg('docker' . $this->getSession());
            } else {
                $cmd = 'sudo fuser -k -TERM ' . escapeshellarg($this->getRunningPath());
            }
            error_log(date('M d H:i:s ') . 'INFO: stopping ' . $cmd);
            exec($cmd, $o, $rc);

            if ($this->getStatus() != 0) {
                // command() reports its own failures by returning
                // array(False, False) -- see device_qemu::command(), which does
                // that when the arch or the binary cannot be resolved. Passing
                // that to escapeshellarg() is a fatal TypeError on PHP 8, so a
                // node that failed to start took the whole stop request down
                // with it and could never be cleaned up.
                $pkillTarget = $this->command();
                if (is_string($pkillTarget) && $pkillTarget !== '') {
                    $cmd = 'sudo pkill -term ' . escapeshellarg($pkillTarget);
                    error_log(date('M d H:i:s ') . 'INFO: stopping ' . $cmd);
                    exec($cmd, $o, $rc);
                }
            }

            usleep(200000); //sleep waiting for vunl free
        }

        // Outside the guard, and no longer a shell pipeline.
        //
        // What was here was
        //     ip link | grep 'vunl<session>_' | sed ... | while read line; do
        //         sudo ip link set $line down; sudo tunctl -d $line; done
        // which matched by PREFIX: 'vunl1_' matches vunl12_0, so stopping
        // session 1 on a busy host tore down session 12's data plane. It also
        // read nothing back, so a tap that survived tunctl -d was reported as
        // removed. releaseTaps() anchors the name and goes through delTap(),
        // which re-checks and says so when the interface is still there.
        $this->releaseTaps();

        return 0;
    }

    /**
     * Export configuration
     */

    public function export()
    {
        return 0;
    }


    /**
     * @return multi_config_active Config actived of Lab
     **/
    public function getActiveConfig()
    {
        return $this->node->getActiveConfig();
    }

    /**
     * Wipe Node
     */

    public function wipe()
    {

        $runningPath = $this->getRunningPath();
        if ($runningPath != null && $runningPath != '') {
            $cmd = 'sudo rm -rf ' . escapeshellarg($runningPath);
            exec($cmd, $o, $rc);
        }

        return 0;
    }


    public function __get($name)
    {
        return isset($this->$name) ? $this->name : '';
    }
}
