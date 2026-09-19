<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs the production dead-letter and replay helpers against SQLite. The
// database contract suite repeats the moves on MySQL and MariaDB.
$root = $argv[1];
$directory = $argv[2];
if ($argv[3] === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($time) => $time === null ? null : strtotime($time), 1);
$db->sqliteCreateFunction('FROM_UNIXTIME', static fn($time) => date('Y-m-d H:i:s', $time), 1);
$fail = array();
$logs = array();
$settings = array('poller_rejected_hours' => 24, 'poller_rejected_rows' => 4);
$rejected_table = true;

function read_config_option($key)
{
    return $GLOBALS['settings'][$key] ?? '';
}
function cacti_log($message, ...$args)
{
    $GLOBALS['logs'][] = $message;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function sqlite_statement($sql)
{
    // SQLite spells MySQL's binary cast and INSERT IGNORE differently.
    $sql = preg_replace('/\bCAST\(CONVERT\(((?:\w+\.)?output|\?) USING utf8mb4\) AS BINARY\)/', 'CAST($1 AS BLOB)', $sql);
    return str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
}
function failing($kind, $sql)
{
    foreach ($GLOBALS['fail'] as $pattern => $wanted) {
        if ($wanted === $kind && strpos($sql, $pattern) !== false) {
            return true;
        }
    }
    return false;
}
function db_table_exists($table, ...$args)
{
    return $GLOBALS['rejected_table'];
}
function db_execute($sql, ...$args)
{
    // The MySQL DDL is recorded; SQLite receives an equivalent table.
    $GLOBALS['logs'][] = 'DDL ' . (strpos($sql, 'CREATE TABLE IF NOT EXISTS poller_output_rejected') !== false ? 'rejected' : 'other');
    $GLOBALS['db']->exec('CREATE TABLE IF NOT EXISTS poller_output_rejected(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT,rrd_path TEXT,reason TEXT,first_rejected TEXT,last_rejected TEXT)');
    $GLOBALS['rejected_table'] = true;
    return !failing('execute', $sql);
}
function db_execute_prepared($sql, $params = array(), ...$args)
{
    if (failing('execute', $sql)) {
        return false;
    }
    $statement = $GLOBALS['db']->prepare(sqlite_statement($sql));
    $statement->execute($params);
    $GLOBALS['affected'] = $statement->rowCount();
    return true;
}
function db_affected_rows()
{
    return $GLOBALS['affected'];
}
function db_fetch_row_prepared($sql, $params = array())
{
    if (failing('read', $sql)) {
        return false;
    }
    $statement = $GLOBALS['db']->prepare(sqlite_statement($sql));
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    if (failing('read', $sql)) {
        return false;
    }
    $statement = $GLOBALS['db']->prepare(sqlite_statement($sql));
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $statement = $GLOBALS['db']->prepare(sqlite_statement($sql));
    $statement->execute($params);
    return $statement->fetchColumn();
}
function db_begin_transaction()
{
    return isset($GLOBALS['fail']['begin']) ? false : $GLOBALS['db']->beginTransaction();
}
function db_commit_transaction()
{
    if (isset($GLOBALS['fail']['commit'])) {
        return false;
    }
    return $GLOBALS['db']->commit();
}
function db_rollback_transaction()
{
    return $GLOBALS['db']->inTransaction() ? $GLOBALS['db']->rollBack() : false;
}
require $root . '/lib/poller.php';

function reset_queues($groups, $age = 60)
{
    $db = $GLOBALS['db'];
    $db->exec('DROP TABLE IF EXISTS poller_output');
    $db->exec('DROP TABLE IF EXISTS poller_output_rejected');
    $db->exec('DROP TABLE IF EXISTS poller_output_boost_arch_1');
    $db->exec('CREATE TABLE poller_output(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT)');
    $db->exec('CREATE TABLE poller_output_boost_arch_1(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT)');
    $GLOBALS['rejected_table'] = false;
    $GLOBALS['fail'] = array();
    $insert = $db->prepare('INSERT INTO poller_output VALUES(1, ?, ?, ?)');
    for ($group = 0; $group < $groups; $group++) {
        foreach (array('a', 'b') as $field) {
            $insert->execute(array($field, date('Y-m-d H:i:s', time() - $age + $group), (string) $group));
        }
    }
}
function counts()
{
    $db = $GLOBALS['db'];
    $rejected = $GLOBALS['rejected_table'] ? (int) $db->query('SELECT COUNT(*) FROM poller_output_rejected')->fetchColumn() : null;
    return array((int) $db->query('SELECT COUNT(*) FROM poller_output')->fetchColumn(), $rejected);
}

$results = array();
$scenario = function ($name, $groups, $failures, $call, $age = 60) use (&$results) {
    reset_queues($groups, $age);
    $GLOBALS['fail'] = $failures;
    $GLOBALS['logs'] = array();
    $value = $call();
    $results[$name] = array($value, counts(), $GLOBALS['logs']);
};
$move = fn() => poller_dead_letter_rejected(1, '/rra/one.rrd', "unknown DS name 'b'");

$scenario('row-limit', 5, array(), $move);
$scenario('under-limit', 2, array(), $move);
$scenario('empty', 0, array(), $move);
$scenario('foreign-table', 5, array(), fn() => poller_dead_letter_rejected(1, '/rra/one.rrd', 'x', array('host')));
$scenario('archive', 0, array(), function () {
    $GLOBALS['db']->exec("INSERT INTO poller_output_boost_arch_1 VALUES(1,'a','" . date('Y-m-d H:i:s', time() - 90000) . "','7')");
    return poller_dead_letter_rejected(1, '/rra/one.rrd', 'x', array('poller_output_boost_arch_1'));
});
$scenario('inspect-failure', 5, array('COUNT(*) AS samples' => 'read'), $move);
$scenario('select-failure', 5, array('SELECT local_data_id, rrd_name, time, output' => 'read'), $move);
$scenario('begin-failure', 5, array('begin' => true), $move);
$scenario('copy-failure', 5, array('INSERT INTO poller_output_rejected' => 'execute'), $move);
$scenario('commit-failure', 5, array('commit' => true), $move);
$scenario('replay', 5, array(), function () {
    poller_dead_letter_rejected(1, '/rra/one.rrd', 'x');
    return array(poller_replay_rejected(1, true), poller_replay_rejected(1), poller_replay_rejected(null));
});
$scenario('replay-missing', 0, array(), fn() => poller_replay_rejected(null));
$scenario('replay-read-failure', 5, array(), function () {
    poller_dead_letter_rejected(1, '/rra/one.rrd', 'x');
    $GLOBALS['fail'] = array('FROM poller_output_rejected WHERE' => 'read');
    return poller_replay_rejected(1);
});
$scenario('replay-begin-failure', 5, array(), function () {
    poller_dead_letter_rejected(1, '/rra/one.rrd', 'x');
    $GLOBALS['fail'] = array('begin' => true);
    return poller_replay_rejected(1);
});
$scenario('replay-delete-failure', 5, array(), function () {
    poller_dead_letter_rejected(1, '/rra/one.rrd', 'x');
    $GLOBALS['fail'] = array('DELETE FROM poller_output_rejected' => 'execute');
    return poller_replay_rejected(1);
});
$scenario('replay-commit-failure', 5, array(), function () {
    poller_dead_letter_rejected(1, '/rra/one.rrd', 'x');
    $GLOBALS['fail'] = array('commit' => true);
    return poller_replay_rejected(1);
});
echo json_encode($results, JSON_THROW_ON_ERROR);
