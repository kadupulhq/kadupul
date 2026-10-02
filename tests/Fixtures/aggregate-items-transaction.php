<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require dirname(__DIR__, 2) . '/lib/api_aggregate.php';
require dirname(__DIR__, 2) . '/include/global_constants.php';
function cacti_log(...$arguments) {}
$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
$database_hostname = 'aggregate-fixture';
$database_port = 0;
$database_default = 'aggregate-fixture';
$database_sessions = ['aggregate-fixture:0:aggregate-fixture' => $db];
$table = 'aggregate_graphs_graph_item';
$db->exec("CREATE TABLE $table (aggregate_graph_id INTEGER, graph_templates_item_id INTEGER, sequence INTEGER, color_template INTEGER, t_graph_type_id TEXT, graph_type_id INTEGER, t_cdef_id TEXT, cdef_id INTEGER, item_skip TEXT, item_total TEXT)");
$db->exec("INSERT INTO $table (aggregate_graph_id,graph_templates_item_id) VALUES(1,100),(2,200)");
$before = $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC);
$items = [['aggregate_graph_id' => 1, 'graph_templates_item_id' => 11], ['aggregate_graph_id' => 1, 'graph_templates_item_id' => 12]];
$case = $argv[1];
$reached = false;
if ($case === 'prepare') {
    $db->exec("DROP TABLE $table");
    $reached = $db->prepare("DELETE FROM $table WHERE aggregate_graph_id=?") === false && $db->errorCode() === 'HY000';
} elseif ($case === 'delete') {
    $db->exec("CREATE TRIGGER refuse_delete BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT,'delete refused'); END");
    $statement = $db->prepare("DELETE FROM $table WHERE aggregate_graph_id=?");
    $reached = !$statement->execute([1]) && $statement->errorCode() === '23000';
} elseif (in_array($case, ['later-insert', 'caller-failure'], true)) {
    $db->exec("CREATE TRIGGER refuse_later_insert BEFORE INSERT ON $table WHEN NEW.graph_templates_item_id=12 BEGIN SELECT RAISE(FAIL,'later row refused'); END");
    $db->beginTransaction();
    $statement = $db->prepare("INSERT INTO $table (aggregate_graph_id,graph_templates_item_id) VALUES(1,11),(1,12)");
    $reached = !$statement->execute() && $statement->errorCode() === '23000'
        && (int) $db->query("SELECT COUNT(*) FROM $table WHERE graph_templates_item_id=11")->fetchColumn() === 1;
    $db->rollBack();
} else {
    $reached = true;
}
$caller = str_starts_with($case, 'caller-');
if ($caller) {
    $db->exec('CREATE TABLE caller_work (value TEXT)');
    $db->beginTransaction();
    $db->exec("INSERT INTO caller_work VALUES('preserve')");
}
if ($case === 'mixed-owners') {
    $items[1]['aggregate_graph_id'] = 2;
}
$handler = static fn() => false;
set_error_handler($handler);
$saved = aggregate_graph_items_save($items, $table);
$actualHandler = set_error_handler(static fn() => false);
restore_error_handler();
restore_error_handler();
$rows = $case === 'prepare' ? null : $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC);
$result = ['reached' => $reached, 'saved' => $saved, 'before' => $before, 'rows' => $rows,
    'transaction' => $db->inTransaction(), 'handler_preserved' => $actualHandler === $handler,
    'caller_work' => $caller ? $db->query('SELECT value FROM caller_work')->fetchColumn() : null];
if ($caller) {
    $db->rollBack();
    $result['after_caller_rollback'] = $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($result, JSON_THROW_ON_ERROR);
