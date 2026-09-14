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
 * The GET guard in include/global.php compared the action loosely, so an
 * action[]=save request never matched 'save' and passed. set_default_action()
 * then unwrapped element 0 and the page dispatched the save it was meant to
 * refuse.
 */

namespace ActionArrayGuardTest;

$GLOBALS['action_guard_request'] = array();

function isset_request_var($variable) {
	return isset($_REQUEST[$variable]);
}

function get_nfilter_request_var($name, $default = '') {
	return isset($_REQUEST[$name]) ? $_REQUEST[$name] : $default;
}

function set_request_var($variable, $value) {
	$GLOBALS['action_guard_request'][$variable] = $value;
}

function read_config_option($name) {
	return '';
}

function cacti_log($message, $output = false, $environ = 'CMDPHP') {
	return true;
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_utility.php');

if ($source === false || preg_match('/^function set_default_action\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract set_default_action() from lib/html_utility.php.');
}

eval('namespace ActionArrayGuardTest;' . $matches[0]);

/**
 * Run the global.php action guard in a separate process, because it exits.
 *
 * @param string               $method  The request method.
 * @param array<string, mixed> $request The request variables.
 * @param array<string, mixed> $post    The POST variables.
 *
 * @return string The response code, or 'pass' when the guard let it through.
 */
function run_guard($method, array $request, array $post = array()) {
	$global = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');
	$start  = strpos($global, '/* check for save actions using GET */');
	$end    = strpos($global, "if (isset(\$_COOKIE['CactiTimeZone']))");

	if ($global === false || $start === false || $end === false) {
		throw new \RuntimeException('Unable to extract the action guard from include/global.php.');
	}

	$script = '<?php
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		function get_nfilter_request_var($n, $d = "") { return isset($_REQUEST[$n]) ? $_REQUEST[$n] : $d; }
		function cacti_log($m, $o = false, $e = "") { return true; }
		function get_client_addr() { return "192.0.2.10"; }
		$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
		$_REQUEST = ' . var_export($request, true) . ';
		$_POST    = ' . var_export($post, true) . ';
		$passed   = false;
		register_shutdown_function(function () use (&$passed) {
			print $passed ? "pass" : (string) http_response_code();
		});
		' . substr($global, $start, $end - $start) . '
		$passed = true;';

	$file = tempnam(sys_get_temp_dir(), 'guard');
	file_put_contents($file, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
	} finally {
		unlink($file);
	}
}

test('set_default_action does not unwrap an array action', function () {
	$_REQUEST = array('action' => array('save'));
	$GLOBALS['action_guard_request'] = array();

	set_default_action();

	expect($GLOBALS['action_guard_request']['action'])->toBe('');

	$_REQUEST = array('action' => array('save'));

	set_default_action('edit');

	expect($GLOBALS['action_guard_request']['action'])->toBe('edit');

	$_REQUEST = array('action' => 'save');

	set_default_action();

	expect($GLOBALS['action_guard_request']['action'])->toBe('save');

	$_REQUEST = array();
});

test('the global guard refuses a guarded action sent by GET as a string', function () {
	expect(run_guard('GET', array('action' => 'save')))->toBe('405');
});

test('the global guard refuses an array action', function () {
	expect(run_guard('GET', array('action' => array('save'))))->toBe('400')
		->and(run_guard('GET', array('action' => array('edit'))))->toBe('400')
		->and(run_guard('GET', array('action' => array('actions'), 'selected_items' => 'x')))->toBe('400')
		->and(run_guard('POST', array('action' => array('save')), array('__csrf_magic' => 'sid:x')))->toBe('400');
});

test('the global guard still passes read-only and token-bearing requests', function () {
	expect(run_guard('GET', array('action' => 'edit')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'actions')))->toBe('pass')
		->and(run_guard('POST', array('action' => 'save'), array('__csrf_magic' => 'sid:x')))->toBe('pass');
});
