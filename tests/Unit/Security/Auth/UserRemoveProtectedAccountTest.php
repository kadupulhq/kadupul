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
*/

/*
 * user_remove() used to skip its template and guest check when the request
 * carried a 'username' equal to the stored one, so a crafted post could
 * delete the user template or the guest account. The shipped user_remove(),
 * is_template_account() and get_guest_account() run in a child against a
 * stubbed user_auth table.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function user_remove_run(string $user_id, array $request = array()) : array {
	$root = dirname(__DIR__, 4);
	$auth = file_get_contents($root . '/lib/auth.php');
	$func = file_get_contents($root . '/lib/functions.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$GLOBALS['req']   = $scenario['request'];
$GLOBALS['calls'] = array('deleted' => array(), 'messages' => array());

/* id 5 is the user template, id 6 the guest named by username, id 7 an ordinary user */
$GLOBALS['users'] = array('5' => 'template', '6' => 'guest', '7' => 'alice');
$GLOBALS['config_options'] = array('admin_user' => '1', 'guest_user' => 'guest', 'user_template' => '5');

register_shutdown_function(function () {
	print json_encode($GLOBALS['calls']);
});

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['req'][$name] ?? $default;
}

function input_validate_input_number($value, $variable = '') {
}

function read_config_option($name, $force = false) {
	return $GLOBALS['config_options'][$name] ?? '';
}

function raise_message($id, $message = '', $level = 0) {
	$GLOBALS['calls']['messages'][] = $id;
}

function get_template_account($user_id) {
	return 0;
}

function api_plugin_hook_function($name, $args = '') {
	return $args;
}

function db_fetch_cell_prepared($sql, $params = array()) {
	if (strpos($sql, 'user_domains') !== false) {
		return 0;
	}

	foreach ($GLOBALS['users'] as $id => $username) {
		if (in_array($username, $params, true) || in_array((string) $id, $params, true)) {
			return (string) $id;
		}
	}

	return false;
}

function db_execute_prepared($sql, $params = array()) {
	if (strpos($sql, 'DELETE FROM user_auth WHERE') !== false) {
		$GLOBALS['calls']['deleted'][] = $params[0];
	}

	return true;
}

PHP;

	$source .= cacti_test_function_source($auth, 'is_template_account') . "\n\n";
	$source .= cacti_test_function_source($func, 'get_guest_account') . "\n\n";
	$source .= cacti_test_function_source($auth, 'user_remove') . "\n\n";
	$source .= "user_remove(\$scenario['user_id']);\n";

	return cacti_test_run_php_source($source, array('user_id' => $user_id, 'request' => $request));
}

test('a matching username in the request does not unlock the user template', function () {
	$result = user_remove_run('5', array('username' => 'template'));

	expect($result['deleted'])->toBe(array())
		->and($result['messages'])->toBe(array(21));
});

test('a matching username in the request does not unlock the guest account', function () {
	$result = user_remove_run('6', array('username' => 'guest'));

	expect($result['deleted'])->toBe(array())
		->and($result['messages'])->toBe(array(21));
});

test('template and guest accounts are refused without a username in the request', function () {
	expect(user_remove_run('5')['deleted'])->toBe(array())
		->and(user_remove_run('6')['deleted'])->toBe(array());
});

test('an ordinary user is still removed', function () {
	$plain   = user_remove_run('7');
	$matched = user_remove_run('7', array('username' => 'alice'));

	expect($plain['deleted'])->toBe(array('7'))
		->and($plain['messages'])->toBe(array())
		->and($matched['deleted'])->toBe(array('7'));
});
