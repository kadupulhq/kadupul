<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$directory = $argv[2];
$scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
require $root . '/include/global_constants.php';
require $root . '/lib/poller.php';
require $root . '/lib/data_source_profile_integrity.php';
function collector_connection(bool $admin = false): PDO
{
    $prefix = $admin && getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
    return new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv($prefix . 'USER') ?: 'root', getenv($prefix . 'PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
$source = collector_connection();
$remote = collector_connection();
$installer = collector_connection(true);
$suffix = bin2hex(random_bytes(6));
$maps = [
    'source' => ['data_source_profiles' => 'src_profiles_' . $suffix, 'data_template_data' => 'src_data_' . $suffix],
    'remote' => ['data_source_profiles' => 'rc_profiles_' . $suffix, 'data_template_data' => 'rc_data_' . $suffix],
];
$calls = [];
$log = [];
$affected = 0;
function collector_statement(string $sql, array $params = [], $connection = false): PDOStatement
{
    $connection = $connection ?: $GLOBALS['source'];
    $side = $connection === $GLOBALS['source'] ? 'source' : 'remote';
    $GLOBALS['calls'][] = [$side, $sql];
    foreach ($GLOBALS['maps'][$side] as $logical => $physical) {
        $sql = preg_replace('/\b' . $logical . '\b/', $physical, $sql);
    }
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    $GLOBALS['affected'] = $statement->rowCount();
    return $statement;
}
function db_fetch_assoc_prepared($sql, $params = [], $log = true, $connection = false)
{
    return collector_statement($sql, $params, $connection)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql, $log = true, $connection = false)
{
    return db_fetch_assoc_prepared($sql, [], $log, $connection);
}
function db_fetch_cell($sql, $default = '', $log = true, $connection = false)
{
    return collector_statement($sql, [], $connection)->fetchColumn();
}
function db_fetch_row($sql, $log = true, $connection = false)
{
    $row = collector_statement($sql, [], $connection)->fetch(PDO::FETCH_ASSOC) ?: [];
    if (isset($row['Create Table'])) {
        foreach ($GLOBALS['maps']['source'] as $logical => $physical) {
            $row['Create Table'] = str_replace($physical, $logical, $row['Create Table']);
        }
    }
    return $row;
}
function db_execute($sql, $log = true, $connection = false)
{
    collector_statement($sql, [], $connection);
    return true;
}
function db_table_exists($table, $log = true, $connection = false)
{
    $connection = $connection ?: $GLOBALS['source'];
    $side = $connection === $GLOBALS['source'] ? 'source' : 'remote';
    $query = $connection->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $query->execute([$GLOBALS['maps'][$side][$table]]);
    return (int) $query->fetchColumn() === 1;
}
function db_column_exists($table, $column, $log = true, $connection = false)
{
    return in_array($column, array_column(db_fetch_assoc('SHOW COLUMNS FROM ' . $table, $log, $connection), 'Field'), true);
}
function sql_save($row, $table, $key = 'id', $autoinc = true, $connection = false)
{
    $columns = array_keys($row);
    $updates = array_map(static fn($column) => '`' . $column . '`=VALUES(`' . $column . '`)', $columns);
    collector_statement('INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates), array_values($row), $connection);
    return $row['id'];
}
function db_qstr($value)
{
    return $GLOBALS['source']->quote((string) $value);
}
function db_affected_rows($connection)
{
    return $GLOBALS['affected'];
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log($message, ...$options)
{
    $GLOBALS['log'][] = $message;
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
try {
    foreach ($maps as $side => $map) {
        $connection = $side === 'source' ? $source : $remote;
        $connection->exec('CREATE TABLE `' . $map['data_source_profiles'] . '` (id INTEGER PRIMARY KEY, name VARCHAR(32), step INTEGER) ENGINE=InnoDB');
        $connection->exec('CREATE TABLE `' . $map['data_template_data'] . '` (id INTEGER PRIMARY KEY, data_source_profile_id INTEGER NOT NULL, name VARCHAR(32)) ENGINE=InnoDB');
        $connection->exec('INSERT INTO `' . $map['data_source_profiles'] . "` VALUES (1,'default',300)");
    }
    $source->exec('INSERT INTO `' . $maps['source']['data_source_profiles'] . "` VALUES (77,'custom',60)");
    $remote->exec('INSERT INTO `' . $maps['remote']['data_template_data'] . "` VALUES (1,1,'existing')");
    foreach (data_source_profile_reference_triggers($maps['remote']['data_source_profiles'], $maps['remote']['data_template_data'], 'collector_guard_' . $suffix) as $definition) {
        $installer->exec($definition['sql']);
    }
    if (($scenario['failure'] ?? '') === 'copy') {
        $installer->exec('CREATE TRIGGER `collector_reject_' . $suffix . '` BEFORE INSERT ON `' . $maps['remote']['data_source_profiles'] . "` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Parent copy rejected'");
    }
    $id = ($scenario['failure'] ?? '') === 'missing' ? 98 : 77;
    $data = [['id' => 2, 'data_source_profile_id' => $id, 'name' => 'replicated']];
    if (($scenario['collector'] ?? '') === 'bulk') {
        replicate_out_table($remote, $data, 'data_template_data', 2);
    } else {
        replicate_table_to_poller($remote, $data, 'data_template_data');
    }
    $rows = $remote->query('SELECT * FROM `' . $maps['remote']['data_template_data'] . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $parent = $remote->query('SELECT id FROM `' . $maps['remote']['data_source_profiles'] . '` WHERE id=77')->fetchColumn();
    file_put_contents($directory . '/result.json', json_encode(['rows' => $rows, 'parent' => $parent, 'log' => $log, 'calls' => $calls], JSON_THROW_ON_ERROR));
} finally {
    foreach ($maps as $map) {
        $remote->exec('DROP TABLE IF EXISTS `' . $map['data_template_data'] . '`');
        $remote->exec('DROP TABLE IF EXISTS `' . $map['data_source_profiles'] . '`');
    }
}
