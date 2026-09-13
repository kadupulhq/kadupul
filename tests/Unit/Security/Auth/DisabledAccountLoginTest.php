<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * A disabled account must not get a session from any entry point: the
 * remember-me cookie, Web Basic authentication, or the shared transition.
 * Disabling an account from the edit form must revoke what it already holds.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function disabled_account_user(array $override = array()) : array {
	return $override + array(
		'id'                   => 42,
		'username'             => 'alice',
		'realm'                => 0,
		'enabled'              => 'on',
		'locked'               => '',
		'password'             => '',
		'must_change_password' => '',
		'password_change'      => 'on',
	);
}

function disabled_account_cookie_scenario(string $enabled) : array {
	return array(
		'config' => array('auth_method' => 1, 'auth_cache_enabled' => 'on'),
		'users'  => array(disabled_account_user(array('enabled' => $enabled))),
		'cache'  => array(array('user_id' => 42, 'token' => hash('sha512', 'valid-token'), 'hostname' => '192.0.2.10')),
		'cookie' => '42,-1,valid-token',
	);
}

function disabled_account_basic_scenario(array $user) : array {
	return array(
		'config' => array('auth_method' => 2),
		'users'  => array(disabled_account_user($user + array('realm' => 2))),
		'server' => array('REMOTE_USER' => 'alice'),
	);
}

function disabled_account_executed(array $result, string $needle) : array {
	return array_values(array_filter($result['executed'], function (array $row) use ($needle) : bool {
		return strpos($row['sql'], $needle) !== false;
	}));
}

test('a remember-me cookie does not restore a session for a disabled account', function () {
	$result = cacti_test_run_auth_entry_probe(disabled_account_cookie_scenario(''));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('login_page')
		->and($result['events'])->not->toContain('cookie_set')
		->and(disabled_account_executed($result, 'INSERT INTO user_auth_cache'))->toBe(array());
});

test('a remember-me cookie still restores a session for an enabled account', function () {
	$result = cacti_test_run_auth_entry_probe(disabled_account_cookie_scenario('on'));

	expect($result['session']['sess_user_id'] ?? null)->toBe(42)
		->and($result['events'])->toContain('cookie_set')
		->and($result['page_continued'])->toBeTrue();
});

test('check_auth_cookie returns false for a disabled account', function () {
	$scenario         = disabled_account_cookie_scenario('');
	$scenario['call'] = array('type' => 'check_auth_cookie');

	expect(cacti_test_run_auth_entry_probe($scenario)['return'])->toBeFalse();
});

test('Web Basic authentication refuses a disabled account and stops the request', function () {
	$result = cacti_test_run_auth_entry_probe(disabled_account_basic_scenario(array('enabled' => '')));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('error_page')
		->and($result['page_continued'])->toBeFalse();
});

test('Web Basic authentication refusing a locked account stops the request', function () {
	$result = cacti_test_run_auth_entry_probe(disabled_account_basic_scenario(array('locked' => 'on')));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['page_continued'])->toBeFalse();
});

test('Web Basic authentication still logs in an enabled account', function () {
	$result = cacti_test_run_auth_entry_probe(disabled_account_basic_scenario(array()));

	expect($result['session']['sess_user_id'] ?? null)->toBe(42)
		->and($result['page_continued'])->toBeTrue();
});

test('cacti_auth_transition refuses disabled and unknown accounts', function () {
	$cases = array(
		array('users' => array(disabled_account_user(array('enabled' => ''))), 'expect' => false),
		array('users' => array(), 'expect' => false),
		array('users' => array(disabled_account_user(array('locked' => 'on'))), 'expect' => false),
		array('users' => array(disabled_account_user()), 'expect' => true),
	);

	foreach ($cases as $case) {
		$result = cacti_test_run_auth_entry_probe(array(
			'users' => $case['users'],
			'call'  => array('type' => 'cacti_auth_transition', 'args' => array(42, 'login')),
		));

		expect($result['return'])->toBe($case['expect']);
	}
});

/*
 * user_admin.php runs page code at file scope, so form_save() and the lib/auth.php
 * revocation helpers are lifted out and run in a child with request and
 * database stubs.
 */
function disabled_account_run_user_save(array $request) : array {
	$root   = dirname(__DIR__, 4);
	$admin  = file_get_contents($root . '/user_admin.php');
	$auth   = file_get_contents($root . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$GLOBALS['req']      = $scenario['request'];
$GLOBALS['executed'] = array();
$GLOBALS['saved']    = array();
$_SESSION            = array('sess_user_id' => 1);

function isset_request_var($name) {
	return isset($GLOBALS['req'][$name]);
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['req'][$name] ?? $default;
}

function get_filter_request_var($name, $filter = 0, $options = array()) {
	return $GLOBALS['req'][$name] ?? '';
}

function get_request_var($name, $default = '') {
	return $GLOBALS['req'][$name] ?? $default;
}

function is_error_message() {
	return false;
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function raise_message($id, $message = '', $level = 0) {
}

function form_input_validate($field_value, $field_name, $regexp_match, $allow_nulls, $custom_message = 3) {
	return $field_value;
}

function input_validate_input_number($value, $variable = '') {
}

function compat_password_hash($password, $algo, $options = array()) {
	return 'hashed';
}

function read_config_option($name, $force = false) {
	return $name == 'admin_user' ? 1 : '';
}

function is_template_account($user_id) {
	return false;
}

function api_plugin_hook_function($name, $parm = null) {
	return $parm;
}

function api_plugin_hook($name) {
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return '';
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array();
}

function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['executed'][] = array('sql' => trim(preg_replace('/\s+/', ' ', $sql)), 'params' => $params);

	return true;
}

function sql_save($save, $table) {
	$GLOBALS['saved'][] = $save;

	return $save['id'];
}

function kill_session_var($name) {
	unset($_SESSION[$name]);
}

PHP;

	$source .= cacti_test_function_source($admin, 'form_save') . "\n\n";
	$source .= cacti_test_function_source($auth, 'user_disable') . "\n\n";
	$source .= cacti_test_function_source($auth, 'reset_user_perms') . "\n\n";
	$source .= "form_save();\n";
	$source .= "print json_encode(array('executed' => \$GLOBALS['executed'], 'saved' => \$GLOBALS['saved']));\n";

	return cacti_test_run_php_source($source, array('request' => $request + array(
		'save_component_user' => 1,
		'id'                  => '7',
		'username'            => 'bob',
		'realm'               => '0',
		'password'            => '',
		'password_confirm'    => '',
		'login_opts'          => '1',
	)));
}

test('disabling an account from the edit form revokes its tokens and sessions', function () {
	$result = disabled_account_run_user_save(array('enabled' => ''));

	expect($result['saved'][0]['enabled'])->toBe('')
		->and(disabled_account_executed($result, 'DELETE FROM user_auth_cache WHERE user_id = ?'))->toContain(array('sql' => 'DELETE FROM user_auth_cache WHERE user_id = ?', 'params' => array('7')))
		->and(disabled_account_executed($result, 'DELETE FROM sessions WHERE user_id = ?'))->toContain(array('sql' => 'DELETE FROM sessions WHERE user_id = ?', 'params' => array('7')));
});

test('saving an enabled account from the edit form keeps its sessions', function () {
	$result = disabled_account_run_user_save(array('enabled' => 'on'));

	expect($result['saved'][0]['enabled'])->toBe('on')
		->and(disabled_account_executed($result, 'DELETE FROM sessions'))->toBe(array());
});
