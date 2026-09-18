<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace PollerRejectedSamplesDatabase;

use PDO;
use PDOException;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/poller.php');
foreach (array('poller_rejected_table_ensure', 'poller_output_key_predicate', 'poller_dead_letter_rejected', 'poller_replay_rejected', 'poller_delete_output_rows') as $name) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
}

// Every database boundary runs the production statement on the real engine.
function pdo()
{
    return $GLOBALS['rejected_pdo'];
}
function read_config_option($key)
{
    return $GLOBALS['rejected_settings'][$key] ?? '';
}
function cacti_log($message, ...$args)
{
    $GLOBALS['rejected_logs'][] = $message;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function db_table_exists($table, $log = true)
{
    try {
        pdo()->query("SELECT 1 FROM $table LIMIT 0");
        return true;
    } catch (PDOException $error) {
        return false;
    }
}
function db_execute($sql, $log = true)
{
    return db_execute_prepared($sql);
}
function db_execute_prepared($sql, $params = array(), $log = true)
{
    try {
        $statement = pdo()->prepare($sql);
        $statement->execute($params);
        $GLOBALS['rejected_affected'] = $statement->rowCount();
        return true;
    } catch (PDOException $error) {
        $GLOBALS['rejected_logs'][] = $error->getMessage();
        return false;
    }
}
function db_affected_rows()
{
    return $GLOBALS['rejected_affected'];
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    $statement = pdo()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    // A concurrent collector replaces one selected sample before the move.
    if (!empty($GLOBALS['rejected_replace']) && strpos($sql, 'FROM poller_output') !== false && strpos($sql, 'poller_output_rejected') === false) {
        pdo()->prepare('UPDATE poller_output SET output = ? WHERE local_data_id = ? AND time = FROM_UNIXTIME(?)')->execute($GLOBALS['rejected_replace']);
        $GLOBALS['rejected_replace'] = null;
    }
    return $rows;
}
function db_fetch_row_prepared($sql, $params = array())
{
    $statement = pdo()->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $statement = pdo()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}
function db_begin_transaction()
{
    return pdo()->beginTransaction();
}
function db_commit_transaction()
{
    return pdo()->commit();
}
function db_rollback_transaction()
{
    return pdo()->rollBack();
}
function queued($table, $id = 1)
{
    $statement = pdo()->prepare("SELECT UNIX_TIMESTAMP(time) AS time, rrd_name, output FROM $table WHERE local_data_id = ? ORDER BY time, rrd_name");
    $statement->execute(array($id));
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function enqueue($id, $time, $fields = array('a'), $output = '1')
{
    $insert = pdo()->prepare('INSERT INTO poller_output VALUES (?, ?, FROM_UNIXTIME(?), ?)');
    foreach ($fields as $field) {
        $insert->execute(array($id, $field, $time, $output));
    }
}

beforeEach(function () {
    $host     = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
    $port     = getenv('BOOST_DB_PORT') ?: '3306';
    $database = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
    $GLOBALS['rejected_pdo'] = new PDO(
        "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
        getenv('BOOST_DB_USER') ?: 'root',
        getenv('BOOST_DB_PASSWORD') ?: '',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    pdo()->exec('DROP TABLE IF EXISTS poller_output, poller_output_rejected, poller_output_boost_arch_1, poller_output_boost_arch_2');
    pdo()->exec("CREATE TABLE poller_output (local_data_id INT UNSIGNED NOT NULL, rrd_name VARCHAR(19) NOT NULL, time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, output VARCHAR(512) NOT NULL, PRIMARY KEY (local_data_id, rrd_name, time)) ENGINE=InnoDB");
    $GLOBALS['rejected_settings'] = array('poller_rejected_hours' => 24, 'poller_rejected_rows' => 10);
    $GLOBALS['rejected_logs'] = array();
    $GLOBALS['rejected_replace'] = null;
});
afterEach(function () {
    pdo()->exec('DROP TABLE IF EXISTS poller_output, poller_output_rejected, poller_output_boost_arch_1, poller_output_boost_arch_2');
});

test('a persistent schema mismatch stays bounded and dead-letters without losing samples', function () {
    $start = time() - 3 * 3600;
    // 40 collection cycles of a two-field sample that RRDtool keeps refusing.
    for ($cycle = 0; $cycle < 40; $cycle++) {
        enqueue(1, $start + $cycle * 60, array('a', 'b'));
        expect(poller_dead_letter_rejected(1, '/rra/one.rrd', "unknown DS name 'b'"))->not->toBeFalse();
        expect(count(queued('poller_output')))->toBeLessThanOrEqual(11);
    }
    // No sample is lost and no timestamp group is split between the tables.
    $live = queued('poller_output');
    $dead = queued('poller_output_rejected');
    expect(count($live) + count($dead))->toBe(80)->and(count($live) % 2)->toBe(0)
        ->and(max(array_column($dead, 'time')))->toBeLessThan(min(array_column($live, 'time')));
    expect($GLOBALS['rejected_logs'][0])->toStartWith('ERROR: Moved 2 rejected samples to poller_output_rejected: ')
        ->toContain('"path":"\/rra\/one.rrd"')->toContain('"local_data_id":1');
});

test('samples retained past the age limit move together and other sources are untouched', function () {
    enqueue(1, time() - 25 * 3600, array('a', 'b'));
    enqueue(1, time() - 60);
    enqueue(2, time() - 25 * 3600);
    expect(poller_dead_letter_rejected(1, '/rra/one.rrd', 'mismatch'))->toBe(3)
        ->and(queued('poller_output'))->toBe(array())
        ->and(queued('poller_output', 2))->toHaveCount(1)
        ->and(queued('poller_output_rejected'))->toHaveCount(3);
    $row = pdo()->query('SELECT rrd_path, reason FROM poller_output_rejected LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    expect($row)->toBe(array('rrd_path' => '/rra/one.rrd', 'reason' => 'mismatch'))
        ->and($GLOBALS['rejected_logs'][0])->toContain('retained longer than 24 hours');
});

test('a concurrent byte-distinct replacement stays queued and is not copied', function () {
    $time = time() - 25 * 3600;
    enqueue(1, $time, array('a'), 'U');
    $GLOBALS['rejected_replace'] = array('u', 1, $time);
    expect(poller_dead_letter_rejected(1, '/rra/one.rrd', 'mismatch'))->toBe(0)
        ->and(queued('poller_output'))->toBe(array(array('time' => $time, 'rrd_name' => 'a', 'output' => 'u')))
        ->and(queued('poller_output_rejected'))->toBe(array());
});

test('replay returns rejected samples for the next drain and a dry run moves nothing', function () {
    enqueue(1, time() - 25 * 3600, array('a', 'b'));
    enqueue(2, time() - 25 * 3600);
    poller_dead_letter_rejected(1, '/rra/one.rrd', 'mismatch');
    poller_dead_letter_rejected(2, '/rra/two.rrd', 'mismatch');
    expect(poller_replay_rejected(1, true))->toBe(2)
        ->and(queued('poller_output_rejected'))->toHaveCount(2);
    expect(poller_replay_rejected(1))->toBe(2)
        ->and(queued('poller_output'))->toHaveCount(2)
        ->and(queued('poller_output_rejected'))->toBe(array())
        ->and(queued('poller_output_rejected', 2))->toHaveCount(1);
    expect(poller_replay_rejected(null))->toBe(1)
        ->and(queued('poller_output', 2))->toHaveCount(1);
});

test('Boost archives across tables dead-letter whole groups and reject foreign table names', function () {
    foreach (array('poller_output_boost_arch_1', 'poller_output_boost_arch_2') as $table) {
        pdo()->exec("CREATE TABLE $table LIKE poller_output");
    }
    $insert = function ($table, $time, $field) {
        pdo()->prepare("INSERT INTO $table VALUES (1, ?, FROM_UNIXTIME(?), '1')")->execute(array($field, $time));
    };
    // Twelve two-field groups split across two archives; one group straddles them.
    $start = time() - 3600;
    for ($group = 0; $group < 12; $group++) {
        $insert('poller_output_boost_arch_1', $start + $group * 60, 'a');
        $insert($group === 5 ? 'poller_output_boost_arch_2' : 'poller_output_boost_arch_1', $start + $group * 60, 'b');
    }
    $tables = array('poller_output_boost_arch_1', 'poller_output_boost_arch_2');
    expect(poller_dead_letter_rejected(1, '/rra/one.rrd', 'mismatch', $tables))->toBe(14)
        ->and(queued('poller_output_rejected'))->toHaveCount(14)
        ->and(count(queued('poller_output_boost_arch_1')) + count(queued('poller_output_boost_arch_2')))->toBe(10)
        ->and(min(array_column(queued('poller_output_boost_arch_1'), 'time')))->toBe($start + 7 * 60);
    expect(poller_dead_letter_rejected(1, '/rra/one.rrd', 'mismatch', array('host')))->toBeFalse();
});
