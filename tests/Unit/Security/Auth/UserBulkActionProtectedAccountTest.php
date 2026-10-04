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
 * The User Management bulk actions must not delete, disable or overwrite the
 * account in use or the admin_user account, or an operator can lock every
 * administrator out. user_admin.php runs page code at file scope, so
 * form_actions() is lifted out and run in a child with request, session and
 * account helpers stubbed.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function user_bulk_action_run(string $action, array $selected, string $admin_user = '1', array $extra = array()) : array {
	$admin = file_get_contents(dirname(__DIR__, 4) . '/user_admin.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

define('MESSAGE_LEVEL_ERROR', 3);

$GLOBALS['req']   = $scenario['request'];
$GLOBALS['calls'] = array('remove' => array(), 'disable' => array(), 'enable' => array(), 'copy' => array(), 'messages' => array());
$_SESSION         = array('sess_user_id' => 2);
$user_actions     = array();
$auth_realms      = array();

register_shutdown_function(function () {
	print json_encode($GLOBALS['calls']);
});

function isset_request_var($name) {
	return isset($GLOBALS['req'][$name]);
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['req'][$name] ?? $default;
}

function get_filter_request_var($name, $filter = 0, $options = array()) {
	return $GLOBALS['req'][$name] ?? '';
}

function sanitize_unserialize_selected_items($items) {
	return json_decode($items, true);
}

function input_validate_input_number($value, $variable = '') {
}

function read_config_option($name, $force = false) {
	return $name == 'admin_user' ? $GLOBALS['scenario']['admin_user'] : '';
}

function cacti_count($array) {
	return count($array);
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function __($text, ...$args) {
	return $text;
}

function raise_message($id, $message = '', $level = 0) {
	$GLOBALS['calls']['messages'][] = $id;
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array('username' => 'user' . $params[0], 'realm' => 0);
}

function user_remove($id) {
	$GLOBALS['calls']['remove'][] = $id;
}

function user_disable($id) {
	$GLOBALS['calls']['disable'][] = $id;
}

function user_enable($id) {
	$GLOBALS['calls']['enable'][] = $id;
}

function user_copy($template, $username, $template_realm, $realm, $overwrite = false, $data_override = array()) {
	$GLOBALS['calls']['copy'][] = $username;

	return true;
}

PHP;

	$source .= cacti_test_function_source($admin, 'form_actions') . "\n\n";
	$source .= "form_actions();\n";

	return cacti_test_run_php_source($source, array('admin_user' => $admin_user, 'request' => $extra + array(
		'drp_action'     => $action,
		'selected_items' => json_encode($selected),
	)));
}

test('bulk delete skips the primary administrator and the current account', function () {
	$result = user_bulk_action_run('1', array('1', '2', '7'));

	expect($result['remove'])->toBe(array('7'))
		->and($result['messages'])->toBe(array('attempt admin', 'attempt current'));
});

test('bulk disable skips the primary administrator and the current account', function () {
	$result = user_bulk_action_run('4', array('7', '1', '2'));

	expect($result['disable'])->toBe(array('7'))
		->and($result['messages'])->toBe(array('attempt admin', 'attempt current'));
});

test('batch copy refuses the whole selection when it includes the primary administrator', function () {
	$result = user_bulk_action_run('5', array('7', '1'), '1', array('template_user' => '9'));

	expect($result['copy'])->toBe(array())
		->and($result['messages'])->toBe(array('attempt protected'));
});

test('batch copy refuses the whole selection when it includes the current account', function () {
	$result = user_bulk_action_run('5', array('7', '2'), '1', array('template_user' => '9'));

	expect($result['copy'])->toBe(array())
		->and($result['messages'])->toBe(array('attempt protected'));
});

test('batch copy still overwrites ordinary accounts', function () {
	$result = user_bulk_action_run('5', array('7', '8'), '1', array('template_user' => '9'));

	expect($result['copy'])->toBe(array('user7', 'user8'))
		->and($result['messages'])->toBe(array(1));
});

test('bulk actions act on every ordinary account when admin_user is unset', function () {
	$delete  = user_bulk_action_run('1', array('1', '7'), '');
	$disable = user_bulk_action_run('4', array('1', '7'), '');

	expect($delete['remove'])->toBe(array('1', '7'))
		->and($disable['disable'])->toBe(array('1', '7'))
		->and($delete['messages'])->toBe(array());
});

test('bulk enable is unchanged for protected accounts', function () {
	$result = user_bulk_action_run('3', array('1', '2'));

	expect($result['enable'])->toBe(array('1', '2'))
		->and($result['messages'])->toBe(array());
});
