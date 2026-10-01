<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require getcwd() . '/include/global_constants.php';
require getcwd() . '/lib/database.php';
require getcwd() . '/lib/api_aggregate.php';
function cacti_log(...$arguments): void {}
function cacti_count($items): int
{
    return count($items);
}
function clean_up_lines($value): string
{
    return $value !== '' ? preg_replace('/\s*[\r\n]+\s*/', ' ', $value) : $value;
}

$dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
$db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = $argv[1];
if (!preg_match('/^color_race_[a-f0-9]+$/D', $schema)) {
    throw new RuntimeException('Unexpected isolated schema.');
}
$db->exec('USE `' . $schema . '`');
$db->exec('SET SESSION innodb_lock_wait_timeout=15');
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . ($argv[3] === 'repeatable' ? 'REPEATABLE READ' : 'READ COMMITTED'));
$database_hostname = 'fixture';
$database_port = 0;
$database_default = 'fixture';
$database_sessions = ['fixture:0:fixture' => $db];
$table = $argv[2];
$id = $table === 'aggregate_graphs_graph_item' ? 'aggregate_graph_id' : 'aggregate_template_id';
echo $db->query('SELECT CONNECTION_ID()')->fetchColumn() . "\n";
flush();
$saved = aggregate_graph_items_save([[$id => 1, 'graph_templates_item_id' => 2, 'color_template' => 1]], $table);
echo json_encode(['saved' => $saved, 'transaction' => $db->inTransaction()], JSON_THROW_ON_ERROR) . "\n";
