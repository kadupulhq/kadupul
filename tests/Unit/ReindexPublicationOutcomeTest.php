<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace ReindexPublicationOutcome;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/poller.php');
if ($source === false) {
    throw new \RuntimeException('Missing publication source');
}
foreach (array('poller_push_reindex_data_to_poller', 'poller_push_reindex_only_data_to_main') as $name) {
    eval('namespace ReindexPublicationOutcome;' . \test_php_function_source($source, $name));
}
function db_fetch_cell_prepared($sql, $args = array())
{
    return $GLOBALS['publication_failure'] === 'minimum' ? false : 2;
}
function db_fetch_assoc_prepared($sql, $args = array())
{
    if (str_contains($sql, 'SELECT DISTINCT host_id')) {
        return $GLOBALS['publication_failure'] === 'selection' ? false : array(array('host_id' => 7));
    }
    return array(array('fixture' => 1));
}
function db_fetch_assoc($sql)
{
    return array(array('fixture' => 1));
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function replicate_table_to_poller($connection, &$rows, $table, $exclude = false)
{
    $GLOBALS['publication_calls'][] = array($table, $exclude);
    return $GLOBALS['publication_failure'] !== $table;
}

beforeEach(function () {
    $GLOBALS['publication_calls'] = array();
    $GLOBALS['publication_failure'] = '';
    $GLOBALS['remote_db_cnn_id'] = null;
});

test('collector publication propagates every failed read and table write', function ($failure, $force) {
    $GLOBALS['publication_failure'] = $failure;
    expect(poller_push_reindex_data_to_poller(7, 11, $force, new \stdClass()))->toBe($failure === '');
    if (in_array($failure, array('minimum','selection'), true)) {
        expect($GLOBALS['publication_calls'])->toBe(array());
    } else {
        expect($GLOBALS['publication_calls'])->toBe(array(array('data_local',false), array('graph_local',false), array('host_snmp_cache',false), array('poller_reindex',array('assert_value'))));
    }
})->with(array('', 'minimum', 'selection', 'data_local', 'graph_local', 'host_snmp_cache', 'poller_reindex'))->with(array(false,true));

test('reindex-only publication preserves the replication outcome', function ($failure) {
    $GLOBALS['publication_failure'] = $failure;
    expect(poller_push_reindex_only_data_to_main(7, 11))->toBe($failure === '');
    if ($failure === 'minimum') {
        expect($GLOBALS['publication_calls'])->toBe(array());
    }
})->with(array('', 'minimum', 'poller_reindex'));
