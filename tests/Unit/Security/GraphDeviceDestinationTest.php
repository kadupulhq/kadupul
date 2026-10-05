<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute the production device-change API against SQLite. Policy and the title
// updater are isolated; the graph read/update and child lookup use actual SQL.
test('graph device changes validate the destination before any database or title work', function ($destination, $accepted, $stored) {
    $root = dirname(__DIR__, 3);
    $program = 'require ' . var_export($root . '/tests/Helpers/PhpSource.php', true) . ';$root=' . var_export($root, true) . ';$destination=' . var_export($destination, true) . ';';
    $program .= <<<'PROBE'
foreach (array('lib/auth.php' => 'auth_resource_id', 'lib/api_graph.php' => 'api_graph_change_device') as $file => $function) {
    eval(test_php_function_source(file_get_contents($root . '/' . $file), $function));
}
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE graph_local(id INTEGER PRIMARY KEY,host_id INTEGER,snmp_query_id INTEGER);INSERT INTO graph_local VALUES(7,99,0);CREATE TABLE graph_templates_item(local_graph_id INTEGER,task_item_id INTEGER);CREATE TABLE data_template_rrd(id INTEGER,local_data_id INTEGER)');
$queries = 0;
$titles = 0;
function is_graph_allowed($id) { return $id === 7; }
function is_device_allowed($id) { return $id === 12; }
function statement($sql, $values) { $GLOBALS['queries']++; $q=$GLOBALS['db']->prepare($sql);$q->execute($values);return $q; }
function db_fetch_cell_prepared($sql, $values) { return statement($sql, $values)->fetchColumn(); }
function db_fetch_assoc_prepared($sql, $values) { return statement($sql, $values)->fetchAll(PDO::FETCH_ASSOC); }
function db_execute_prepared($sql, $values) { statement($sql, $values);return true; }
function cacti_sizeof($values) { return count($values); }
function update_graph_title_cache($id) { $GLOBALS['titles']++; }
$status = api_graph_change_device(7, $destination);
echo json_encode(array('status' => $status, 'stored' => (int)$db->query('SELECT host_id FROM graph_local WHERE id=7')->fetchColumn(), 'queries' => $queries, 'titles' => $titles));
PROBE;
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    expect($result)->toBe(array('status' => $accepted, 'stored' => $stored,
        'queries' => $accepted ? 3 : 0, 'titles' => $accepted ? 1 : 0));
})->with(array(
    'None' => array(0, true, 0), 'allowed' => array(12, true, 12),
    'equivalent' => array('012 ', true, 12),
    'foreign' => array(13, false, 99), 'negative' => array(-1, false, 99),
    'fractional' => array('12.5', false, 99), 'missing' => array(null, false, 99),
    'array' => array(array(12), false, 99), 'empty' => array('', false, 99),
));
