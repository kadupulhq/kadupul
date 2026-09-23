<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Saving an LDAP domain disables the account named as its user template. The
 * dropdown offers local accounts only, so the save must reject anything else.
 * Making a domain default clears every other default first, so the domain has
 * to exist before that happens.
 */

namespace UserDomainTemplateUserTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/**
 * Runs one handler in a child, because a refusal exits.
 *
 * @param string               $handler The handler to call, with its arguments.
 * @param array<string, mixed> $request The request variables.
 * @param array<string, mixed> $db      user_auth rows by id, realm holders and existing domain ids.
 *
 * @return array<string, mixed> The writes made and the message raised.
 */
function run_handler($handler, array $request, array $db) {
	$root   = dirname(__DIR__, 4);
	$source = file_get_contents($root . '/user_domains.php');

	$functions = '';
	foreach (array('domain_template_user_valid', 'domain_exists', 'domain_default', 'form_save') as $name) {
		$functions .= test_php_function_source($source, $name) . "\n";
	}

	$probe = '<?php
$request = ' . var_export($request, true) . ';
$db = ' . var_export($db, true) . ';
$writes = array();
$message = null;
$registered_cacti_names = array();
define("MESSAGE_LEVEL_ERROR", 2);
function __($text, ...$args) { return $text; }
function isset_request_var($name) { return isset($GLOBALS["request"][$name]); }
function get_filter_request_var($name) { return $GLOBALS["request"][$name] ?? 0; }
function get_request_var($name) { return $GLOBALS["request"][$name] ?? 0; }
function get_nfilter_request_var($name, $default = "") { return $GLOBALS["request"][$name] ?? $default; }
function form_input_validate($value, $name, $regex, $allow_empty, $error) { return $value; }
function is_error_message() { return false; }
function raise_message($id, $text = "", $level = 0) { if ($GLOBALS["message"] === null) { $GLOBALS["message"] = $id; } }
function sql_save($save, $table, $key = "id") { $GLOBALS["writes"][] = "SAVE " . $table; return $save[$key] > 0 ? $save[$key] : 7; }
function db_execute($sql) { $GLOBALS["writes"][] = "CLEAR"; return true; }
function db_execute_prepared($sql, $params = array()) { $GLOBALS["writes"][] = strtok(trim($sql), " "); return true; }
function cacti_authorize_has_realm($user_id, $realm_id) { return in_array($user_id, $GLOBALS["db"]["realm1"]); }
function db_fetch_cell_prepared($sql, $params) {
	$db = $GLOBALS["db"];
	if (strpos($sql, "defdomain = 1") !== false) {
		// The row is default only once the UPDATE above matched it.
		return (int) (in_array($params[0], $db["domains"]) && in_array("UPDATE", $GLOBALS["writes"], true));
	}
	if (strpos($sql, "FROM user_domains") !== false) { return (int) in_array($params[0], $db["domains"]); }
	return (int) in_array($params[0], $db["local_users"]);
}
register_shutdown_function(function () { echo json_encode(array("writes" => $GLOBALS["writes"], "message" => $GLOBALS["message"])); });
' . $functions . $handler . ';
';

	$file = tempnam(sys_get_temp_dir(), 'user-domain-');
	file_put_contents($file, $probe);

	try {
		return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
	} finally {
		unlink($file);
	}
}

// Users 3 and 4 are local accounts; user 9 is not.
$db = array('local_users' => array(3, 4), 'realm1' => array(), 'domains' => array(1, 2));

test('an LDAP domain save keeps a forged user template out of user_auth', function () use ($db) {
	$ldap = array('save_component_domain_ldap' => 1, 'domain_id' => 1, 'type' => 2, 'domain_name' => 'ad');

	// A local account without User Management rights is saved and disabled.
	$saved = run_handler('form_save()', $ldap + array('user_id' => 3), $db);
	expect($saved['message'])->toBe(1)
		->and($saved['writes'])->toContain('UPDATE');

	foreach (array(9, 404) as $user_id) {
		expect(run_handler('form_save()', $ldap + array('user_id' => $user_id), $db))
			->toBe(array('writes' => array(), 'message' => 'domain_template_user'));
	}
});

test('a non-LDAP domain save applies the same rule', function () use ($db) {
	$domain = array('save_component_domain' => 1, 'domain_id' => 1, 'type' => 1, 'domain_name' => 'local');

	expect(run_handler('form_save()', $domain + array('user_id' => 0), $db)['message'])->toBe(1);
	expect(run_handler('form_save()', $domain + array('user_id' => 9), $db))
		->toBe(array('writes' => array(), 'message' => 'domain_template_user'));
});

test('a domain becomes default only when it exists', function () use ($db) {
	// The target is set first, then the others are cleared, so a delete racing
	// the check cannot leave the system with no default at all.
	expect(run_handler('domain_default(2)', array(), $db))->toBe(array('writes' => array('UPDATE', 'UPDATE'), 'message' => null));
	expect(run_handler('domain_default(404)', array(), $db))->toBe(array('writes' => array('UPDATE'), 'message' => 'domain_missing'));
});
