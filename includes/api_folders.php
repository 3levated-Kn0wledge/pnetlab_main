<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/includes/api_folders.php
 *
 * Folders related functions for REST APIs.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

/*
 * Function to add a folder to a path.
 *
 * @param   string     $name            Folder name
 * @param   string     $path            Path
 * @return  Array                       Return code (JSend data)
 */
function apiAddFolder($name, $path) {
	// apiAddFolder() validated NOTHING, and the other two routes in this file
	// validated their paths — so the three disagreed about what a folder name is.
	// A name containing '&' or '#' could be created here and then could not be
	// renamed or deleted, because apiEditFolder() and apiDeleteFolder() refused
	// it. That asymmetry predates this change (the old blocklist refused
	// [#;|&] and '..'); what is new is that the three routes now agree.
	//
	// The trade is stated rather than hidden: SECURE_PATH is narrower than the
	// old blocklist, so a folder name carrying a quote, a '%' or a '~' is no
	// longer creatable either. That is deliberate — these routes no longer build
	// a shell command at all, so the shape is a belt against a future regression
	// rather than the control, and a coherent set of legal names is worth more
	// than an exotic one. Widening it is one character class in secureCmd().
	secureCmd($path, SECURE_PATH);
	secureCmd($name, SECURE_PATH);
	// SECURE_PATH permits '/', because a path has them. A NAME does not.
	if ($name === '' || strpos($name, '/') !== false) {
		throw new Exception('Folder name is not valid');
	}
	$rc = checkFolder(BASE_LAB.$path);
	if ($rc === 2) {
		// Folder is not valid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60009];
		return $output;
	} else if ($rc === 1) {
		// Folder does not exist
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60008];
		return $output;
	}

	if ($path == '/') {
		// Avoid double '/'
		$path = '';
	}

	// Check if exists
	if (is_dir(BASE_LAB.$path.'/'.$name)) {
		// Folder already exists
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60013];
	} else {
		try {
			mkdir(BASE_LAB.$path.'/'.$name);
			$output['code'] = 200;
			$output['status'] = 'success';
			$output['message'] = $GLOBALS['messages'][60014];
		} catch (Exception $e) {
			error_log(date('M d H:i:s ').'ERROR: '.$GLOBALS['messages'][60015]);
			error_log(date('M d H:i:s ').(string) $e);
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages'][60015];
		}
	}

	return $output;
}

/**
 * Function to delete a folder.
 *
 * @param   string     $path            Path
 * @return  Array                       Return code (JSend data)
 */
function apiDeleteFolder($path) {
	// SECURE_PATH: a folder path off the request, so the shape is a path and not
	// a command line. It is the belt — the braces are the argv array below, which
	// reaches no shell at all — but the '..' half of it is still load-bearing,
	// because $path is concatenated onto BASE_LAB and never canonicalised.
	secureCmd($path, SECURE_PATH);
	$rc = checkFolder(BASE_LAB.$path);
	checkLabFolder(BASE_LAB.$path);

	if ($rc === 2) {
		// Folder is not valid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60009];
		return $output;
	} else if ($rc === 1) {
		// Folder does not exist
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60008];
		return $output;
	}

	if ($path == '/') {
		// Cannot delete '/'
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60010];
		return $output;
	}

	// Deleting the folder.
	//
	// This used to be exec('rm -rf "' . BASE_LAB . $path . '" 2>&1'). The double
	// quotes stop word splitting and NOTHING else — $( ) and a backtick both
	// expand inside them — and secureCmd()'s old blocklist was five characters
	// that did not include a dollar or a backtick. So this reads like a call site
	// that was correct only because of secureCmd().
	//
	// IT WAS NOT, AND THE REAL ANSWER IS WORTH WRITING DOWN. checkFolder(), two
	// lines above, is
	//     preg_match('/^\/[\/A-Za-z0-9_\s-]*$/', $s)
	// in devices/functions.php — an allowlist, applied to the whole path before
	// the exec ever ran, and stricter than anything in this file. It is what
	// actually stopped the injection, and nothing said so. Measured on the
	// reference host against the parent commit: a folder named
	// `x$(touch pnet_rce_proof)y` CAN be created — apiAddFolder() validated
	// nothing at all — and deleting it is then refused by checkFolder() with
	// "Requested folder is not valid (60009)", with nothing executed.
	//
	// So the fix here is defence in depth on a path that was already held, and
	// the fix that bites is the one in apiAddFolder() above: the unvalidated
	// half was the one that let the payload onto the disk in the first place.
	// An argv array execs rm(1) directly with no shell, so there is nothing left
	// to escape. `--` terminates option parsing, so a path beginning with '-'
	// cannot become a flag.
	$rc = unl_exec_argv(array('rm', '-rf', '--', BASE_LAB . $path), $o);

	if ($rc == 0) {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = 'Folder deleted';
		$output['message'] = $GLOBALS['messages'][60012];
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = 'Cannot delete folder';
		$output['message'] = $GLOBALS['messages'][60011];
	}
	return $output;
}

/**
 * Function to edit a folder.
 *
 * @param   string     $s	            Full path of the source folder
 * @param   string     $d				Full path of the destination folder
 * @return  Array                       Return code (JSend data)
 */
function apiEditFolder($s, $d) {
	secureCmd($s, SECURE_PATH);
	secureCmd($d, SECURE_PATH);
	$rc = checkFolder(BASE_LAB.$s);
	if ($rc === 2) {
		// Folder is not valid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60009];
		return $output;
	} else if ($rc === 1) {
		// Folder does not exist
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60008];
		return $output;
	}

	$rc = checkFolder(BASE_LAB.$d);
	if ($rc === 2) {
		// Folder is not valid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60047];
		return $output;
	} else if ($rc === 0) {
		// Folder already exists
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60046];
		return $output;
	}

	// Moving the folder. Same shape as the rm in apiDeleteFolder() above, and the
	// same fix: two request-supplied paths that were interpolated into a
	// double-quoted shell string are now two elements of an argv array.
	$search = $s;
	$replacement = $d;

	$rc = unl_exec_argv(array('mv', '--', BASE_LAB . $s, BASE_LAB . $d), $o);
	if ($rc == 0) {

		if($s[-1] != '/') $search .= '/';
		if($d[-1] != '/') $replacement .= '/';
		replaceLabSessionPath($search, $replacement);

		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60049];
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = 'Cannot move folder';
		$output['message'] = $GLOBALS['messages'][60048];
	}
	return $output;
}


function checkLabFolder($path){
	$labFiles = scanDirFiles($path);
	$user = getUser();
	if(!$user) throw new Exception('No User');

	$db = checkDatabase();
	$query = 'SELECT user_role_workspace, user_role_name FROM user_roles';
	$statement = $db->prepare($query);
	$statement->execute();
	$result = $statement->fetchAll(PDO::FETCH_ASSOC);

	foreach($result as $workspace){
		$workspacePath = BASE_LAB.$workspace['user_role_workspace'];
		if (strpos($workspacePath, $path) === 0) {
			throw new ResponseException( 'error_folder_workspace', ['folder' => $workspacePath, 'role' => $workspace['user_role_name']]);
		}
	}

	foreach ($labFiles as $file){
		try {
			$lab = new Lab($file, $user['pod']);
		} catch (Exception $e) {
			continue;
		}
		if(isset($lab) && $lab->isRunning()) throw new ResponseException('error_folder_running', ['data' => $path]);
		
		
	}


	return true;
}


/**
 * Function to get all folders from a path.
 *
 * @param   string     $path            Path
 * @return  Array                       List of folders (JSend data)
 */
function apiGetFolders($path) {
	$rc = checkFolder(BASE_LAB.$path);
	if ($rc === 2) {
		// Folder is not valid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60009];
		return $output;
	} else if ($rc === 1) {
		// Folder does not exist
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60008];
		return $output;
	}

	// Listing content
	$folders = Array();
	$labs = Array();

	if ($path != '/') {
		// Adding '..' folder
		$folders[] = Array(
			'name' => '..',
			'path' => dirname($path)
		);
	}

	// Scanning directory
	foreach (scandir(BASE_LAB.$path) as $element) {
		if (!in_array($element, array('.', '..'))) {
			if (is_dir(BASE_LAB.$path.'/'.$element)) {
				if ($path == '/') {
					$folders[] = Array(
						'name' => $element,
						'path' => '/'.$element
					);
				} else {
					$folders[] = Array(
						'name' => $element,
						'path' => $path.'/'.$element
					);
				}
				continue;
			}
			if (is_file(BASE_LAB.$path.'/'.$element) && preg_match('/^.+\.unl$/', $element)) {
				if ($path == '/') {
					$labs[] = Array(
						'file' => $element,
						'path' => '/'.$element,
						'umtime' => filemtime(BASE_LAB.$path.'/'.$element),
						'mtime' => date ("d M Y H:i", filemtime(BASE_LAB.$path.'/'.$element))
					);
				} else {
					$labs[] = Array(
						'file' => $element,
						'path' => $path.'/'.$element,
						'umtime' => filemtime(BASE_LAB.$path.'/'.$element),
						'mtime' => date ("d M Y  H:i", filemtime(BASE_LAB.$path.'/'.$element))
					);
				}
				continue;
			}
		}
	}


	//EVE_STORE sell lab
	foreach ($labs as $key => $lab){
		$labs[$key]['owner'] = file_get_contents(BASE_LAB.$lab['path'], false, null, 0, 5) == '<?xml';
	}

	$sharedFolders = getSharedFolder();
	
	foreach($sharedFolders as $sharedFolder){
		$sharedFolder = preg_replace('/' . preg_quote(BASE_LAB, '/') . '/', '', $sharedFolder);
		foreach ($folders as $key=>$folder){
			if($folder['path'] == $sharedFolder) $folders[$key]['shared'] = true;
		}
	}
	

	// Sorting
	usort($folders, function($a, $b){
		return strnatcasecmp($a['name'], $b['name']);
	});
	usort($labs, function($a, $b){
		return strnatcasecmp($a['umtime'], $b['umtime']);
	});

	


	// Printing info
	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][60007];
	$output['data'] = Array(
		'folders' => $folders,
		'labs' => $labs
	);
	return $output;
}
?>
