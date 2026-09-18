<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerAcknowledgement;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'poller_delete_output_rows'));
function db_affected_rows()
{
    return $GLOBALS['ack_db']->query('SELECT changes()')->fetchColumn();
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'process_poller_output'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'process_poller_output_page'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/poller_realtime.php'), 'process_poller_output_rt'));
function cacti_sizeof($v)
{
    return is_array($v) ? count($v) : 0;
}
function cacti_log(...$args) {}
function read_config_option($key)
{
    return $key === 'realtime_cache_path' ? $GLOBALS['ack_dir'] : '';
}
function get_data_source_path(...$args)
{
    return $GLOBALS['ack_dir'] . '/user_1_1.rrd';
}
function array_rekey($rows, $key, $value)
{
    $mapped = array();
    foreach ($rows as $row) {
        $mapped[$row[$key]] = is_array($value) ? array_intersect_key($row, array_flip($value)) : $row[$value];
    }
    return $mapped;
}
function is_hexadecimal($value)
{
    return ctype_xdigit($value);
}

function dsstats_poller_output(...$args) {}
function dsdebug_poller_output(...$args) {}
function api_plugin_hook_function(...$args) {}
function boost_poller_on_demand(...$args)
{
    return true;
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    if (strpos($sql, 'poller_data_template_field_mappings') !== false) {
        return array(array('keyname' => '1_value', 'data_source_name' => 'value'), array('keyname' => '1_hidden', 'data_source_name' => 'hidden'));
    }
    $statement = $GLOBALS['ack_db']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
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
    if ($GLOBALS['ack_result'] === false) {
        throw new \RuntimeException('Deleted samples without acknowledgement');
    }
    $statement = $GLOBALS['ack_db']->prepare($sql);
    return $statement->execute($params);
}
function rrdtool_function_update($updates, $pipe = false, &$completed = null)
{
    $GLOBALS['ack_updates'] = $updates;
    // A new timestamp arrives after selection but before the write completes.
    $GLOBALS['ack_db']->exec("INSERT INTO " . $GLOBALS['ack_table'] . " VALUES (1,'value','2020-01-02','43'" . ($GLOBALS['ack_table'] === 'poller_output_realtime' ? ',1' : '') . ")");
    $completed = array();
    if ($GLOBALS['ack_result'] !== false) {
        foreach ($updates as $path => $fields) {
            foreach ($fields['times'] as $time => $values) {
                $completed[$path][$time] = true;
            }
        }
    }
    return $GLOBALS['ack_result'];
}
beforeEach(function () {
    foreach (array('SQL_NO_CACHE' => '','POLLER_VERBOSITY_HIGH' => 4) as $key => $value) {
        if (!defined($key)) {
            define($key, $value);
        }
    }
    $GLOBALS['ack_dir'] = sys_get_temp_dir() . '/poller-ack-' . bin2hex(random_bytes(8));
    mkdir($GLOBALS['ack_dir'], 0700);
    file_put_contents($GLOBALS['ack_dir'] . '/rrd.php', '<?php');
    file_put_contents($GLOBALS['ack_dir'] . '/user_1_1.rrd', 'fixture');
    $GLOBALS['config'] = array('library_path' => $GLOBALS['ack_dir']);
    $GLOBALS['ack_db'] = new \PDO('sqlite::memory:');
    $GLOBALS['ack_db']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $GLOBALS['ack_db']->sqliteCreateFunction('UNIX_TIMESTAMP', 'strtotime', 1);
    $GLOBALS['ack_db']->exec("CREATE TABLE data_local(id INTEGER,data_template_id INTEGER); INSERT INTO data_local VALUES(1,0)");
    $GLOBALS['ack_db']->exec("CREATE TABLE poller_item(local_data_id INTEGER,rrd_name TEXT,rrd_num INTEGER,rrd_path TEXT); INSERT INTO poller_item VALUES(1,'value',1,'fixture.rrd')");
    $GLOBALS['ack_db']->exec('CREATE TABLE poller_output(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT)');
    $GLOBALS['ack_db']->exec('CREATE TABLE poller_output_realtime(local_data_id INTEGER,rrd_name TEXT,time TEXT,output TEXT,poller_id INTEGER)');
});
afterEach(function () {
    foreach (glob($GLOBALS['ack_dir'] . '/*') as $file) {
        unlink($file);
    }rmdir($GLOBALS['ack_dir']);
});
test('normal and realtime processors delete only acknowledged samples and retain concurrent arrivals', function ($realtime, $result) {
    $GLOBALS['ack_table'] = $realtime ? 'poller_output_realtime' : 'poller_output';
    $GLOBALS['ack_result'] = $result;
    $GLOBALS['ack_db']->exec("INSERT INTO " . $GLOBALS['ack_table'] . " VALUES(1,'value','2020-01-01','42'" . ($realtime ? ',1' : '') . ")");
    $pipe = true;
    $actual = $realtime ? process_poller_output_rt($pipe, 1, 5) : process_poller_output($pipe, 1);
    expect($actual)->toBe($result);
    $values = $GLOBALS['ack_db']->query('SELECT output FROM ' . $GLOBALS['ack_table'] . ' ORDER BY time')->fetchAll(\PDO::FETCH_COLUMN);
    expect($values)->toBe($result === false ? array('42','43') : array('43'));
})->with(array(array(false,false),array(false,1),array(true,false),array(true,1)));

test('MULTI completeness counts active fields while retaining genuinely partial timestamps', function ($unused) {
    $db = $GLOBALS['ack_db'];
    $GLOBALS['debug'] = false;
    $GLOBALS['ack_table'] = 'poller_output';
    $GLOBALS['ack_result'] = 1;
    $db->exec("UPDATE data_local SET data_template_id=1; UPDATE poller_item SET rrd_name='',rrd_num=2");
    $db->exec('CREATE TABLE data_template_rrd(id INTEGER,local_data_id INTEGER,data_source_name TEXT,data_input_field_id INTEGER)');
    $db->exec("INSERT INTO data_template_rrd VALUES(1,1,'value',1),(2,1,'hidden',2)");
    $db->exec('CREATE TABLE graph_templates_item(task_item_id INTEGER); INSERT INTO graph_templates_item VALUES(1)');
    if (!$unused) {
        $db->exec('INSERT INTO graph_templates_item VALUES(2)');
    }
    $db->exec("INSERT INTO poller_output VALUES(1,'','2020-01-01','value:42')");
    $pipe = true;
    process_poller_output($pipe, 1);
    $remaining = (int) $db->query("SELECT COUNT(*) FROM poller_output WHERE time='2020-01-01'")->fetchColumn();
    expect($remaining)->toBe($unused ? 0 : 1);
    $sample = $GLOBALS['ack_updates']['fixture.rrd']['times'][strtotime('2020-01-01')] ?? null;
    expect($sample)->toBe($unused ? array('value' => '42') : null);
})->with(array(true, false));
