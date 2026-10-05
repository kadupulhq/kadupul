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
 * When the template account a new login would be copied from is missing,
 * auth_login_create_user_from_template() refuses the login. Under Web Basic
 * authentication it must show the custom error page and stop, as every other
 * Basic failure does; it read an undefined $auth_method, so it never did.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function template_user_missing_run(int $auth_method, bool $template_exists) : array {
	$auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$GLOBALS['calls'] = array('custom_error' => array(), 'copied' => array());
$GLOBALS['user']  = null;

$error     = false;
$error_msg = '';

register_shutdown_function(function () {
	print json_encode(array(
		'user'   => $GLOBALS['user'],
		'error'  => $GLOBALS['error'],
		'calls'  => $GLOBALS['calls'],
	));
});

function read_config_option($name, $force = false) {
	$config = array('auth_method' => $GLOBALS['scenario']['auth_method'], 'user_template' => 9);

	return $config[$name] ?? '';
}

function get_template_account($username = '') {
	return 9;
}

function db_fetch_row_prepared($sql, $params = array()) {
	if (strpos($sql, 'WHERE id = ?') !== false) {
		return $GLOBALS['scenario']['template_exists'] ? array('id' => 9, 'username' => 'template', 'realm' => 0) : array();
	}

	return array('id' => 50, 'username' => $params[0], 'realm' => $params[1]);
}

function user_copy($template, $username, $template_realm, $realm, $overwrite = false, $data_override = array()) {
	$GLOBALS['calls']['copied'][] = $username;
}

function auth_display_custom_error_message($message) {
	$GLOBALS['calls']['custom_error'][] = $message;
}

function cacti_log(...$args) {
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

PHP;

	$source .= cacti_test_function_source($auth, 'auth_log_username') . "\n\n";
	$source .= cacti_test_function_source($auth, 'auth_login_create_user_from_template') . "\n\n";
	$source .= "\$GLOBALS['user'] = auth_login_create_user_from_template('alice', 2);\n";

	return cacti_test_run_php_source($source, array('auth_method' => $auth_method, 'template_exists' => $template_exists));
}

test('a Basic login with no template account shows the custom error page and stops', function () {
	$result = template_user_missing_run(2, false);

	expect($result['calls']['custom_error'])->toHaveCount(1)
		->and($result['calls']['custom_error'][0])->toContain('Template user id 9 does not exist')
		->and($result['user'])->toBeNull()
		->and($result['error'])->toBeTrue();
});

test('a form login with no template account returns the error to the login page', function () {
	$result = template_user_missing_run(1, false);

	expect($result['calls']['custom_error'])->toBe(array())
		->and($result['user'])->toBe(array())
		->and($result['error'])->toBeTrue();
});

test('a Basic login with a template account is copied and returned', function () {
	$result = template_user_missing_run(2, true);

	expect($result['calls']['custom_error'])->toBe(array())
		->and($result['calls']['copied'])->toBe(array('alice'))
		->and($result['user']['id'] ?? null)->toBe(50)
		->and($result['error'])->toBeFalse();
});
