<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace BoostFailedRrdIsolation;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
foreach (array(
    'poller_boost.php' => array('boost_process_local_data_ids', 'boost_process_output'),
    'lib/boost.php' => array('boost_limit_complete_timestamp_page'),
) as $file => $functions) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    foreach ($functions as $function) {
        // test-only eval of source read from this repository, not external input
        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
    }
}
const BOOST_TIMER_START = 1;
const BOOST_TIMER_END = 2;
const SQL_NO_CACHE = '';
function set_error_handler($handler) {}
function restore_error_handler() {}
function get_installed_rrdtool_version() { return '1.7.2'; }
function get_rrdtool_version() { return '1.7.2'; }
function cacti_version_compare($a, $b, $op) { return version_compare($a, $b, $op); }
function read_config_option($name) { return $name === 'boost_rrd_update_string_length' ? 2000 : ''; }
function boost_get_arch_table_names($table) { return array('poller_output_boost_arch_1'); }
function array_rekey($rows, ...$args) { return array(); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function boost_timer(...$args) {}
function boost_debug(...$args) {}
function cacti_log($message, ...$args) { $GLOBALS['isolation_logs'][] = $message; }
function boost_get_unused_data_source_names($id) { return array(); }
function boost_get_rrd_filename_and_template($id) { return array('rrd_template' => 'value', 'rrd_path' => "/rra/$id.rrd"); }
function boost_rrdtool_function_update($id, $path, $template, $values, $pipe) {
    $GLOBALS['isolation_updates'][] = array($id, $values);
    return $id == 2 || $GLOBALS['isolation_all_fail'] ? 'ERROR: permission denied; retain samples for retry' : 'OK';
}
function db_fetch_assoc_prepared($sql, $params = array()) {
    if (strpos($sql, 'poller_data_template_field_mappings') !== false) { return array(); }
    $statement = $GLOBALS['isolation_db']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
}
function db_execute_prepared($sql, $params = array(), $log = true) {
    if ($GLOBALS['isolation_fail'] !== '' && strpos($sql, $GLOBALS['isolation_fail']) !== false) { return false; }
    $statement = $GLOBALS['isolation_db']->prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql));
    return $statement->execute($params);
}

beforeEach(function () {
    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('UNIX_TIMESTAMP', fn($value) => strtotime($value . ' UTC'), 1);
    $db->exec('CREATE TABLE poller_output_boost(local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT, PRIMARY KEY (local_data_id, time, rrd_name))');
    $db->exec('CREATE TABLE poller_output_boost_arch_1(local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT)');
    $db->exec("CREATE TABLE poller_output_boost_local_data_ids(run_id TEXT, local_data_id INTEGER, process_handler INTEGER, cursor_time TEXT NULL, cursor_rrd_name TEXT NOT NULL DEFAULT '')");
    $db->exec('CREATE TABLE data_local(id INTEGER, data_template_id INTEGER)');
    $db->exec('INSERT INTO data_local VALUES (1,0),(2,0),(3,0)');
    foreach (array(1, 2, 3) as $id) {
        $db->exec("INSERT INTO poller_output_boost_arch_1 VALUES ($id,'value','2020-01-01 00:00:00','{$id}0'),($id,'value','2020-01-01 00:05:00','{$id}1')");
        $db->exec("INSERT INTO poller_output_boost_local_data_ids VALUES ('run',$id,1,NULL,'')");
    }
    // The first sample of the failing data source was acknowledged on an earlier page.
    $db->exec("UPDATE poller_output_boost_local_data_ids SET cursor_time = '2020-01-01 00:00:00', cursor_rrd_name = 'value' WHERE local_data_id = 2");
    $GLOBALS['isolation_db'] = $db;
    $GLOBALS['run_id'] = 'run';
    $GLOBALS['archive_table'] = 'poller_output_boost_arch_1';
    $GLOBALS['current_lock'] = false;
    $GLOBALS['isolation_fail'] = '';
    $GLOBALS['isolation_all_fail'] = false;
    $GLOBALS['isolation_logs'] = $GLOBALS['isolation_updates'] = array();
    $GLOBALS['config'] = array('library_path' => sys_get_temp_dir() . '/boost-isolation-' . bin2hex(random_bytes(8)));
    mkdir($GLOBALS['config']['library_path']);
    file_put_contents($GLOBALS['config']['library_path'] . '/rrd.php', '<?php');
});

afterEach(function () {
    unlink($GLOBALS['config']['library_path'] . '/rrd.php');
    rmdir($GLOBALS['config']['library_path']);
});

test('a failing RRD is requeued without holding back the rest of the shard', function () {
    $db = $GLOBALS['isolation_db'];
    expect(boost_process_local_data_ids(1, false, 100))->toBe(5)
        ->and(array_column($GLOBALS['isolation_updates'], 0))->toBe(array(1, 2, 3))
        ->and($db->query('SELECT local_data_id, time, output FROM poller_output_boost')->fetchAll(\PDO::FETCH_NUM))
            ->toBe(array(array(2, '2020-01-01 00:05:00', '21')))
        ->and($db->query('SELECT local_data_id, cursor_time FROM poller_output_boost_local_data_ids ORDER BY local_data_id')->fetchAll(\PDO::FETCH_NUM))
            ->toBe(array(array(1, '2020-01-01 00:05:00'), array(3, '2020-01-01 00:05:00')))
        ->and(implode("\n", $GLOBALS['isolation_logs']))->toContain("requeued Local Data ID '2'");
    expect(boost_process_local_data_ids(1, false, 100))->toBe(0)
        ->and(count($GLOBALS['isolation_updates']))->toBe(3);
});

test('a failed handback retains the whole page', function ($statement) {
    $GLOBALS['isolation_fail'] = $statement;
    $db = $GLOBALS['isolation_db'];
    expect(boost_process_local_data_ids(1, false, 100))->toBe(-1)
        ->and($db->query('SELECT COUNT(*) FROM poller_output_boost_local_data_ids WHERE cursor_time IS NULL')->fetchColumn())->toBe(2)
        ->and($db->query('SELECT COUNT(*) FROM poller_output_boost_local_data_ids')->fetchColumn())->toBe(3);
})->with(array('INSERT IGNORE INTO poller_output_boost', 'DELETE FROM poller_output_boost_local_data_ids'));

test('a page where every RRD fails is retained instead of requeued', function () {
    $GLOBALS['isolation_all_fail'] = true;
    $db = $GLOBALS['isolation_db'];
    expect(boost_process_local_data_ids(1, false, 100))->toBe(-1)
        ->and(array_column($GLOBALS['isolation_updates'], 0))->toBe(array(1, 2, 3))
        ->and($db->query('SELECT COUNT(*) FROM poller_output_boost')->fetchColumn())->toBe(0)
        ->and($db->query('SELECT COUNT(*) FROM poller_output_boost_local_data_ids WHERE cursor_time IS NULL')->fetchColumn())->toBe(2)
        ->and($db->query('SELECT COUNT(*) FROM poller_output_boost_local_data_ids')->fetchColumn())->toBe(3)
        ->and(implode("\n", $GLOBALS['isolation_logs']))->toContain('failed for every data source');
});
