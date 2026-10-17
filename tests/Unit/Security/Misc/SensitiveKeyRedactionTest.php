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
 * cacti_is_sensitive_key() decides which log values are replaced with
 * '[REDACTED]'. It matches its list as case-insensitive substrings of the
 * key, so names that merely contain a listed word are redacted too; that
 * over-match is the fail-closed direction and is kept.
 *
 * The production functions are extracted into this namespace.
 */

namespace SensitiveKeyRedactionTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

if (!function_exists(__NAMESPACE__ . '\cacti_is_sensitive_key')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
	$code   = '';

	foreach (array('cacti_is_sensitive_key', 'cacti_redact_value', 'cacti_redact_sensitive') as $name) {
		$code .= test_php_function_source($source, $name) . "\n";
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $code);
}

/**
 * Credential names that were logged in clear before #871.
 *
 * @return array<int, string>
 */
function added_names() {
	return array(
		'api_key', 'apikey', 'private_key', 'privkey', 'pwd', 'authorization',
		'bearer', 'credential', 'access_key', 'session_id', 'psk', 'jwt',
		'communities',
	);
}

/**
 * Lower, upper, mixed case and embedded forms of each added name.
 *
 * @return array<string, array{0: string}>
 */
function added_name_variants() {
	$variants = array();

	foreach (added_names() as $name) {
		$variants[$name]               = array($name);
		$variants[$name . ' upper']    = array(strtoupper($name));
		$variants[$name . ' mixed']    = array(ucwords($name, '_'));
		$variants[$name . ' prefix']   = array($name . '_value');
		$variants[$name . ' suffix']   = array('remote_' . $name);
		$variants[$name . ' embedded'] = array('X_' . strtoupper($name) . '_2');
	}

	return $variants;
}

test('credential names added for #871 are sensitive', function ($key) {
	expect(cacti_is_sensitive_key($key))->toBeTrue()
		->and(cacti_redact_value($key, 'hunter2'))->toBe('[REDACTED]');
})->with(added_name_variants());

test('names that were already sensitive stay sensitive', function ($key) {
	expect(cacti_is_sensitive_key($key))->toBeTrue()
		->and(cacti_redact_value($key, 'hunter2'))->toBe('[REDACTED]');
})->with(array(
	'password', 'pass', 'passwd', 'db_pass', 'snmp_password', 'snmp_priv_passphrase',
	'snmp_auth_passphrase', 'rsa_private_key', 'secret', 'secret_key', 'auth_key',
	'priv_key', 'token', 'cookie', 'community', 'community_string', 'snmp_community',
	'specific_password', 'ldap_password', 'csrf', 'csrf_token', 'LOGIN_PASSWORD',
));

test('ordinary names are not redacted', function ($key) {
	expect(cacti_is_sensitive_key($key))->toBeFalse()
		->and(cacti_redact_value($key, 'plain'))->toBe('plain');
})->with(array('hostname', 'description', 'snmp_version', 'graph_id', 'login_username', 'ordinary'));

test('non-string keys are compared as strings', function () {
	$warnings = array();

	set_error_handler(function ($errno, $message) use (&$warnings) {
		$warnings[] = $message;

		return true;
	});

	try {
		$array_key = cacti_is_sensitive_key(array('password'));
	} finally {
		restore_error_handler();
	}

	expect(cacti_is_sensitive_key(null))->toBeFalse()
		->and(cacti_is_sensitive_key(0))->toBeFalse()
		->and(cacti_is_sensitive_key(42))->toBeFalse()
		->and(cacti_redact_value(7, 'plain'))->toBe('plain')
		->and($array_key)->toBeFalse()
		->and($warnings)->toBe(array('Array to string conversion'));
});

test('cacti_redact_sensitive redacts added names at every depth', function () {
	$data = array(
		'hostname' => 'router1',
		'api_key'  => 'k-1',
		'nested'   => array(
			'Authorization' => 'Bearer abc',
			'ids'           => array(1, 2),
			'deeper'        => array('session_id' => 's-1', 'PSK' => 'p-1', 'note' => 'keep'),
		),
		'communities' => array('public', 'private'),
		3             => 'indexed',
	);

	expect(cacti_redact_sensitive($data))->toBe(array(
		'hostname' => 'router1',
		'api_key'  => '[REDACTED]',
		'nested'   => array(
			'Authorization' => '[REDACTED]',
			'ids'           => array(1, 2),
			'deeper'        => array('session_id' => '[REDACTED]', 'PSK' => '[REDACTED]', 'note' => 'keep'),
		),
		'communities' => '[REDACTED]',
		3             => 'indexed',
	))
		->and(cacti_redact_sensitive('jwt'))->toBe('jwt');
});
