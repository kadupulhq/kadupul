<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$fixture = getenv('ACK_FIXTURE');
$config = array('base_path' => $fixture, 'library_path' => $fixture . '/lib');
$ack_table = getenv('ACK_REALTIME') === '1' ? 'poller_output_realtime' : 'poller_output';
$ack_db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$ack_db->sqliteCreateFunction('UNIX_TIMESTAMP', function ($value = null) { return $value === null ? time() : strtotime($value); }, -1);
$ack_db->sqliteCreateFunction('FROM_UNIXTIME', function ($value) { return date('Y-m-d H:i:s', $value); }, 1);
$ack_db->sqliteCreateCollation('queue_output', static fn ($a, $b) => strcasecmp(rtrim($a, ' '), rtrim($b, ' ')));
$ack_db->exec('CREATE TABLE data_local(id INTEGER,data_template_id INTEGER)');
$ack_db->exec('INSERT INTO data_local VALUES(1,0)');
$ack_db->exec("CREATE TABLE poller_item(local_data_id INTEGER,rrd_name TEXT,rrd_num INTEGER,rrd_path TEXT); INSERT INTO poller_item VALUES(1,'value',1,'fixture.rrd')");
$ack_db->exec('CREATE TABLE poller_output(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT COLLATE queue_output)');
$ack_db->exec('CREATE TABLE poller_output_realtime(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT COLLATE queue_output,poller_id INTEGER)');
$ack_db->exec("INSERT INTO $ack_table VALUES(1,'value','2020-01-01','42'" . ($ack_table === 'poller_output_realtime' ? ',1' : '') . ')');
$ack_db->exec('CREATE TABLE poller_time(end_time TEXT)');
$ack_db->exec("INSERT INTO poller_time VALUES('0000-00-00')");
if (in_array(getenv('ACK_FAIL'), array('mixed', 'page'), true)) {
    $ack_db->exec('DELETE FROM poller_output');
    $ack_db->exec("INSERT INTO data_local VALUES(2,0); INSERT INTO poller_item VALUES(2,'value',1,'good.rrd')");
    $insert = $ack_db->prepare("INSERT INTO poller_output VALUES(1,'value',?,'42')");
    $ack_db->beginTransaction();
    for ($i = 0; $i < (getenv('ACK_FAIL') === 'page' ? 40001 : 1); $i++) {
        $insert->execute(array(date('Y-m-d H:i:s', 1577836800 + $i)));
    }
    $ack_db->exec("INSERT INTO poller_output VALUES(2,'value','2020-01-01','44')");
    $ack_db->commit();
}
function is_hexadecimal($value) { return false; }
foreach (array('SQL_NO_CACHE' => '', 'POLLER_VERBOSITY_HIGH' => 4) as $name => $value) {
    define($name, $value);
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$args) {}
function cacti_rrdtool_valid_path($path) { return is_file($path); }
function cacti_exec($command) { return shell_exec($command); }
function db_affected_rows() { return $GLOBALS['ack_db']->query('SELECT changes()')->fetchColumn(); }
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
    return $key === 'realtime_cache_path' ? getenv('ACK_FIXTURE') : ($key === 'path_php_binary' ? PHP_BINARY : '');
}
function get_data_source_path(...$args)
{
    return getenv('ACK_FIXTURE') . '/user_1_1.rrd';
}
function array_rekey($rows, ...$args)
{
    return $rows;
}
function dsstats_poller_output(...$args) { file_put_contents(getenv('ACK_FIXTURE') . '/dsstats_poller_output.jsonl', json_encode($args[count($args)-1]) . PHP_EOL, FILE_APPEND); }
function dsdebug_poller_output(...$args) { file_put_contents(getenv('ACK_FIXTURE') . '/dsdebug_poller_output.jsonl', json_encode($args[count($args)-1]) . PHP_EOL, FILE_APPEND); }
function api_plugin_hook_function(...$args) { file_put_contents(getenv('ACK_FIXTURE') . '/api_plugin_hook_function.jsonl', json_encode($args[count($args)-1]) . PHP_EOL, FILE_APPEND); }
function boost_poller_on_demand(...$args)
{
    return true;
}
function rrd_init()
{
    return true;
}
function rrd_close($pipe) {}
function db_fetch_assoc_prepared($sql, $params = array())
{
    if (strpos($sql, 'poller_data_template_field_mappings') !== false) {
        return array();
    }
    $query = $GLOBALS['ack_db']->prepare($sql);
    $query->execute($params);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function db_fetch_cell($sql)
{
    return $GLOBALS['ack_db']->query($sql)->fetchColumn();
}
function db_execute_prepared($sql, $params)
{
    // SQLite expresses MySQL's binary cast as a BLOB cast.
    $sql = preg_replace('/\bCAST\(CONVERT\((output|\?) USING utf8mb4\) AS BINARY\)/', 'CAST($1 AS BLOB)', $sql);
    if (getenv('ACK_FAIL') === 'delete') { return false; }

    if (getenv('ACK_FAIL') === '1') {
        throw new RuntimeException('Deletion before acknowledgement');
    }
    $query = $GLOBALS['ack_db']->prepare($sql);
    return $query->execute($params);
}
function rrdtool_function_update($updates, $pipe = false, &$completed = null)
{
    static $calls = 0;
    $calls++;
    if (getenv('ACK_FAIL') === 'replace-space') {
        $GLOBALS['ack_db']->exec("UPDATE " . $GLOBALS['ack_table'] . " SET output='42 ' WHERE time='2020-01-01'");
    }
    if (getenv('ACK_FAIL') === 'replace') {
        $GLOBALS['ack_db']->exec("UPDATE " . $GLOBALS['ack_table'] . " SET output='99' WHERE time='2020-01-01'");
    }

    if (!in_array(getenv('ACK_FAIL'), array('mixed', 'page', 'rejected', 'after-rejection'), true)) {
    $GLOBALS['ack_db']->exec('INSERT INTO ' . $GLOBALS['ack_table'] . " VALUES(1,'value','2020-01-02','43'" . (getenv('ACK_REALTIME') === '1' ? ',1' : '') . ')');
    }
    $completed = array();
    if (getenv('ACK_FAIL') !== '1') {
        foreach ($updates as $path => $fields) {
            if ((getenv('ACK_FAIL') === 'mixed' || (getenv('ACK_FAIL') === 'page' && $calls === 1)) && $path === 'fixture.rrd') { continue; }
            foreach ($fields['times'] as $time => $values) {
                $completed[$path][$time] = getenv('ACK_FAIL') !== 'rejected';
            }
        }
    }
    return in_array(getenv('ACK_FAIL'), array('0','replace','replace-space'), true) ? 1 : false;
}
function db_close()
{
    if (in_array(getenv('ACK_FAIL'), array('mixed', 'page'), true)) {
        $rows = $GLOBALS['ack_db']->query('SELECT output, COUNT(*) AS remaining FROM poller_output GROUP BY output')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['remaining'] = (int) $row['remaining']; }
        unset($row);
        file_put_contents(getenv('ACK_FIXTURE') . '/outcome.json', json_encode($rows));
        return;
    }
    file_put_contents(getenv('ACK_FIXTURE') . '/outcome.json', json_encode($GLOBALS['ack_db']->query('SELECT output FROM ' . $GLOBALS['ack_table'] . ' ORDER BY time')->fetchAll(PDO::FETCH_COLUMN)));
}
