<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 2);
$case = $argv[1];
$directory = $argv[2];
$copy = $directory . '/integrity.php';
copy($root . '/lib/data_source_profile_integrity.php', $copy);
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $copy);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/lib/data_source_profile_integrity.php');
    require __DIR__ . '/rrd-process-coverage.php';
}
$source = new PDO('sqlite::memory:');
$remote = new PDO('sqlite::memory:');
$source->exec('CREATE TABLE data_source_profiles (id INTEGER PRIMARY KEY, name TEXT)');
$source->exec("INSERT INTO data_source_profiles VALUES (77,'Custom profile'),(1,'Updated default')");
if ($case !== 'missing-table' && $case !== 'create-failure' && $case !== 'missing-definition') {
    $remote->exec('CREATE TABLE data_source_profiles (id INTEGER PRIMARY KEY, name TEXT)');
    $remote->exec("INSERT INTO data_source_profiles VALUES (1,'Old default')");
}
foreach ([$source, $remote] as $connection) {
    $connection->exec('CREATE TABLE data_source_profiles_rra (id INTEGER PRIMARY KEY, data_source_profile_id INTEGER, steps INTEGER)');
    $connection->exec('CREATE TABLE data_source_profiles_cf (data_source_profile_id INTEGER, consolidation_function_id INTEGER)');
}
$source->exec('INSERT INTO data_source_profiles_rra VALUES (1,1,1),(77,77,6)');
$source->exec('INSERT INTO data_source_profiles_cf VALUES (1,1),(77,1)');
if ($case === 'copy-exception') {
    $remote->exec("CREATE TRIGGER reject_parent BEFORE INSERT ON data_source_profiles BEGIN SELECT RAISE(FAIL,'Rejected parent'); END");
}
function db_fetch_assoc_prepared($sql, $params, $log = true, $connection = false)
{
    if ($GLOBALS['case'] === 'query-failure') {
        return false;
    }
    $query = ($connection ?: $GLOBALS['source'])->prepare($sql);
    $query->execute($params);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
function db_table_exists($table, $log, $connection)
{
    return (bool) $connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
}
function db_fetch_row($sql)
{
    return $GLOBALS['case'] === 'missing-definition' ? array() : array('Create Table' => $GLOBALS['source']->query("SELECT sql FROM sqlite_master WHERE name='data_source_profiles'")->fetchColumn());
}
function db_execute($sql, $log, $connection)
{
    if ($GLOBALS['case'] === 'create-failure') {
        return false;
    }
    return $connection->exec($sql) !== false;
}
function sql_save($row, $table, $key, $autoincrement, $connection)
{
    if ($GLOBALS['case'] === 'copy-failure') {
        return false;
    }
    $connection->prepare('INSERT INTO data_source_profiles VALUES (?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name')->execute(array($row['id'], $row['name']));
    return $row['id'];
}
function db_execute_prepared($sql, $params, $log, $connection)
{
    return $connection->prepare($sql)->execute($params);
}
function cacti_log(...$arguments) {}
require $copy;
$id = match ($case) {
    'missing-parent' => 98, 'negative' => -1, 'invalid' => '3 --foo', 'zero' => 0, default => 77
};
$data = array(array('data_source_profile_id' => $id), array('data_source_profile_id' => $id));
if ($case === 'success') {
    $data[] = array('data_source_profile_id' => 1);
}
$result = replicate_data_source_profile_parents($remote, $data);
$rows = db_table_exists('data_source_profiles', false, $remote) ? $remote->query('SELECT * FROM data_source_profiles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : array();
file_put_contents($directory . '/result.json', json_encode(array('success' => $result, 'rows' => $rows), JSON_THROW_ON_ERROR));
