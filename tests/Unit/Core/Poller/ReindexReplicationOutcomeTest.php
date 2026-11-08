<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace ReindexReplicationOutcome;
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'poller_push_reindex_data_to_poller'));
function db_fetch_cell_prepared($sql, $params) { return $GLOBALS['reindex_failure'] === 'cutoff' ? false : 100; }
function db_fetch_assoc_prepared($sql, $params) {
	if (str_contains($sql, 'DISTINCT host_id')) {
		return $GLOBALS['reindex_failure'] === 'hosts' ? false : ($GLOBALS['reindex_failure'] === 'empty' ? array() : array(array('host_id'=>1)));
	}
	return array(array('host_id'=>1));
}
function array_rekey($rows, $key, $value) { return array_column($rows, $value, $key); }
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function replicate_table_to_poller($conn, &$data, $table, $exclude = false) {
	$GLOBALS['reindex_tables'][] = $table;
	return $GLOBALS['reindex_failure'] !== $table;
}
test('forced metadata replication aggregates all required acknowledgements', function ($failure, $expected) {
	$GLOBALS['reindex_failure'] = $failure; $GLOBALS['reindex_tables'] = array();
	expect(poller_push_reindex_data_to_poller(0, 0, true, 'main'))->toBe($expected);
	if ($failure === '') expect($GLOBALS['reindex_tables'])->toBe(array('data_local','graph_local','host_snmp_cache','poller_reindex'));
	if ($failure === 'hosts' || $failure === 'empty') expect($GLOBALS['reindex_tables'])->toBe(array());
})->with(array(array('',true),array('empty',true),array('hosts',false),array('data_local',false),array('graph_local',false),array('host_snmp_cache',false),array('poller_reindex',false)));
test('a failed incremental cutoff lookup is not an empty successful replication', function () {
	$GLOBALS['reindex_failure'] = 'cutoff'; $GLOBALS['reindex_tables'] = array();
	expect(poller_push_reindex_data_to_poller(0,0,false,'main'))->toBeFalse();
	expect($GLOBALS['reindex_tables'])->toBe(array());
});
