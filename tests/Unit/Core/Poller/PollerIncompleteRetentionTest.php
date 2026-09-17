<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
namespace PollerIncompleteRetention;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
foreach (array('poller_delete_output_rows', 'poller_cleanup_orphan_rows', 'poller_expire_incomplete_rows', 'process_poller_output') as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function array_rekey($rows, ...$args) { return $rows; }
function cacti_log(...$args) { $GLOBALS['retention_logs'][] = $args[0]; }
function read_config_option($name) { return $name === 'poller_interval' ? 300 : ''; }
function db_fetch_assoc_prepared($sql, $parameters = array()) {
    if ($GLOBALS['retention_fail'] === 'select' && strpos($sql, 'AS incomplete') !== false) { return false; }
    if (strpos($sql, 'poller_data_template_field_mappings') !== false) { return array(); }
    $statement = $GLOBALS['retention_db']->prepare($sql);
    $statement->execute($parameters);
    $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
    if ((strpos($sql, 'AS incomplete') !== false || strpos($sql, 'dl.id IS NULL') !== false) && $GLOBALS['retention_arrival']) {
        $GLOBALS['retention_db']->exec($GLOBALS['retention_arrival']);
        $GLOBALS['retention_arrival'] = '';
    }
    return $rows;
}
function db_fetch_cell($sql) { return $GLOBALS['retention_running']; }
function db_fetch_assoc($sql) { return db_fetch_assoc_prepared($sql); }
function db_execute_prepared($sql, $parameters) {
    // SQLite expresses MySQL's binary cast as a BLOB cast.
    $sql = preg_replace('/\bBINARY (output|\?)/', 'CAST($1 AS BLOB)', $sql);
    if ($GLOBALS['retention_fail'] === 'delete') { return false; }
    $statement = $GLOBALS['retention_db']->prepare($sql); $statement->execute($parameters);
    $GLOBALS['retention_affected'] = $statement->rowCount();
    return true;
}
function db_affected_rows() { return $GLOBALS['retention_affected']; }

beforeEach(function () {
    $GLOBALS['retention_db'] = new \PDO('sqlite::memory:');
    $GLOBALS['retention_db']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $GLOBALS['retention_db']->sqliteCreateFunction('UNIX_TIMESTAMP', 'strtotime', 1);
    $GLOBALS['retention_db']->exec('CREATE TABLE poller_output(local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT)');
    $GLOBALS['retention_db']->exec('CREATE TABLE poller_item(local_data_id INTEGER, rrd_name TEXT, rrd_num INTEGER, rrd_path TEXT)');
    $GLOBALS['retention_db']->exec('CREATE TABLE data_local(id INTEGER, data_template_id INTEGER)');
    $GLOBALS['retention_running'] = 0;
    $GLOBALS['retention_fail'] = ''; $GLOBALS['retention_arrival'] = ''; $GLOBALS['retention_logs'] = array();
});

test('expiry preserves complete groups, recent partials, boundary timestamps and concurrent arrivals', function () {
    $db = $GLOBALS['retention_db'];
    $db->exec("INSERT INTO poller_item VALUES (1,'a',2,''),(1,'b',2,''),(2,'a',2,''),(2,'b',2,''),(3,'a',2,''),(5,'a',2,'')");
    $db->exec("INSERT INTO poller_output VALUES (1,'a','2020-01-01','1'),(1,'b','2020-01-01','2'),(2,'a','2020-01-01','3'),(2,'a','2020-01-03','4'),(2,'b','2020-01-03','5'),(3,'a','2020-01-03','6'),(4,'orphan-cache','2020-01-01','7'),(5,'a','2020-01-02','8')");
    $GLOBALS['retention_arrival'] = "INSERT INTO poller_output VALUES (2,'b','2020-01-01','late')";
    $expired = poller_expire_incomplete_rows('2020-01-02', $failed);
    expect($failed)->toBeFalse()->and($expired)->toBe(2)
        ->and($db->query('SELECT output FROM poller_output ORDER BY output')->fetchAll(\PDO::FETCH_COLUMN))->toBe(array('1','2','4','5','6','8','late'));
    expect($GLOBALS['retention_logs'][0])->toContain('Expired 2 incomplete')->toContain('complete retry groups were retained');
});

test('unavailable database operations retain the selected samples', function ($failure) {
    $GLOBALS['retention_db']->exec("INSERT INTO poller_output VALUES (1,'a','2020-01-01','saved')");
    $GLOBALS['retention_fail'] = $failure;
    expect(poller_expire_incomplete_rows('2020-01-02', $failed))->toBe(0)->and($failed)->toBeTrue()
        ->and($GLOBALS['retention_db']->query('SELECT output FROM poller_output')->fetchColumn())->toBe('saved');
})->with(array('select', 'delete'));

test('the production processor expires old incomplete rows only after a writer initializes', function ($available) {
    if (!defined('SQL_NO_CACHE')) { define('SQL_NO_CACHE', ''); }
    if (!defined('POLLER_VERBOSITY_HIGH')) { define('POLLER_VERBOSITY_HIGH', 4); }
    $this->dependency_dir = sys_get_temp_dir() . '/poller-retention-' . bin2hex(random_bytes(8));
    mkdir($this->dependency_dir);
    file_put_contents($this->dependency_dir . '/rrd.php', '<?php // No RRD calls occur in this empty-result fixture.');
    $GLOBALS['config'] = array('library_path' => $this->dependency_dir);
    $GLOBALS['retention_db']->exec("INSERT INTO data_local VALUES (1,1)");
    $GLOBALS['retention_db']->exec("INSERT INTO poller_output VALUES (1,'a','2001-01-01','saved')");
    $pipe = $available;
    expect(process_poller_output($pipe, 0, $deferred, $consumed))->toBe(0)
        ->and($deferred)->toBe(!$available)
        ->and((int) $GLOBALS['retention_db']->query('SELECT COUNT(*) FROM poller_output')->fetchColumn())->toBe($available ? 0 : 1);
})->with(array(true, false));

afterEach(function () {
    if (isset($this->dependency_dir)) { unlink($this->dependency_dir . '/rrd.php'); rmdir($this->dependency_dir); }
});

test('cleanup preserves a selected key replaced before deletion', function ($orphan) {
    $db = $GLOBALS['retention_db'];
    $db->exec("INSERT INTO poller_output VALUES(1,'a','2001-01-01','original')");
    if (!$orphan) { $db->exec("INSERT INTO poller_item VALUES(1,'a',2,'')"); }
    $GLOBALS['retention_arrival'] = "UPDATE poller_output SET output='replacement'";
    $deleted = $orphan ? poller_cleanup_orphan_rows($failed) : poller_expire_incomplete_rows('2020-01-01', $failed);
    expect($deleted)->toBe(0)->and($failed)->toBeTrue()
        ->and($db->query('SELECT output FROM poller_output')->fetchColumn())->toBe('replacement');
})->with(array(true, false));

test('empty-result cleanup waits for a verified idle poller', function ($running) {
    if (!defined('SQL_NO_CACHE')) { define('SQL_NO_CACHE', ''); }
    if (!defined('POLLER_VERBOSITY_HIGH')) { define('POLLER_VERBOSITY_HIGH', 4); }
    $this->dependency_dir = sys_get_temp_dir() . '/poller-retention-' . bin2hex(random_bytes(8));
    mkdir($this->dependency_dir);
    file_put_contents($this->dependency_dir . '/rrd.php', '<?php');
    $GLOBALS['config'] = array('library_path' => $this->dependency_dir);
    $GLOBALS['retention_running'] = $running;
    $GLOBALS['retention_db']->exec("INSERT INTO poller_output VALUES(1,'a','2001-01-01','saved')");
    $pipe = true;
    expect(process_poller_output($pipe, 0, $deferred, $consumed))->toBe(0)
        ->and($deferred)->toBe($running === false)->and($consumed)->toBe(0)
        ->and($GLOBALS['retention_db']->query('SELECT output FROM poller_output')->fetchColumn())->toBe('saved');
})->with(array(false, 1));
