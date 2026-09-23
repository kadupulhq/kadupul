<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The notification log purge on managers.php carries no action name, so the
 * guard in include/global.php never sees it. The Purge button posts a token;
 * a GET is refused, as utilities.php already refuses its copy of this log.
 */

namespace ManagersLogPurgeGuardTest;

/**
 * Runs the managers.php purge branch in a child process.
 *
 * @param string               $method The request method.
 * @param array<string, mixed> $post   The POST variables.
 *
 * @return array<string, mixed> Whether the log was purged and what was logged.
 */
function run_purge($method, array $post = array()) {
	$root  = dirname(__DIR__, 4);
	$page  = file_get_contents($root . '/managers.php');
	$start = strpos($page, '/* csrf-magic only checks the token on POST');
	$end   = strpos($page, "/* ================= input validation ================= */", $start);

	if ($start === false || $end === false) {
		throw new \RuntimeException('Unable to extract the purge guard from managers.php.');
	}

	$probe = '<?php
$id = 4;
$purged = false;
$logged = "";
function isset_request_var($name) { return $name === "purge"; }
function set_request_var($name, $value) {}
function get_client_addr() { return "192.0.2.10"; }
function cacti_log($message, $output = false, $environ = "") { $GLOBALS["logged"] = $message; }
function db_execute_prepared($sql, $params = array()) { $GLOBALS["purged"] = $params[0]; return true; }
$_SERVER = array("REQUEST_METHOD" => ' . var_export($method, true) . ');
$_POST   = ' . var_export($post, true) . ';
register_shutdown_function(function () { echo json_encode(array("purged" => $GLOBALS["purged"], "logged" => $GLOBALS["logged"])); });
' . substr($page, $start, $end - $start);

	$file = tempnam(sys_get_temp_dir(), 'managers-purge-');
	file_put_contents($file, $probe);

	try {
		return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
	} finally {
		unlink($file);
	}
}

test('the Purge button still empties that manager log', function () {
	expect(run_purge('POST', array('__csrf_magic' => 'token')))->toBe(array('purged' => 4, 'logged' => ''));
});

test('a GET cannot purge the log', function () {
	$result = run_purge('GET');

	expect($result['purged'])->toBeFalse()
		->and($result['logged'])->toContain('Rejected non-POST request');
});

test('a POST without the token cannot purge the log', function () {
	expect(run_purge('POST')['purged'])->toBeFalse();
});
