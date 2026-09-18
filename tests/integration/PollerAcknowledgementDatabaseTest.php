<?php

// SPDX-FileCopyrightText: 2004-2026 The Cacti Group
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = dirname(__DIR__, 2);
function pollerQueueDbReset()
{
    $host     = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
    $port     = getenv('BOOST_DB_PORT') ?: '3306';
    $database = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
    $user     = getenv('BOOST_DB_USER') ?: 'root';
    $password = getenv('BOOST_DB_PASSWORD') ?: '';
    $socket   = getenv('BOOST_DB_SOCKET');
    $dsn      = $socket ? "mysql:unix_socket=$socket;dbname=$database;charset=utf8mb4" :
        "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";

    $GLOBALS['poller_contract_pdo'] = new PDO(
        $dsn,
        $user,
        $password,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $GLOBALS['boost_delete_calls'] = 0;
    $GLOBALS['boost_delete_fail_at'] = 0;
}

beforeEach(function () {
    pollerQueueDbReset();
});

function pollerQueueDbDeletePrepared($sql, $params)
{
    $GLOBALS['boost_delete_statement'] = array($sql, $params);
    if (++$GLOBALS['boost_delete_calls'] === $GLOBALS['boost_delete_fail_at']) {
        return false;
    }
    try {
        $statement = $GLOBALS['poller_contract_pdo']->prepare($sql);
        $statement->execute($params);
        $GLOBALS['boost_delete_affected'] = $statement->rowCount();
        return true;
    } catch (PDOException $error) {
        $GLOBALS['boost_delete_affected'] = 0;
        return false;
    }
}

function pollerQueueDbDeleteAffected()
{
    return $GLOBALS['boost_delete_affected'];
}

test('poller deletes only its selected sample keys when newer rows arrive before deletion', function () use ($root) {
    pollerQueueDbLoadDeleteRows($root);
    $db = $GLOBALS['poller_contract_pdo'];
    $db->exec('CREATE TEMPORARY TABLE poller_output (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(512), PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=MEMORY');
    try {
        $insert = $db->prepare('INSERT INTO poller_output VALUES (?,?,?,?)');
        $insert->execute(array(7, 'traffic_in', '2026-09-15 00:00:00', '10'));
        $selected = $db->query('SELECT local_data_id,rrd_name,time,output FROM poller_output')->fetchAll(PDO::FETCH_NUM);
        // Deterministic interleaving: these rows arrive after the drain's SELECT.
        $insert->execute(array(7, 'traffic_in', '2026-09-15 00:01:00', '11'));
        $insert->execute(array(7, 'traffic_out', '2026-09-15 00:00:00', '12'));
        expect(pollerQueueDbDeleteOutputRows($selected))->toBe(1)
            ->and((int) $db->query('SELECT count(*) FROM poller_output')->fetchColumn())->toBe(2)
            ->and(pollerQueueDbDeleteOutputRows($selected))->toBe(0)
            ->and(pollerQueueDbDeleteOutputRows(array()))->toBe(0);
        expect($db->query('SELECT output FROM poller_output ORDER BY output')->fetchAll(PDO::FETCH_COLUMN))->toBe(array('11', '12'));
        $insert->execute(array(7, 'traffic_in', '2026-09-15 00:00:00', '99'));
        expect(pollerQueueDbDeleteOutputRows($selected))->toBe(0)
            ->and($db->query("SELECT output FROM poller_output WHERE output='99'")->fetchColumn())->toBe('99');
        expect(pollerQueueDbDeleteOutputRows(array(array(7, 'traffic_in', '2026-09-15 00:00:00')), $failed))->toBe(0)
            ->and($failed)->toBeTrue();

    } finally {
        $db->exec('DROP TEMPORARY TABLE poller_output');
    }
});

function pollerQueueDbLoadDeleteRows($root)
{
    if (!function_exists('pollerQueueDbDeleteOutputRows')) {
        preg_match('/^function poller_delete_output_rows\(.*?^}\n/ms', file_get_contents($root . '/lib/poller.php'), $match);
        expect($match)->not->toBeEmpty();
        eval(str_replace(array('poller_delete_output_rows(', 'db_execute_prepared(', 'db_affected_rows(', 'cacti_sizeof('), array('pollerQueueDbDeleteOutputRows(', 'pollerQueueDbDeletePrepared(', 'pollerQueueDbDeleteAffected(', 'count('), $match[0]));
    }
}

test('poller acknowledgement preserves byte-distinct replacement values and uses the primary key', function ($observed, $replacement, $batch_size, $collation) use ($root) {
    pollerQueueDbLoadDeleteRows($root);
    $db = $GLOBALS['poller_contract_pdo'];
    $db->exec('CREATE TEMPORARY TABLE poller_output (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(512), PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=InnoDB COLLATE=' . $collation);
    try {
        $rows = $keys = array();
        for ($id = 1; $id <= 10000; $id++) {
            $rows[] = "($id,'value','2026-09-15 00:00:00'," . $db->quote($observed) . ')';
            if ($id <= $batch_size) {
                $keys[] = array($id, 'value', '2026-09-15 00:00:00', $observed);
            }
        }
        $db->exec('INSERT INTO poller_output VALUES ' . implode(',', $rows));
        $db->prepare('UPDATE poller_output SET output=? WHERE local_data_id=1')->execute(array($replacement));
        expect(pollerQueueDbDeleteOutputRows($keys, $failed))->toBe($batch_size - 1)->and($failed)->toBeFalse();
        expect($db->query('SELECT output FROM poller_output WHERE local_data_id=1')->fetchColumn())->toBe($replacement);
        list($sql, $params) = $GLOBALS['boost_delete_statement'];
        $explain = $db->prepare('EXPLAIN FORMAT=TRADITIONAL ' . $sql);
        $explain->execute($params);
        $plan = $explain->fetch(PDO::FETCH_ASSOC);
        expect($plan['key'])->toBe('PRIMARY')->and($plan['type'])->toBe('range');
    } finally {
        $db->exec('DROP TEMPORARY TABLE poller_output');
    }
})->with(array(array('U', 'u'), array('42', '42 '), array('café', 'CAFÉ'), array('café', 'café ')))->with(array(2, 500))->with(array('utf8mb4_unicode_ci', 'latin1_swedish_ci'));


test('poller reports failed source deletion even after earlier chunks made progress', function ($fail_at) use ($root) {
    pollerQueueDbLoadDeleteRows($root);
    $db = $GLOBALS['poller_contract_pdo'];
    $db->exec('CREATE TEMPORARY TABLE poller_output (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(32), PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=MEMORY');
    try {
        $values = array();
        $keys = array();
        for ($id = 1; $id <= 501; $id++) {
            $values[] = "($id,'value','2026-09-15 00:00:00','10')";
            $keys[] = array($id, 'value', '2026-09-15 00:00:00', '10');
        }
        $db->exec('INSERT INTO poller_output VALUES ' . implode(',', $values));
        $GLOBALS['boost_delete_fail_at'] = $fail_at;
        $consumed = pollerQueueDbDeleteOutputRows($keys, $failed);
        expect($failed)->toBeTrue()
            ->and($consumed)->toBe($fail_at === 1 ? 0 : 500)
            ->and((int) $db->query('SELECT count(*) FROM poller_output')->fetchColumn())->toBe(501 - $consumed);
    } finally {
        $db->exec('DROP TEMPORARY TABLE poller_output');
    }
})->with(array(1, 2));

// Exercise the production batch on real engine collations; native consumer
// tests separately verify which observed rows reach this boundary.
test('Boost retains byte-distinct replacements in live and archive queues', function ($observed, $replacement, $collation, $consumer) use ($root) {
    expect(file_get_contents($root . '/' . $consumer))->toContain('boost_delete_samples($table, $results)');
    if (!function_exists('pollerQueueDbDeleteSamples')) {
        preg_match('/^function boost_delete_samples\(.*?^}\n/ms', file_get_contents($root . '/lib/boost.php'), $match);
        expect($match)->not->toBeEmpty();
        eval(str_replace(array('boost_delete_samples(', 'db_execute_prepared('), array('pollerQueueDbDeleteSamples(', 'pollerQueueDbDeletePrepared('), $match[0]));
    }
    $db = $GLOBALS['poller_contract_pdo'];
    foreach (array('poller_output_boost', 'poller_output_boost_arch_fixture') as $table) {
        $db->exec("CREATE TEMPORARY TABLE $table (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(512), PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=InnoDB COLLATE=" . $collation);
        try {
            $insert = $db->prepare("INSERT INTO $table VALUES (?, 'value', FROM_UNIXTIME(?), ?)");
            $insert->execute(array(1, 1700000000, $replacement));
            $insert->execute(array(2, 1700000000, $observed));
            // Both observed tuples share one statement; only the byte-identical row may go.
            $observedRows = array();
            foreach (array(1, 2) as $id) {
                $observedRows[] = array('local_data_id' => $id, 'rrd_name' => 'value', 'timestamp' => 1700000000, 'output' => $observed);
            }
            expect(pollerQueueDbDeleteSamples($table, $observedRows))->toBeTrue()
                ->and($GLOBALS['boost_delete_affected'])->toBe(1)
                ->and($db->query("SELECT local_data_id, output FROM $table")->fetchAll(PDO::FETCH_NUM))->toBe(array(array(1, $replacement)));
            list($sql, $params) = $GLOBALS['boost_delete_statement'];
            $explain = $db->prepare('EXPLAIN FORMAT=TRADITIONAL ' . $sql);
            $explain->execute($params);
            $plan = $explain->fetch(PDO::FETCH_ASSOC);
            expect($plan['key'])->toBe('PRIMARY')->and($plan['type'])->toBe('range');
        } finally {
            $db->exec("DROP TEMPORARY TABLE $table");
        }
    }
})->with(array(array('U', 'u'), array('42', '42 '), array('café', 'CAFÉ'), array('café', 'café ')))->with(array('utf8mb4_unicode_ci', 'latin1_swedish_ci'))->with(array('lib/boost.php', 'poller_boost.php'));
