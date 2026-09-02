<?php

/**
 * includes/functions.php
 *
 * Various shared functions for the legacy API and the wrappers.
 *
 * Derived from UNetLab html/includes/functions.php.
 * Its BSD-3-Clause notice was absent from the copy this fork inherited
 * and is restored below. See docs/LICENSING.md section 2.2.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 *
 * Substantially modified by PNETLab and by the pnetlab_main fork. Those
 * modifications are licensed under the terms in this repository's LICENSE;
 * the notice above must be retained regardless.
 */

use App\Exceptions\FinishException;

$db = null;
function checkDatabase()
{
	// Database connection
	try {
		//$db = new PDO('sqlite:'.DATABASE);
		if ($GLOBALS['db'] == null) {
			$db = new PDO('mysql:host=localhost;dbname=pnetlab_db', 'pnetlab', 'pnetlab');
			$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$GLOBALS['db'] = $db;
		}

		return $GLOBALS['db'];
	} catch (Exception $e) {
		error_log(date('M d H:i:s ') . (string) $e);
		return False;
	}
}


/** Helper for load model */
$models = [];
function loadModel($name){
	if(!isset($GLOBALS['models'][$name])){
		$modelName = BASE_DIR.'/html/includes/models/'. $name. '.php';
		if(is_file($modelName)){
			require_once($modelName);
			$GLOBALS['models'][$name] = new $name();
		}else{
			throw new Exception($modelName . ' is not exist');
		}
	}
	return $GLOBALS['models'][$name];
}


/**
 * Function to check user expiration.
 *
 * @param	PDO		$db					PDO object for database connection
 * @param	string	$username			Username
 * @return	bool						True if valid
 */
function checkUserExpiration($db, $username)
{
	$now = time() + SESSION;
	try {
		$query = 'SELECT COUNT(*) AS rows FROM users WHERE username = :username AND (expiration < 0 OR expiration >= :expiration);';
		$statement = $db->prepare($query);
		$statement->bindParam(':expiration', $now, PDO::PARAM_INT);
		$statement->bindParam(':username', $username, PDO::PARAM_STR);
		$statement->execute();
		$result = $statement->fetch();
		if ($result['rows'] == 1) {
			return True;
		} else {
			return False;
		}
	} catch (Exception $e) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][90024]);
		error_log(date('M d H:i:s ') . (string) $e);
		return False;
	}
}



function updateOnlineTime($pod)
{
	$db = checkDatabase();
	$query = 'UPDATE users SET online_time=:now WHERE pod = :pod';
	$statement = $db->prepare($query);
	$statement->execute(['now' => time(), 'pod' => $pod]);
}




/**
 * Function to lock a file.
 *
 * @param   string  $file               File to lock
 * @return  bool                        True if locked
 */
function lockFile($file)
{
	$timeout = TIMEOUT * 1000000;
	$locked = False;

	while ($timeout > 0) {
		if (file_exists($file . '.lock')) {
			// File is locked, wait for a random interval
			$wait = 1000 * rand(0, 500);
			$timeout = $timeout - $wait;
			usleep($wait);
		} else {
			$locked = True;
			touch($file . '.lock');
			break;
		}
	}

	if (!$locked) unlockFile($file);

	return true;
}

/**
 * Function to unlock a file.
 *
 * @param   string  $file               File to lock
 * @return  bool                        True if unlocked
 */
function unlockFile($file)
{
	if (is_file($file . '.lock')) {
		return unlink($file . '.lock');
	}
	return true;
}


function lockSession($labSession)
{
	$timeout = TIMEOUT * 1000000;
	$locked = False;
	$file = BASE_LAB . '/'.$labSession.'.lock';

	while ($timeout > 0) {
		if (file_exists($file)) {
			$wait = 1000 * rand(0, 500);
			$timeout = $timeout - $wait;
			usleep($wait);
		} else {
			$locked = True;
			touch($file);
			break;
		}
	}

	if (!$locked) unlockSession($labSession);

	return true;
}

/**
 * Function to unlock a file.
 *
 * @param   string  $file               File to lock
 * @return  bool                        True if unlocked
 */
function unlockSession($labSession)
{
	$file = BASE_LAB . '/'.$labSession.'.lock';
	if (is_file($file)) {
		return unlink($file);
	}
	return true;
}


function Ctrl_get($name, $default=''){
	try {
		$db = checkDatabase();
		$query = 'SELECT * FROM control WHERE control_name=:control_name';
		$statement = $db->prepare($query);
		$statement->execute(['control_name' => $name]);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (isset($result[0])) {
			return $result[0][CONTROL_VALUE];
		} else {
			return $default;
		}
	} catch (Exception $th) {
		return $default;
	}
}


/**
 * Function to update user session (expiration).
 *
 * @param   PDO     $db                 PDO object for database connection
 * @param   string  $username           Username
 * @param   string  $cookie             Session cookie
 * @return  0                           0 means ok
 */
function updateUserCookie($db, $username, $cookie)
{
	try {
		$ip = $_SERVER['REMOTE_ADDR'];
		$now = time() + SESSION;
		$query = 'UPDATE users SET cookie = :cookie, session = :session, ip = :ip WHERE username = :username;';
		$statement = $db->prepare($query);
		$statement->bindParam(':cookie', $cookie, PDO::PARAM_STR);
		$statement->bindParam(':session', $now, PDO::PARAM_INT);
		$statement->bindParam(':username', $username, PDO::PARAM_STR);
		$statement->bindParam(':ip', $ip, PDO::PARAM_STR);
		$statement->execute();
		return 0;
	} catch (Exception $e) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][90017]);
		error_log(date('M d H:i:s ') . (string) $e);
		return 90017;
	}
}

/**
 * Function to update user folder.
 *
 * @param   PDO     $db                 PDO object for database connection
 * @param   string  $cookie             Session cookie
 * @param   string  $folder             Last seen folder
 * @return  0                           0 means ok
 */
function updateUserFolder($pod, $folder)
{
	try {
		$db=checkDatabase();
		$query = 'UPDATE users SET folder = :folder WHERE pod = :pod;';
		$statement = $db->prepare($query);
		$statement->bindParam(':pod', $pod, PDO::PARAM_STR);
		$statement->bindParam(':folder', $folder, PDO::PARAM_STR);
		$statement->execute();
		return 0;
	} catch (Exception $e) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][90033]);
		error_log(date('M d H:i:s ') . (string) $e);
		return 90033;
	}
}

/**
 * Function to update POD lab.
 *
 * @param   PDO     $db                 PDO object for database connection
 * @param   string  $cookie             Session cookie
 * @param   string  $lab				Running lab
 * @return  0                           0 means ok
 */


function html5_checkDatabase()
{
	// Database connection
	try {
		//$db = new PDO('sqlite:'.DATABASE);
		$db = new PDO('mysql:host=127.0.0.1;dbname=guacdb', 'guacuser', 'pnetlab');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $db;
	} catch (Exception $e) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][90003]);
		error_log(date('M d H:i:s ') . (string) $e);
		return False;
	}
}


function html5AddSession($db, $name, $type, $port, $userid, $hostname = null, $servicePort = null, $username = null, $password = null, $onresize = null)
{
	if ($servicePort === null) $servicePort = $port;
	if ($hostname === null) $hostname = '127.0.0.1';

	$connectionId = $port.$userid;

	$query = "delete from guacamole_connection where connection_id=:connection_id";
	$statement = $db->prepare($query);
	$statement->execute(['connection_id'=>$connectionId]);

	// $name is derived from the node name, which is user-supplied. Bind it.
	$query = "replace into guacamole_connection ( connection_id , connection_name , protocol ) values ( ?, ?, ? )";
	$statement = $db->prepare($query);
	$statement->execute([$connectionId, $name, $type]);

	$query = "replace into guacamole_connection_permission ( entity_id, connection_id, permission ) values ( ?, ?, 'READ' )";
	$statement = $db->prepare($query);
	$statement->execute([$userid + 1000, $connectionId]);

	// Parameter rows, as [name, value] pairs. Values reach the database only as
	// bound parameters — several of them ($hostname, $username, $password) are
	// caller-controlled.
	$connectionData = [
		['ignore-cert', 'true'],
		['hostname', $hostname],
		['port', $servicePort],
		['create-drive-path', 'true'],
		['enable-drive', 'true'],
		['enable-printing', 'false'],
		['drive-path', '/tmp/' . $connectionId],
	];

	if ($password != null && $username != null) {
		$connectionData[] = ['disable-auth', 'false'];
		$connectionData[] = ['username', $username];
		$connectionData[] = ['password', $password];
		$connectionData[] = ['security', 'any'];

		if ($onresize != null) {
			$connectionData[] = ['resize-method', $onresize];
		}
	} else {
		$connectionData[] = ['disable-auth', 'true'];
	}

	if ($type == 'rdp') {
		$connectionData[] = ['disable-glyph-caching', 'true'];
	}

	$placeholders = implode(',', array_fill(0, count($connectionData), '( ?, ?, ? )'));
	$params = [];
	foreach ($connectionData as $row) {
		$params[] = $connectionId;
		$params[] = $row[0];
		$params[] = $row[1];
	}

	$query = "insert into guacamole_connection_parameter ( connection_id , parameter_name , parameter_value ) values " . $placeholders;
	$statement = $db->prepare($query);
	$statement->execute($params);
}

/**
 * Hash a user password for storage.
 *
 * Passwords were previously stored as unsalted, single-round hash('sha256', $p).
 * That is reversible by rainbow table and brute-forceable at billions of guesses
 * per second on commodity hardware; the default admin password's digest matches
 * `echo -n pnet | sha256sum` exactly.
 *
 * @param   string  $plain              Plaintext password
 * @return  string                      Hash suitable for the users.password column
 */
function unl_password_hash($plain)
{
	return password_hash($plain, PASSWORD_DEFAULT);
}

/**
 * Verify a password against a stored hash, accepting the legacy format.
 *
 * Existing installations hold sha256 digests, and users cannot be asked to reset
 * passwords they cannot log in to change. So a legacy digest still verifies, and
 * the caller is told to re-hash it — see unl_password_needs_rehash().
 *
 * hash_equals() is used for the legacy comparison because the original code used
 * != on two strings, which is not constant time.
 *
 * @param   string  $plain              Plaintext password as supplied
 * @param   string  $stored             Value from the users.password column
 * @return  bool                        True if the password matches
 */
function unl_password_verify($plain, $stored)
{
	if (!is_string($stored) || $stored === '') return false;

	// Legacy: 64 hex characters and nothing else.
	if (preg_match('/^[0-9a-f]{64}$/i', $stored)) {
		return hash_equals(strtolower($stored), hash('sha256', $plain));
	}

	return password_verify($plain, $stored);
}

/**
 * Should this stored hash be replaced after a successful login?
 *
 * True for any legacy sha256 digest, and for a modern hash whose cost or
 * algorithm has since moved on.
 *
 * @param   string  $stored             Value from the users.password column
 * @return  bool                        True if it should be re-hashed
 */
function unl_password_needs_rehash($stored)
{
	if (!is_string($stored) || $stored === '') return true;
	if (preg_match('/^[0-9a-f]{64}$/i', $stored)) return true;
	return password_needs_rehash($stored, PASSWORD_DEFAULT);
}

/**
 * Hash a lab-level password for storage in the .unl file.
 *
 * Lab passwords were stored as a bare, unsalted md5() digest and compared with
 * ==, which is loose comparison on two strings. PHP still juggles two numeric
 * strings to numbers there, so the classic "magic hash" collision applies: any
 * two passwords whose md5 digests both match /^0e[0-9]+$/ compare equal, and
 * md5('240610708') and md5('QNKCDZO') are the textbook pair. Unsalted md5 is
 * also trivially reversed by rainbow table.
 *
 * These are kept separate from unl_password_hash()/unl_password_verify()
 * deliberately. Those accept a 64-hex legacy digest for user accounts; teaching
 * them to also accept a 32-hex md5 would widen the accepted legacy formats for
 * user login, which is the higher-value target. Lab passwords carry their own
 * legacy format, so they get their own pair.
 *
 * @param   string  $plain              Plaintext lab password
 * @return  string                      Hash suitable for the lab password attribute
 */
function unl_lab_password_hash($plain)
{
	return password_hash($plain, PASSWORD_DEFAULT);
}

/**
 * Verify a lab password against the stored value, accepting the legacy format.
 *
 * Labs already on disk hold md5 digests and their owners are not necessarily
 * around to re-set them, so a legacy digest must still open the lab. The
 * comparison is hash_equals() rather than ==: constant time, no type juggling,
 * so the magic-hash collision is gone even for the legacy path.
 *
 * @param   string  $plain              Plaintext password as supplied
 * @param   string  $stored             Value of the lab's password attribute
 * @return  bool                        True if the password matches
 */
function unl_lab_password_verify($plain, $stored)
{
	if (!is_string($stored) || $stored === '') return false;
	if (!is_string($plain)) return false;

	// Legacy: a bare md5 digest, 32 hex characters and nothing else.
	if (preg_match('/^[0-9a-f]{32}$/i', $stored)) {
		return hash_equals(strtolower($stored), md5($plain));
	}

	return password_verify($plain, $stored);
}

/**
 * Is this stored lab password still in the legacy md5 format?
 *
 * Nothing rewrites the lab file automatically on a successful unlock — saving a
 * lab rewrites the whole .unl document, which is not something an unlock should
 * do to a lab that may be running. The upgrade happens when the password is next
 * set. This is here so a caller that does own the file can ask.
 *
 * @param   string  $stored             Value of the lab's password attribute
 * @return  bool                        True if it should be re-hashed
 */
function unl_lab_password_needs_rehash($stored)
{
	if (!is_string($stored) || $stored === '') return true;
	if (preg_match('/^[0-9a-f]{32}$/i', $stored)) return true;
	return password_needs_rehash($stored, PASSWORD_DEFAULT);
}

/**
 * The credential PNETLab presents to Guacamole on a user's behalf.
 *
 * Guacamole needs a password it can store and check, and PNETLab previously gave
 * it the sha256 digest of the user's own password — which meant the guacdb
 * database held material derived directly from user credentials, and meant the
 * digest had to remain derivable, which is incompatible with storing a proper
 * password hash.
 *
 * This derives a stable per-user value from an installation secret instead, so
 * Guacamole holds nothing related to the user's password. The value is
 * deterministic, so both the login path and the console path can compute it
 * without storing anything extra.
 *
 * @param   string  $username           PNETLab username
 * @return  string                      Credential to present to Guacamole
 */
function unl_guacamole_secret($username)
{
	static $installSecret = null;

	if ($installSecret === null) {
		$path = '/opt/unetlab/data/.guacamole_secret';
		if (is_readable($path)) {
			$installSecret = trim(file_get_contents($path));
		}
		if (!$installSecret) {
			$installSecret = bin2hex(random_bytes(32));
			// Written 0600 so only the web user can read it.
			@file_put_contents($path, $installSecret, LOCK_EX);
			@chmod($path, 0600);
		}
	}

	return hash_hmac('sha256', (string) $username, $installSecret);
}

function updateUserToken($username, $password, $pod)
{
	$url = 'http://127.0.0.1/html5/api/tokens';
	$data = array('username' => $username, 'password' => $password);
	
	$options = array(
		'http' => array(
			'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
			'method'  => 'POST',
			'content' => http_build_query($data),
			// Without this the request inherits default_socket_timeout and can
			// stall a web worker. The console service is local, so a short bound
			// is generous.
			'timeout' => 5,
			'ignore_errors' => true,
		)
	);

	$context  = stream_context_create($options);

	// The HTML5 console service is optional. It may not be installed, may not be
	// running, or may be mid-restart. None of those are a reason to fail a login:
	// the caller queues the session cookie *after* this returns, so throwing here
	// locked every user out of an appliance whose consoles happened to be down.
	// See docs/OFFLINE-FIRST.md — an absent service degrades, it does not block.
	$body = @file_get_contents($url, false, $context);
	if ($body === false) {
		error_log(date('M d H:i:s ') . 'WARNING: HTML5 console service unreachable at ' . $url . '; console access will be unavailable for this session');
		return false;
	}

	$result = (array) json_decode($body);
	if (!isset($result['authToken'])) {
		error_log(date('M d H:i:s ') . 'WARNING: HTML5 console service returned no authToken; console access will be unavailable for this session');
		return false;
	}

	$db = checkDatabase();
	$token = $result['authToken'];
	// $username arrives from the login form.
	$query = "delete from html5 where username = ?";
	$statement = $db->prepare($query);
	$statement->execute([$username]);
	$query = "delete from html5 where pod = ?";
	$statement = $db->prepare($query);
	$statement->execute([$pod]);
	$query = "replace into html5 ( username , pod, token ) values ( ?, ?, ? )";
	$statement = $db->prepare($query);
	$statement->execute([$username, $pod, $token]);
}

function getHtml5Token($userid)
{
	$db = checkDatabase();
	$query = "select token from html5 where pod = ?";
	$statement = $db->prepare($query);
	$statement->execute([$userid]);
	$result = $statement->fetch();
	return $result['token'];
}


function style_to_object($style)
{
	$return = array();
	$divstyle = explode(";", $style);
	array_pop($divstyle);
	foreach ($divstyle as $param) {
		$key = trim(explode(":", $param)[0]);
		$value = trim(explode(":", $param)[1]);
		$return[$key] = $value;
	}
	return $return;
}
function data_to_textobjattr($data)
{
	$return = array();
	$text = "";
	$dom = new DOMDocument();
	if (preg_match("/style/i", $data)) {
		$dom->loadHTML(htmlspecialchars_decode($data));
	} else {
		if (preg_match("/RECT/i", base64_decode($data))) {
			// OLD RECT STYLE 
			return -1;
		}
		$dom->loadHTML(base64_decode($data));
	}
	$pstyle = style_to_object($dom->documentElement->getElementsByTagName("div")->item(0)->getAttribute("style"));
	$doc = $dom->documentElement->getElementsByTagName("p")->item(0);
	$childs = $doc->childNodes;
	for ($i = 0; $i < $childs->length; $i++) {
		$text .= $dom->saveXML($childs->item($i));
	}
	$tstyle = style_to_object($dom->documentElement->getElementsByTagName("p")->item(0)->getAttribute("style"));
	$return['text'] = $text;
	$return['top'] = preg_replace('/px/', '', $pstyle['top']);
	$return['left'] = preg_replace('/px/', '', $pstyle['left']);
	$return['fontColor'] = $tstyle['color'];
	$return['fontWeight'] = $tstyle['font-weight'];
	$return['bgColor'] = $tstyle['background-color'];
	$return['fontSize'] = preg_replace('/px/', '', $tstyle['font-size']);
	$return['zindex'] = $pstyle['z-index'];
	if (isset($pstyle['transform'])) {
		$return['transform'] = $pstyle['transform'];
	} else {
		$return['transform'] = "rotate(0deg)";
	}
	return $return;
}
function dataToCircleAttr($data)
{
	$return = array();
	$p = xml_parser_create();
	if (preg_match("/style/i", $data)) {
		xml_parse_into_struct($p, htmlspecialchars_decode($data), $vals, $index);
	} else {
		xml_parse_into_struct($p, base64_decode($data), $vals, $index);
	}
	$svg = $vals[$index["SVG"][0]];
	$style = (style_to_object($vals[$index["DIV"][0]]["attributes"]["STYLE"]));
	$circle = $vals[$index["ELLIPSE"][0]];
	$return["borderWidth"] = $circle["attributes"]["STROKE-WIDTH"];
	$return["stroke"] = $circle["attributes"]["STROKE"];
	$return["bgcolor"] = $circle["attributes"]["FILL"];
	$return["cx"] = $circle["attributes"]["CX"];
	$return["cy"] = $circle["attributes"]["CY"];
	$return["rx"] = $circle["attributes"]["RX"];
	$return["ry"] = $circle["attributes"]["RY"];
	$return['top'] = preg_replace('/px/', '', $style['top']);
	$return['left'] = preg_replace('/px/', '', $style['left']);
	$return['width'] = preg_replace('/px/', '', $style['width']);
	$return['height'] = preg_replace('/px/', '', $style['height']);
	$return['svgWidth'] = $svg["attributes"]["WIDTH"];
	$return['svgHeight'] = $svg["attributes"]["HEIGHT"];
	$return['zindex'] = $style['z-index'];
	if (isset($circle["attributes"]["STROKE-DASHARRAY"])) {
		$return["strokeDashArray"] = $circle["attributes"]["STROKE-DASHARRAY"];
	} else {
		$return["strokeDashArray"] = "0,0";
	}
	if (isset($style['transform'])) {
		$return['transform'] = $style['transform'];
	} else {
		$return['transform'] = "rotate(0deg)";
	}
	return $return;
}
function datatoSquareAttr($data)
{
	$return = array();
	$p = xml_parser_create();
	if (preg_match("/style/i", $data)) {
		xml_parse_into_struct($p, preg_replace('/"=""/', '', htmlspecialchars_decode($data)), $vals, $index);
	} else {
		xml_parse_into_struct($p, preg_replace('/"=""/', '', base64_decode($data)), $vals, $index);
	}
	$svg = $vals[$index["SVG"][0]];
	$square = $vals[$index["RECT"][0]];
	$style = (style_to_object($vals[$index["DIV"][0]]["attributes"]["STYLE"]));
	$return['top'] = preg_replace('/px/', '', $style['top']);
	$return['left'] = preg_replace('/px/', '', $style['left']);
	$return['width'] = preg_replace('/px/', '', $style['width']);
	$return['height'] = preg_replace('/px/', '', $style['height']);
	$return['svgWidth'] = $svg["attributes"]["WIDTH"];
	$return['svgHeight'] = $svg["attributes"]["HEIGHT"];
	$return['zindex'] = $style['z-index'];
	$return["stroke"] = $square["attributes"]["STROKE"];
	if (isset($square["attributes"]["STROKE-DASHARRAY"])) {
		$return["strokeDashArray"] = $square["attributes"]["STROKE-DASHARRAY"];
	} else {
		$return["strokeDashArray"] = "0,0";
	}
	$return["borderWidth"] = $square["attributes"]["STROKE-WIDTH"];
	$return["bgcolor"] = $square["attributes"]["FILL"];
	if (isset($style['transform'])) {
		$return['transform'] = $style['transform'];
	} else {
		$return['transform'] = "rotate(0deg)";
	}
	return $return;
}

/** EVE_STORE Whireshark */

function getDockerIp()
{
	$cmd = 'ifconfig docker0 | grep inet';
	exec($cmd, $o, $rc);
	foreach ($o as $line) {
		if (preg_match('/inet\s(addr:)?(?<ip>[0-9]+.[0-9]+.[0-9]+.[0-9]+)/', $line, $matches)) {
			return $matches['ip'];
		}
	}
	return '';
}

function getWiresharkPort($db)
{
	$query = 'SELECT ws_port FROM wiresharks';
	$statement = $db->prepare($query);
	$statement->execute();
	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	$portColumn = array_column($result, 'ws_port', 'ws_port');
	$basePort = 60000;
	$port = $basePort + count($portColumn);
	while (isset($portColumn[$port])) {
		$port++;
	}
	return $port;
}

function addWireshark($lab, $node_id, $interface_id)
{
	if ($interface_id === '' || $node_id === '') throw new Exception('Missing data');
	$lab_session = $lab->getSession();
	if ($lab_session == null) throw new Exception('No Lab Session');

	$tenant = $lab->getTenant();

	$db=checkDatabase();

	$query = 'SELECT * FROM wiresharks WHERE ws_tenant=:ws_tenant AND ws_lab=:ws_lab AND ws_node=:ws_node AND ws_if=:ws_if';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_tenant' => $tenant,
		'ws_lab' => $lab_session,
		'ws_node' => $node_id,
		'ws_if' => $interface_id,
	]);

	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	if (count($result) == 0) {

		$node = $lab->getNodes()[$node_id];
		if (!$node) throw new Exception('Undefine node');
		$interface = $node->getInterfaces()[$interface_id];
		if (!$interface) throw new Exception('Undefine Interface');

		$network_id = $interface->getNetworkId();
		$node_name = $node->getName();
		$interface_name = $interface->getName();
		$uniqueId = $tenant . '_' . $lab_session . '_' . $node_id . '_' . $interface_id;
		$dockerName = 'Capture_' . $uniqueId;
		$port = getWiresharkPort($db);
		$oct4 = $port % 255;
		$oct3 = 200 + floor($port / 255) % 55;

		$dockerIp = getDockerIp();
		$dockerIp = explode('.', trim($dockerIp));

		$oct1 = isset($dockerIp[0]) ? $dockerIp[0] : 10;
		$oct2 = isset($dockerIp[1]) ? $dockerIp[1] : 178;

		$ipAddress = $oct1 . '.' . $oct2 . '.' . $oct3 . '.' . $oct4;

		$query = 'INSERT INTO wiresharks (ws_tenant, ws_lab, ws_node, ws_if, ws_net, ws_node_name, ws_if_name, ws_dc_name, ws_port, ws_ip) 
					VALUES (:ws_tenant, :ws_lab, :ws_node, :ws_if, :ws_net, :ws_node_name, :ws_if_name, :ws_dc_name, :ws_port, :ws_ip)';
		$statement = $db->prepare($query);
		$statement->execute([
			'ws_tenant' => $tenant,
			'ws_lab' => $lab_session,
			'ws_node' => $node_id,
			'ws_if' => $interface_id,
			'ws_net' => $network_id,
			'ws_node_name' => $node_name,
			'ws_if_name' => $interface_name,
			'ws_dc_name' => $dockerName,
			'ws_port' => $port,
			'ws_ip' => $ipAddress,
		]);
	}
}

function addWiresharkSystem($lab, $node_id, $interface_id)
{
	// The emptiness test comes FIRST now. secureCmd()'s allowlist refuses an empty
	// string, so leaving it second would have replaced 'Missing data' with a
	// less useful message for the commonest bad request on this route.
	//
	// SECURE_TOKEN: both of these are ids, not command lines. They are used as
	// array keys two lines below and never reach a shell on this path at all, so
	// this is a shape assertion rather than a shell defence.
	if ($interface_id === '' || $node_id === '') throw new Exception('Missing data');
	$node_id = secureCmd($node_id, SECURE_TOKEN);
	$interface_id = secureCmd($interface_id, SECURE_TOKEN);

	$lab_session = $lab->getSession();
	if ($lab_session == null) throw new Exception('No Lab Session');

	$nets = $lab->getNetworks();
	$node = $lab->getNodes()[$node_id];
	if (!$node) throw new Exception('Node is undefined');
	$interface = $node->getInterfaces()[$interface_id];
	if (!$interface) throw new Exception('Interface is undefined');

	$tenant = $lab->getTenant();

	$db=checkDatabase();

	$query = 'SELECT * FROM wiresharks WHERE ws_tenant=:ws_tenant AND ws_lab=:ws_lab AND ws_node=:ws_node AND ws_if=:ws_if';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_tenant' => $tenant,
		'ws_lab' => $lab_session,
		'ws_node' => $node_id,
		'ws_if' => $interface_id,
	]);

	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	if (count($result) > 0) {
		$result = $result[0];
		$network_id = $result['ws_net'];
		$node_name = $result['ws_node_name'];
		$interface_name = $result['ws_if_name'];
		$uniqueId = $tenant . '_' . $lab_session . '_' . $node_id . '_' . $interface_id;
		$dockerName = 'Capture_' . $uniqueId;
		$port = $result['ws_port'];
		$ipAddress = $result['ws_ip'];
	} else {
		throw new Exception('Please capture again');
	}

	$connectPort = 3389;

	if (isset($nets[$interface->getNetworkId()]) && $nets[$interface->getNetworkId()]->isCloud()) {
		// Network is a Cloud
		$net_name = $nets[$interface->getNetworkId()]->getNType();
	} else {
		$net_name = 'vnet' . $lab_session . '_' . $interface->getNetworkId();
	}

	// create wireshark docker container.

	/*
	 * -H=unix:///var/run/docker.sock, not the tcp://127.0.0.1:4243 every one of
	 * these call sites used to name. Two reasons, and the second is the one that
	 * made this urgent:
	 *
	 *   - that TCP socket is unauthenticated. Anything that can open a loopback
	 *     connection — every PHP path here, but equally any other local user or
	 *     any SSRF in the web layer — can POST /containers/create with
	 *     Binds: ["/:/host"] and start a container that owns the host. www-data
	 *     reaching it is www-data being root, whatever the sudo policy says;
	 *   - nothing in install/ ever configured it. Docker listens on the unix
	 *     socket out of the box and needs an explicit -H (or a systemd drop-in)
	 *     to listen on 4243 as well, so on a clean install of this fork every
	 *     command below failed to connect and Docker nodes could not work at all.
	 *
	 * The unix socket is root:docker 0660, so access is group membership rather
	 * than a listening port: install/lib/platform.sh puts the PHP-FPM user in the
	 * docker group, which is also why none of these need sudo any more. Group
	 * membership is read at process start, so that step restarts php-fpm — a
	 * running pool will not pick it up.
	 *
	 * The endpoint is named explicitly rather than left to the CLI default so a
	 * stray DOCKER_HOST cannot redirect it. tests/Security/DockerSocketTest.php
	 * fails if tcp://127.0.0.1:4243 comes back.
	 */
	$cmd = 'docker -H=unix:///var/run/docker.sock container ls -a | grep ' . escapeshellarg($dockerName); // Check docker is exist
	$o = [];
	exec($cmd, $o, $rc);

	if (count($o) == 0) {
		$cmd = 'docker -H=unix:///var/run/docker.sock create --shm-size 1G --privileged -ti --net=none --name=' . escapeshellarg($dockerName)
			. ' -h ' . escapeshellarg($node_name . '_' . $interface_name) . ' pnetlab/pnet-wireshark';
		exec($cmd, $o, $rc);
	}

	$cmd = 'docker -H=unix:///var/run/docker.sock start ' . escapeshellarg($dockerName);
	exec($cmd, $o, $rc);

	$cmd = 'docker -H=unix:///var/run/docker.sock inspect --format "{{ .State.Pid }}" ' . escapeshellarg($dockerName);
	$o = [];
	exec($cmd, $o, $rc);
	$pid = $o[0];

	// Create rdp connection to eth1

	$cmd = 'ip link | grep ' . escapeshellarg('rdp' . $uniqueId);
	$o = [];
	exec($cmd, $o, $rc);

	if (count($o) == 0) {
		$cmd = 'ip link add ' . escapeshellarg('rdp' . $uniqueId) . ' type veth peer name ' . escapeshellarg('dc0' . $uniqueId);
		exec($cmd, $o, $rc);
		$cmd = 'ip link set dev ' . escapeshellarg('rdp' . $uniqueId) . ' up';
		exec($cmd, $o, $rc);

		$cmd = 'ip link set dev ' . escapeshellarg('dc0' . $uniqueId) . ' up';
		exec($cmd, $o, $rc);

		// Add eth1 for docker

		$mac = sprintf('48:%02x:%02x:%02x:%02x:%02x', $lab_session, intdiv($node_id, 512), $node_id % 512, $interface_id, 1);
		$cmd = 'ip link set netns ' . escapeshellarg($pid) . ' ' . escapeshellarg('dc0' . $uniqueId)
			. ' name eth1 address ' . escapeshellarg($mac) . ' up';
		exec($cmd, $o, $rc);

		//connect eth1 to docker 0
		$cmd = 'brctl addif docker0 ' . escapeshellarg('rdp' . $uniqueId);
		exec($cmd, $o, $rc);

		//config ip address eth1
		$cmd = 'sudo /opt/unetlab/wrappers/nsenter -t ' . escapeshellarg($pid) . ' -n ip addr add ' . escapeshellarg($ipAddress . '/16') . ' dev eth1';
		exec($cmd, $o, $rc);

		$cmd = 'sudo /opt/unetlab/wrappers/nsenter -t ' . escapeshellarg($pid) . ' -n ip route add default via ' . escapeshellarg($ipAddress);
		exec($cmd, $o, $rc);

		// // nat rdp port
		// $cmd = 'sudo iptables -t nat -I PREROUTING -p tcp --dport ' . $port . ' -j DNAT --to ' . $ipAddress . ':' . $connectPort;
		// exec($cmd, $o, $rc);

		// $dockerIp = getDockerIp();
		// $cmd = 'sudo iptables -t nat -I POSTROUTING -p tcp -d ' . $ipAddress . ' --dport ' . $connectPort . ' -j SNAT --to ' . $dockerIp;
		// exec($cmd, $o, $rc);
	}

	$cmd = 'ip link | grep ' . escapeshellarg('span' . $uniqueId);
	$o = [];
	exec($cmd, $o, $rc);

	if (count($o) == 0) {

		$cmd = 'ip link add ' . escapeshellarg('span' . $uniqueId) . ' type veth peer name ' . escapeshellarg('cap' . $uniqueId);
		exec($cmd, $o, $rc);

		$cmd = 'ip link set dev ' . escapeshellarg('span' . $uniqueId) . ' up';
		exec($cmd, $o, $rc);

		$cmd = 'ip link set dev ' . escapeshellarg('span' . $uniqueId) . ' mtu 9000';
		exec($cmd, $o, $rc);

		$cmd = 'ip link set dev ' . escapeshellarg('cap' . $uniqueId) . ' up';
		exec($cmd, $o, $rc);

		$cmd = 'ip link set dev ' . escapeshellarg('cap' . $uniqueId) . ' mtu 9000';
		exec($cmd, $o, $rc);

		// add capture port to docker 
		$mac = sprintf('48:%02x:%02x:%02x:%02x:%02x', $lab_session, intdiv($node_id, 512), $node_id % 512, $interface_id, 0);
		$cmd = 'ip link set netns ' . escapeshellarg($pid) . ' ' . escapeshellarg('cap' . $uniqueId)
			. ' name eth0 address ' . escapeshellarg($mac) . ' up';
		exec($cmd, $o, $rc);

		// add span port to network need capture
		$cmd = 'brctl addif ' . escapeshellarg($net_name) . ' ' . escapeshellarg('span' . $uniqueId);
		exec($cmd, $o, $rc);

		// set age
		$cmd = 'brctl setageing ' . escapeshellarg($net_name) . ' 0';
		exec($cmd, $o, $rc);
	}


	$html5_db = html5_checkDatabase();

	html5AddSession($html5_db, $dockerName, 'rdp', $port, $tenant, $ipAddress, $connectPort, 'root', LOCAL_PASS, 'reconnect');
	$html5_db = null;
	// addHtml5Perm($port, $tenant);
	$token = getHtml5Token($tenant);
	$b64id = base64_encode($port.$tenant . "\0" . 'c' . "\0" . 'mysql');
	$link = '/html5/#/client/' . $b64id . '?token=' . $token;

	$output = [];
	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = [
		'link' => $link,
		'node' => $node_name,
		'port' => $interface_name,
	];
	return $output;
}



function deleteWireshark($lab, $node_id, $interface_id)
{
	// delete wireshark when user close capture;
	$tenant = $lab->getTenant();
	$lab_session = $lab->getSession();
	if ($lab_session == null) throw new Exception('No Lab Session');

	$db=checkDatabase();

	$query = 'SELECT * FROM wiresharks WHERE ws_tenant=:ws_tenant AND ws_lab=:ws_lab AND ws_node=:ws_node AND ws_if=:ws_if';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_tenant' => $tenant,
		'ws_lab' => $lab_session,
		'ws_node' => $node_id,
		'ws_if' => $interface_id,
	]);

	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	if (count($result) > 0) {
		$result = $result[0];
		$port = $result['ws_port'];
		$ipAddress = $result['ws_ip'];
		deleteWiresharkSystem($tenant, $lab_session, $node_id, $interface_id, $port, $ipAddress);
	}

	$query = 'DELETE FROM wiresharks WHERE ws_tenant=:ws_tenant AND ws_lab=:ws_lab AND ws_node=:ws_node AND ws_if=:ws_if';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_tenant' => $tenant,
		'ws_lab' => $lab_session,
		'ws_node' => $node_id,
		'ws_if' => $interface_id,
	]);
}


function deleteWiresharkSystem($tenant, $lab_session, $node_id, $interface_id, $port, $ipAddress)
{

	$uniqueId = $tenant . '_' . $lab_session . '_' . $node_id . '_' . $interface_id;
	$dockerName = 'Capture_' . $uniqueId;
	$connectPort = 3389;
	// nat rdp port
	$cmd = 'sudo iptables -t nat -D PREROUTING -p tcp --dport ' . escapeshellarg($port) . ' -j DNAT --to ' . escapeshellarg($ipAddress . ':' . $connectPort);
	exec($cmd, $o, $rc);

	$dockerIp = getDockerIp();
	$cmd = 'sudo iptables -t nat -D POSTROUTING -p tcp -d ' . escapeshellarg($ipAddress) . ' --dport ' . escapeshellarg($connectPort) . ' -j SNAT --to ' . escapeshellarg($dockerIp);
	exec($cmd, $o, $rc);

	// remove rdp connection to eth1
	$cmd = 'ip link delete ' . escapeshellarg('rdp' . $uniqueId);
	$o = [];
	exec($cmd, $o, $rc);

	// remove span connection to eth1
	$cmd = 'ip link delete ' . escapeshellarg('span' . $uniqueId);
	$o = [];
	exec($cmd, $o, $rc);

	$cmd = 'docker -H=unix:///var/run/docker.sock container stop ' . escapeshellarg($dockerName);
	$o = [];
	exec($cmd, $o, $rc);

	$cmd = 'docker -H=unix:///var/run/docker.sock container rm ' . escapeshellarg($dockerName) . ' &';
	$o = [];
	exec($cmd, $o, $rc);

	return true;
}


function deleteWiresharkByNode($db, $lab, $node_id)
{
	// delete wireshark when a node is close, or delete
	$tenant = $lab->getTenant();
	$lab_session = $lab->getSession();
	if ($lab_session == null) throw new Exception('No Lab Session');

	$query = 'SELECT * FROM wiresharks WHERE ws_tenant=:ws_tenant AND ws_lab=:ws_lab AND ws_node=:ws_node';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_tenant' => $tenant,
		'ws_lab' => $lab_session,
		'ws_node' => $node_id,
	]);

	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	if (count($result) > 0) {
		foreach ($result as $ws) {
			$port = $ws['ws_port'];
			$ipAddress = $ws['ws_ip'];
			$node_id = $ws['ws_node'];
			$interface_id = $ws['ws_if'];
			deleteWiresharkSystem($tenant, $lab_session, $node_id, $interface_id, $port, $ipAddress);
		}
	}

	$query = 'DELETE FROM wiresharks WHERE ws_tenant=:ws_tenant AND ws_lab=:ws_lab AND ws_node=:ws_node';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_tenant' => $tenant,
		'ws_lab' => $lab_session,
		'ws_node' => $node_id,
	]);
}

function deleteWiresharkByLab($db, $lab)
{
	// delete wireshark when an user leave lab
	$tenant = $lab->getTenant();
	$lab_session = $lab->getSession();
	if ($lab_session == null) throw new Exception('No Lab Session');

	$query = 'SELECT * FROM wiresharks WHERE ws_tenant=:ws_tenant AND ws_lab=:ws_lab';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_tenant' => $tenant,
		'ws_lab' => $lab_session,
	]);

	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	if (count($result) > 0) {
		foreach ($result as $ws) {
			$port = $ws['ws_port'];
			$ipAddress = $ws['ws_ip'];
			$node_id = $ws['ws_node'];
			$interface_id = $ws['ws_if'];
			deleteWiresharkSystem($tenant, $lab_session, $node_id, $interface_id, $port, $ipAddress);
		}
	}

	$query = 'DELETE FROM wiresharks WHERE ws_tenant=:ws_tenant AND ws_lab=:ws_lab';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_tenant' => $tenant,
		'ws_lab' => $lab_session,
	]);
}

function deleteWiresharkBySession($db, $lab_session)
{
	//delete wireshark when session is destroyed
	if ($lab_session == null) throw new Exception('No Lab Session');

	$query = 'SELECT * FROM wiresharks WHERE ws_lab=:ws_lab';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_lab' => $lab_session,
	]);

	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	if (count($result) > 0) {
		foreach ($result as $ws) {
			$port = $ws['ws_port'];
			$ipAddress = $ws['ws_ip'];
			$node_id = $ws['ws_node'];
			$interface_id = $ws['ws_if'];
			$tenant = $ws['ws_tenant'];
			deleteWiresharkSystem($tenant, $lab_session, $node_id, $interface_id, $port, $ipAddress);
		}
	}

	$query = 'DELETE FROM wiresharks WHERE ws_lab=:ws_lab';
	$statement = $db->prepare($query);
	$statement->execute([
		'ws_lab' => $lab_session,
	]);
}




// ==========EVE_STORE workbook ===================//

/**
 * Return the KEY of the first element matching $callback, or false.
 *
 * Prefixed because PHP 8.4 added four built-ins in this namespace —
 * array_find(), array_find_key(), array_any() and array_all() — and redeclaring
 * any of them is a fatal error, not a warning.
 *
 * It was previously named array_find(), whose 8.4 built-in has the same
 * signature but returns the matching VALUE rather than its key. Guarding the old
 * name with function_exists() instead of renaming would therefore have silently
 * changed every call site from a key to a value on PHP >= 8.4.
 */
function unl_array_find_key($array, $callback)
{
	foreach ($array as $key => $item) {
		if ($callback($item)) {
			return $key;
		}
	}
	return false;
}

function objSort(&$objArray, $indexFunction, $sort_flags = 0)
{
	$indeces = array();
	foreach ($objArray as $obj) {
		$indeces[] = $indexFunction($obj);
	}
	return array_multisort($indeces, $objArray, $sort_flags);
}

if (!function_exists('get')) {
	function get(&$var, $default = null)
	{
		if (!isset($var)) return $default;
		if ($var === null) return $default;
		return $var;
	}
}

/** ============================ */


/** EVE_STORE lab session */

function createLabSession($db)
{
	$query = 'SELECT lab_session_id FROM lab_sessions';
	$statement = $db->prepare($query);
	$statement->execute();
	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	$idColumn = array_column($result, 'lab_session_id');
	if (count($idColumn) > 0) {
		$id = $idColumn[count($idColumn) - 1];
	} else {
		$id = 1;
	}
	while (array_search($id, $idColumn) !== false) {
		$id++;
	}
	return $id;
}

function replaceLabSessionPath($search, $replacement){
	$db=checkDatabase();
	$query = 'UPDATE lab_sessions SET lab_session_path=REPLACE(lab_session_path, :search, :replacement)';
	$statement = $db->prepare($query);
	$statement->execute([
		"search" => $search,
		"replacement" => $replacement,
	]);
}


function addLabSession($lid, $pod, $labpath)
{
	try {
		//Check if labsession exited
		$db=checkDatabase();
		$query = 'SELECT * FROM lab_sessions WHERE lab_session_lid=:lab_session_lid AND lab_session_pod = :lab_session_pod';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_lid' => $lid,
			'lab_session_pod' => $pod,
		]);

		$result = $statement->fetchAll(PDO::FETCH_ASSOC);

		if (count($result) == 0) {
			lockSession(0);
			$id = createLabSession($db);
			$query = 'INSERT INTO lab_sessions (`lab_session_id`, `lab_session_lid`, `lab_session_pod`, `lab_session_joined`, `lab_session_path`) 
					VALUES (:lab_session_id, :lab_session_lid, :lab_session_pod, :lab_session_joined, :lab_session_path)';
			$statement = $db->prepare($query);
			$statement->execute([
				'lab_session_id' => $id,
				'lab_session_lid' => $lid,
				'lab_session_pod' => $pod,
				'lab_session_joined' => $pod,
				'lab_session_path' => $labpath,
			]);
			unlockSession(0);
			$query = 'UPDATE users SET lab_session = :lab_session WHERE pod = :pod;';
			$statement = $db->prepare($query);
			$statement->execute([
				'lab_session' => $id,
				'pod' => $pod,
			]);
		} else {
			$id = $result[0]['lab_session_id'];
			return joinLabSession($pod, $id);
		}

		return ['result' => true, 'message' => 'Success'];
	} catch (Exception $e) {
		return ['result' => false, 'message' => $e->getMessage()];
	}
}

function joinLabSession($tenant, $lab_session)
{
	try {
		$db=checkDatabase();
		$query = 'SELECT * FROM lab_sessions WHERE lab_session_id=:lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_id' => $lab_session,
		]);

		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (!isset($result[0])) throw new Exception('Lab Session not found');
		$labSession = $result[0];

		if ($labSession['lab_session_joined'] == '' || $labSession['lab_session_joined'] == null) {
			$joined = [];
		} else {
			$joined = explode(',', $labSession['lab_session_joined']);
		}

		$index = array_search($tenant, $joined);
		if ($index === false) {
			$joined[] = $tenant;
		}

		$query = 'UPDATE lab_sessions SET lab_session_joined = :lab_session_joined WHERE lab_session_id = :lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_joined' => implode(',', $joined),
			'lab_session_id' => $lab_session,
		]);

		$query = 'UPDATE users SET lab_session = :lab_session WHERE pod = :pod;';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session' => $lab_session,
			'pod' => $tenant,
		]);

		return ['result' => true, 'message' => 'Success'];
	} catch (Exception $e) {
		return ['result' => false, 'message' => $e->getMessage()];
	}
}

function leaveLabSession($tenant, $lab)
{
	try {
		$db=checkDatabase();
		$query = 'SELECT * FROM lab_sessions WHERE lab_session_id=:lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_id' => $lab->getSession(),
		]);

		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (!isset($result[0])) throw new Exception('Lab Session not found');
		$labSession = $result[0];

		$joined = explode(',', $labSession['lab_session_joined']);
		$index = array_search($tenant, $joined);
		if ($index !== false) {
			array_splice($joined, $index, 1);
		}

		$query = 'UPDATE lab_sessions SET lab_session_joined = :lab_session_joined WHERE lab_session_id = :lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_joined' => implode(',', $joined),
			'lab_session_id' => $lab->getSession(),
		]);

		$query = 'UPDATE users SET lab_session = :lab_session WHERE pod = :pod;';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session' => null,
			'pod' => $tenant,
		]);

		deleteWiresharkByLab($db, $lab);

		return ['result' => true, 'message' => 'Success'];
	} catch (Exception $e) {
		return ['result' => false, 'message' => $e->getMessage()];
	}
}

function emptyLabSession($tenant)
{
	$db = checkDatabase();
	$query = 'UPDATE users SET lab_session = :lab_session WHERE pod = :pod;';
	$statement = $db->prepare($query);
	$statement->execute([
		'lab_session' => null,
		'pod' => $tenant,
	]);
}

function destroyLabSession($lab)
{
	try {
		$db=checkDatabase();
		deleteWiresharkBySession($db, $lab->getSession());

		$query = 'SELECT * FROM lab_sessions WHERE lab_session_id=:lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_id' => $lab->getSession(),
		]);

		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (!isset($result[0])) throw new Exception('Lab Session not found');

		foreach ($lab->getNodes() as $node) {
			$result = stop($node);
			if ($result != 0) throw new Exception('Fail to Stop Node ' . $node->getName());

			$result = wipe($node);
			if ($result != 0) throw new Exception('Fail to Wipe Node ' . $node->getName());
		}

		$query = 'DELETE FROM node_sessions WHERE node_session_lab = :node_session_lab';
		$statement = $db->prepare($query);
		$statement->execute([
			'node_session_lab' => $lab->getSession(),
		]);

		$query = 'DELETE FROM lab_sessions WHERE lab_session_id = :lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_id' =>  $lab->getSession(),
		]);

		$query = 'UPDATE users SET lab_session = null WHERE lab_session = :lab_session';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session' =>  $lab->getSession(),
		]);

		$cmd = 'brctl show | grep ' . escapeshellarg('vnet' . $lab->getSession()) . ' | sed \'s/^\(vnet[0-9]\+_[0-9]\+\).*/\1/g\' | while read line; do sudo ifconfig $line down; sudo brctl delbr $line; done';
		exec($cmd, $o, $rc);

		$cmd = 'sudo rm -rf ' . escapeshellarg(BASE_TMP . '/' . $lab->getSession());
		exec($cmd, $o, $rc);

		return ['result' => true, 'message' => 'Success'];
	} catch (Exception $e) {
		return ['result' => false, 'message' => $e->getMessage()];
	}
}


function stopLabSession($lab)
{
	// Run when user click on stop all nodes button of lab session
	try {
		$db=checkDatabase();
		deleteWiresharkBySession($db, $lab->getSession());

		$query = 'SELECT * FROM lab_sessions WHERE lab_session_id=:lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_id' => $lab->getSession(),
		]);

		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (!isset($result[0])) throw new Exception('Lab Session not found');

		foreach ($lab->getNodes() as $node) {
			$result = stop($node);
			if ($result != 0) throw new Exception('Fail to Stop Node ' . $node->getName());
		}
		
		return ['result' => true, 'message' => 'Success'];
	} catch (Exception $e) {
		return ['result' => false, 'message' => $e->getMessage()];
	}
}

function destroyBrokenLabSession($lab_session)
{
	try {
		$db=checkDatabase();
		deleteWiresharkBySession($db, $lab_session);

		$query = 'SELECT * FROM lab_sessions WHERE lab_session_id=:lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_id' => $lab_session,
		]);

		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (!isset($result[0])) throw new Exception('Lab Session not found');

		$query = 'SELECT * FROM node_sessions WHERE node_session_lab=:node_session_lab';
		$statement = $db->prepare($query);
		$statement->execute([
			'node_session_lab' => $lab_session,
		]);

		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		foreach ($result as $node) {
			if ($node['node_session_type'] == 'docker') {
				$cmd = 'docker -H=unix:///var/run/docker.sock stop ' . escapeshellarg('docker' . $node['node_session_id']);
				exec($cmd, $o, $rc);
				$cmd = 'docker -H=unix:///var/run/docker.sock rm ' . escapeshellarg('docker' . $node['node_session_id']);
				exec($cmd, $o, $rc);
			} else {
				$cmd = 'sudo fuser -k -TERM ' . escapeshellarg($node['node_session_workspace']) . ' > /dev/null 2>&1';
				exec($cmd, $o, $rc);
			}

			// The node's taps. device::stop() releases these through
			// releaseTaps(); this path never builds a Node object, so it has
			// to do the same by hand, from the host's interface list rather
			// than the lab's -- a start that died inside prepare() created a
			// prefix of the lab's list, and an interface removed from the lab
			// after a start left a tap the list no longer names.
			foreach (unl_session_taps($node['node_session_id']) as $tap) {
				if (delTap($tap) !== 0) {
					error_log(date('M d H:i:s ') . 'ERROR: could not remove ' . $tap);
				}
			}
		}

		$query = 'DELETE FROM node_sessions WHERE node_session_lab = :node_session_lab';
		$statement = $db->prepare($query);
		$statement->execute([
			'node_session_lab' => $lab_session,
		]);

		// Reap the tenant accounts. This path does NOT go through
		// device::stop(), which is where the ordinary reap lives -- it kills
		// the processes itself, precisely because the lab is too broken to
		// build Node objects for -- so without this, destroying a broken
		// session is the one teardown that still leaks an account.
		//
		// ORDER IS THE WHOLE POINT. The reaper refuses while a process runs as
		// the uid, while a vunl<N>_* tap still exists, or while node_sessions
		// still reports the node as running. An earlier revision called it
		// after the kill but BEFORE the taps were deleted and BEFORE the rows
		// were, so it refused every time, silently, and the leak this comment
		// claimed to close stayed open. It now runs after all three.
		foreach ($result as $node) {
			$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a reap-tenant'
				. ' -S ' . (int) $node['node_session_id'] . ' > /dev/null 2>&1';
			exec($cmd, $o, $rc);
		}

		$query = 'DELETE FROM lab_sessions WHERE lab_session_id = :lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session_id' =>  $lab_session,
		]);

		$query = 'UPDATE users SET lab_session = null WHERE lab_session = :lab_session';
		$statement = $db->prepare($query);
		$statement->execute([
			'lab_session' =>  $lab_session
		]);

		$cmd = 'brctl show | grep ' . escapeshellarg('vnet' . $lab_session) . ' | sed \'s/^\(vnet[0-9]\+_[0-9]\+\).*/\1/g\' | while read line; do sudo ifconfig $line down; sudo brctl delbr $line; done';
		exec($cmd, $o, $rc);

		$cmd = 'sudo rm -rf ' . escapeshellarg(BASE_TMP . '/' . $lab_session);
		exec($cmd, $o, $rc);

		return ['result' => true, 'message' => 'Success'];
	} catch (Exception $e) {
		return ['result' => false, 'message' => $e->getMessage()];
	}
}

$getLabFromSessionResult = [];
function getLabFromSession($lab_session)
{
	if (!isset($GLOBALS['getLabFromSessionResult'][$lab_session])) {
		$db = checkDatabase();
		$query = 'SELECT * FROM lab_sessions WHERE lab_session_id=:lab_session_id';
		$statement = $db->prepare($query);
		$statement->execute(['lab_session_id' => $lab_session]);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);

		if (isset($result[0])) {
			$GLOBALS['getLabFromSessionResult'][$lab_session] = $result[0];
		} else {
			$GLOBALS['getLabFromSessionResult'][$lab_session] = null;
		}
	}
	return $GLOBALS['getLabFromSessionResult'][$lab_session];
}


function getAllSessionOfNode($lab_id, $node_id)
{
	$db = checkDatabase();
	$query = 'SELECT * FROM node_sessions LEFT JOIN lab_sessions ON node_session_lab = lab_session_id WHERE node_session_nid=:node_session_nid AND lab_session_lid = :lab_session_lid';
	$statement = $db->prepare($query);
	$statement->execute([
		'node_session_nid' => $node_id,
		'lab_session_lid' => $lab_id,
	]);
	$result = $statement->fetchAll(PDO::FETCH_ASSOC);

	foreach ($result as $key => $nodeSession) {
		$result[$key]['node_session_status'] = getNodeStatus(
			$nodeSession['node_session_id'],
			$nodeSession['node_session_type'],
			$nodeSession['node_session_workspace'],
			$nodeSession['node_session_port']
		);
	}

	return $result;
}

function getAllUser()
{
	$db = checkDatabase();
	$query = 'SELECT * FROM users';
	$statement = $db->prepare($query);
	$statement->execute();
	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	return $result;
}







/** ======================== */

//==========================



function getNodesStatus($lab_session)
{
	$db = checkDatabase();
	if ($lab_session === null) {
		$query = 'SELECT * FROM node_sessions';
		$statement = $db->prepare($query);
		$statement->execute();
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		foreach ($result as $id => $node) {
			$result[$id]['node_session_status'] = getNodeStatus(
				$node['node_session_id'],
				$node['node_session_type'],
				$node['node_session_workspace'],
				$node['node_session_port']
			);
		}
		return $result;
	} else {
		$query = 'SELECT * FROM node_sessions WHERE node_session_lab=:node_session_lab';
		$statement = $db->prepare($query);
		$statement->execute(['node_session_lab' => $lab_session]);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		$status = [];
		foreach ($result as $node) {
			$status[$node['node_session_nid']] = getNodeStatus(
				$node['node_session_id'],
				$node['node_session_type'],
				$node['node_session_workspace'],
				$node['node_session_port']
			);
		}
		return $status;
	}
}

function getNodeStatus($session, $type, $running_path, $port)
{
	
	if (!isset($session)) return 0;

	if ($type == 'docker') {
		$cmd = 'docker -H=unix:///var/run/docker.sock inspect --format="{{ .State.Running }}" ' . escapeshellarg('docker' . $session);
		exec($cmd, $o, $rc);
		if ($rc == 0) {
			if ($o[0] == 'true') {
				// Node is running
				if (is_file($running_path . '/.lock')) {
					// Node is running and locked
					return 3;
				} else {
					return 2;
				}
			} else {
				if (is_file($running_path . '/.lock')) {
					// Node is stopped and locked
					return 1;
				} else {
					return 0;
				}
			}
		} else {
			// Instance does not exist
			return 0;
		}
	} else {
		// Need to check if node port is used (netstat + grep doesn't require root privileges)
		$cmd = 'netstat -a -t -n | grep LISTEN | grep ' . escapeshellarg(':' . $port) . ' 2>&1';
		
		exec($cmd, $o, $rc);
		if ($rc == 0) {
			// Console available -> node is running
			if (is_file($running_path . '/.lock')) {
				// Node is running and locked
				return 3;
			} else {
				return 2;
			}
		} else {
			// No console available -> node is stopped
			if (is_file($running_path . '/.lock')) {
				// Node is stopped and locked
				return 1;
			} else {
				return 0;
			}
		}
	}
}

function createRunningPath($lab_session, $node_session)
{
	// Both are int(11) columns — lab_sessions.lab_session_id is AUTO_INCREMENT
	// and node_sessions.node_session_id is allocated modulo 30000 by
	// createNodeSession(). The cast says so, and it is what makes the workspace
	// path this returns provably free of shell syntax: it is interpolated into
	// emulator command lines all over devices/ through getRunningPath(), and it
	// was the last unescaped value on several of them.
	return BASE_TMP . '/' . (int) $lab_session . '/' . (int) $node_session;
}


function getTemplates()
{

	$templates = $GLOBALS['node_templates'];
	$qemudir = scandir("/opt/unetlab/addons/qemu/");
	$ioldir = scandir("/opt/unetlab/addons/iol/bin/");
	$dyndir = scandir("/opt/unetlab/addons/dynamips/");

	foreach ($templates as $templ => $desc) {
		try {

			if (!is_file(BASE_DIR . '/html/templates/' . $templ . '.yml')) {
				unset($templates[$templ]);
				continue;
			}

			$p = yaml_parse_file(BASE_DIR . '/html/templates/' . $templ . '.yml');
			if (isset($p['description'])) {
				$desc = $p['description'];
			} else {
				$desc = $templ;
			}

			// }
			if(!isset($p['type'])){
				unset($templates[$templ]);
				continue;
			}

			$found = 0;
			if ($p['type'] == "iol") {
				foreach ($ioldir as $dir) {
					if (preg_match("/\.bin/", $dir)  ==  1) {
						$found = 1;
						break;
					}
				}
			}
			if ($p['type'] == 'dynamips') {
				foreach ($dyndir as $dir) {
					if (preg_match("/" . $templ . "/", $dir)  ==  1) {
						$found = 1;
						break;
					}
				}
			}
			if ($templ == "vpcs") {
				$found = 1;
			}

			if($p['type'] == 'docker'){
				if($templ == 'docker'){
					$found = 1;
				}else{
					$cmd = 'docker -H=unix:///var/run/docker.sock images | grep ' . escapeshellarg($templ);
					exec($cmd, $o, $r);
					if(count($o) > 0) $found = 1;
				}
			}

			foreach ($qemudir as $dir) {
				if (preg_match("/^" . $templ . "-.*/", $dir)  ==  1) {
					$found = 1;
					break;
				}
			}
			if ($found == 0) {
				$templates[$templ] = $desc . TEMPLATE_DISABLED;
			} else {
				$templates[$templ] = $desc;
			}

		}catch (Exception $e){
			throw new ResponseException('Can not load template {data}', ['data' => $templ]);
		}
	}

	return $templates;
}

function scanDirFiles($path)
{
	if (!is_dir($path)) return [];
	$files = [];
	$scaned = scandir($path);
	array_splice($scaned, 0, 2);
	foreach ($scaned as $item) {
		$itemPath = $path . '/' . $item;
		if (is_file($itemPath)) {
			$files[] = $itemPath;
		} else {
			$files = array_merge($files, scanDirFiles($itemPath));
		}
	}
	return $files;
}


/* license helper */
$user = null;
function getUser()
{
	if ($GLOBALS['user'] != null) return $GLOBALS['user'];
	return null;
}

function getLocalPass(){
	if ($GLOBALS['user'] != null) return $GLOBALS['user'][USER_PASSWORD];
	return null;
}

$role = null;
function getRole()
{
	if ($GLOBALS['role'] != null) return $GLOBALS['role'];
	$user = getUser();
	if (!$user) return null;
	$db = checkDatabase();
	$query = 'SELECT * FROM ' . USER_ROLES_TABLE . ' WHERE ' . USER_ROLE_ID . ' = :role';
	$statement = $db->prepare($query);
	$statement->execute([
		'role' => $user['role']
	]);
	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	if (!isset($result[0])) return null;
	$GLOBALS['role'] = $result[0];
	return $GLOBALS['role'];
}

$getRoleByPod = [];
function getRoleByPod($pod)
{

	if (isset($GLOBALS['getRoleByPod'][$pod])) return $GLOBALS['getRoleByPod'][$pod];

	$user = getUser();
	if (!$user) return null;
	if ($user['pod'] == $pod) {
		$GLOBALS['getRoleByPod'][$pod] = getRole();
		return $GLOBALS['getRoleByPod'][$pod];
	}

	$db = checkDatabase();
	$hostLab = getUserByPod($pod);
	if(!$hostLab) return null;
	$roleID = $hostLab[USER_ROLE];

	$query = 'SELECT * FROM ' . USER_ROLES_TABLE . ' WHERE ' . USER_ROLE_ID . ' = :role';
	$statement = $db->prepare($query);
	$statement->execute([
		'role' => $roleID
	]);
	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	if (!isset($result[0])) return null;

	$GLOBALS['getRoleByPod'][$pod] = $result[0];
	return $GLOBALS['getRoleByPod'][$pod];
}

$userByPod = [];
function getUserByPod($pod){
	if(!isset($userByPod[$pod])){
		$db = checkDatabase();
		$query = 'SELECT * FROM ' . USERS_TABLE . ' WHERE ' . USER_POD . ' = :pod';
		$statement = $db->prepare($query);
		$statement->execute([
			'pod' => $pod
		]);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (!isset($result[0])) return false;
		$userByPod[$pod] = $result[0];
	}
	return $result[0];
}

function getTotalDisk()
{
	$cmd = 'df -k /';
	exec($cmd, $o, $rc);
	$data = [];
	foreach ($o as $output) {
		if (preg_match('/^.*\s([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d]+)%.*$/mi', $output, $match)) {
			$data['free'] = (int) $match[3];
			$data['used'] = (int) $match[2];
			$data['total'] = (int) $match[1];
			$data['percent'] = (float) $match[4];
		}
	}
	return $data;
}


function checkRunningNodeLimit($pod){
	
	$hostLab = getUserByPod($pod);
	if(!$hostLab) throw new ResponseException('User not exist');
	if (!$hostLab || ($hostLab[USER_ROLE] ?? 0) == 0) return true;

	$checkMaxNode = false;
	if(isset($hostLab[USER_MAX_NODE]) && $hostLab[USER_MAX_NODE] > 0){
		$checkMaxNode = true;
	}
	$checkMaxNodeLab = false;
	if(isset($hostLab[USER_MAX_NODELAB]) && $hostLab[USER_MAX_NODELAB] > 0){
		$checkMaxNodeLab = true;
	}

	if(!$checkMaxNode && !$checkMaxNodeLab) return true;

	$db = checkDatabase();
	$query = 'SELECT COUNT(*) as total_running_node FROM ' . NODE_SESSIONS_TABLE . ' WHERE ' . NODE_SESSION_POD . ' = :pod AND ' . NODE_SESSION_RUNNING . ' = 1';

	$statement = $db->prepare($query);
	$statement->execute([
		'pod' => $pod
	]);
	$result = $statement->fetch(PDO::FETCH_ASSOC);

	$totalRunningNode = $result['total_running_node'];

	if($checkMaxNode){
		if ($hostLab[USER_MAX_NODE] !== null && $totalRunningNode >= $hostLab[USER_MAX_NODE]) {
			throw new ResponseException('max_running_node_limit', ['data' => $hostLab[USER_MAX_NODE]]);
		}
	}

	if($checkMaxNodeLab){
		if ($hostLab[USER_MAX_NODELAB] !== null && $totalRunningNode >= $hostLab[USER_MAX_NODELAB]) {
			throw new ResponseException('max_running_nodelab_limit', ['data' => $hostLab[USER_MAX_NODELAB]]);
		}
	}

	return true;
}

function checkLimit($pod)
{

	$role = getRoleByPod($pod);

	// user_roles is empty on a stock installation — including the appliance —
	// so $role is routinely null here. The '' fallbacks immediately below are
	// what supply the defaults; null-coalescing simply restores the PHP 7
	// behaviour those fallbacks were written against.
	$ramLimit = $role[USER_ROLE_RAM] ?? '';
	$cpuLimit = $role[USER_ROLE_CPU] ?? '';
	$hddLimit = $role[USER_ROLE_HDD] ?? '';

	if ($ramLimit == '' || $ramLimit > 95) $ramLimit = 95;
	if ($cpuLimit == '' || $cpuLimit > 95) $cpuLimit = 95;
	if ($hddLimit == '') {
		$disk = getTotalDisk();
		$totalDisk = isset($disk['total']) ? ($disk['total'] / 1024) : 0;
		$hddLimit = $totalDisk - 512;
	}

	$db = checkDatabase();
	$query = 'SELECT SUM(' . NODE_SESSION_RAM . ') as consume_ram, SUM(' . NODE_SESSION_CPU . ') as consume_cpu, SUM(' . NODE_SESSION_HDD . ') as consume_hdd FROM ' . NODE_SESSIONS_TABLE . ' WHERE ' . NODE_SESSION_POD . ' = :pod';

	$statement = $db->prepare($query);
	$statement->execute([
		'pod' => $pod
	]);
	$result = $statement->fetch(PDO::FETCH_ASSOC);

	$consumeRam = $result['consume_ram'];
	$consumeCpu = $result['consume_cpu'];
	$consumeHdd = $result['consume_hdd'];

	if ($consumeCpu >= $cpuLimit) throw new Exception('Over the threshold:' . $cpuLimit . '% CPU. Please turn off idle Devices');
	if ($consumeRam >= $ramLimit) throw new Exception('Over the threshold:' . $ramLimit . '% RAM. Please turn off idle Devices');
	if ($consumeHdd >= $hddLimit) throw new Exception('Over the threshold:' . $hddLimit . 'MB Hard disk. Please Wipe or Destroy idle Devices and Labs');

	return true;
}



$permission = null;
function getPermission()
{
	if ($GLOBALS['permission'] != null) return $GLOBALS['permission'];
	$role = getRole();
	if (!$role) return null;
	$roleId = $role[USER_ROLE_ID] ?? null;
	$db = checkDatabase();
	$query = 'SELECT * FROM ' . USER_PERMISSION_TABLE . ' WHERE ' . USER_PER_ROLE . ' = :role_id';
	$statement = $db->prepare($query);
	$statement->execute([
		'role_id' => $roleId
	]);
	$result = $statement->fetchAll(PDO::FETCH_ASSOC);
	$GLOBALS['permission'] = $result;
	return $GLOBALS['permission'];
}

function isAdmin()
{
	$user = getUser();
	if (!$user) return false;
	return (int) $user['role'] === 0;
}

function isOffline()
{
	$user = getUser();
	if (!$user) return false;
	return $user['offline'] == 1;
}

function checkLockLab($lab)
{
	if ($lab->isLock()) throw new Exception('This lab is locked, Please unlock it first');
}


function getSharedFolder()
{
	try {
		$db = checkDatabase();
		$query = 'SELECT * FROM control WHERE control_name=:control_name';
		$statement = $db->prepare($query);
		$statement->execute(['control_name' => CTRL_SHARED]);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (isset($result[0])) {
			return json_decode($result[0][CONTROL_VALUE]);
		} else {
			return [];
		}
	} catch (Exception $th) {
		return [];
	}
}



function checkSharePermission($action)
{
	if (isAdmin()) return true;
	try {
		$db = checkDatabase();
		$query = 'SELECT * FROM control WHERE control_name=:control_name';
		$statement = $db->prepare($query);
		$statement->execute(['control_name' => CTRL_SHARED_PERMISSION]);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (isset($result[0])) {
			$permission = json_decode($result[0][CONTROL_VALUE]);
		} else {
			$permission = (object) [];
		}
	} catch (Exception $th) {
		$permission = (object) [];
	}
	if (!isset($permission->{$action})) return false;
	return $permission->{$action};
}


function checkWorkSpace($path, $allowShare = false)
{
	if (isAdmin()) return true;

	$path = str_replace(['//', '.'], ['/', ''], $path);
	if ($path[-1] != '/') $path .= '/';

	if ($allowShare) {
		$sharedFolders = getSharedFolder();
		foreach ($sharedFolders as $sharedFolder) {
			$sharedFolder = preg_replace('/' . preg_quote(BASE_LAB, '/') . '/', '', $sharedFolder, 1);
			if ($sharedFolder[-1] != '/') $sharedFolder .= '/';
			if (strpos($path, $sharedFolder) === 0) return true;
		}
	}

	$workspace = getWorkspace();

	if ($workspace[-1] != '/') $workspace .= '/';
	if (strpos($path, $workspace) === 0) return true;
	throw new Exception('You do not have permission to access folder ' . $path);
}

function getWorkspace()
{
	if (isAdmin()) return '/';

	$role = getRole();
	if ($role == 'null') throw new Exception('You do not have permission');
	$workspace = $role['user_role_workspace'] ?? '';

	$user = getUser();
	if ($user[USER_WORKSPACE] != null && $user[USER_WORKSPACE] != '') {
		$workspace .= $user[USER_WORKSPACE];
		$workspace = str_replace('//', '/', $workspace);
	}

	if ($workspace[0] != '/') $workspace = '/' . $workspace;

	if (!is_dir(BASE_LAB . $workspace)) {
		mkdir(BASE_LAB . $workspace, 0755, true);
	}

	return $workspace;
}

function checkPermission($action)
{
	if (isAdmin()) return true;
	$permission = getPermission();
	if (!$permission) throw new Exception('You do not have permission');
	foreach ($permission as $item) {
		if ($item[USER_PER_NAME] == $action) return true;
	};
	throw new Exception('You do not have permission');
}

function checkLabPermission($lab, $action)
{
	if (isAdmin()) return true;

	$user = getUser();
	if (!$user) throw new Exception('You do not have permission');

	if ($action == USER_PER_EDIT_LAB) {
		$flag = $lab->getEditable();
		if ($flag == 0) throw new Exception('You do not have permission to Edit this Lab');
		if ($flag == 1) return true;
		$allowes = $lab->getEditableEmails();
		$email = $user['email'];
		$pod = $user['pod'];
		if (array_search($email, $allowes) !== false || array_search($pod, $allowes) !== false) return true;
		throw new Exception('You do not have permission to Edit this Lab');
	} else if ($action == USER_PER_OPEN_LAB) {
		$flag = $lab->getOpenable();
		if ($flag == 0) throw new Exception('You do not have permission to Open this Lab');
		if ($flag == 1) return true;
		$allowes = $lab->getOpenableEmails();
		$email = $user['email'];
		$pod = $user['pod'];
		if (array_search($email, $allowes) !== false || array_search($pod, $allowes) !== false) return true;
		throw new Exception('You do not have permission to Open this Lab');
	} else if ($action == USER_PER_JOIN_LAB) {
		$flag = $lab->getJoinable();
		if ($flag == 0) throw new Exception('You do not have permission to Join this Lab');
		if ($flag == 1) return true;
		$allowes = $lab->getJoinableEmails();
		$email = $user['email'];
		$pod = $user['pod'];
		if (array_search($email, $allowes) !== false || array_search($pod, $allowes) !== false) return true;
		throw new Exception('You do not have permission to Join this Lab');
	}
	throw new Exception('You do not have permission');
}

function checkDestroy($lab_session)
{
	if (isAdmin()) return true;
	$user = getUser();
	if (!$user) throw new Exception('You do not have permission');
	$labSession = getLabFromSession($lab_session);
	if (!$lab_session) throw new Exception('You do not have permission');
	if ($labSession['lab_session_pod'] == $user['pod']) {
		return true;
	}
	throw new Exception('Only Admin or Host can destroy the Lab Session');
}

function checkStopNodes($lab_session)
{
	// Function check before user click on stop all nodes
	if (isAdmin()) return true;
	$user = getUser();
	if (!$user) throw new Exception('You do not have permission');
	$labSession = getLabFromSession($lab_session);
	if (!$lab_session) throw new Exception('You do not have permission');
	if ($labSession['lab_session_pod'] == $user['pod']) {
		return true;
	}
	throw new Exception('Only Admin or Host can Stop this Lab Session');
}

function loadLanguage($lang)
{
	if(!isset($lang) || $lang == ''){
		$lang  = Ctrl_get(CTRL_DEFAULT_LANG, 'English');
	}
	if($lang == '') $lang = "English";
	$LANGDIR = '/opt/unetlab/html/language';
	$langPackes = scandir($LANGDIR);
	array_splice($langPackes, 0, 2);
	$langData = [];
	$log = 'Load language packages successfull';
	if (is_dir($LANGDIR . '/' . $lang)) {
		$langFiles = scandir($LANGDIR . '/' . $lang);
		array_splice($langFiles, 0, 2);
		
		foreach ($langFiles as $file) {
			try {
				$fileContent = file_get_contents($LANGDIR . '/' . $lang . '/' . $file);
				$data = json_decode($fileContent, true);
				$langData = array_merge($langData, $data);
			} catch (\Exception $th) {
				$log = '[Warning] Load language package faild: '. $lang . '/' . $file;
			}
		}
	}
	return ['packages' => $langPackes,  'language' => $lang, 'data' => (object) $langData, 'log'=>$log];
}


/**
 * exec() without a shell.
 *
 * proc_open() given an ARRAY execs the binary directly on PHP >= 7.4: there is
 * no /bin/sh anywhere on the path, so nothing in $argv can be a metacharacter,
 * a redirect or a second command, and no escaping is needed or possible. That
 * is a stronger statement than escapeshellarg() makes, and it is the shape
 * tests/Security/ShellEscapingTest.php can actually prove — see its
 * argv_literal/argv_param fixtures.
 *
 * The signature mirrors exec()'s on purpose, so converting a call site is one
 * line and the surrounding `if ($rc != 0)` keeps working. stderr is folded into
 * $output because every caller converted so far logged the two together.
 *
 * The same helper exists on the Laravel side as System\Wrapper::run() and in
 * platform/wrappers/actions/. It is duplicated rather than shared because
 * includes/ is loaded by the legacy API with no autoloader.
 *
 * @param  array  $argv    program then arguments; never a string
 * @param  array  $output  filled with the combined output, one line per element
 * @return int             the exit status, or 127 if the program could not run
 */
function unl_exec_argv(array $argv, &$output = null)
{
	$output = array();
	if (count($argv) === 0) return 127;

	$desc = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	);
	$pipes = array();
	$proc = @proc_open($argv, $desc, $pipes);
	if (!is_resource($proc)) {
		error_log(date('M d H:i:s ') . 'ERROR: cannot run ' . $argv[0]);
		return 127;
	}

	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$rc = proc_close($proc);

	$combined = rtrim($out . $err, "\n");
	if ($combined !== '') $output = explode("\n", $combined);
	return $rc;
}


/**
 * secureCmd() — an ALLOWLIST, and deliberately not the control.
 *
 * WHAT IT USED TO BE
 *
 *     $re = '/[#;|&]|\.{2,}/m';
 *     if (preg_match($re, $cmd, $matches)) throw ...;
 *     return $cmd;
 *
 * Five characters and a traversal check, applied to whole command lines on some
 * paths and to bare values on others. tests/Security/SecureCmdTest.php measured
 * what it let through: backticks, $( ), a newline, > and < redirects, a bare
 * space, $HOME, quotes and globs — ten working injections, each asserted
 * individually. A denylist of five characters cannot describe a shell.
 *
 * WHAT IT IS NOW
 *
 * Three named shapes, each an allowlist, and every call site has to say which
 * one it means. That is the substantive half of the change: the old function
 * was asked to judge `x86_64` and
 * `sudo unl_wrapper -a start -T 'x' -S '1' 2>> /path/log` with one regex, and
 * the answer that is right for a bare identifier is wrong for a command line.
 *
 *   SECURE_TOKEN  one shell word — an interface name, a session id, a port, a
 *                 username. [A-Za-z0-9_] then [A-Za-z0-9_.:=@,+-]. No space, no
 *                 metacharacter, and it may not begin with '-' so a value can
 *                 never be read as an option.
 *
 *   SECURE_PATH   a path fragment beneath a fixed base — a lab or folder path
 *                 off the request. Letters and digits in any script, plus
 *                 space and _ . / @ + , = ( ) [ ] - and no '..' anywhere.
 *                 Unicode is allowed because lab names are user-facing text and
 *                 no shell metacharacter lives above U+007F; invalid UTF-8 is
 *                 refused, because a string PCRE cannot decode is a string
 *                 nothing downstream can reason about either.
 *
 *   SECURE_LINE   a whole command line. Parsed, not pattern-matched: the line
 *                 must consist of single-quoted runs (what escapeshellarg()
 *                 emits, including its '\'' joiner), double-quoted runs that
 *                 contain no expansion, and unquoted text drawn from a safe
 *                 class. Redirections are permitted, `2>&1` included, because
 *                 the call sites build them.
 *
 * WHAT SECURE_LINE PROVES, AND WHAT IT DOES NOT
 *
 * It proves the string cannot spawn a second command: no $( ), no backtick, no
 * ; | & newline, no unquoted glob or brace, no ~ or $ expansion. It does NOT
 * prove the arguments are the intended ones. An unquoted space is still a word
 * separator, so a value interpolated raw can still become several arguments.
 *
 * That distinction is the whole reason this function is defence in depth and
 * not defence. The control is escapeshellarg() at the interpolation, or
 * proc_open() with an argv array and no shell at all. Where a call site is
 * correct only because this function ran, that is a bug in the call site, and
 * two were found and fixed when this was written — see the commit.
 *
 * NON-STRING INPUT IS AN ERROR, NOT A PASS
 *
 * device_qemu::command() returns array(False, False) when it cannot resolve an
 * architecture. The old body handed that to preg_match(), a TypeError on PHP 8
 * reached before any caller's emptiness check — a QEMU node with an
 * unresolvable template took the request down with a fatal and left its taps
 * behind. Integers are accepted and stringified for SECURE_TOKEN, because ports
 * and session ids arrive as ints; everything else is refused by name.
 */
const SECURE_TOKEN = 'token';
const SECURE_PATH  = 'path';
const SECURE_LINE  = 'line';

/**
 * Unquoted bytes a command line may contain. No shell metacharacter is in it:
 * no $ ` ; | & < > ( ) { } [ ] * ? ~ ! # ^ " ' \ and no control byte. The
 * operators the call sites genuinely build are handled separately below.
 */
const SECURE_LINE_PLAIN = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 \t_./:=@,+-%";

function secureCmd($value, $shape = SECURE_TOKEN)
{
	// $v, and NOT `$value = (string) $value`. That self-assignment is invisible to
	// the tokenizer sweep in tests/Security/ShellEscapingTest.php: resolving
	// $value finds an assignment whose right-hand side is $value, and the cycle
	// guard that stops the walk looping also stops it reporting. Writing it that
	// way silently retired `includes/functions.php $cmd` from the baseline —
	// which would have been a lie, because this function still hands its argument
	// back unescaped and devices/device.php still interpolates the result.
	// Assigning to a second name keeps the chain resolvable and the entry honest.
	//
	// The default shape is the strictest one on purpose: a call site added
	// without declaring what it is fails closed rather than being waved through.
	// Written as an if/else rather than a ternary for the same reason: a call in
	// the assigned expression becomes its own baseline entry, so `is_int()` on the
	// right-hand side would report as a second violation that means nothing.
	if (is_int($value)) {
		$v = (string) $value;
	} else {
		$v = $value;
	}
	if (!is_string($v)) {
		throw new Exception('secureCmd() needs a string, got ' . gettype($value));
	}

	switch ($shape) {
		case SECURE_TOKEN:
			// \z, not $. Without it PCRE's $ also matches before a trailing
			// newline, so "vnet1_1\n" would pass and then be two words to
			// anything reading line by line. The same trap is recorded on
			// unl_valid_ifname() in includes/cli.php.
			if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.:=@,+-]*\z/', $v) !== 1) {
				throw new Exception('not a permitted token: ' . json_encode($v));
			}
			return $v;

		case SECURE_PATH:
			if (strpos($v, '..') !== false) {
				throw new Exception('path traversal: ' . json_encode($v));
			}
			// preg_match() returns false, not 0, on malformed UTF-8 under /u.
			// Both are refusals here, which is why this tests for !== 1.
			if (preg_match('#\A[\p{L}\p{N}_ ./@+,=()\[\]-]*\z#u', $v) !== 1) {
				throw new Exception('not a permitted path: ' . json_encode($v));
			}
			return $v;

		case SECURE_LINE:
			secure_line_parse($v);
			return $v;
	}

	throw new Exception('secureCmd() called with an unknown shape: ' . json_encode($shape));
}

/**
 * Walk a command line and refuse anything that is not literal text, a quoted
 * run, or one of the redirections the call sites build.
 *
 * Written as a scanner rather than a regex because the question is stateful:
 * a byte's meaning depends on whether a quote is open, and that is exactly the
 * distinction the old denylist could not make. Inside single quotes the shell
 * expands nothing, so a run is opaque; inside double quotes it still expands
 * $ and backtick, so those two are refused there and nowhere else is different.
 */
function secure_line_parse($cmd)
{
	$n = strlen($cmd);
	for ($i = 0; $i < $n; $i++) {
		$c = $cmd[$i];

		// No shell metacharacter is above U+007F, and node and lab names are
		// user-facing text. High bytes are literal wherever they appear.
		if (ord($c) >= 0x80) continue;

		if ($c === "'") {
			$end = strpos($cmd, "'", $i + 1);
			if ($end === false) {
				throw new Exception('unterminated single quote: ' . json_encode($cmd));
			}
			$i = $end;
			continue;
		}

		if ($c === '"') {
			$j = $i + 1;
			for (; $j < $n && $cmd[$j] !== '"'; $j++) {
				if ($cmd[$j] === '$' || $cmd[$j] === '`' || $cmd[$j] === '\\') {
					throw new Exception('expansion inside double quotes: ' . json_encode($cmd));
				}
				if (ord($cmd[$j]) < 0x20) {
					throw new Exception('control byte inside double quotes: ' . json_encode($cmd));
				}
			}
			if ($j >= $n) {
				throw new Exception('unterminated double quote: ' . json_encode($cmd));
			}
			$i = $j;
			continue;
		}

		// escapeshellarg() splices an embedded apostrophe as '\'' — close,
		// escaped quote, reopen. The backslash is legal only in that one place.
		if ($c === '\\') {
			if ($i + 1 < $n && $cmd[$i + 1] === "'") { $i++; continue; }
			throw new Exception('backslash outside an escaped quote: ' . json_encode($cmd));
		}

		// Redirections. `>` and `>>` write a file, which the call sites do on
		// purpose (wrapper.txt, unl_wrapper.txt); `&` is permitted only as the
		// `>&` of `2>&1`, never as a separator or a background operator.
		if ($c === '>') continue;
		if ($c === '&') {
			if ($i > 0 && $cmd[$i - 1] === '>') continue;
			throw new Exception('& is a command separator here: ' . json_encode($cmd));
		}

		if (strpos(SECURE_LINE_PLAIN, $c) !== false) continue;

		throw new Exception('not permitted in a command line: ' . json_encode($c)
			. ' in ' . json_encode($cmd));
	}
}



/** ========EVE_STORE ==================*/
