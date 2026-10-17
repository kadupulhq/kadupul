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
 * settings_user.user_id must hold every user_auth.id (#888). The unchanged
 * lib/database.php, install/functions.php, lib/auth.php and
 * install/upgrades/1_2_32.php run here on a real server through
 * db_connect_real(), so the session uses the LTS sql_mode, in which an id
 * the column cannot hold is clamped rather than refused.
 *
 * CI provisions an empty schema named by SETTINGS_USER_DB_NAME. Without it a
 * throwaway schema is created and dropped.
 */

$root = dirname(__DIR__, 2);

/* as cli/upgrade_database.php and install/install.php run the upgrade */
define('IN_CACTI_INSTALL', 1);

require_once $root . '/include/global_constants.php';
require_once $root . '/lib/database.php';
require_once $root . '/install/functions.php';
require_once $root . '/lib/auth.php';
require_once $root . '/install/upgrades/1_2_32.php';

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/* the lib/functions.php helpers these paths call, as shipped */
foreach (array('cacti_sizeof', 'cacti_count', 'clean_up_lines', 'array_rekey', 'cacti_has_control_chars', 'cacti_is_sensitive_key') as $function) {
	// test-only eval of source read from this repository, not external input
	eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}

/* stubs for the rest, which these paths only use for logging and messages */
function set_config_option($name, $value, $remote = false) {}
function cacti_log($message, $output = false, $facility = '', $level = '') { $GLOBALS['settings_user_width_log'][] = $message; }
function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0) {}
function input_validate_input_number($value) { if (!is_numeric($value) && $value != '') { throw new RuntimeException('invalid number'); } }
function raise_message($id, $message = '', $level = 0) { throw new RuntimeException('unexpected raise_message ' . $id); }
function api_plugin_hook_function($name, $parm = null) { return $parm; }

const SETTINGS_USER_OLD_DDL = "CREATE TABLE settings_user (
  user_id smallint(8) unsigned NOT NULL default '0',
  name varchar(255) NOT NULL default '',
  value varchar(4096) NOT NULL default '',
  PRIMARY KEY (user_id, name)
) ENGINE=InnoDB ROW_FORMAT=Dynamic";

const SETTINGS_USER_TABLES = array('settings_user', 'settings_user_base', 'poller_output', 'poller_output_boost_processes', 'settings', 'user_auth_throttle', 'user_auth', 'user_auth_perms', 'user_auth_realm', 'settings_tree', 'user_auth_group_members');

function settings_user_width_cacti_sql_table($table) {
	$sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');

	if (!preg_match('/^CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?^\)[^;]*;/ms', $sql, $match)) {
		throw new RuntimeException('cacti.sql has no table ' . $table);
	}

	return rtrim($match[0], ';');
}

function settings_user_width_connect() {
	global $database_hostname, $database_port, $database_default, $config;
	static $connected = false;

	if ($connected) {
		return;
	}

	$host     = getenv('BOOST_DB_HOST') ?: 'localhost';
	$port     = getenv('BOOST_DB_PORT') ?: '3306';
	$user     = getenv('BOOST_DB_USER') ?: 'root';
	$password = getenv('BOOST_DB_PASSWORD') ?: '';
	$schema   = getenv('SETTINGS_USER_DB_NAME');

	if (!$schema) {
		$schema = 'kadupul_settings_user_' . bin2hex(random_bytes(8));
		$admin  = new PDO("mysql:host=$host;port=$port", $user, $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		$admin->exec('CREATE DATABASE `' . $schema . '`');

		register_shutdown_function(static function () use ($admin, $schema) {
			$admin->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
		});
	}

	$config = array('poller_id' => 1);

	if (!db_connect_real($host, $user, $password, $schema, 'mysql', $port, 1)) {
		throw new RuntimeException('Cannot connect to the settings_user contract schema: ' . json_encode($config['DATABASE_ERROR'] ?? array()));
	}

	$database_hostname = $host;
	$database_port     = $port;
	$database_default  = $schema;
	$connected         = true;
}

function settings_user_width_reset() {
	settings_user_width_connect();

	db_execute('DROP VIEW IF EXISTS settings_user');
	foreach (SETTINGS_USER_TABLES as $table) {
		db_execute('DROP TABLE IF EXISTS `' . $table . '`');
	}

	$GLOBALS['database_upgrade_status'] = array();
	$GLOBALS['cacti_upgrade_version']   = '1.2.32';
	$GLOBALS['settings_user_width_log'] = array();
	$_SESSION = array();
}

function settings_user_width_column() {
	return db_fetch_row("SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY
		FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = SCHEMA()
		AND TABLE_NAME = 'settings_user'
		AND COLUMN_NAME = 'user_id'");
}

function settings_user_width_create() {
	return db_fetch_cell('SHOW CREATE TABLE settings_user', 'Create Table');
}

function settings_user_width_rows($where = '') {
	/* PHP 8.1 and later return native integers here; older drivers return strings */
	return array_map(function ($row) {
		return array('user_id' => (int) $row['user_id'], 'name' => $row['name'], 'value' => $row['value']);
	}, db_fetch_assoc('SELECT user_id, name, value FROM settings_user ' . $where . ' ORDER BY user_id, name'));
}

/** Run the real upgrade_to_1_2_32() and return what it recorded for the installer. */
function settings_user_width_upgrade() {
	$GLOBALS['database_upgrade_status'] = array();

	ob_start();
	try {
		upgrade_to_1_2_32();
	} finally {
		ob_end_clean();
	}

	return $GLOBALS['database_upgrade_status']['1.2.32'] ?? array();
}

function settings_user_width_alters(array $recorded) {
	return array_values(array_filter($recorded, function ($item) {
		return stripos($item['sql'], 'ALTER TABLE settings_user') !== false;
	}));
}

function settings_user_width_errors(array $recorded) {
	return array_values(array_filter($recorded, function ($item) {
		return $item['status'] == DB_STATUS_ERROR;
	}));
}

/* the other tables upgrade_to_1_2_32() touches, as cacti.sql defines them */
function settings_user_width_upgrade_fixtures() {
	expect(db_execute(settings_user_width_cacti_sql_table('poller_output')))->toBeTrue();
	expect(db_execute(settings_user_width_cacti_sql_table('settings')))->toBeTrue();
}

beforeEach(function () {
	settings_user_width_reset();
});

afterEach(function () {
	settings_user_width_reset();
});

test('the upgrade widens a 1.2.31 settings_user.user_id to mediumint unsigned and keeps every row and key', function () {
	settings_user_width_upgrade_fixtures();
	expect(db_execute(SETTINGS_USER_OLD_DDL))->toBeTrue();
	expect(db_execute("INSERT INTO settings_user (user_id, name, value) VALUES
		(0, 'selected_theme', 'classic'),
		(1, 'selected_theme', 'modern'),
		(1, 'session_epoch', '3'),
		(65534, 'page_refresh', '300'),
		(65535, 'selected_theme', 'dark'),
		(65535, 'session_epoch', '7')"))->toBeTrue();

	$before_create = settings_user_width_create();
	$before_rows   = settings_user_width_rows();

	expect(settings_user_width_column()['DATA_TYPE'])->toBe('smallint');

	$first = settings_user_width_upgrade();
	$column = settings_user_width_column();

	expect(settings_user_width_errors($first))->toBe(array())
		->and(settings_user_width_alters($first))->toHaveCount(1)
		->and(settings_user_width_alters($first)[0]['status'])->toBe(DB_STATUS_SUCCESS)
		->and($column['DATA_TYPE'])->toBe('mediumint')
		->and($column['COLUMN_TYPE'])->toMatch('/^mediumint(\(8\))? unsigned$/')
		->and($column['IS_NULLABLE'])->toBe('NO')
		->and(trim((string) $column['COLUMN_DEFAULT'], "'"))->toBe('0')
		->and($column['COLUMN_KEY'])->toBe('PRI')
		/* only the user_id type changed: the primary key, the other columns,
		 * the engine and the row format are as they were */
		->and(settings_user_width_create())->toBe(str_replace('`user_id` smallint', '`user_id` mediumint', $before_create))
		->and(settings_user_width_create())->not->toBe($before_create)
		->and(settings_user_width_rows())->toBe($before_rows);

	$widened = settings_user_width_create();
	$second  = settings_user_width_upgrade();

	expect(settings_user_width_errors($second))->toBe(array())
		->and(settings_user_width_alters($second))->toBe(array())
		->and(settings_user_width_create())->toBe($widened)
		->and(settings_user_width_rows())->toBe($before_rows);

	/* the widened column now holds an id the old one clamped */
	expect(db_execute("INSERT INTO settings_user (user_id, name, value) VALUES (70000, 'selected_theme', 'classic')"))->toBeTrue()
		->and(settings_user_width_rows('WHERE user_id >= 65535'))->toBe(array(
			array('user_id' => 65535, 'name' => 'selected_theme', 'value' => 'dark'),
			array('user_id' => 65535, 'name' => 'session_epoch', 'value' => '7'),
			array('user_id' => 70000, 'name' => 'selected_theme', 'value' => 'classic'),
		));
});

test('the upgrade leaves a settings_user created from cacti.sql untouched', function () {
	settings_user_width_upgrade_fixtures();
	expect(db_execute(settings_user_width_cacti_sql_table('settings_user')))->toBeTrue();
	expect(db_execute("INSERT INTO settings_user (user_id, name, value) VALUES (1, 'selected_theme', 'modern'), (70000, 'session_epoch', '2')"))->toBeTrue();

	$before_create = settings_user_width_create();
	$before_rows   = settings_user_width_rows();

	expect(settings_user_width_column()['DATA_TYPE'])->toBe('mediumint');

	$recorded = settings_user_width_upgrade();

	expect(settings_user_width_errors($recorded))->toBe(array())
		->and(settings_user_width_alters($recorded))->toBe(array())
		->and(settings_user_width_create())->toBe($before_create)
		->and(settings_user_width_rows())->toBe($before_rows);
});

test('a failed widening is recorded as an upgrade error and succeeds when the upgrade is run again', function () {
	settings_user_width_upgrade_fixtures();

	/* information_schema reports the view column as smallint, and ALTER TABLE
	 * refuses a view, so the widening fails on the server */
	expect(db_execute(str_replace('CREATE TABLE settings_user', 'CREATE TABLE settings_user_base', SETTINGS_USER_OLD_DDL)))->toBeTrue();
	expect(db_execute('CREATE VIEW settings_user AS SELECT user_id, name, value FROM settings_user_base'))->toBeTrue();

	$failed = settings_user_width_upgrade();
	$errors = settings_user_width_errors($failed);

	/* the installer and cli/upgrade_database.php keep the schema version
	 * below 1.2.32 when any recorded status is an error */
	expect($errors)->toHaveCount(1)
		->and($errors[0]['sql'])->toContain('ALTER TABLE settings_user MODIFY user_id mediumint(8) unsigned')
		->and(settings_user_width_column()['DATA_TYPE'])->toBe('smallint');

	db_execute('DROP VIEW settings_user');
	expect(db_execute(SETTINGS_USER_OLD_DDL))->toBeTrue();
	expect(db_execute("INSERT INTO settings_user (user_id, name, value) VALUES (65535, 'session_epoch', '7')"))->toBeTrue();

	$retried = settings_user_width_upgrade();

	expect(settings_user_width_errors($retried))->toBe(array())
		->and(settings_user_width_alters($retried))->toHaveCount(1)
		->and(settings_user_width_column()['DATA_TYPE'])->toBe('mediumint')
		->and(settings_user_width_rows())->toBe(array(array('user_id' => 65535, 'name' => 'session_epoch', 'value' => '7')));
});

test('logout everywhere for an account above 65535 advances its own counter and not user 65535', function () {
	expect(db_execute(settings_user_width_cacti_sql_table('settings_user')))->toBeTrue();
	expect(db_execute("INSERT INTO settings_user (user_id, name, value) VALUES (65535, 'session_epoch', '7'), (65535, 'selected_theme', 'dark')"))->toBeTrue();

	expect(auth_session_epoch(70000))->toBe('0');

	auth_session_epoch_advance(70000);

	expect(auth_session_epoch(70000))->toBe('1')
		->and($_SESSION['sess_user_epoch'])->toBe('1')
		->and(auth_session_epoch(65535))->toBe('7');

	auth_session_epoch_advance(70000);

	expect(auth_session_epoch(70000))->toBe('2')
		->and(settings_user_width_rows())->toBe(array(
			array('user_id' => 65535, 'name' => 'selected_theme', 'value' => 'dark'),
			array('user_id' => 65535, 'name' => 'session_epoch', 'value' => '7'),
			array('user_id' => 70000, 'name' => 'session_epoch', 'value' => '2'),
		))
		/* no statement failed on the way */
		->and($GLOBALS['settings_user_width_log'])->toBe(array());
});

test('copying a template to a new account above 65535 writes that account settings only', function () {
	foreach (array('user_auth', 'user_auth_perms', 'user_auth_realm', 'settings_user', 'settings_tree', 'user_auth_group_members') as $table) {
		expect(db_execute(settings_user_width_cacti_sql_table($table)))->toBeTrue();
	}

	expect(db_execute("INSERT INTO user_auth (id, username, realm, full_name) VALUES (1, 'template', 0, 'Template'), (65535, 'neighbour', 0, 'Neighbour')"))->toBeTrue();
	expect(db_execute('ALTER TABLE user_auth AUTO_INCREMENT = 70000'))->toBeTrue();
	expect(db_execute("INSERT INTO settings_user (user_id, name, value) VALUES
		(1, 'selected_theme', 'classic'),
		(1, 'page_refresh', '120'),
		(1, 'session_epoch', '9'),
		(65535, 'selected_theme', 'dark'),
		(65535, 'page_refresh', '600'),
		(65535, 'session_epoch', '4')"))->toBeTrue();

	$new_id = user_copy('template', 'newcomer');

	expect((int) $new_id)->toBe(70000)
		->and((int) db_fetch_cell("SELECT id FROM user_auth WHERE username = 'newcomer'"))->toBe(70000)
		->and(settings_user_width_rows())->toBe(array(
			array('user_id' => 1, 'name' => 'page_refresh', 'value' => '120'),
			array('user_id' => 1, 'name' => 'selected_theme', 'value' => 'classic'),
			array('user_id' => 1, 'name' => 'session_epoch', 'value' => '9'),
			array('user_id' => 65535, 'name' => 'page_refresh', 'value' => '600'),
			array('user_id' => 65535, 'name' => 'selected_theme', 'value' => 'dark'),
			array('user_id' => 65535, 'name' => 'session_epoch', 'value' => '4'),
			array('user_id' => 70000, 'name' => 'page_refresh', 'value' => '120'),
			array('user_id' => 70000, 'name' => 'selected_theme', 'value' => 'classic'),
		))
		/* sql_save() logs each write at DEVEL level; nothing else is expected */
		->and(preg_grep('/^DEVEL: SQL Save on table /', $GLOBALS['settings_user_width_log'], PREG_GREP_INVERT))->toBe(array());
});
