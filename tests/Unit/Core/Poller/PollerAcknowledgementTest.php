<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerAcknowledgement;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'process_poller_output'));
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
function array_rekey($rows, ...$args)
{
    return $rows;
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
        return array();
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
    if ($GLOBALS['ack_result'] === false) {
        throw new \RuntimeException('Deleted samples without acknowledgement');
    }
    $statement = $GLOBALS['ack_db']->prepare($sql);
    return $statement->execute($params);
}
function rrdtool_function_update($updates, ...$args)
{
    // A new timestamp arrives after selection but before the write completes.
    $GLOBALS['ack_db']->exec("INSERT INTO " . $GLOBALS['ack_table'] . " VALUES (1,'value','2020-01-02','43'" . ($GLOBALS['ack_table'] === 'poller_output_realtime' ? ',1' : '') . ")");
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
