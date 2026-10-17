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
 * The Primary Admin setting is concatenated into the Guest User and User
 * Template dropdown queries as an id list.  The shipped
 * include/global_settings.php runs in a child process with the database
 * stubbed, so the assertions are made on the SQL text it would execute.  The
 * save side runs the shipped settings.php against those same settings.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function admin_account_prelude() : string {
	$root = dirname(__DIR__, 4);

	return "<?php\n\$scenario = json_decode(stream_get_contents(STDIN), true);\n\$GLOBALS['scenario'] = \$scenario;\n\$root = " . var_export($root, true) . ";\n" . <<<'PHP'
$config = array('base_path' => $root, 'library_path' => $root . '/lib', 'include_path' => $root . '/include', 'cacti_server_os' => 'unix', 'is_web' => false, 'poller_id' => 1, 'url_path' => '/');

$_SESSION            = $scenario['session'];
$GLOBALS['queries']  = array();
$GLOBALS['writes']   = array();
$GLOBALS['messages'] = array();

/* global_settings.php reads option arrays that global_arrays.php would define */
set_error_handler(function ($errno, $errstr) {
	return strpos($errstr, 'Undefined variable $') === 0;
}, E_WARNING);

function __($text, ...$args) {
	return $args ? vsprintf($text, $args) : $text;
}

function __x($context, $text, ...$args) {
	return __($text, ...$args);
}

function __n($singular, $plural, $number) {
	return $number == 1 ? $singular : $plural;
}

function __esc($text, ...$args) {
	return htmlspecialchars(__($text, ...$args), ENT_QUOTES);
}

function db_table_exists($table) {
	return false;
}

function db_fetch_cell($sql) {
	$GLOBALS['queries'][] = $sql;

	return $GLOBALS['scenario']['admin_user'];
}

function db_qstr($value) {
	return "'" . addslashes((string) $value) . "'";
}

function read_config_option($name, $force = false) {
	return $name == 'poller_interval' ? 300 : '';
}

function is_realm_allowed($realm) {
	return true;
}

function api_plugin_hook($name) {
}

function api_plugin_hook_function($name, $args = '') {
	return $args;
}

function get_installed_locales() {
	return array('en-US' => 'English');
}

function get_new_user_default_language() {
	return 'en-US';
}

require $root . '/include/global_constants.php';

$user_auth_realm_filenames = array();
$no_http_header_files      = array();

require $root . '/include/global_settings.php';

restore_error_handler();
PHP;
}

/**
 * @param string|false         $admin_user the stored settings value, false when the row is absent
 * @param array<string, mixed> $session
 *
 * @return array<string, mixed>
 */
function admin_account_run($admin_user, array $session) : array {
	$source = admin_account_prelude() . <<<'PHP'

print json_encode(array(
	'guest'    => $settings['authentication']['guest_user']['sql'],
	'template' => $settings['authentication']['user_template']['sql'],
	'session'  => $_SESSION['admin_account'] ?? null,
	'queries'  => $GLOBALS['queries'],
));
PHP;

	return cacti_test_run_php_source($source, array('admin_user' => $admin_user, 'session' => $session));
}

/**
 * Save the Authentication tab through the shipped settings.php.
 *
 * @return array<string, mixed>
 */
function admin_account_save(string $posted) : array {
	$stubs = sys_get_temp_dir() . '/admin-account-' . bin2hex(random_bytes(6));

	mkdir($stubs . '/include', 0700, true);
	mkdir($stubs . '/lib', 0700, true);
	file_put_contents($stubs . '/include/auth.php', "<?php\n");
	file_put_contents($stubs . '/lib/poller.php', "<?php\n");

	$source = admin_account_prelude() . <<<'PHP'

$disable_log_rotation = false;
$local_db_cnn_id      = false;

function set_default_action($default = '') {
}

function get_request_var($name, $default = '') {
	return $GLOBALS['scenario']['request'][$name] ?? $default;
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return $GLOBALS['scenario']['request'][$name] ?? '';
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['scenario']['request'][$name] ?? $default;
}

function isset_request_var($name) {
	return isset($GLOBALS['scenario']['request'][$name]);
}

function isempty_request_var($name) {
	return empty($GLOBALS['scenario']['request'][$name]);
}

function raise_message($id, $text = '', $level = 0) {
	$GLOBALS['messages'][] = $id;
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function ldap_bind_password_reentry_required($saved, $submitted, $password, $entered) {
	return false;
}

function db_execute_prepared($sql, $params = array(), $log = true, $conn = false) {
	$GLOBALS['writes'][$params[0]] = $params[1] ?? '';

	return true;
}

function db_execute($sql) {
	return true;
}

function db_fetch_assoc($sql) {
	return array();
}

function array_rekey($array, $key, $value) {
	return array();
}

function is_remote_path_setting($name) {
	return false;
}

function kill_session_var($name) {
}

function snmpagent_global_settings_update() {
}

register_shutdown_function(function () {
	ob_end_clean();

	print json_encode(array(
		'writes'   => $GLOBALS['writes'],
		'messages' => $GLOBALS['messages'],
		'errors'   => $_SESSION['sess_error_fields'] ?? array(),
	));
});

ob_start();
PHP;

	$source .= "\nchdir(" . var_export($stubs, true) . ");\ninclude " . var_export(dirname(__DIR__, 4) . '/settings.php', true) . ";\n";

	try {
		return cacti_test_run_php_source($source, array(
			'admin_user' => '1',
			'session'    => array(),
			'request'    => array('action' => 'save', 'tab' => 'authentication', 'admin_user' => $posted),
		));
	} finally {
		unlink($stubs . '/include/auth.php');
		unlink($stubs . '/lib/poller.php');
		rmdir($stubs . '/include');
		rmdir($stubs . '/lib');
		rmdir($stubs);
	}
}

/** @return string the id list inside NOT IN, or the whole statement when it does not match */
function admin_account_list(string $sql) : string {
	$prefix = 'SELECT id AS id, username AS name FROM user_auth WHERE realm = 0 AND id NOT IN (';
	$suffix = ') ORDER BY username';

	if (strpos($sql, $prefix) !== 0 || substr($sql, -strlen($suffix)) !== $suffix) {
		return $sql;
	}

	return substr($sql, strlen($prefix), -strlen($suffix));
}

dataset('injected admin_user values', array(
	'union'      => array('1) UNION SELECT password FROM user_auth WHERE id IN (1'),
	'or'         => array('1 OR 1=1'),
	'quote'      => array("'"),
	'hex'        => array('0x41'),
	'exponent'   => array('1e3'),
	'space'      => array(' 7'),
	'newline'    => array("7\n"),
	'negative'   => array('-5'),
	'empty'      => array(''),
	'zero'       => array('0'),
	'absent row' => array(false),
));

test('the first request of a session lists only integer ids', function ($admin_user) {
	$result = admin_account_run($admin_user, array('sess_user_id' => 3));

	expect(admin_account_list($result['guest']))->toBe('3')
		->and(admin_account_list($result['template']))->toBe('3')
		->and($result['session'])->toBe('3');
})->with('injected admin_user values');

test('a later request with a quoted session value from an earlier release is rebuilt', function ($admin_user) {
	$old = "'" . addslashes((string) $admin_user) . "', 3";

	$result = admin_account_run($admin_user, array('sess_user_id' => 3, 'admin_account' => $old));

	expect(admin_account_list($result['guest']))->toBe('3')
		->and(admin_account_list($result['template']))->toBe('3')
		->and($result['session'])->toBe('3');
})->with('injected admin_user values');

test('a stored number too large for an id stays a digit list', function () {
	$result = admin_account_run('99999999999999999999', array('sess_user_id' => 3));

	expect(admin_account_list($result['guest']))->toMatch('/^[0-9]+(, [0-9]+)*$/D')
		->and(admin_account_list($result['guest']))->toEndWith(', 3');
});

test('a valid Primary Admin and the session user are both excluded', function () {
	$first = admin_account_run('7', array('sess_user_id' => 3));

	expect(admin_account_list($first['guest']))->toBe('7, 3')
		->and(admin_account_list($first['template']))->toBe('7, 3')
		->and($first['session'])->toBe('7, 3');

	$later = admin_account_run('99', array('sess_user_id' => 3, 'admin_account' => '7, 3'));

	expect(admin_account_list($later['guest']))->toBe('7, 3')
		->and($later['queries'])->toBe(array());

	$upgraded = admin_account_run('7', array('sess_user_id' => 3, 'admin_account' => "'7', 3"));

	expect(admin_account_list($upgraded['guest']))->toBe('7, 3')
		->and($upgraded['session'])->toBe('7, 3');
});

test('a tampered session value never reaches the query', function () {
	$result = admin_account_run('7', array('sess_user_id' => 3, 'admin_account' => '3) OR (1'));

	expect(admin_account_list($result['guest']))->toBe('7, 3');
});

test('without a session the lists keep the 0 default and read nothing', function () {
	$result = admin_account_run('1) OR (1', array());

	expect(admin_account_list($result['guest']))->toBe('0')
		->and($result['queries'])->toBe(array())
		->and($result['session'])->toBeNull();
});

test('a non-integer Primary Admin is not saved', function ($posted) {
	$result = admin_account_save($posted);

	expect($result['writes'])->not->toHaveKey('admin_user')
		->and($result['errors'])->toHaveKey('admin_user')
		->and($result['messages'])->toContain(3);
})->with(array(
	'union'    => array('1) UNION SELECT password FROM user_auth WHERE id IN (1'),
	'or'       => array('1 OR 1=1'),
	'quote'    => array("'"),
	'hex'      => array('0x41'),
	'exponent' => array('1e3'),
	'space'    => array(' 7'),
	'newline'  => array("7\n"),
	'negative' => array('-5'),
	'huge'     => array('99999999999999999999'),
));

test('a user id, No User and an empty value still save', function ($posted) {
	$result = admin_account_save($posted);

	expect($result['writes'])->toHaveKey('admin_user')
		->and($result['writes']['admin_user'])->toBe($posted)
		->and($result['errors'])->toBe(array())
		->and($result['messages'])->toBe(array(1));
})->with(array(
	'id'      => array('7'),
	'no user' => array('0'),
	'empty'   => array(''),
));
