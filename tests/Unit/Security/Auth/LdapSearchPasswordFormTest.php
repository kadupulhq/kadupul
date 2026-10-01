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
 * The LDAP search password is never rendered into the Authentication settings
 * page or the User Domains edit page. A blank field keeps the saved password, a
 * typed one replaces it, and changing the server, a port or the encryption
 * without typing it again is refused, so a saved password cannot be sent to a
 * server it was never entered for (the pattern of Zabbix CVE-2025-27231).
 *
 * settings.php runs whole in a child process, with ./include/auth.php resolved
 * to a stub; the user_domains.php handlers run as extracted functions. Both
 * render through the shipped draw_edit_control() and form_text_box().
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

const LDAP_FORM_SECRET = 'S3arch-Pa55-in-db';

function ldap_form_shared_source() : string {
	$root = dirname(__DIR__, 4);
	$html = file_get_contents($root . '/lib/html_form.php');
	$auth = file_get_contents($root . '/lib/auth.php');

	$source = test_php_function_source($html, 'draw_edit_control') . "\n\n" .
		test_php_function_source($html, 'form_text_box') . "\n\n";

	if (strpos($auth, "\nfunction ldap_bind_password_reentry_required(") !== false) {
		$source .= test_php_function_source($auth, 'ldap_bind_password_reentry_required') . "\n\n";
	}

	return $source;
}

function ldap_form_stub_source() : string {
	return <<<'PHP'
define('MESSAGE_LEVEL_NONE', 0);
define('MESSAGE_LEVEL_INFO', 1);
define('MESSAGE_LEVEL_WARN', 2);
define('MESSAGE_LEVEL_ERROR', 3);

$GLOBALS['writes']   = array();
$GLOBALS['messages'] = array();
$_SESSION            = array();

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function __esc($text, ...$args) {
	return htmlspecialchars(vsprintf($text, $args), ENT_QUOTES);
}

function html_escape($text) {
	return htmlspecialchars((string) $text, ENT_QUOTES);
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function read_config_option($name, $force = false) {
	return $GLOBALS['scenario']['settings'][$name] ?? ($name == 'poller_interval' ? 300 : '');
}

function config_value_exists($name) {
	return isset($GLOBALS['scenario']['settings'][$name]);
}

function set_default_action($default = '') {
	if (!isset($GLOBALS['scenario']['request']['action'])) {
		$GLOBALS['scenario']['request']['action'] = $default;
	}
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

function set_request_var($name, $value) {
	$GLOBALS['scenario']['request'][$name] = $value;
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

function is_error_message() {
	return false;
}

/* like the real validator, every value is retained for a redisplay after an error */
function form_input_validate($value, $name, $regex, $allow_empty, $error) {
	$_SESSION['sess_field_values'][$name] = $value;

	return $value;
}

function top_header() {
}

function bottom_footer() {
}

function form_start($action, $id = '') {
}

function html_start_box($title, $width, $div, $cell, $align, $add) {
}

function html_end_box($trailing = true, $div = false) {
}

function form_hidden_box($name, $value, $default, $in_form = false) {
}

function form_save_button($cancel, $action = 'save') {
}

/* text and password controls render through the shipped code; others print their value */
function draw_edit_form($array) {
	foreach ($array['fields'] as $name => $field) {
		if (in_array($field['method'], array('textbox', 'textbox_password'), true)) {
			draw_edit_control($name, $field);
		} elseif (isset($field['value'])) {
			print "<span id='$name'>" . htmlspecialchars((string) $field['value'], ENT_QUOTES) . '</span>';
		}
	}
}

class CactiSecureHeaders {
	public static function getNonceAttribute() {
		return '';
	}
}

register_shutdown_function(function () {
	$html = ob_get_clean();

	print json_encode(array(
		'html'     => $html,
		'writes'   => $GLOBALS['writes'],
		'messages' => $GLOBALS['messages'],
		'retained' => $_SESSION['sess_field_values'] ?? array(),
	));
});

ob_start();
PHP;
}

/**
 * @param array<string, mixed> $scenario
 *
 * @return array<string, mixed>
 */
function ldap_form_settings_run(array $scenario) : array {
	$root  = dirname(__DIR__, 4);
	$stubs = sys_get_temp_dir() . '/ldap-form-' . bin2hex(random_bytes(6));

	mkdir($stubs . '/include', 0700, true);
	mkdir($stubs . '/lib', 0700, true);
	file_put_contents($stubs . '/include/auth.php', "<?php\n");
	file_put_contents($stubs . '/lib/poller.php', "<?php\n");

	$source = "<?php\n\$scenario = json_decode(stream_get_contents(STDIN), true);\n\$GLOBALS['scenario'] = \$scenario;\n" .
		ldap_form_stub_source() . "\n" . ldap_form_shared_source() . <<<'PHP'

$settings = array('authentication' => array(
	'ldap_general_header'    => array('friendly_name' => 'LDAP General Settings', 'method' => 'spacer'),
	'ldap_server'            => array('friendly_name' => 'Server(s)', 'method' => 'textbox', 'max_length' => '255'),
	'ldap_port'              => array('friendly_name' => 'Port Standard', 'method' => 'textbox', 'max_length' => '5', 'default' => '389'),
	'ldap_port_ssl'          => array('friendly_name' => 'Port SSL', 'method' => 'textbox', 'max_length' => '5', 'default' => '636'),
	'ldap_encryption'        => array('friendly_name' => 'Encryption', 'method' => 'drop_array', 'default' => '0', 'array' => array('0' => 'None', '1' => 'LDAPS', '2' => 'LDAP + TLS')),
	'ldap_mode'              => array('friendly_name' => 'Mode', 'method' => 'drop_array', 'default' => '0', 'array' => array('0' => 'No Searching', '1' => 'Anonymous', '2' => 'Specific')),
	'ldap_specific_dn'       => array('friendly_name' => 'Search Distinguished Name (DN)', 'method' => 'textbox', 'max_length' => '255'),
	'ldap_specific_password' => array('friendly_name' => 'Search Password', 'method' => 'textbox_password', 'max_length' => '255'),
));
$tabs                 = array('authentication' => 'Authentication');
$config               = array('poller_id' => 1);
$disable_log_rotation = false;
$local_db_cnn_id      = false;

function db_fetch_cell_prepared($sql, $params = array(), $col = '', $log = true, $conn = false) {
	return $GLOBALS['scenario']['settings'][$params[0]] ?? '';
}

function db_fetch_cell($sql) {
	return 1;
}

function db_fetch_assoc($sql) {
	return array();
}

function db_execute_prepared($sql, $params = array(), $log = true, $conn = false) {
	$GLOBALS['writes'][$params[0]] = $params[1] ?? '';

	return true;
}

function db_execute($sql) {
	return true;
}

function db_qstr($value) {
	return "'" . addslashes((string) $value) . "'";
}

function array_rekey($array, $key, $value) {
	return array();
}

function is_remote_path_setting($name) {
	return false;
}

function set_config_option($name, $value) {
}

function kill_session_var($name) {
}

function snmpagent_global_settings_update() {
}

function api_plugin_hook_function($name, $args = '') {
	return $args;
}

function api_plugin_hook($name) {
}

PHP;

	$source .= "\nchdir(" . var_export($stubs, true) . ");\ninclude " . var_export($root . '/settings.php', true) . ";\n";

	try {
		return cacti_test_run_php_source($source, $scenario);
	} finally {
		unlink($stubs . '/include/auth.php');
		unlink($stubs . '/lib/poller.php');
		rmdir($stubs . '/include');
		rmdir($stubs . '/lib');
		rmdir($stubs);
	}
}

/**
 * @return array<string, string>
 */
function ldap_form_saved_settings() : array {
	return array(
		'ldap_server'            => 'ldap1.example.com ldap2.example.com',
		'ldap_port'              => '389',
		'ldap_port_ssl'          => '636',
		'ldap_encryption'        => '2',
		'ldap_mode'              => '2',
		'ldap_specific_dn'       => 'cn=search,dc=example,dc=com',
		'ldap_specific_password' => LDAP_FORM_SECRET,
	);
}

/**
 * The form as a browser posts it back unchanged, with the password fields blank.
 *
 * @return array<string, string>
 */
function ldap_form_settings_post(array $changes = array()) : array {
	$post = ldap_form_saved_settings();

	$post['ldap_specific_password']         = '';
	$post['ldap_specific_password_confirm'] = '';

	return array_merge(array('action' => 'save', 'tab' => 'authentication'), $post, $changes);
}

/**
 * @param array<string, mixed> $request
 * @param array<string, mixed> $row     the saved user_domains_ldap row
 *
 * @return array<string, mixed>
 */
function ldap_form_domain_run(string $call, array $request, array $row) : array {
	$root   = dirname(__DIR__, 4);
	$page   = file_get_contents($root . '/user_domains.php');
	$utils  = file_get_contents($root . '/lib/html_utility.php');
	$source = "<?php\n\$scenario = json_decode(stream_get_contents(STDIN), true);\n\$GLOBALS['scenario'] = \$scenario;\n" .
		ldap_form_stub_source() . "\n" . ldap_form_shared_source() .
		test_php_function_source($utils, 'inject_form_variables') . "\n\n";

	foreach (array('domain_template_user_valid', 'domain_ldap_target', 'form_save', 'domain_edit') as $name) {
		if (strpos($page, "\nfunction $name(") !== false) {
			$source .= test_php_function_source($page, $name) . "\n\n";
		}
	}

	$source .= <<<'PHP'
$registered_cacti_names = array();
$ldap_versions   = array('2' => 'Version 2', '3' => 'Version 3');
$ldap_encryption = array('0' => 'None', '1' => 'LDAPS', '2' => 'LDAP + TLS');
$ldap_modes      = array('0' => 'No Searching', '1' => 'Anonymous', '2' => 'Specific');
$domain_types    = array('1' => 'Builtin', '2' => 'LDAP or AD');

function db_fetch_row_prepared($sql, $params = array()) {
	if (strpos($sql, 'user_domains_ldap') !== false) {
		return $GLOBALS['scenario']['row'];
	}

	return array('domain_id' => 1, 'domain_name' => 'Example', 'type' => 2, 'user_id' => 0, 'enabled' => 'on');
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return 1;
}

function db_execute_prepared($sql, $params = array()) {
	return true;
}

function sql_save($save, $table, $key = 'id', $autoinc = true) {
	$GLOBALS['writes'][$table] = $save;

	return 1;
}

PHP;

	$source .= $call . ";\n";

	return cacti_test_run_php_source($source, array('request' => $request, 'row' => $row, 'settings' => ldap_form_saved_settings()));
}

/**
 * @return array<string, mixed>
 */
function ldap_form_domain_row(array $changes = array()) : array {
	return array_merge(array(
		'domain_id'         => 1,
		'server'            => 'dc1.example.com',
		'port'              => '389',
		'port_ssl'          => '636',
		'proto_version'     => '3',
		'encryption'        => '2',
		'referrals'         => '0',
		'mode'              => '2',
		'dn'                => '<username>@example.com',
		'group_require'     => '',
		'group_dn'          => '',
		'group_attrib'      => '',
		'group_member_type' => '1',
		'search_base'       => 'dc=example,dc=com',
		'search_filter'     => '(sAMAccountName=<username>)',
		'specific_dn'       => 'cn=svc,dc=example,dc=com',
		'specific_password' => LDAP_FORM_SECRET,
		'cn_full_name'      => 'displayName',
		'cn_email'          => 'mail',
	), $changes);
}

/**
 * The domain form as a browser posts it back unchanged, with the password fields blank.
 *
 * @return array<string, mixed>
 */
function ldap_form_domain_post(array $changes = array()) : array {
	$post = ldap_form_domain_row();

	unset($post['group_require']);

	$post['specific_password']         = '';
	$post['specific_password_confirm'] = '';

	return array_merge(array('save_component_domain_ldap' => 1, 'domain_id' => 1, 'type' => 2, 'user_id' => 0, 'domain_name' => 'Example', 'enabled' => 'on'), $post, $changes);
}

test('the Authentication settings page does not render the saved search password', function () {
	$result = ldap_form_settings_run(array('request' => array('tab' => 'authentication'), 'settings' => ldap_form_saved_settings()));

	expect($result['html'])->toContain("name='ldap_specific_password'")
		->and($result['html'])->toContain("name='ldap_specific_password_confirm'")
		->and($result['html'])->toContain("value='ldap1.example.com ldap2.example.com'")
		->and($result['html'])->not->toContain(LDAP_FORM_SECRET);
});

test('saving the settings with a blank search password keeps the saved one', function () {
	$result = ldap_form_settings_run(array('request' => ldap_form_settings_post(), 'settings' => ldap_form_saved_settings()));

	expect($result['messages'])->toBe(array(1))
		->and($result['writes']['ldap_server'])->toBe('ldap1.example.com ldap2.example.com')
		->and($result['writes'])->not->toHaveKey('ldap_specific_password');
});

test('saving the settings with a new search password stores it', function () {
	$result = ldap_form_settings_run(array(
		'request'  => ldap_form_settings_post(array('ldap_specific_password' => 'new-pass', 'ldap_specific_password_confirm' => 'new-pass')),
		'settings' => ldap_form_saved_settings(),
	));

	expect($result['messages'])->toBe(array(1))
		->and($result['writes']['ldap_specific_password'])->toBe('new-pass');
});

test('changing the LDAP server, a port or the encryption needs the search password again', function () {
	$changes = array(
		'server'     => array('ldap_server' => 'rogue.example.net'),
		'port'       => array('ldap_port' => '3389'),
		'ssl port'   => array('ldap_port_ssl' => '1636'),
		'encryption' => array('ldap_encryption' => '0'),
	);

	foreach ($changes as $case => $change) {
		$refused = ldap_form_settings_run(array('request' => ldap_form_settings_post($change), 'settings' => ldap_form_saved_settings()));

		expect($refused['messages'])->toBe(array('ldap_password_reentry'), $case)
			->and($refused['writes'])->toBe(array(), $case);

		$typed = ldap_form_settings_run(array(
			'request'  => ldap_form_settings_post($change + array('ldap_specific_password' => 'retyped', 'ldap_specific_password_confirm' => 'retyped')),
			'settings' => ldap_form_saved_settings(),
		));

		expect($typed['messages'])->toBe(array(1), $case)
			->and($typed['writes']['ldap_specific_password'])->toBe('retyped', $case);
	}
});

test('a search password whose confirmation differs does not count as entered again', function () {
	$result = ldap_form_settings_run(array(
		'request'  => ldap_form_settings_post(array('ldap_server' => 'rogue.example.net', 'ldap_specific_password' => 'x', 'ldap_specific_password_confirm' => 'y')),
		'settings' => ldap_form_saved_settings(),
	));

	/* nothing is written, so the old password is never paired with the new server */
	expect($result['messages'])->toBe(array('ldap_password_reentry'))
		->and($result['writes'])->toBe(array());
});

test('a search password of 0, which the settings save never writes, does not count as entered again', function () {
	$result = ldap_form_settings_run(array(
		'request'  => ldap_form_settings_post(array('ldap_server' => 'rogue.example.net', 'ldap_specific_password' => '0', 'ldap_specific_password_confirm' => '0')),
		'settings' => ldap_form_saved_settings(),
	));

	expect($result['messages'])->toBe(array('ldap_password_reentry'))
		->and($result['writes'])->toBe(array());
});

test('settings without a saved search password save a new server as before', function () {
	$settings = ldap_form_saved_settings();
	$settings['ldap_specific_password'] = '';

	$result = ldap_form_settings_run(array('request' => ldap_form_settings_post(array('ldap_server' => 'ldap3.example.com')), 'settings' => $settings));

	expect($result['messages'])->toBe(array(1))
		->and($result['writes']['ldap_server'])->toBe('ldap3.example.com');
});

test('the User Domains edit page does not render the saved search password', function () {
	$result = ldap_form_domain_run('domain_edit()', array('domain_id' => 1), ldap_form_domain_row());

	expect($result['html'])->toContain("name='specific_password'")
		->and($result['html'])->toContain("value='dc1.example.com'")
		->and($result['html'])->not->toContain(LDAP_FORM_SECRET);
});

test('saving a domain with a blank search password keeps the saved one', function () {
	$result = ldap_form_domain_run('form_save()', ldap_form_domain_post(), ldap_form_domain_row());

	expect($result['messages'])->toBe(array(1, 1))
		->and($result['writes']['user_domains_ldap']['specific_password'])->toBe(LDAP_FORM_SECRET);
});

test('saving a domain with a blank search password does not retain the saved one for a redisplay', function () {
	$result = ldap_form_domain_run('form_save()', ldap_form_domain_post(), ldap_form_domain_row());

	expect($result['retained']['specific_password'] ?? '')->toBe('')
		->and(json_encode($result['retained']))->not->toContain(LDAP_FORM_SECRET);
});

test('saving a domain with a new search password stores it', function () {
	$result = ldap_form_domain_run('form_save()', ldap_form_domain_post(array('specific_password' => 'new-pass')), ldap_form_domain_row());

	expect($result['writes']['user_domains_ldap']['specific_password'])->toBe('new-pass')
		->and($result['retained']['specific_password'] ?? null)->toBe('new-pass');
});

test('changing a domain server, port or encryption needs the search password again', function () {
	$changes = array(
		'server'     => array('server' => 'rogue.example.net'),
		'port'       => array('port' => '3389'),
		'ssl port'   => array('port_ssl' => '1636'),
		'encryption' => array('encryption' => '1'),
	);

	foreach ($changes as $case => $change) {
		$refused = ldap_form_domain_run('form_save()', ldap_form_domain_post($change), ldap_form_domain_row());

		expect($refused['messages'])->toBe(array('domain_ldap_password'), $case)
			->and($refused['writes'])->toBe(array(), $case);

		$typed = ldap_form_domain_run('form_save()', ldap_form_domain_post($change + array('specific_password' => 'retyped')), ldap_form_domain_row());

		expect($typed['writes']['user_domains_ldap']['specific_password'])->toBe('retyped', $case);
	}
});

test('a domain that inherits the global server is not refused when the form shows that server', function () {
	$row = ldap_form_domain_row(array('server' => '', 'port' => '', 'port_ssl' => '', 'encryption' => '0'));

	/* the edit form fills an empty server and ports with the global values */
	$post = ldap_form_domain_post(array('server' => 'ldap1.example.com ldap2.example.com', 'port' => '389', 'port_ssl' => '636', 'encryption' => '0'));

	$result = ldap_form_domain_run('form_save()', $post, $row);

	expect($result['messages'])->toBe(array(1, 1))
		->and($result['writes']['user_domains_ldap']['specific_password'])->toBe(LDAP_FORM_SECRET);
});
