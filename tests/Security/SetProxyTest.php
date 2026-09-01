<?php
/**
 * Exercises `unl_wrapper -a set-proxy`, the action that replaced a root RCE.
 *
 * WHAT WAS THERE BEFORE
 *
 * store/app/Helpers/Request/Query.php::setProxy():
 *
 *     $file = '/etc/apt/apt.conf.d/00proxy';
 *     if(!is_file($file)) exec('sudo touch '.$file);
 *     $proxyAddr = $p['proxy_username'].':'.$p['proxy_password'].'@'
 *                . $p['proxy_ip'].':'.$p['proxy_port'];
 *     $result = exec("echo '".$proxyAddr."' | sudo tee ".$file);
 *
 * Four request fields, no escaping, inside a single-quoted shell string, with
 * `sudo tee` on the far side of the pipe and an apt configuration file as the
 * destination. old_setproxy_argv() below is that call site, used as a NEGATIVE
 * CONTROL: the same inputs the validator now refuses are shown becoming a
 * second root command there.
 *
 * WHAT IS ASSERTED HERE
 *
 *   - every rejected input shape, per component: quote, newline, backslash,
 *     '@', ':', space, out-of-range port, malformed host;
 *   - that a password IS allowed to be rich, and that no byte of it reaches the
 *     file as syntax — it is percent-encoded, which is the trade that lets the
 *     class be wider than the username's without widening the blast radius;
 *   - that clearing removes the file, rather than leaving the empty one the old
 *     code left;
 *   - that what the action writes is what Query::parseProxy() reads back. A
 *     writer and a reader that disagree is how the original stayed broken for
 *     its whole life without anyone noticing it was also a vulnerability.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');
require_once $root . '/platform/wrappers/actions/UnlSetProxy.php';
require_once $root . '/store/app/Helpers/Request/Query.php';

// ---------------------------------------------------------------- scaffolding

$ws = sys_get_temp_dir() . '/setproxy-test-' . getmypid();
mkdir($ws, 0755, true);
$file = $ws . '/00proxy';

register_shutdown_function(function () use ($ws) {
    foreach (scandir($ws) as $n) if ($n !== '.' && $n !== '..') @unlink($ws . '/' . $n);
    @rmdir($ws);
});

function sp()
{
    global $file;
    return new UnlSetProxy(['file' => $file]);
}

/** NEGATIVE CONTROL — the pre-change call site's command string. */
function old_setproxy_argv(array $p)
{
    $proxyAddr = $p['proxy_username'] . ':' . $p['proxy_password']
        . '@' . $p['proxy_ip'] . ':' . $p['proxy_port'];
    $proxyAddr = 'Acquire::http::Proxy "http://' . $proxyAddr . '/";';
    return ['/bin/sh', '-c', "echo '" . $proxyAddr . "' | sudo tee /etc/apt/apt.conf.d/00proxy"];
}

// --------------------------------------------------------------------- the host

echo "  -- the host is an IP literal or a hostname, and nothing else\n";

$goodHosts = [
    '10.0.0.1'          => '10.0.0.1',
    '127.0.0.1'         => '127.0.0.1',
    '::1'               => '[::1]',
    '2001:db8::1'       => '[2001:db8::1]',
    'proxy.example.com' => 'proxy.example.com',
    'my-proxy'          => 'my-proxy',
    'a.b.c.d.example'   => 'a.b.c.d.example',
];
foreach ($goodHosts as $in => $want) {
    assert_same($want, UnlSetProxy::host($in), "accepts host $in");
}

$badHosts = [
    '', ' ', '10.0.0.1 ', "10.0.0.1\n", "10.0.0.1\r", '10.0.0.1;id', '10.0.0.1|id',
    "10.0.0.1'", '10.0.0.1"', '10.0.0.1\\', '10.0.0.1$(id)', '10.0.0.1`id`',
    'a b.com', 'a_b.com', 'a..b.com', 'a.b.com.', '.a.com', '-lead.com', 'trail-.com',
    '[::1]', '::1%eth0', 'http://10.0.0.1', '10.0.0.1/x', 'user@10.0.0.1',
    str_repeat('a', 254), str_repeat('a', 64) . '.com',
    null, false, true, 42, ['10.0.0.1'],
];
$rejected = 0;
foreach ($badHosts as $bad) {
    if (UnlSetProxy::host($bad) === null) $rejected++;
    else echo "        ACCEPTED a bad host: " . var_export($bad, true) . "\n";
}
assert_same(count($badHosts), $rejected,
    sprintf('rejects every malformed host (%d of %d)', $rejected, count($badHosts)));

// --------------------------------------------------------------------- the port

echo "  -- the port is an integer in 1-65535\n";
assert_same(3128, UnlSetProxy::port('3128'), 'accepts 3128');
assert_same(1, UnlSetProxy::port(1), 'accepts the low bound');
assert_same(65535, UnlSetProxy::port('65535'), 'accepts the high bound');

$badPorts = ['', '0', '-1', '65536', '99999', '999999', '80a', '8 0', "80\n", '8;id',
             '0x50', ' 80', '80 ', null, false, true, ['80'], '+80', '8.0'];
$rejected = 0;
foreach ($badPorts as $bad) {
    if (UnlSetProxy::port($bad) === null) $rejected++;
    else echo "        ACCEPTED a bad port: " . var_export($bad, true) . "\n";
}
assert_same(count($badPorts), $rejected,
    sprintf('rejects every port outside 1-65535 (%d of %d)', $rejected, count($badPorts)));

// ----------------------------------------------------------------- the username

echo "  -- the username cannot carry a quote, a newline, a backslash, an @ or a :\n";
foreach (['bob', 'bob.smith', 'bob_smith', 'bob-1', str_repeat('u', 64)] as $ok) {
    assert_true(UnlSetProxy::user($ok) !== null, "accepts username $ok");
}
$badUsers = [
    '', "bob'", 'bob"', "bob\n", "bob\r", 'bob\\', 'bob@host', 'bob:pass', 'bob smith',
    'bob;id', 'bob$(id)', 'bob`id`', 'bob|id', 'bob/x', "bob\0", str_repeat('u', 65),
    null, false, true, 42, ['bob'],
];
$rejected = 0;
foreach ($badUsers as $bad) {
    if (UnlSetProxy::user($bad) === null) $rejected++;
    else echo "        ACCEPTED a bad username: " . var_export($bad, true) . "\n";
}
assert_same(count($badUsers), $rejected,
    sprintf('rejects every username outside the slug class (%d of %d)', $rejected, count($badUsers)));

// ----------------------------------------------------------------- the password

echo "  -- the password may be rich, but only because every byte of it is encoded\n";

// These are refused outright: no printable-ASCII password contains them, and a
// newline in this position would split one apt directive into two.
$badPasswords = [
    '', ' ', "pw\n", "pw\r", "pw\t", "pw\0", 'pw pw', "\x7f", str_repeat('p', 129),
    null, false, true, 42, ['pw'],
];
$rejected = 0;
foreach ($badPasswords as $bad) {
    if (UnlSetProxy::password($bad) === null) $rejected++;
    else echo "        ACCEPTED a bad password: " . var_export($bad, true) . "\n";
}
assert_same(count($badPasswords), $rejected,
    sprintf('rejects every password with a space or a control character (%d of %d)',
        $rejected, count($badPasswords)));

// ------------------------------------------------------- what reaches the file

echo "  -- nothing a caller sends can reach the file as syntax\n";

$hostile = [
    "p'w",                       // the byte that broke the old single-quoted string
    'p"w',                       // the byte that would close apt's own quoting
    'p\\w',
    'p@w', 'p:w',
    'p;id', 'p$(id)', 'p`id`', 'p|id', 'p&id',
    'p";};APT::Update::Pre-Invoke{"/bin/sh"};//',   // a root command at the next apt run
];
foreach ($hostile as $pw) {
    $r = sp()->run('10.0.0.1', '3128', 'bob', $pw);
    assert_true($r['ok'], 'writes a proxy with a hostile password: ' . $pw);
    $written = file_get_contents($file);

    // Three separate properties, because each one on its own can be satisfied
    // by a file that is still wrong.
    assert_true(substr_count($written, '"') === 6,
        'the file holds exactly the six quotes its three directives need');
    assert_true(strpos($written, '\\') === false, 'no backslash reached the file');
    assert_true(substr_count($written, "\n") === 4, 'no directive was split by a newline');

    // And the round trip: what the wrapper wrote is what the reader reads.
    $back = \App\Helpers\Request\Query::parseProxy($written);
    assert_same($pw, $back['proxy_password'], 'the password survives the round trip intact');
    assert_same('bob', $back['proxy_username'], 'and so does the username');
    assert_same('10.0.0.1', $back['proxy_ip'], 'and the host');
    assert_same('3128', $back['proxy_port'], 'and the port');
}

// ----------------------------------------------------------- rejected combinations

echo "  -- a half-specified proxy is refused, not guessed at\n";
$badCalls = [
    ['10.0.0.1', null, null, null],          // no port
    ['10.0.0.1', '3128', 'bob', null],       // username with no password
    ['10.0.0.1', '3128', null, 'secret'],    // password with no username
    ['10.0.0.1', '3128', "bob'", 'secret'],  // the old injection point
    ["10.0.0.1'", '3128', 'bob', 'secret'],
    ['10.0.0.1', "3128' ; touch /tmp/pwned ; echo '", 'bob', 'secret'],
    [null, '3128', null, null],              // a port with no host is not a clear
    [null, null, 'bob', 'secret'],           // nor is a credential with no host
];
$rejected = 0;
$before = file_get_contents($file);
foreach ($badCalls as $call) {
    $r = sp()->run($call[0], $call[1], $call[2], $call[3]);
    if (!$r['ok']) $rejected++;
    else echo "        ACCEPTED a bad call: " . var_export($call, true) . "\n";
}
assert_same(count($badCalls), $rejected,
    sprintf('refuses every half-specified or hostile combination (%d of %d)', $rejected, count($badCalls)));
assert_same($before, file_get_contents($file), 'and changed nothing while refusing');

// An error message must not quote the secret back: it is echoed as JSON and
// logged by the caller.
$r = sp()->run('10.0.0.1', '3128', 'bob', "sec ret");
assert_true(!$r['ok'], 'refuses a password with a space');
assert_true(strpos((string) $r['error'], 'sec ret') === false,
    'and does not repeat the password in the error');

// ------------------------------------------------------------------- clearing

echo "  -- clearing removes the file\n";
$r = sp()->run('10.0.0.1', '3128', 'bob', 'secret');
assert_true($r['ok'] && is_file($file), 'a proxy is configured');
$r = sp()->run(null, null, null, null);
assert_true($r['ok'], 'clearing succeeds');
assert_same('clear', $r['action'], 'and says so');
assert_true(!file_exists($file), 'the file is gone, not left empty');
$r = sp()->run('', '', '', '');
assert_true($r['ok'], 'clearing an already-clear proxy succeeds');
assert_true(\App\Helpers\Request\Query::parseProxy('') === null, 'and reads back as no proxy');

// ------------------------------------------------------------------ the modes

echo "  -- the file is written atomically, with the right mode\n";
sp()->run('10.0.0.1', '3128', null, null);
assert_same('0644', substr(sprintf('%o', fileperms($file)), -4),
    'a credential-free proxy file is world-readable, so unprivileged apt stays quiet');
sp()->run('10.0.0.1', '3128', 'bob', 'secret');
assert_same('0640', substr(sprintf('%o', fileperms($file)), -4),
    'a file holding a password is not world-readable');
$leftovers = array_values(array_filter(scandir($ws), function ($n) {
    return $n !== '.' && $n !== '..' && $n !== '00proxy';
}));
assert_same([], $leftovers, 'no temp file is left behind by a successful write');

$r = sp()->run('::1', '8080', null, null);
assert_true($r['ok'], 'an IPv6 proxy is accepted');
assert_true(strpos(file_get_contents($file), '"http://[::1]:8080/";') !== false,
    'and is bracketed in the URL, as a URL requires');
assert_same('::1', \App\Helpers\Request\Query::parseProxy(file_get_contents($file))['proxy_ip'],
    'and unbracketed again when it is read back');

// ------------------------------------------- negative control: the old shape

echo "  -- negative control: what the same input did before\n";

$old = old_setproxy_argv([
    'proxy_ip' => '10.0.0.1', 'proxy_port' => '3128',
    'proxy_username' => 'bob',
    'proxy_password' => "x' ; touch /tmp/pwned ; echo '",
]);
assert_true($old[0] === '/bin/sh' && $old[1] === '-c',
    'the old call site handed a STRING to a shell');
assert_true(strpos($old[2], "; touch /tmp/pwned ;") !== false,
    'and an apostrophe in the password became a second command');

$r = sp()->run('10.0.0.1', '3128', 'bob', "x' ; touch /tmp/pwned ; echo '");
assert_true(!$r['ok'], 'the same password is refused now');

// The other half of the old bug: whatever survived was written, as root, into a
// file apt executes from.
$old = old_setproxy_argv([
    'proxy_ip' => '10.0.0.1', 'proxy_port' => '3128',
    'proxy_username' => 'bob',
    'proxy_password' => 'p";};APT::Update::Pre-Invoke{"/bin/sh -c id";};//',
]);
assert_true(strpos($old[2], 'APT::Update::Pre-Invoke') !== false,
    'and an apt directive in the password reached an apt config file as root');
$r = sp()->run('10.0.0.1', '3128', 'bob', 'p";};APT::Update::Pre-Invoke{"/bin/sh"};//');
assert_true($r['ok'], 'the same value is accepted as a password now');
$written = file_get_contents($file);
// The letters survive percent-encoding; the syntax does not, and the syntax is
// the whole of the attack. ':' becomes %3A, so there is no APT:: to name a
// directive with, and the three ';' in the file are the three the three
// directives end with.
assert_true(strpos($written, 'APT::') === false,
    'the injected directive cannot even be named: its ":" are encoded');
assert_same(3, substr_count($written, ';'), 'and it adds no statement terminator');
assert_same(6, substr_count($written, '"'), 'and no quote it could close');

// ------------------------------------------------- the call site is really gone

echo "  -- the old call site no longer exists\n";

/** The file with every comment removed: a comment is not code. */
function code_without_comments($path)
{
    $out = '';
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { $out .= "\n"; continue; }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
}

/**
 * Everything this change had to remove from Query.php.
 *
 * The exec-family names are matched on a word boundary, not with strpos():
 * Query::make() legitimately calls curl_exec(), and a substring test reports
 * that as a shell. A scanner that cries wolf gets deleted by the next person.
 */
function proxy_offences($path)
{
    $code = code_without_comments($path);
    $found = [];
    foreach (['sudo touch', 'sudo tee'] as $needle) {
        if (strpos($code, $needle) !== false) $found[] = $needle;
    }
    if (preg_match_all('/\b(exec|shell_exec|system|passthru|popen)\s*\(/', $code, $m)) {
        foreach (array_unique($m[1]) as $fn) $found[] = $fn . '(';
    }
    return $found;
}

assert_same([], proxy_offences($root . '/store/app/Helpers/Request/Query.php'),
    'Query.php no longer reaches a shell at all');

// NEGATIVE CONTROL for that scanner: it must fail against the pre-change file.
$beforeFile = $ws . '/Query.before.php';
file_put_contents($beforeFile, "<?php\nclass Q {\n"
    . "  public static function setProxy(\$p){\n"
    . "    \$file = '/etc/apt/apt.conf.d/00proxy';\n"
    . "    if(!is_file(\$file)) exec('sudo touch '.\$file);\n"
    . "    \$result = exec(\"echo '\".\$proxyAddr.\"' | sudo tee \".\$file);\n"
    . "  }\n}\n");
assert_same(['sudo touch', 'sudo tee', 'exec('], proxy_offences($beforeFile),
    'the scanner does find all three in the pre-change file');

test_summary();
