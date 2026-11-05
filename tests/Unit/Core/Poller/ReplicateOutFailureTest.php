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
 * replicate_out() must delete stale remote poller items with a bound cutoff
 * and must report completion only when every replication step succeeded.
 */

namespace ReplicateOutFailure;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
foreach (array('replicate_out', 'replicate_out_execute') as $function) {
	eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
}

foreach (array('POLLER_VERBOSITY_NONE' => 0, 'POLLER_VERBOSITY_LOW' => 2, 'MESSAGE_LEVEL_ERROR' => 3) as $name => $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

function __($text) { return $text; }
function cacti_sizeof($value) { return is_array($value) || $value instanceof \Countable ? count($value) : 0; }
function poller_connect_to_remote($poller_id) { return $GLOBALS['rf_remote']; }
function replicate_log($text, $level = 0) { $GLOBALS['rf_logs'][] = $text; }
function raise_message($id, $message = '', $level = 0) { $GLOBALS['rf_messages'][] = $id; }
function api_plugin_hook_function($hook, $data = array()) { return $data; }
function db_fetch_assoc($sql) { return array(); }
function db_fetch_assoc_prepared($sql, $params = array()) { return array(); }
function db_fetch_row_prepared($sql, $params = array()) { return array('snmp' => 1, 'script' => 0, 'server' => 0); }
function db_fetch_cell($sql, $col = '', $log = true, $conn = false) { return $GLOBALS['rf_time']; }
function replicate_out_table($conn, &$data, $table, $remote_poller_id, $truncate = true, $exclude = false) {
	global $replicate_out_success;

	if ($GLOBALS['rf_fail_table'] === $table) {
		$replicate_out_success = false;
	}
}
function db_execute($sql, $log = true, $conn = false) {
	// A bare placeholder is a MySQL syntax error.
	return strpos($sql, '?') === false;
}
function db_execute_prepared($sql, $params = array(), $log = true, $conn = false) {
	// Only statements sent to the remote connection touch the remote table.
	if (!is_object($conn) || strpos($sql, 'poller_item') === false) {
		return true;
	}

	if (substr_count($sql, '?') !== count($params)) {
		return false;
	}

	try {
		return $GLOBALS['rf_remote']->prepare($sql)->execute($params);
	} catch (\PDOException $e) {
		return false;
	}
}

beforeEach(function () {
	$GLOBALS['rf_remote'] = new \PDO('sqlite::memory:');
	$GLOBALS['rf_remote']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	$GLOBALS['rf_remote']->exec('CREATE TABLE poller_item(id INTEGER, last_updated TEXT)');
	$GLOBALS['rf_time']       = strtotime('2020-01-03 00:00:00 UTC');
	$GLOBALS['rf_logs']       = array();
	$GLOBALS['rf_messages']   = array();
	$GLOBALS['rf_fail_table'] = '';
	$GLOBALS['config']        = array('is_web' => true);
	$this->timezone           = date_default_timezone_get();
	date_default_timezone_set('UTC');
});

afterEach(function () {
	date_default_timezone_set($this->timezone);
});

test('stale remote poller items are deleted with a bound cutoff', function () {
	$GLOBALS['rf_remote']->exec("INSERT INTO poller_item VALUES (1,'2020-01-01 00:00:00'),(2,'2020-01-03 00:00:00'),(3,NULL)");

	expect(replicate_out(2, 'data'))->toBeTrue();
	expect($GLOBALS['rf_remote']->query('SELECT id FROM poller_item ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN))->toBe(array(2, 3));
});

test('a failed stale item cleanup is reported as a failed synchronization', function () {
	// A statement the remote rejects, standing in for the old unbound placeholder.
	$GLOBALS['rf_remote']->exec('DROP TABLE poller_item');

	expect(replicate_out(2, 'data'))->toBeFalse();
	expect($GLOBALS['rf_logs'])->toContain('ERROR: Unable to remove stale remote poller items.');
	expect($GLOBALS['rf_messages'])->toBe(array('poller_sync_failed'));
});

test('completion is reported only when every replication step succeeded', function ($failed_table) {
	$GLOBALS['rf_fail_table'] = $failed_table;
	$result                   = replicate_out(2, 'data');

	if ($failed_table === '') {
		expect($result)->toBeTrue();
		expect($GLOBALS['rf_messages'])->toBe(array('poller_sync'));
		expect(implode("\n", $GLOBALS['rf_logs']))->toContain('Synchronization of Poller 2 completed');
	} else {
		expect($result)->toBeFalse();
		expect($GLOBALS['rf_messages'])->toBe(array('poller_sync_failed'));
		expect(implode("\n", $GLOBALS['rf_logs']))->not->toContain('completed');
		expect($GLOBALS['rf_logs'])->toContain('ERROR: Synchronization of Poller 2 failed.');
	}
})->with(array('', 'data_local', 'poller_item'));
