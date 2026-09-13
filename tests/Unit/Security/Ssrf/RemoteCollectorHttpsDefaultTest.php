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
 * A new install must verify the Remote Data Collector's HTTPS certificate,
 * while an existing install keeps what it had. Before 1.2.32 an install with
 * no stored allow_unsafe_https row ran with the old 'on' default, so both
 * upgrade paths must leave it 'on':
 *
 * - cli/upgrade_database.php runs upgrade_to_1_2_32() only.
 * - install/install.php runs prime_default_settings() first, then the
 *   installer runs upgrade_to_1_2_32() in the background.
 *
 * The real functions are extracted into this namespace and run against an
 * in-memory settings table whose name column is the primary key, as in
 * cacti.sql, so INSERT IGNORE never replaces a stored row.
 */

namespace RemoteCollectorHttpsDefaultTest;

if (!defined('CACTI_VERSION')) {
	define('CACTI_VERSION', '1.2.32');
}

function shipped_default($name) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/include/global_settings.php');

	preg_match("/^\t\t'" . preg_quote($name, '/') . "' => array\((.*?)^\t\t\),/ms", $source, $match);
	preg_match("/'default'\s*=>\s*'([^']*)'/", $match[1], $default);

	return $default[1];
}

if (!function_exists(__NAMESPACE__ . '\get_default_contextoption')) {
	$root = dirname(__DIR__, 4);
	$code = '';

	$sources = array(
		'/lib/functions.php'            => array('get_default_contextoption', 'cacti_version_compare', 'version_to_decimal'),
		'/install/functions.php'        => array('prime_default_settings'),
		'/install/upgrades/1_2_32.php'  => array('upgrade_to_1_2_32'),
	);

	foreach ($sources as $file => $names) {
		$source = file_get_contents($root . $file);

		foreach ($names as $name) {
			preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match);
			$code .= $match[0];
		}
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $code);
}

/* ---- in-memory settings table and database stubs ---- */

function insert_ignore($name, $value) {
	if (!array_key_exists($name, $GLOBALS['settings_table'])) {
		$GLOBALS['settings_table'][$name] = (string) $value;
	}
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return array_key_exists($params[0], $GLOBALS['settings_table']) ? $GLOBALS['settings_table'][$params[0]] : false;
}

function db_execute_prepared($sql, $params = array()) {
	if (preg_match('/^\s*INSERT IGNORE INTO settings\s+\(name, value\)\s+VALUES \(\?, \?\)\s*$/s', $sql)) {
		insert_ignore($params[0], $params[1]);
	}

	return true;
}

function db_install_execute($sql, $params = array(), $log = true) {
	if (preg_match("/^INSERT IGNORE INTO settings \(name, value\) VALUES \('([^']+)', '([^']*)'\)$/", $sql, $match)) {
		insert_ignore($match[1], $match[2]);
	}

	return true;
}

function db_table_exists($table, $log = true, $db_conn = false) {
	return true;
}

function db_install_add_column($table, $column, $log = true) {
	return true;
}

function db_install_add_key($table, $type, $key, $columns, $using = '') {
	return true;
}

function get_cacti_version() {
	return $GLOBALS['stored_version'];
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function read_config_option($name) {
	return array_key_exists($name, $GLOBALS['settings_table']) ? $GLOBALS['settings_table'][$name] : shipped_default($name);
}

function get_url_type() {
	return 'https';
}

function api_plugin_hook_function($name, $data = '') {
	return $data;
}

function run_install_flow($version, array $stored, $prime, $upgrade) {
	$GLOBALS['stored_version'] = $version;
	$GLOBALS['settings_table'] = $stored;
	$GLOBALS['settings']       = array(
		'general' => array(
			'force_https'        => array('default' => shipped_default('force_https')),
			'allow_unsafe_https' => array('default' => shipped_default('allow_unsafe_https')),
		),
	);

	unset($_SESSION['settings_primed']);

	if ($prime) {
		prime_default_settings();
	}

	if ($upgrade) {
		upgrade_to_1_2_32();
	}

	return read_config_option('allow_unsafe_https');
}

/* ---- defaults ---- */

test('ships allow_unsafe_https off for new installs', function () {
	expect(shipped_default('allow_unsafe_https'))->toBe('');
});

test('leaves force_https off so the console redirect is unchanged', function () {
	expect(shipped_default('force_https'))->toBe('');
});

test('verifies the collector certificate on a new install', function () {
	$GLOBALS['settings_table'] = array();

	$ssl = get_default_contextoption(5)['ssl'];

	expect($ssl['verify_peer'])->toBeTrue()
		->and($ssl['verify_peer_name'])->toBeTrue()
		->and($ssl['allow_self_signed'])->toBeFalse();
});

test('keeps a stored opt-in to unsafe HTTPS', function () {
	$GLOBALS['settings_table'] = array('allow_unsafe_https' => 'on');

	$ssl = get_default_contextoption(5)['ssl'];

	expect($ssl['verify_peer'])->toBeFalse()
		->and($ssl['allow_self_signed'])->toBeTrue();
});

/* ---- install and upgrade paths ---- */

test('a fresh web install stores the new default', function () {
	expect(run_install_flow('new_install', array(), true, false))->toBe('')
		->and($GLOBALS['settings_table'])->toHaveKey('allow_unsafe_https');
});

test('a fresh CLI install reads the new default', function () {
	expect(run_install_flow('new_install', array(), false, false))->toBe('');
});

dataset('upgrades without a stored row', array(
	'CLI upgrade from 1.2.31' => array('1.2.31', false),
	'web upgrade from 1.2.31' => array('1.2.31', true),
	'web upgrade from 1.2.27' => array('1.2.27', true),
	'CLI upgrade from 1.2.27' => array('1.2.27', false),
));

test('an upgrade without a stored row keeps allow_unsafe_https on', function ($version, $prime) {
	expect(run_install_flow($version, array(), $prime, true))->toBe('on')
		->and($GLOBALS['settings_table']['allow_unsafe_https'])->toBe('on');
})->with('upgrades without a stored row');

dataset('stored values', array(
	'stored off, CLI upgrade' => array('', false),
	'stored off, web upgrade' => array('', true),
	'stored on, CLI upgrade'  => array('on', false),
	'stored on, web upgrade'  => array('on', true),
));

test('an upgrade leaves a stored allow_unsafe_https untouched', function ($value, $prime) {
	expect(run_install_flow('1.2.31', array('allow_unsafe_https' => $value), $prime, true))->toBe($value);
})->with('stored values');

test('priming after 1.2.32 is in place stores the new default', function () {
	expect(run_install_flow('1.2.32', array(), true, false))->toBe('');
});
