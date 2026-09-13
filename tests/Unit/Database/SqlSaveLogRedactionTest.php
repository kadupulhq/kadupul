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
 * At DEVDBG, sql_save() and db_replace() log the row they write. Rows for
 * devices, collectors and users carry community strings, passphrases and
 * the collector database password, and cacti.log is readable by the log
 * viewer realm. Secret values are masked with the '********' placeholder
 * db_replace() used in 1.2.31; the rest of each line keeps its 1.2.31 form.
 *
 * The functions are extracted into this namespace with the database layer
 * stubbed.
 */

namespace SqlSaveLogRedactionTest;

if (!defined('POLLER_VERBOSITY_DEVDBG')) {
	define('POLLER_VERBOSITY_DEVDBG', 6);
}

if (!function_exists(__NAMESPACE__ . '\sql_save')) {
	$root = dirname(__DIR__, 3);
	$code = '';

	$sources = array(
		'/lib/database.php'  => array('sql_save', 'db_replace'),
		'/lib/functions.php' => array('cacti_is_sensitive_key'),
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

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['sql_log'][] = array($message, $environ, $level);
}

function db_table_exists($table, $log = true, $db_conn = false) {
	return true;
}

function db_get_table_column_types($table, $db_conn = false) {
	$cols = array();

	foreach (array_keys(collector_row()) as $name) {
		$cols[$name] = array('type' => $name == 'id' ? 'int' : 'varchar', 'null' => 'NO', 'extra' => '', 'default' => '');
	}

	return $cols;
}

function db_qstr($value, $db_conn = false) {
	return "'" . $value . "'";
}

function _db_replace($db_conn, $table, $fields, $keys) {
	return 1;
}

function db_fetch_insert_id($db_conn = false) {
	return 1;
}

function collector_row() {
	return array(
		'id'                   => 3,
		'name'                 => 'collector-east',
		'dbpass'               => 'db-secret-1',
		'snmp_community'       => 'community-secret-2',
		'snmp_auth_passphrase' => 'auth-secret-3',
		'snmp_priv_passphrase' => 'priv-secret-4',
		'password'             => 'hash-secret-5',
	);
}

function masked_row() {
	return array(
		'id'                   => 3,
		'name'                 => 'collector-east',
		'dbpass'               => '********',
		'snmp_community'       => '********',
		'snmp_auth_passphrase' => '********',
		'snmp_priv_passphrase' => '********',
		'password'             => '********',
	);
}

dataset('row writers', array(
	'sql_save'   => array('sql_save', 'SQL Save'),
	'db_replace' => array('db_replace', 'SQL Replace'),
));

test('masks secret columns in the DEVDBG row log and keeps the line format', function ($writer, $label) {
	$GLOBALS['sql_log'] = array();

	if ($writer == 'sql_save') {
		sql_save(collector_row(), 'poller', 'id', true, new \stdClass());
	} else {
		db_replace('poller', collector_row(), 'id', new \stdClass());
	}

	expect($GLOBALS['sql_log'][0])->toBe(array(
		"DEVEL: $label on table 'poller': '" . serialize(masked_row()) . "'",
		'DBCALL',
		POLLER_VERBOSITY_DEVDBG,
	));
})->with('row writers');
