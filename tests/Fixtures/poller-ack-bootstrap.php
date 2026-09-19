<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$fixture = getenv('ACK_FIXTURE');
$config = array('base_path' => $fixture, 'library_path' => $fixture . '/lib');
$ack_table = getenv('ACK_REALTIME') === '1' ? 'poller_output_realtime' : 'poller_output';
$ack_db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$ack_db->sqliteCreateFunction('UNIX_TIMESTAMP', 'strtotime', 1);
$ack_db->sqliteCreateFunction('FROM_UNIXTIME', static fn($time) => date('Y-m-d H:i:s', $time), 1);
$ack_db->exec('CREATE TABLE poller_output_rejected(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT,rrd_path TEXT,reason TEXT,first_rejected TEXT,last_rejected TEXT)');
// Model the queue's case-insensitive, PAD SPACE collation.
$ack_db->sqliteCreateCollation('queue_output', static fn($a, $b) => strcasecmp(rtrim($a, ' '), rtrim($b, ' ')));
$ack_db->exec('CREATE TABLE data_local(id INTEGER,data_template_id INTEGER)');
$ack_db->exec('INSERT INTO data_local VALUES(1,0)');
$ack_db->exec("CREATE TABLE poller_item(local_data_id INTEGER,rrd_name TEXT,rrd_num INTEGER,rrd_path TEXT); INSERT INTO poller_item VALUES(1,'value',1,'fixture.rrd')");
$ack_db->exec('CREATE TABLE poller_output(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT COLLATE queue_output)');
// Match production key lookup performance without imposing additional fixture uniqueness.
$ack_db->exec('CREATE INDEX poller_output_sample ON poller_output(local_data_id,rrd_name,time)');
$ack_db->exec('CREATE TABLE poller_output_realtime(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT COLLATE queue_output,poller_id INTEGER)');
$ack_db->exec("INSERT INTO $ack_table VALUES(1,'value','2020-01-01','42'" . ($ack_table === 'poller_output_realtime' ? ',1' : '') . ')');
if (in_array(getenv('ACK_FAIL'), array('field-failure', 'field-success'), true)) {
    $ack_db->exec("UPDATE poller_output_realtime SET output='value:42'");
}
$ack_db->exec('CREATE TABLE poller_time(end_time TEXT)');
$ack_db->exec("INSERT INTO poller_time VALUES('0000-00-00')");
if (in_array(getenv('ACK_FAIL'), array('mixed', 'page', 'tail-failure', 'page-success'), true)) {
    $ack_db->exec('DELETE FROM poller_output');
    $ack_db->exec("INSERT INTO data_local VALUES(2,0); INSERT INTO poller_item VALUES(2,'value',1,'good.rrd')");
    $insert = $ack_db->prepare("INSERT INTO poller_output VALUES(1,'value',?,'42')");
    $ack_db->beginTransaction();
    for ($i = 0; $i < (in_array(getenv('ACK_FAIL'), array('page', 'tail-failure', 'page-success'), true) ? (getenv('ACK_FAIL') === 'tail-failure' ? 40001 : 120001) : 1); $i++) {
        $insert->execute(array(date('Y-m-d H:i:s', 1577836800 + $i)));
    }
    $ack_db->exec("INSERT INTO poller_output VALUES(2,'value','2020-01-01','44')");
    $ack_db->commit();
}
if (in_array(getenv('ACK_FAIL'), array('incomplete', 'incomplete-recent'), true)) {
    $ack_db->exec('UPDATE poller_item SET rrd_num=2');
}
if (getenv('ACK_FAIL') === 'incomplete-recent') {
    $ack_db->exec("UPDATE poller_output SET time='" . date('Y-m-d H:i:s') . "'");
}
function is_hexadecimal($value)
{
    return false;
}
foreach (array('SQL_NO_CACHE' => '', 'POLLER_VERBOSITY_HIGH' => 4) as $name => $value) {
    define($name, $value);
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$args) {}
function cacti_exec($command)
{
    return shell_exec($command);
}
function db_affected_rows()
{
    return $GLOBALS['ack_db']->query('SELECT changes()')->fetchColumn();
}
function cacti_escapeshellcmd($value)
{
    return escapeshellcmd($value);
}
function cacti_escapeshellarg($value)
{
    return escapeshellarg($value);
}
function read_config_option($key)
{
    if ($key === 'storage_location') {
        return getenv('ACK_PROXY') === '1' ? 1 : '';
    }
    return $key === 'realtime_cache_path' ? getenv('ACK_FIXTURE') : ($key === 'path_php_binary' ? PHP_BINARY : '');
}
function get_data_source_path(...$args)
{
    return getenv('ACK_FIXTURE') . '/user_1_1.rrd';
}
function array_rekey($rows, $key, $value)
{
    if (!$rows) {
        return array();
    }
    return array_column($rows, $value, $key);
}
function dsstats_poller_output(...$args)
{
    file_put_contents(getenv('ACK_FIXTURE') . '/dsstats_poller_output.jsonl', json_encode($args[count($args) - 1]) . PHP_EOL, FILE_APPEND);
}
function dsdebug_poller_output(...$args)
{
    file_put_contents(getenv('ACK_FIXTURE') . '/dsdebug_poller_output.jsonl', json_encode($args[count($args) - 1]) . PHP_EOL, FILE_APPEND);
}
function api_plugin_hook_function(...$args)
{
    file_put_contents(getenv('ACK_FIXTURE') . '/api_plugin_hook_function.jsonl', json_encode($args[count($args) - 1]) . PHP_EOL, FILE_APPEND);
}
function boost_poller_on_demand(...$args)
{
    return getenv('ACK_FAIL') === 'handoff' ? null : true;
}
function rrd_init($output = true, $exclusive = false, $acknowledged = false, $timeout = null, &$busy = null)
{
    $GLOBALS['ack_opens'] = ($GLOBALS['ack_opens'] ?? 0) + 1;
    $busy = getenv('ACK_FAIL') === 'busy';
    return !in_array(getenv('ACK_FAIL'), array('init', 'busy'), true);
}
function rrd_close($pipe)
{
    $GLOBALS['ack_closes'] = ($GLOBALS['ack_closes'] ?? 0) + 1;
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    if (getenv('ACK_FAIL') === 'tail-failure' && strpos($sql, 'WHERE po.local_data_id = ? AND po.time = ?') !== false) {
        return false;
    }
    if (strpos($sql, 'SELECT DISTINCT dtr.data_source_name') !== false) {
        return getenv('ACK_FAIL') === 'field-failure' ? false : array(array('data_source_name' => 'value', 'data_name' => 'value'));
    }
    if (strpos($sql, 'poller_data_template_field_mappings') !== false) {
        return array();
    }
    if (getenv('ACK_FAIL') === 'select' && (strpos($sql, 'FROM poller_output AS po') !== false || strpos($sql, 'FROM poller_output_realtime AS port') !== false)) {
        return false;
    }
    // SQLite expresses MySQL's binary cast as a BLOB cast.
    $sql = preg_replace('/\bCAST\(CONVERT\((output|\?) USING utf8mb4\) AS BINARY\)/', 'CAST($1 AS BLOB)', $sql);
    $query = $GLOBALS['ack_db']->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (strpos($sql, 'FROM poller_output AS po') !== false) {
        $depth = count(array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), static fn($frame) => in_array($frame['function'], array('process_poller_output', 'process_poller_output_page'), true)));
        file_put_contents(getenv('ACK_FIXTURE') . '/pages.jsonl', json_encode(array('rows' => count($rows), 'depth' => $depth)) . PHP_EOL, FILE_APPEND);
    }
    return $rows;
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    if (getenv('ACK_FAIL') === 'count') {
        return false;
    }
    $query = $GLOBALS['ack_db']->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}
function db_fetch_row_prepared($sql, $params = array())
{
    $query = $GLOBALS['ack_db']->prepare($sql);
    $query->execute($params);
    return $query->fetch(PDO::FETCH_ASSOC);
}
function db_table_exists($table, ...$args)
{
    return true;
}
function db_begin_transaction()
{
    return $GLOBALS['ack_db']->beginTransaction();
}
function db_commit_transaction()
{
    return $GLOBALS['ack_db']->commit();
}
function db_rollback_transaction()
{
    return $GLOBALS['ack_db']->rollBack();
}
function db_fetch_cell($sql)
{
    return $GLOBALS['ack_db']->query($sql)->fetchColumn();
}
function db_execute_prepared($sql, $params)
{
    if (getenv('ACK_FAIL') === 'delete') {
        return false;
    }
    if (getenv('ACK_FAIL') === '1') {
        throw new RuntimeException('Deletion before acknowledgement');
    }
    // SQLite expresses MySQL's binary cast as a BLOB cast.
    $sql = preg_replace('/\bCAST\(CONVERT\((output|\?) USING utf8mb4\) AS BINARY\)/', 'CAST($1 AS BLOB)', $sql);
    $query = $GLOBALS['ack_db']->prepare($sql);
    return $query->execute($params);
}
function rrdtool_function_update($updates, $pipe = false, &$completed = null, &$rejected = null)
{
    $rejected = array();
    if (getenv('ACK_FAIL') === 'mismatch') {
        // RRDtool refuses the sample because the file lacks a data source.
        $completed = array();
        $rejected = array_fill_keys(array_keys($updates), "unknown DS name 'value'");
        return false;
    }
    static $calls = 0;
    $calls++;
    file_put_contents(getenv('ACK_FIXTURE') . '/updates.json', json_encode($updates));
    if (getenv('ACK_FAIL') === 'replace-space') {
        $GLOBALS['ack_db']->exec("UPDATE " . $GLOBALS['ack_table'] . " SET output='42 ' WHERE time='2020-01-01'");
    }
    if (getenv('ACK_FAIL') === 'replace') {
        $GLOBALS['ack_db']->exec("UPDATE " . $GLOBALS['ack_table'] . " SET output='99' WHERE time='2020-01-01'");
    }

    if (!in_array(getenv('ACK_FAIL'), array('mixed', 'page', 'rejected', 'incomplete', 'incomplete-recent', 'tail-failure', 'page-success'), true)) {
        $GLOBALS['ack_db']->exec('INSERT INTO ' . $GLOBALS['ack_table'] . " VALUES(1,'value','2020-01-02','43'" . (getenv('ACK_REALTIME') === '1' ? ',1' : '') . ')');
    }
    $completed = array();
    if (getenv('ACK_FAIL') !== '1') {
        foreach ($updates as $path => $fields) {
            if ((getenv('ACK_FAIL') === 'mixed' || (getenv('ACK_FAIL') === 'page' && $calls === 1)) && $path === 'fixture.rrd') {
                continue;
            }
            foreach ($fields['times'] as $time => $values) {
                $completed[$path][$time] = getenv('ACK_FAIL') !== 'rejected';
            }
        }
    }
    return in_array(getenv('ACK_FAIL'), array('1', 'mixed', 'page', 'rejected'), true) ? false : 1;
}
function db_close()
{
    file_put_contents(getenv('ACK_FIXTURE') . '/rejected.json', json_encode($GLOBALS['ack_db']->query('SELECT local_data_id, output, rrd_path, reason FROM poller_output_rejected')->fetchAll(PDO::FETCH_ASSOC)));
    if (in_array(getenv('ACK_FAIL'), array('mixed', 'page', 'tail-failure', 'page-success'), true)) {
        file_put_contents(getenv('ACK_FIXTURE') . '/outcome.json', json_encode($GLOBALS['ack_db']->query('SELECT output, COUNT(*) AS remaining FROM poller_output GROUP BY output')->fetchAll(PDO::FETCH_ASSOC)));
        return;
    }
    file_put_contents(getenv('ACK_FIXTURE') . '/outcome.json', json_encode($GLOBALS['ack_db']->query('SELECT output FROM ' . $GLOBALS['ack_table'] . ' ORDER BY time')->fetchAll(PDO::FETCH_COLUMN)));
}
