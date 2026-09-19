<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
namespace PollerUnmappedMultiOutput;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
foreach (array('poller_delete_output_rows', 'poller_cleanup_orphan_rows', 'poller_expire_incomplete_rows', 'process_poller_output') as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function array_rekey($rows, $key, $value) {
    $keyed = array();
    foreach ((array) $rows as $row) {
        $keyed[$row[$key]] = is_array($value) ? array_intersect_key($row, array_flip($value)) : $row[$value];
    }
    return $keyed;
}
function is_hexadecimal($value) { return false; }
function cacti_log(...$args) { $GLOBALS['unmapped_logs'][] = $args[0]; }
function read_config_option($name) { return $name === 'poller_interval' ? 300 : ''; }
function boost_poller_on_demand($results) { return true; }
function rrdtool_function_update($updates, $pipe, &$completed) {
    $completed = array();
    foreach ($updates as $path => $fields) {
        foreach ($fields['times'] as $time => $values) {
            $GLOBALS['unmapped_written'][] = array($path, gmdate('Y-m-d', $time), $values);
            $completed[$path][$time] = true;
        }
    }
    return count($updates);
}
function dsstats_poller_output($updates) {}
function dsdebug_poller_output($updates) {}
function api_plugin_hook_function($name, $updates) {}
function db_fetch_assoc_prepared($sql, $parameters = array()) {
    if (strpos($sql, 'poller_data_template_field_mappings') !== false) {
        return array(array('keyname' => '1_in', 'data_source_name' => 'traffic_in'));
    }
    if (strpos($sql, 'data_template_rrd') !== false) { return array(); }
    $statement = $GLOBALS['unmapped_db']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql) { return db_fetch_assoc_prepared($sql); }
function db_fetch_cell($sql) {
    // A running collector keeps orphan and expiry cleanup out of this fixture.
    return strpos($sql, 'poller_time') !== false ? 1 : $GLOBALS['unmapped_db']->query($sql)->fetchColumn();
}
function db_execute_prepared($sql, $parameters) {
    // SQLite expresses MySQL's binary cast as a BLOB cast.
    $sql = preg_replace('/\bCAST\(CONVERT\((output|\?) USING utf8mb4\) AS BINARY\)/', 'CAST($1 AS BLOB)', $sql);
    $statement = $GLOBALS['unmapped_db']->prepare($sql); $statement->execute($parameters);
    $GLOBALS['unmapped_affected'] = $statement->rowCount();
    return true;
}
function db_affected_rows() { return $GLOBALS['unmapped_affected']; }

beforeEach(function () {
    if (!defined('SQL_NO_CACHE')) { define('SQL_NO_CACHE', ''); }
    if (!defined('POLLER_VERBOSITY_HIGH')) { define('POLLER_VERBOSITY_HIGH', 4); }
    if (!defined('POLLER_VERBOSITY_NONE')) { define('POLLER_VERBOSITY_NONE', 1); }
    $this->dependency_dir = sys_get_temp_dir() . '/poller-unmapped-' . bin2hex(random_bytes(8));
    mkdir($this->dependency_dir);
    file_put_contents($this->dependency_dir . '/rrd.php', '<?php');
    $GLOBALS['config'] = array('library_path' => $this->dependency_dir);
    $GLOBALS['debug'] = false;
    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('UNIX_TIMESTAMP', function ($value = null) { return $value === null ? strtotime('2020-01-03 UTC') : strtotime($value . ' UTC'); }, -1);
    $db->sqliteCreateFunction('FROM_UNIXTIME', function ($value) { return gmdate('Y-m-d', $value); }, 1);
    $db->exec('CREATE TABLE poller_output(local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT)');
    $db->exec('CREATE TABLE poller_item(local_data_id INTEGER, rrd_name TEXT, rrd_num INTEGER, rrd_path TEXT)');
    $db->exec('CREATE TABLE data_local(id INTEGER, data_template_id INTEGER)');
    $GLOBALS['unmapped_db'] = $db;
    $GLOBALS['unmapped_logs'] = array(); $GLOBALS['unmapped_written'] = array();
});

afterEach(function () {
    unlink($this->dependency_dir . '/rrd.php'); rmdir($this->dependency_dir);
});

test('an old unmappable MULTI sample is discarded without dropping its neighbours', function () {
    $db = $GLOBALS['unmapped_db'];
    // Script data sources with several outputs store one row with an empty rrd_name and rrd_num 1.
    $db->exec("INSERT INTO data_local VALUES (1,1),(2,1)");
    $db->exec("INSERT INTO poller_item VALUES (1,'',1,'one.rrd'),(2,'',1,'two.rrd')");
    $recent = gmdate('Y-m-d H:i:s', time() - 60);
    $db->exec("INSERT INTO poller_output VALUES (1,'','2001-01-01','in:5'),(1,'','2001-01-02','bogus:7'),(1,'','2001-01-03','in:6'),(2,'','$recent','bogus:8')");
    $pipe = true;
    process_poller_output($pipe, 0, $deferred, $consumed);
    expect($deferred)->toBeFalse()->and($consumed)->toBe(3)
        ->and($GLOBALS['unmapped_written'])->toBe(array(
            array('one.rrd', '2001-01-01', array('traffic_in' => '5')),
            array('one.rrd', '2001-01-03', array('traffic_in' => '6')),
        ))
        ->and($db->query('SELECT output FROM poller_output')->fetchAll(\PDO::FETCH_COLUMN))->toBe(array('bogus:8'))
        ->and(implode("\n", $GLOBALS['unmapped_logs']))->toContain('Discarded unmapped MULTI output for DS[1] at 2001-01-02');
});

test('expiry does not count a stale field name toward a complete group', function () {
    $db = $GLOBALS['unmapped_db'];
    $db->exec("INSERT INTO poller_item VALUES (3,'a',2,''),(3,'b',2,''),(4,'a',2,''),(4,'b',2,'')");
    $db->exec("INSERT INTO poller_output VALUES (3,'renamed','2020-01-01','1'),(3,'a','2020-01-01','2'),(4,'a','2020-01-01','3'),(4,'b','2020-01-01','4')");
    expect(poller_expire_incomplete_rows(86400, $failed))->toBe(2)->and($failed)->toBeFalse()
        ->and($db->query('SELECT output FROM poller_output ORDER BY output')->fetchAll(\PDO::FETCH_COLUMN))->toBe(array('3', '4'));
});
