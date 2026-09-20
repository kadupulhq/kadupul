<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* since we'll have additional headers, tell php when to flush them */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	header('Allow: POST');
	http_response_code(405);
	exit;
}

ob_start();

// Reject malformed tokens before the authentication bootstrap invokes CSRF.
if (!is_string($_POST['__csrf_magic'] ?? null)) {
	http_response_code(403);
	exit;
}

// Prevent redirect to /install/
define('IN_CACTI_INSTALL', 1);
chdir(__DIR__ . '/../');

/* set the json variable for request validation handling */
include_once('lib/functions.php');
include_once('lib/html_utility.php');
set_request_var('json', true);
$auth_json = true;

include('include/auth.php');
if (!is_string($_POST['__csrf_magic'] ?? null) || !csrf_check(false)) {
	http_response_code(403);
	exit;
}

include('install/functions.php');
include('lib/installer.php');
include('lib/utility.php');

$debug = false;


$initialData = array();
/* ================= input validation ================= */
get_nfilter_request_var('data', array());
if (isset_request_var('data') && get_nfilter_request_var('data')) {
	log_install_debug('json','Using supplied data');
	$initialData = get_nfilter_request_var('data');
	if (!is_array($initialData)) {
		$initialData = array($initialData);
	}
}

$json_level = log_install_level('json',POLLER_VERBOSITY_NONE);
log_install_high('json','Start: ' . clean_up_lines(json_encode($initialData)));

$initialData = array_merge(array('Runtime' => 'Web'), $initialData);
if (isset($initialData['step']) && $initialData['step'] == Installer::STEP_TEST_REMOTE) {
	$json = install_test_remote_database_connection();
	$json_debug = $json;
} else {
	$installer = new Installer($initialData);
	$json = json_encode($installer);

	$json_debug = $json;
	if ($json_level < POLLER_VERBOSITY_DEBUG) {
		$installer->setRuntime('Json');
		$json_debug = json_encode($installer);
	}

}
log_install_high('json','  End: ' . clean_up_lines($json_debug) . PHP_EOL);
header('Content-Type: application/json');
header('Content-Length: ' . strlen($json));
print $json;
