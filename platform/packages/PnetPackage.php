<?php
/**
 * The .pnetpkg container: reading it, writing it, and deciding whether to
 * believe it.
 *
 * This file owns three things and nothing else — no filesystem side effects
 * outside a staging directory, no privileged operations, no shell. Applying a
 * package is PnetPackageApplier's job; this class only produces a verified,
 * fully-validated plan for it.
 *
 *   1. THE CONTAINER. A .pnetpkg is a gzip-compressed ustar archive whose first
 *      two members must be manifest.json and manifest.json.minisig, in that
 *      order, followed by payload/ members and nothing else. It is read with a
 *      tar parser written here rather than with ZipArchive or PharData because
 *      both of those extract by name and follow what the archive tells them;
 *      every path check below has to happen BEFORE a byte is written, and the
 *      only way to be sure of that is to do the writing ourselves.
 *
 *   2. THE SIGNATURE. Detached Ed25519 over the manifest bytes, in minisign's
 *      file format, verified with ext-sodium. Format compatibility is
 *      deliberate: the fork's release key can be generated and used with the
 *      stock `minisign` tool and kept on a machine that has never seen this
 *      repository. Nothing here shells out to minisign, and no keyring,
 *      agent or trust database is involved — the trusted keys are files in a
 *      directory.
 *
 *   3. THE MANIFEST. Parsed into a plan of operations drawn from a fixed verb
 *      table, each with a fixed argument schema. An unknown verb, an unknown
 *      argument, a value that fails its pattern, or a path that does not
 *      resolve under a managed root is a hard rejection of the whole package.
 *      There is no partial acceptance and no "warn and continue".
 *
 * WHY THE MANIFEST IS THE FIRST MEMBER
 *
 * Because it bounds the rest. The manifest names every payload member with its
 * exact size and sha256, and the manifest is signed. So once the signature
 * verifies, the total number of bytes that will ever be written to disk is an
 * authenticated number, checked before extraction begins. A decompression bomb
 * cannot be built out of a signed manifest, and an unsigned package is bounded
 * by the absolute caps instead. A member not in the payload map, or one whose
 * size or digest differs by a byte, aborts extraction.
 */

if (!function_exists('sodium_crypto_sign_verify_detached')) {
    throw new \RuntimeException('ext-sodium is required to verify packages');
}

class PnetPackageError extends \RuntimeException
{
}

/**
 * A streaming ustar reader.
 *
 * Deliberately narrow: it understands regular files and directories and
 * rejects everything else, which is how symlinks, hardlinks, devices, fifos
 * and the GNU long-name extensions are handled. There is no code path that
 * creates a link of any kind, so there is nothing to get wrong later.
 */
class PnetTarReader
{
    /** @var resource zlib stream; gzread reads a plain tar transparently too. */
    private $fh;
    private $path;
    private $pending = 0;   // unread bytes of the current member's body
    private $padding = 0;   // bytes of NUL padding after that body
    private $closed = false;

    public function __construct($path)
    {
        $fh = @gzopen($path, 'rb');
        if ($fh === false) {
            throw new PnetPackageError('cannot open package: ' . $path);
        }
        $this->fh = $fh;
        $this->path = $path;
    }

    public function close()
    {
        if (!$this->closed && $this->fh) {
            gzclose($this->fh);
            $this->closed = true;
        }
    }

    /** Exactly $n bytes, or an exception. gzread short-reads on a chunk boundary. */
    private function readExactly($n)
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if (gzeof($this->fh)) {
                throw new PnetPackageError('truncated package: wanted ' . $n . ' bytes, got ' . strlen($buf));
            }
            $chunk = gzread($this->fh, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new PnetPackageError('truncated package: read failed at ' . strlen($buf) . ' of ' . $n);
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Advance to the next member.
     *
     * @return array|null ['name' => string, 'size' => int, 'type' => '0'|'5']
     */
    public function next()
    {
        // Discard whatever is left of the previous member.
        $skip = $this->pending + $this->padding;
        while ($skip > 0) {
            $take = $skip > 65536 ? 65536 : $skip;
            $this->readExactly($take);
            $skip -= $take;
        }
        $this->pending = 0;
        $this->padding = 0;

        if (gzeof($this->fh)) {
            return null;
        }
        $header = @gzread($this->fh, 512);
        if ($header === false || $header === '') {
            return null;
        }
        if (strlen($header) < 512) {
            throw new PnetPackageError('truncated package: short tar header');
        }
        if (trim($header, "\0") === '') {
            return null; // end-of-archive marker
        }

        $checksum = trim(substr($header, 148, 8), " \0");
        if ($checksum === '' || !preg_match('/^[0-7]+$/', $checksum)) {
            throw new PnetPackageError('malformed tar header: bad checksum field');
        }
        $signed = 0;
        for ($i = 0; $i < 512; $i++) {
            $byte = ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
            $signed += $byte;
        }
        if ($signed !== (int) octdec($checksum)) {
            throw new PnetPackageError('malformed tar header: checksum mismatch');
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");
        $type = substr($header, 156, 1);
        $sizeField = trim(substr($header, 124, 12), " \0");

        // The prefix field is a second path component tar splits long names
        // across. Our writer never uses it, so a package that does is either
        // not ours or is trying to hide a name from the checks below.
        if ($prefix !== '') {
            throw new PnetPackageError('rejected tar member: ustar prefix field is not supported');
        }
        if ($type === '') {
            $type = '0';
        }
        if ($type === "\0") {
            $type = '0';
        }
        if ($type !== '0' && $type !== '5') {
            throw new PnetPackageError(
                'rejected tar member ' . self::describe($name) . ': type "' . addcslashes($type, "\0..\37") .
                '" is not a regular file or directory'
            );
        }
        if ($sizeField === '' || !preg_match('/^[0-7]+$/', $sizeField)) {
            throw new PnetPackageError('malformed tar header: bad size field');
        }
        $size = (int) octdec($sizeField);
        if ($size < 0) {
            throw new PnetPackageError('malformed tar header: negative size');
        }
        if ($type === '5') {
            $size = 0;
        }

        $this->pending = $size;
        $this->padding = $size % 512 === 0 ? 0 : 512 - ($size % 512);

        return array('name' => $name, 'size' => $size, 'type' => $type);
    }

    /** The current member's body, in memory. Only for the manifest and its signature. */
    public function readBody($max)
    {
        if ($this->pending > $max) {
            throw new PnetPackageError('member is larger than the ' . $max . ' byte limit for this member');
        }
        $body = $this->pending > 0 ? $this->readExactly($this->pending) : '';
        $this->pending = 0;
        return $body;
    }

    /**
     * Stream the current member's body to an open handle, hashing as it goes.
     *
     * Nothing is buffered whole: a 40 GB disk image moves through in 1 MB
     * chunks, so memory does not scale with package size.
     *
     * @return string lowercase hex sha256 of the body
     */
    public function copyBodyTo($out)
    {
        $ctx = hash_init('sha256');
        while ($this->pending > 0) {
            $take = $this->pending > 1048576 ? 1048576 : $this->pending;
            $chunk = $this->readExactly($take);
            hash_update($ctx, $chunk);
            if ($out !== null && fwrite($out, $chunk) !== strlen($chunk)) {
                throw new PnetPackageError('short write while extracting');
            }
            $this->pending -= $take;
        }
        return hash_final($ctx);
    }

    private static function describe($name)
    {
        return '"' . addcslashes(substr($name, 0, 120), "\0..\37\"\\") . '"';
    }
}

/** A minimal ustar writer, used only by the packaging tool. */
class PnetTarWriter
{
    private $fh;

    public function __construct($path, $compress = true)
    {
        $this->fh = $compress ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if ($this->fh === false) {
            throw new PnetPackageError('cannot write package: ' . $path);
        }
        $this->compress = $compress;
    }

    private $compress = true;

    private function put($data)
    {
        if ($this->compress) {
            gzwrite($this->fh, $data);
        } else {
            fwrite($this->fh, $data);
        }
    }

    public function addString($name, $content, $mode = 0644)
    {
        $this->header($name, strlen($content), $mode, '0');
        $this->put($content);
        $pad = strlen($content) % 512 === 0 ? 0 : 512 - (strlen($content) % 512);
        if ($pad) {
            $this->put(str_repeat("\0", $pad));
        }
    }

    public function addFile($name, $path, $mode = 0644)
    {
        $size = filesize($path);
        $this->header($name, $size, $mode, '0');
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new PnetPackageError('cannot read ' . $path);
        }
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1048576);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $this->put($chunk);
            $written += strlen($chunk);
        }
        fclose($in);
        if ($written !== $size) {
            throw new PnetPackageError('file changed size while being packaged: ' . $path);
        }
        $pad = $size % 512 === 0 ? 0 : 512 - ($size % 512);
        if ($pad) {
            $this->put(str_repeat("\0", $pad));
        }
    }

    private function header($name, $size, $mode, $type)
    {
        if (strlen($name) > 100) {
            throw new PnetPackageError('member name longer than 100 bytes: ' . $name);
        }
        $h = str_pad($name, 100, "\0");
        $h .= str_pad(sprintf('%07o', $mode), 8, "\0");
        $h .= str_pad(sprintf('%07o', 0), 8, "\0");
        $h .= str_pad(sprintf('%07o', 0), 8, "\0");
        $h .= str_pad(sprintf('%011o', $size), 12, "\0");
        $h .= str_pad(sprintf('%011o', 0), 12, "\0"); // mtime 0: packages are reproducible
        $h .= '        ';                              // checksum placeholder
        $h .= $type;
        $h .= str_repeat("\0", 100);                   // linkname
        $h .= "ustar\0" . '00';
        $h .= str_pad('root', 32, "\0");
        $h .= str_pad('root', 32, "\0");
        $h .= str_repeat("\0", 8) . str_repeat("\0", 8);
        $h .= str_repeat("\0", 155);
        $h = str_pad($h, 512, "\0");

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        // The checksum field is 8 bytes: six octal digits, a NUL and a space.
        // Writing seven digits pushes every later field along by one and the
        // archive stops being a tar; the reader's checksum check is what
        // catches it, which is the point of having one.
        $h = substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
        $this->put($h);
    }

    public function finish()
    {
        $this->put(str_repeat("\0", 1024));
        if ($this->compress) {
            gzclose($this->fh);
        } else {
            fclose($this->fh);
        }
    }
}

/**
 * minisign-format Ed25519 keys and detached signatures, in PHP.
 *
 * Only the parts we need: no password-protected secret keys (the KDF is
 * scrypt, and reimplementing it buys nothing — a key that needs a password is
 * a key held by a human, and a human should use the real `minisign` binary).
 * `pnet-package keygen` therefore writes minisign's unencrypted secret-key
 * form, which the stock tool reads and writes with -W.
 */
class PnetMinisign
{
    const ALG_PURE = 'Ed';       // signature over the file itself
    const ALG_PREHASHED = 'ED';  // signature over blake2b-512 of the file
    const KDF_NONE = "\x00\x00";

    /** @return array ['id' => 8-byte string, 'pk' => 32-byte string, 'alg' => string] */
    public static function parsePublicKey($text)
    {
        $blob = self::decodeSecondLine($text, 'public key');
        if (strlen($blob) !== 42) {
            throw new PnetPackageError('malformed public key: expected 42 bytes, got ' . strlen($blob));
        }
        $alg = substr($blob, 0, 2);
        if ($alg !== self::ALG_PURE) {
            throw new PnetPackageError('unsupported public key algorithm');
        }
        return array('alg' => $alg, 'id' => substr($blob, 2, 8), 'pk' => substr($blob, 10, 32));
    }

    /** @return array ['id' => string, 'sk' => 64-byte string] */
    public static function parseSecretKey($text)
    {
        $blob = self::decodeSecondLine($text, 'secret key');
        if (strlen($blob) !== 158) {
            throw new PnetPackageError('malformed secret key: expected 158 bytes, got ' . strlen($blob));
        }
        if (substr($blob, 0, 2) !== self::ALG_PURE) {
            throw new PnetPackageError('unsupported secret key algorithm');
        }
        if (substr($blob, 2, 2) !== self::KDF_NONE) {
            throw new PnetPackageError(
                'this secret key is password-protected; sign with the minisign binary instead, ' .
                'or export an unencrypted key with `minisign -R`/`-W`'
            );
        }
        $keynum = substr($blob, 54, 104);
        $id = substr($keynum, 0, 8);
        $sk = substr($keynum, 8, 64);
        $checksum = substr($keynum, 72, 32);
        $expected = sodium_crypto_generichash(self::ALG_PURE . $id . $sk, '', 32);
        if (!hash_equals($expected, $checksum)) {
            throw new PnetPackageError('secret key checksum mismatch');
        }
        return array('id' => $id, 'sk' => $sk);
    }

    /**
     * @param string $signature the .minisig file contents
     * @param string $message   the bytes that were signed
     * @param array  $keys      id => pk, the trusted set
     * @return string the hex key id that verified
     */
    public static function verify($signature, $message, array $keys)
    {
        $lines = self::lines($signature);
        if (count($lines) < 2) {
            throw new PnetPackageError('malformed signature file');
        }
        $blob = base64_decode($lines[1], true);
        if ($blob === false || strlen($blob) !== 74) {
            throw new PnetPackageError('malformed signature: expected 74 bytes');
        }
        $alg = substr($blob, 0, 2);
        $id = substr($blob, 2, 8);
        $sig = substr($blob, 10, 64);

        $hexId = bin2hex($id);
        if (!isset($keys[$hexId])) {
            throw new PnetPackageError('signature is from key ' . $hexId . ', which is not trusted');
        }
        $pk = $keys[$hexId];

        if ($alg === self::ALG_PREHASHED) {
            $signed = sodium_crypto_generichash($message, '', 64);
        } elseif ($alg === self::ALG_PURE) {
            $signed = $message;
        } else {
            throw new PnetPackageError('unsupported signature algorithm');
        }
        if (!sodium_crypto_sign_verify_detached($sig, $signed, $pk)) {
            throw new PnetPackageError('signature does not verify against key ' . $hexId);
        }

        // minisign's fourth line signs the signature together with the trusted
        // comment, which is what stops the comment being swapped between files.
        if (count($lines) >= 4 && strpos($lines[2], 'trusted comment:') === 0) {
            $trusted = substr($lines[2], strlen('trusted comment:'));
            $trusted = ltrim($trusted, ' ');
            $global = base64_decode($lines[3], true);
            if ($global === false || strlen($global) !== 64) {
                throw new PnetPackageError('malformed global signature');
            }
            if (!sodium_crypto_sign_verify_detached($global, $sig . $trusted, $pk)) {
                throw new PnetPackageError('trusted comment does not verify against key ' . $hexId);
            }
        }
        return $hexId;
    }

    public static function sign($message, array $secret, $untrustedComment, $trustedComment)
    {
        $signed = sodium_crypto_generichash($message, '', 64);
        $sig = sodium_crypto_sign_detached($signed, $secret['sk']);
        $global = sodium_crypto_sign_detached($sig . $trustedComment, $secret['sk']);
        return "untrusted comment: " . $untrustedComment . "\n"
            . base64_encode(self::ALG_PREHASHED . $secret['id'] . $sig) . "\n"
            . "trusted comment: " . $trustedComment . "\n"
            . base64_encode($global) . "\n";
    }

    /** @return array [publicKeyFileText, secretKeyFileText, hexId] */
    public static function keygen($comment)
    {
        $pair = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($pair);
        $sk = sodium_crypto_sign_secretkey($pair);
        $id = random_bytes(8);
        $checksum = sodium_crypto_generichash(self::ALG_PURE . $id . $sk, '', 32);
        $secretBlob = self::ALG_PURE . self::KDF_NONE . 'B2'
            . str_repeat("\0", 32)            // kdf salt, unused with KDF_NONE
            . str_repeat("\0", 8)             // opslimit
            . str_repeat("\0", 8)             // memlimit
            . $id . $sk . $checksum;
        $publicText = "untrusted comment: " . $comment . " (public key " . bin2hex($id) . ")\n"
            . base64_encode(self::ALG_PURE . $id . $pk) . "\n";
        $secretText = "untrusted comment: " . $comment . " (SECRET key " . bin2hex($id) . ")\n"
            . base64_encode($secretBlob) . "\n";
        return array($publicText, $secretText, bin2hex($id));
    }

    private static function lines($text)
    {
        $out = array();
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $out[] = rtrim($line, "\r");
        }
        while (count($out) > 0 && end($out) === '') {
            array_pop($out);
        }
        return $out;
    }

    private static function decodeSecondLine($text, $what)
    {
        $lines = self::lines($text);
        if (count($lines) < 2) {
            throw new PnetPackageError('malformed ' . $what . ' file');
        }
        $blob = base64_decode(trim($lines[1]), true);
        if ($blob === false) {
            throw new PnetPackageError('malformed ' . $what . ': not base64');
        }
        return $blob;
    }
}

/**
 * The manifest: schema, verb table, and the argument patterns.
 *
 * Every value that will ever reach a filesystem call or an argv array is
 * checked here against a pattern, and there is no default-permit branch. A
 * verb not in VERBS, an argument not in that verb's schema, or a value that
 * fails its pattern rejects the package.
 */
class PnetManifest
{
    const FORMAT = 1;
    const MANIFEST_MEMBER = 'manifest.json';
    const SIGNATURE_MEMBER = 'manifest.json.minisig';
    const PAYLOAD_PREFIX = 'payload/';

    /** Absolute ceilings, used when a manifest's own numbers are not trusted. */
    const MAX_MANIFEST_BYTES = 1048576;
    const MAX_SIGNATURE_BYTES = 8192;
    const MAX_MEMBERS = 4096;
    const MAX_UNSIGNED_TOTAL_BYTES = 2147483648; // 2 GiB

    /**
     * The roots a package may write under. Nothing else on the filesystem is
     * reachable from a manifest: a path is written "root:relative", the root is
     * looked up here, and the relative part is pattern-checked. There is no
     * syntax for an absolute path, so there is nothing to strip.
     */
    public static function roots()
    {
        return array(
            'addons'    => '/opt/unetlab/addons',
            'templates' => '/opt/unetlab/html/templates',
            'icons'     => '/opt/unetlab/html/images/icons',
            'scripts'   => '/opt/unetlab/scripts',
            'html'      => '/opt/unetlab/html',
            'state'     => '/opt/unetlab/data/packages',
        );
    }

    /**
     * The verb table.
     *
     * Derived from what an install actually has to do on a PNETLab host, read
     * off the reference appliance at 10.85.44.5:
     *
     *   /opt/unetlab/addons/{qemu,iol,dynamips}        emulator images
     *   /opt/unetlab/html/templates/{intel,amd}/*.yml  198 device templates
     *   /opt/unetlab/html/images/icons/*.png           the icon a template names
     *   /opt/unetlab/scripts/config_*.py               the per-device config script
     *                                                  a template names in
     *                                                  config_script:
     *   docker images                                  the docker node type pulls
     *                                                  by name; the appliance's
     *                                                  local image list is empty
     *
     * 'reversible' says whether the applier can undo the operation from its
     * journal. Everything false runs last, after every reversible operation has
     * succeeded, so a failure cannot leave a half-applied filesystem.
     */
    public static function verbs()
    {
        return array(
            'mkdir' => array(
                'args' => array('path' => 'path', 'mode' => 'mode?'),
                'reversible' => true,
            ),
            'install_file' => array(
                'args' => array('source' => 'source', 'path' => 'path', 'mode' => 'mode?', 'owner' => 'owner?'),
                'reversible' => true,
            ),
            'install_image' => array(
                'args' => array(
                    'emulator' => 'emulator', 'folder' => 'component?', 'name' => 'component',
                    'source' => 'source', 'mode' => 'mode?', 'owner' => 'owner?',
                ),
                'reversible' => true,
            ),
            'install_template' => array(
                'args' => array('arch' => 'arch?', 'name' => 'yaml', 'source' => 'source'),
                'reversible' => true,
            ),
            'install_icon' => array(
                'args' => array('name' => 'icon', 'source' => 'source'),
                'reversible' => true,
            ),
            'install_config_script' => array(
                'args' => array('name' => 'configscript', 'source' => 'source'),
                'reversible' => true,
                'signed_only' => true,
            ),
            'set_permissions' => array(
                'args' => array('path' => 'path', 'mode' => 'mode?', 'owner' => 'owner?', 'recursive' => 'bool?'),
                'reversible' => true,
            ),
            'remove' => array(
                'args' => array('path' => 'path'),
                'reversible' => true,
            ),
            'set_version' => array(
                'args' => array('version' => 'version'),
                'reversible' => true,
            ),
            'docker_pull' => array(
                'args' => array('image' => 'dockerimage'),
                'reversible' => false,
            ),
            'restart_service' => array(
                'args' => array('service' => 'service'),
                'reversible' => false,
            ),
        );
    }

    /** Argument patterns. A type not listed here cannot appear in the table above. */
    public static function types()
    {
        return array(
            // "root:a/b/c". No absolute form exists; '..' is rejected component-wise below.
            'path'         => '/^(addons|templates|icons|scripts|html|state):[A-Za-z0-9][A-Za-z0-9._-]*(\/[A-Za-z0-9][A-Za-z0-9._-]*)*$/',
            'source'       => '/^[A-Za-z0-9][A-Za-z0-9._-]*(\/[A-Za-z0-9][A-Za-z0-9._-]*)*$/',
            'component'    => '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
            'emulator'     => '/^(qemu|iol|dynamips)$/',
            'arch'         => '/^(intel|amd)$/',
            'yaml'         => '/^[A-Za-z0-9][A-Za-z0-9._-]*\.yml$/',
            'icon'         => '/^[A-Za-z0-9][A-Za-z0-9._ -]*\.(png|jpg|jpeg|svg|gif)$/',
            'configscript' => '/^config_[A-Za-z0-9][A-Za-z0-9._-]*\.(py|sh|php)$/',
            'mode'         => '/^(0600|0640|0644|0664|0700|0750|0755|0775|2775)$/',
            'owner'        => '/^(root:root|root:unl|www-data:www-data|root:www-data)$/',
            'version'      => '/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]+)?$/',
            'service'      => '/^(apache2|guacd|docker|cpulimit|php[0-9]+\.[0-9]+-fpm)$/',
            'dockerimage'  => '/^[a-z0-9]+([._-][a-z0-9]+)*(\/[a-z0-9]+([._-][a-z0-9]+)*)*(:[A-Za-z0-9][A-Za-z0-9._-]{0,127})?$/',
            'bool'         => '/^(0|1|true|false)$/',
            'id'           => '/^[a-z0-9][a-z0-9._-]{0,63}$/',
            'kind'         => '/^(device|update)$/',
        );
    }

    /** @var array the parsed, validated manifest */
    public $data;
    /** @var array member name => ['size' => int, 'sha256' => string] */
    public $payload;
    /** @var array list of ['verb' => string, 'args' => array, 'reversible' => bool] */
    public $plan;
    public $id;
    public $version;
    public $kind;
    public $digest;

    /**
     * @param string $json   the manifest bytes
     * @param bool   $signed whether those bytes carried a verified signature
     */
    public function __construct($json, $signed)
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new PnetPackageError('manifest is not valid JSON');
        }
        $this->digest = hash('sha256', $json);

        $known = array('format', 'id', 'version', 'name', 'kind', 'description',
            'device_id', 'payload', 'install', 'uninstall', 'provides', 'requires');
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $known, true)) {
                throw new PnetPackageError('unknown manifest key: ' . self::quote($key));
            }
        }

        if (!isset($data['format']) || $data['format'] !== self::FORMAT) {
            throw new PnetPackageError('unsupported manifest format');
        }
        $types = self::types();
        foreach (array('id' => 'id', 'version' => 'version', 'kind' => 'kind') as $key => $type) {
            if (!isset($data[$key]) || !is_string($data[$key]) || !preg_match($types[$type], $data[$key])) {
                throw new PnetPackageError('manifest ' . $key . ' is missing or malformed');
            }
        }
        $this->id = $data['id'];
        $this->version = $data['version'];
        $this->kind = $data['kind'];

        // --- payload map -----------------------------------------------------
        $this->payload = array();
        $payload = isset($data['payload']) ? $data['payload'] : array();
        if (!is_array($payload)) {
            throw new PnetPackageError('manifest payload is not an object');
        }
        $total = 0;
        foreach ($payload as $name => $entry) {
            if (!is_string($name) || !preg_match($types['source'], $name)) {
                throw new PnetPackageError('malformed payload member name: ' . self::quote((string) $name));
            }
            if (!is_array($entry) || !isset($entry['sha256']) || !isset($entry['size'])) {
                throw new PnetPackageError('payload member ' . self::quote($name) . ' needs sha256 and size');
            }
            if (!is_string($entry['sha256']) || !preg_match('/^[0-9a-f]{64}$/', $entry['sha256'])) {
                throw new PnetPackageError('payload member ' . self::quote($name) . ' has a malformed sha256');
            }
            if (!is_int($entry['size']) || $entry['size'] < 0) {
                throw new PnetPackageError('payload member ' . self::quote($name) . ' has a malformed size');
            }
            $total += $entry['size'];
            $this->payload[$name] = array('size' => $entry['size'], 'sha256' => $entry['sha256']);
        }
        if (count($this->payload) > self::MAX_MEMBERS) {
            throw new PnetPackageError('manifest declares more than ' . self::MAX_MEMBERS . ' payload members');
        }
        if (!$signed && $total > self::MAX_UNSIGNED_TOTAL_BYTES) {
            throw new PnetPackageError('unsigned package exceeds the ' . self::MAX_UNSIGNED_TOTAL_BYTES . ' byte cap');
        }

        // --- the plan --------------------------------------------------------
        $this->plan = array();
        foreach (array('install', 'uninstall') as $section) {
            if (!isset($data[$section])) {
                continue;
            }
            if (!self::isList($data[$section])) {
                throw new PnetPackageError('manifest ' . $section . ' is not a list');
            }
        }
        if (!isset($data['install']) || count($data['install']) === 0) {
            throw new PnetPackageError('manifest has no install operations');
        }
        foreach ($data['install'] as $i => $op) {
            $this->plan[] = self::compileOperation($op, $i, $signed);
        }
        if (isset($data['uninstall'])) {
            foreach ($data['uninstall'] as $i => $op) {
                self::compileOperation($op, $i, $signed); // validated now, used by `remove`
            }
        }

        // Every source named by an operation must be in the payload map, and
        // every payload member must be used. An unused member is a file that
        // would be extracted and never looked at again, which is exactly how a
        // package smuggles something onto disk.
        $used = array();
        foreach ($this->plan as $op) {
            if (isset($op['args']['source'])) {
                $src = $op['args']['source'];
                if (!isset($this->payload[$src])) {
                    throw new PnetPackageError('operation references payload member ' . self::quote($src) . ' which the manifest does not declare');
                }
                $used[$src] = true;
            }
        }
        foreach (array_keys($this->payload) as $name) {
            if (!isset($used[$name])) {
                throw new PnetPackageError('payload member ' . self::quote($name) . ' is declared but never used');
            }
        }

        $this->data = $data;
    }

    public function uninstallPlan()
    {
        if (!isset($this->data['uninstall'])) {
            return array();
        }
        return self::compilePlan($this->data['uninstall'], true);
    }

    /**
     * Compile a list of raw operations into a validated plan.
     *
     * Public because the uninstall path recompiles the operations recorded in
     * the installed-state file rather than trusting what is stored there. The
     * state file is written by root after a signature check, so its provenance
     * is good — but "written by a privileged process" is not the same as
     * "still well-formed", and revalidating costs nothing.
     */
    public static function compilePlan(array $ops, $signed = true)
    {
        $out = array();
        foreach ($ops as $i => $op) {
            $out[] = self::compileOperation($op, $i, $signed);
        }
        return $out;
    }

    private static function compileOperation($op, $index, $signed)
    {
        if (!is_array($op) || !isset($op['verb']) || !is_string($op['verb'])) {
            throw new PnetPackageError('operation ' . $index . ' has no verb');
        }
        $verbs = self::verbs();
        $verb = $op['verb'];
        if (!isset($verbs[$verb])) {
            throw new PnetPackageError('unknown manifest verb: ' . self::quote($verb));
        }
        $spec = $verbs[$verb];
        if (!empty($spec['signed_only']) && !$signed) {
            throw new PnetPackageError('verb ' . self::quote($verb) . ' is only permitted in a signed package');
        }

        $types = self::types();
        $args = array();
        foreach ($op as $key => $value) {
            if ($key === 'verb') {
                continue;
            }
            if (!isset($spec['args'][$key])) {
                throw new PnetPackageError('verb ' . self::quote($verb) . ' has no argument ' . self::quote((string) $key));
            }
            $type = rtrim($spec['args'][$key], '?');
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (is_int($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                throw new PnetPackageError('argument ' . self::quote((string) $key) . ' of ' . self::quote($verb) . ' is not a scalar');
            }
            if (!preg_match($types[$type], $value)) {
                throw new PnetPackageError(
                    'argument ' . self::quote((string) $key) . ' of ' . self::quote($verb) .
                    ' does not match the ' . $type . ' pattern: ' . self::quote($value)
                );
            }
            $args[$key] = $value;
        }
        foreach ($spec['args'] as $key => $type) {
            if (substr($type, -1) !== '?' && !isset($args[$key])) {
                throw new PnetPackageError('verb ' . self::quote($verb) . ' is missing required argument ' . self::quote((string) $key));
            }
        }

        // The patterns already forbid a '.' or '..' component (every component
        // must start with an alphanumeric), but the rule is restated here so
        // that loosening a pattern cannot quietly reopen traversal.
        foreach (array('path', 'source') as $key) {
            if (!isset($args[$key])) {
                continue;
            }
            $rel = $args[$key];
            $colon = strpos($rel, ':');
            if ($key === 'path' && $colon !== false) {
                $rel = substr($rel, $colon + 1);
            }
            foreach (explode('/', $rel) as $component) {
                if ($component === '' || $component === '.' || $component === '..') {
                    throw new PnetPackageError('path traversal in ' . self::quote($args[$key]));
                }
            }
        }

        return array('verb' => $verb, 'args' => $args, 'reversible' => $spec['reversible']);
    }

    /**
     * Resolve a manifest path to an absolute filesystem path under $prefix.
     *
     * The result is checked to be a descendant of the root by string prefix on
     * the normalised form, which is belt-and-braces given the pattern, and is
     * the check that would still hold if the pattern were ever relaxed.
     */
    public static function resolve($spec, $prefix = '')
    {
        $roots = self::roots();
        $colon = strpos($spec, ':');
        if ($colon === false) {
            throw new PnetPackageError('malformed path: ' . self::quote($spec));
        }
        $rootName = substr($spec, 0, $colon);
        $rel = substr($spec, $colon + 1);
        if (!isset($roots[$rootName])) {
            throw new PnetPackageError('unknown path root: ' . self::quote($rootName));
        }
        foreach (explode('/', $rel) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new PnetPackageError('path traversal in ' . self::quote($spec));
            }
        }
        $base = rtrim($prefix, '/') . $roots[$rootName];
        $full = $base . '/' . $rel;
        if (strpos($full, $base . '/') !== 0) {
            throw new PnetPackageError('resolved path escapes its root: ' . self::quote($spec));
        }
        return array('base' => $base, 'path' => $full);
    }

    public static function quote($s)
    {
        return '"' . addcslashes(substr($s, 0, 160), "\0..\37\"\\") . '"';
    }

    private static function isList($v)
    {
        if (!is_array($v)) {
            return false;
        }
        $i = 0;
        foreach (array_keys($v) as $k) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }
}
