<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$config = array('base_path' => dirname(__DIR__), 'poller_id' => 1);
$database_default = 'fixture database; echo ignored';
$database_username = 'fixture';
$database_password = '';
$database_hostname = 'localhost';
$database_port = '3306';
define('CACTI_VERSION', getenv('AUDIT_TEST_VERSION'));
define('COPYRIGHT_YEARS', '2004-2026');
$db = new PDO('sqlite:' . getenv('AUDIT_TEST_SQLITE'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_escapeshellarg($value)
{
    return escapeshellarg($value);
}
function db_execute($sql)
{
    $failure = getenv('AUDIT_TEST_CASE');
    if (($failure === 'load-truncate-columns' && $sql === 'TRUNCATE table_columns') || ($failure === 'load-truncate-indexes' && $sql === 'TRUNCATE table_indexes')) {
        return false;
    }
    file_put_contents(dirname(__DIR__) . '/db-mutations', $sql . "\n", FILE_APPEND);
    if (str_starts_with($sql, 'RENAME TABLE')) {
        if (getenv('AUDIT_TEST_CASE') === 'swap-failure') {
            return false;
        }
        preg_match_all('/`([^`]+)` TO `([^`]+)`/', $sql, $pairs, PREG_SET_ORDER);
        $GLOBALS['db']->beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $GLOBALS['db']->exec('ALTER TABLE `' . $pair[1] . '` RENAME TO `' . $pair[2] . '`');
            }
            $GLOBALS['db']->commit();
            return true;
        } catch (Throwable $error) {
            $GLOBALS['db']->rollBack();
            return false;
        }
    }
    if (str_starts_with($sql, 'ALTER TABLE')) {
        if (getenv('AUDIT_TEST_CASE') === 'repair-failure') {
            return false;
        }
        // SQLite cannot MODIFY COLUMN; all plans and acknowledgements stay visible.
        return true;
    }
    if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS')) {
        return true;
    }
    $sql = preg_replace('/^TRUNCATE (\w+)/', 'DELETE FROM $1', $sql);
    return $GLOBALS['db']->exec($sql) !== false;
}
function db_table_exists($table)
{
    if (getenv('AUDIT_TEST_CASE') === 'create-' . $table . '-failure') {
        return false;
    }
    $query = $GLOBALS['db']->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute(array($table));
    return (bool) $query->fetchColumn();
}
function audit_query($sql, $params = array())
{
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($params);
    return $query;
}
function db_fetch_cell($sql)
{
    if (str_contains($sql, 'FROM version')) {
        return str_starts_with(getenv('AUDIT_TEST_CASE'), 'upgrade-') ? '0.0' : CACTI_VERSION;
    }
    return audit_query($sql)->fetchColumn();
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return audit_query($sql, $params)->fetchColumn();
}
function db_fetch_row($sql)
{
    if (str_starts_with($sql, 'SHOW TABLE STATUS')) {
        return array('Collation' => 'utf8mb4_unicode_ci');
    }
    return audit_query($sql)->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_row_prepared($sql, $params = array())
{
    if (str_contains($sql, 'information_schema.tables')) {
        return array('ENGINE' => 'InnoDB', 'COLLATION' => 'utf8mb4');
    }
    return audit_query($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    return audit_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql)
{
    if ($sql === 'SHOW TABLES') {
        return array(array('Tables_in_' . $GLOBALS['database_default'] => 'probe'));
    }
    if (preg_match('/SHOW COLUMNS (?:IN|FROM) (\w+)/', $sql, $match)) {
        $rows = $GLOBALS['db']->query('PRAGMA table_info(' . $match[1] . ')')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn($row) => array('Field' => $row['name'], 'Type' => strtolower($row['type']) === 'integer' ? 'int' : strtolower($row['type']), 'Null' => $row['notnull'] ? 'NO' : 'YES', 'Key' => $row['pk'] ? 'PRI' : '', 'Default' => $row['dflt_value'], 'Extra' => ''), $rows);
    }
    if (preg_match('/SHOW INDEX(?:ES)? (?:IN|FROM) (\w+)/', $sql, $match)) {
        $rows = array();
        foreach ($GLOBALS['db']->query('PRAGMA index_list(' . $match[1] . ')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            foreach ($GLOBALS['db']->query('PRAGMA index_info(`' . $index['name'] . '`)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $rows[] = array('Table' => $match[1], 'Non_unique' => $index['unique'] ? 0 : 1, 'Key_name' => $index['name'], 'Seq_in_index' => $column['seqno'] + 1, 'Column_name' => $column['name'], 'Collation' => 'A', 'Cardinality' => 1, 'Sub_part' => null, 'Packed' => null, 'Null' => 'YES', 'Index_type' => 'BTREE', 'Comment' => '');
            }
        }
        return $rows;
    }
    return audit_query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_execute_prepared($sql, $params = array())
{
    if ((getenv('AUDIT_TEST_CASE') === 'load-column-failure' && str_contains($sql, 'INSERT INTO table_columns')) || (getenv('AUDIT_TEST_CASE') === 'load-index-failure' && str_contains($sql, 'INSERT INTO table_indexes'))) {
        return false;
    }
    return audit_query($sql, $params) !== false;
}
function db_column_exists($table, $column)
{
    return in_array($column, array_column($GLOBALS['db']->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
}
function db_index_exists($table, $index)
{
    return in_array($index, array_column($GLOBALS['db']->query('PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
}
function db_dump_data(...$arguments)
{
    file_put_contents(dirname(__DIR__) . '/dump-called', '1');
    return getenv('AUDIT_TEST_CASE') === 'dump-failure' ? 1 : 0;
}

function cacti_log($message, ...$arguments)
{
    file_put_contents(dirname(__DIR__) . '/upgrade-log', $message . "\n", FILE_APPEND);
}
function api_plugin_uninstall($name, $removeTables)
{
    file_put_contents(dirname(__DIR__) . '/uninstall-log', json_encode(array($name, $removeTables)) . "\n", FILE_APPEND);
}
function get_cacti_cli_version()
{
    return 'Fixture CLI ' . CACTI_VERSION;
}
