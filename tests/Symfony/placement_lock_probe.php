<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Run only against the disposable HTTP fixture, with IDs supplied by its runner.
require __DIR__ . '/../../bin/legacy-assignment-bootstrap.php';
require_once __DIR__ . '/../../lib/reports.php';
require_once __DIR__ . '/../../lib/sort.php';
define('KADUPUL_THROW_DATABASE_ERRORS', true);
[$kind, $destination, $parent, $device, $actor] = $placementFixture;
$key = "$database_hostname:$database_port:$database_default";
$owner = $database_sessions[$key];
$writer = new PDO("mysql:host=$database_hostname;port=$database_port;dbname=$database_default;charset=utf8mb4", $database_username, $database_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
$writer->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
$table = $kind === 'tree' ? 'graph_tree' : 'reports';
$items = $kind === 'tree' ? 'graph_tree_items' : 'reports_items';
$column = $kind === 'tree' ? 'graph_tree_id' : 'report_id';
$_SESSION['sess_user_id'] = $actor;
$operation = static fn() => $kind === 'tree'
    ? api_tree_item_save(0, $destination, TREE_ITEM_TYPE_HOST, $parent, '', 0, $device, 0, 1, 1, false)
    : reports_add_devices($destination, [$device], 7, 2);
try {
    $owner->beginTransaction();
    $owner->query("SELECT id FROM $table WHERE id = $destination FOR UPDATE")->fetchColumn();
    $database_sessions[$key] = $writer;
    $started = microtime(true);
    $blocked = false;
    try {
        $operation();
    } catch (Throwable) {
        $blocked = microtime(true) - $started >= 0.9;
    }
    if (!$blocked || $writer->inTransaction()) {
        throw new RuntimeException('Legacy placement did not respect destination lock');
    }
    $owner->commit();
    $database_last_error = '';
    if (!$operation()) {
        throw new RuntimeException('Legacy placement did not recover after lock release');
    }
    if ($operation() !== false || (int) $writer->query("SELECT COUNT(*) FROM $items WHERE $column = $destination AND host_id = $device")->fetchColumn() !== 1) {
        throw new RuntimeException('Legacy placement inserted a duplicate');
    }
} finally {
    foreach ([$owner, $writer] as $db) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    $database_sessions[$key] = $owner;
    $owner->exec("DELETE FROM $items WHERE $column = $destination AND host_id = $device");
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo "PLACEMENT_LOCK_OK\n";
