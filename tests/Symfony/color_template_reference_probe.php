<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require getcwd() . '/include/vendor/autoload.php';
require getcwd() . '/include/global_constants.php';
require getcwd() . '/lib/database.php';
require getcwd() . '/lib/api_aggregate.php';
// Keep the fixture's expected failures out of the application log. The writer
// and its prepared SQL adapter remain the actual production functions.
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
if ($dsn !== false && $dsn !== '') {
    $db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} else {
    $configuration = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd());
    $db = (new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($configuration))->get();
}
$persistent = $dsn !== false && $dsn !== '';
if ($persistent) {
    if (!preg_match('/^color_race_[a-f0-9]+$/D', $db->query('SELECT DATABASE()')->fetchColumn())) {
        throw new RuntimeException('Persistent fixture requires its isolated owned schema.');
    }
    $db->exec('DELETE FROM color_templates');
}
foreach (['color_templates', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
    if ($persistent) {
        continue;
    }
    $ddl = $db->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
    $db->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
}
$database_hostname = 'fixture';
$database_port = 0;
$database_default = 'fixture';
$database_sessions = ['fixture:0:fixture' => $db];
$db->exec("INSERT INTO color_templates (color_template_id,name) VALUES (1,'First'),(2,'Second')");
$results = [];
foreach (['aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
    $id = $table === 'aggregate_graphs_graph_item' ? 'aggregate_graph_id' : 'aggregate_template_id';
    $items = [[$id => 1, 'graph_templates_item_id' => 1, 'color_template' => 2], [$id => 1, 'graph_templates_item_id' => 2, 'color_template' => 1]];
    $results[$table . '.saved'] = aggregate_graph_items_save($items, $table) && !$db->inTransaction();
    $before = $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC);
    $db->beginTransaction();
    $db->exec("INSERT INTO color_templates (color_template_id,name) VALUES (3,'Caller')");
    $invalid = $items;
    $invalid[0]['color_template'] = 99999999;
    $results[$table . '.stale'] = !aggregate_graph_items_save($invalid, $table) && $db->inTransaction()
        && $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $before;
    $collision = $items;
    $collision[0]['item_skip'] = null;
    $results[$table . '.atomic'] = !aggregate_graph_items_save($collision, $table) && $db->inTransaction()
        && $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $before
        && (int) $db->query('SELECT COUNT(*) FROM color_templates WHERE color_template_id=3')->fetchColumn() === 1;
    $results[$table . '.duplicate'] = !aggregate_graph_items_save([$items[0], $items[0]], $table) && $db->inTransaction()
        && $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $before;
    $results[$table . '.caller'] = aggregate_graph_items_save($items, $table) && $db->inTransaction();
    $db->rollBack();
    if ($persistent) {
        $results[$table . '.caller'] = $results[$table . '.caller']
            && $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $before
            && (int) $db->query('SELECT COUNT(*) FROM color_templates WHERE color_template_id=3')->fetchColumn() === 0;
    }
    $engineBefore = $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC);
    $db->exec("ALTER TABLE $table ENGINE=MyISAM");
    $results[$table . '.engine'] = !aggregate_graph_items_save($items, $table) && !$db->inTransaction()
        && $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $engineBefore;
    $db->exec("ALTER TABLE $table ENGINE=InnoDB");
}
echo json_encode($results, JSON_THROW_ON_ERROR) . "\n";
