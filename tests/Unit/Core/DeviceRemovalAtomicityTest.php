<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Upstream Cacti #7732: api_device_remove() deleted or flagged the device row
 * before its dependent rows, outside any transaction, so a failed statement
 * left a removed device with live poller items. The production function runs
 * in a child process against SQLite; only graph removal, remote collector
 * cleanup and settings writes are recorded instead of performed.
 */

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function device_removal_run(array $scenario): array
{
    $source = file_get_contents(dirname(__DIR__, 3) . '/lib/api_device.php');
    $program = test_php_function_source($source, 'api_device_remove') . "\n";
    if (str_contains($source, 'function api_device_remove_rows(')) {
        $program .= test_php_function_source($source, 'api_device_remove_rows') . "\n";
    }
    $program .= <<<'PHP'
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
require $argv[2] . '/src/Platform/Infrastructure/Legacy/LegacyConnectionRegistry.php';
$db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$database_hostname = 'fixture'; $database_port = 0; $database_default = 'device';
$database_sessions = array('fixture:0:device' => $db);
$config = array();
require $argv[2] . '/include/global_constants.php';
$db->exec("CREATE TABLE host(id INTEGER PRIMARY KEY, poller_id INTEGER, deleted TEXT DEFAULT '');
    CREATE TABLE graph_local(id INTEGER PRIMARY KEY, host_id INTEGER);
    CREATE TABLE caller_work(id INTEGER);");
foreach (array('host_graph', 'host_snmp_query', 'host_snmp_cache', 'poller_item', 'poller_reindex', 'graph_tree_items', 'reports_items') as $table) {
    $db->exec("CREATE TABLE $table(host_id INTEGER)");
    $db->exec("INSERT INTO $table VALUES(12),(120)");
}
$db->exec("CREATE TABLE poller_command(poller_id INTEGER DEFAULT 1, time TEXT, action INTEGER DEFAULT 0, command TEXT, last_updated TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(poller_id, action, command)); INSERT INTO poller_command(command) VALUES('12:0:1'),('120:0:1')");
$db->prepare('INSERT INTO host VALUES(12, ?, \'\'),(120, 1, \'\')')->execute(array($scenario['poller']));
$db->exec('INSERT INTO graph_local VALUES(7, 12),(8, 120)');
if ($scenario['fail'] ?? false) {
    $db->exec("CREATE TRIGGER refuse BEFORE DELETE ON poller_command BEGIN SELECT RAISE(ABORT, 'refused'); END");
}
if ($scenario['queue_fail'] ?? false) {
    $db->exec("CREATE TRIGGER refuse_queue BEFORE INSERT ON poller_command BEGIN SELECT RAISE(ABORT, 'refused'); END");
}
$events = array();
function db_fetch_cell_prepared($sql, $params = array()) { $q = $GLOBALS['db']->prepare($sql); $q->execute($params); $v = $q->fetchColumn(); return $v === false ? false : $v; }
function db_fetch_assoc_prepared($sql, $params = array()) { $q = $GLOBALS['db']->prepare($sql); $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC); }
function db_execute_prepared($sql, $params = array()) { return $GLOBALS['db']->prepare($sql)->execute($params); }
function array_rekey($rows, $key, $value) { $out = array(); foreach ($rows as $row) { $out[$row[$key]] = $row[$value]; } return $out; }
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function __($text, ...$args) { return vsprintf($text, $args); }
function cacti_log(...$args) {}
function raise_message($id, $text = '', $level = 0) { $GLOBALS['events'][] = 'message'; }
function api_plugin_hook_function($name, $data = null) { $GLOBALS['events'][] = 'hook'; return $data; }
function api_delete_graphs(&$graphs, $type) {
    $GLOBALS['events'][] = 'graphs:' . ($GLOBALS['db']->query('SELECT COUNT(*) FROM host WHERE id = 12')->fetchColumn());
    if (!($GLOBALS['scenario']['graphs_stay'] ?? false)) {
        $GLOBALS['db']->exec('DELETE FROM graph_local WHERE id IN (' . implode(',', array_map('intval', $graphs)) . ')');
    }
}
function api_device_purge_from_remote($id, $poller) { $GLOBALS['events'][] = 'purge:' . ($GLOBALS['db']->inTransaction() ? 'in-transaction' : 'committed'); }
function api_device_cache_crc_update($poller) { $GLOBALS['events'][] = 'crc'; }
function set_config_option($name, $value) {}
if ($scenario['caller']) {
    $db->beginTransaction();
    $db->exec('INSERT INTO caller_work VALUES(1)');
}
$result = api_device_remove($scenario['id'] ?? 12);
$queued = $db->query('SELECT poller_id, action, command FROM poller_command WHERE action = ' . POLLER_COMMAND_PURGE)->fetchAll(PDO::FETCH_ASSOC);
if ($scenario['rollback'] ?? false) {
    $db->rollBack();
}
$state = array(
    'queued' => $queued,
    'result' => $result,
    'events' => $events,
    'transaction' => $db->inTransaction(),
    'host' => $db->query('SELECT id, deleted FROM host ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'graphs' => array_map('intval', $db->query('SELECT id FROM graph_local ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
    'caller_work' => (int) $db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn(),
);
foreach (array('host_graph', 'host_snmp_query', 'host_snmp_cache', 'poller_item', 'poller_reindex', 'graph_tree_items', 'reports_items') as $table) {
    $state['rows'][$table] = array_map('intval', $db->query("SELECT host_id FROM $table ORDER BY host_id")->fetchAll(PDO::FETCH_COLUMN));
}
$state['rows']['poller_command'] = $db->query('SELECT command FROM poller_command ORDER BY command')->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($state, JSON_THROW_ON_ERROR);
PHP;
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, json_encode($scenario + array('caller' => false), JSON_THROW_ON_ERROR), dirname(__DIR__, 3)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $err . $out)->and($err)->toBe('');

    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

function device_removal_untouched(array $state): void
{
    foreach ($state['rows'] as $table => $rows) {
        expect($rows)->toBe($table === 'poller_command' ? array('120:0:1', '12:0:1') : array(12, 120), $table);
    }
    expect($state['host'])->toBe(array(array('id' => 12, 'deleted' => ''), array('id' => 120, 'deleted' => '')));
}

test('removing a device clears its rows and keeps other devices', function (int $poller, bool $caller) {
    $state = device_removal_run(array('poller' => $poller, 'caller' => $caller));

    expect($state['result'])->toBeTrue()
        ->and($state['graphs'])->toBe(array(8))
        ->and($state['transaction'])->toBe($caller)
        ->and($state['caller_work'])->toBe($caller ? 1 : 0);
    foreach ($state['rows'] as $table => $rows) {
        expect($rows)->toBe($table === 'poller_command' ? ($caller && $poller > 1 ? array('12', '120:0:1') : array('120:0:1')) : array(120), $table);
    }
    expect($state['host'])->toBe($poller === 1
        ? array(array('id' => 120, 'deleted' => ''))
        : array(array('id' => 12, 'deleted' => 'on'), array('id' => 120, 'deleted' => '')));
    // Graph hooks see the device; remote cleanup follows the local commit.
    expect($state['events'])->toBe(array_merge(array('hook', 'graphs:1'), $poller > 1 && !$caller ? array('purge:committed') : array(), array('crc')));
    expect($state['queued'])->toBe($caller && $poller > 1 ? array(array('poller_id' => 2, 'action' => 3, 'command' => '12')) : array());
})->with(array('main collector' => 1, 'remote collector' => 2))->with(array('own transaction' => false, 'caller transaction' => true));

test('a failed removal statement leaves the device and its rows in place', function (int $poller, bool $caller) {
    $state = device_removal_run(array('poller' => $poller, 'caller' => $caller, 'fail' => true));

    expect($state['result'])->toBeFalse()
        ->and($state['transaction'])->toBe($caller)
        ->and($state['caller_work'])->toBe($caller ? 1 : 0)
        ->and($state['events'])->toBe(array('hook', 'graphs:1', 'message'));
    device_removal_untouched($state);
})->with(array('main collector' => 1, 'remote collector' => 2))->with(array('own transaction' => false, 'caller transaction' => true));

test('graphs that survive graph removal stop the device removal', function () {
    $state = device_removal_run(array('poller' => 1, 'graphs_stay' => true));

    expect($state['result'])->toBeFalse()
        ->and($state['graphs'])->toBe(array(7, 8))
        ->and($state['events'])->toBe(array('hook', 'graphs:1', 'message'));
    device_removal_untouched($state);
});

test('a caller rollback restores the device and removes queued collector cleanup', function () {
    $state = device_removal_run(array('poller' => 2, 'caller' => true, 'rollback' => true));

    expect($state['result'])->toBeTrue()
        ->and($state['queued'])->toBe(array(array('poller_id' => 2, 'action' => 3, 'command' => '12')))
        ->and($state['events'])->not->toContain('purge:in-transaction', 'purge:committed');
    device_removal_untouched($state);
});

test('a failed purge enqueue restores removal rows and preserves caller work', function () {
    $state = device_removal_run(array('poller' => 2, 'caller' => true, 'queue_fail' => true));

    expect($state['result'])->toBeFalse()
        ->and($state['transaction'])->toBeTrue()
        ->and($state['caller_work'])->toBe(1)
        ->and($state['queued'])->toBe(array())
        ->and($state['events'])->toBe(array('hook', 'graphs:1', 'message'));
    device_removal_untouched($state);
});

test('a malformed device id never becomes a different device', function (mixed $id) {
    $state = device_removal_run(array('poller' => 1, 'id' => $id));

    expect($state['result'])->toBeFalse()
        ->and($state['events'])->toBe(array())
        ->and($state['graphs'])->toBe(array(7, 8));
    device_removal_untouched($state);
})->with(array('fraction string' => '12.9', 'fraction' => 12.9, 'exponent' => '12e0', 'zero' => 0, 'oversized' => '16777216', 'array' => array(array(12))));
