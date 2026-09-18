<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace BoostSampleDeletion;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php'), 'boost_delete_samples'));
function db_execute_prepared($sql, $params)
{
    // SQLite expresses MySQL's binary cast as a BLOB cast and its epoch conversion natively.
    $sql = preg_replace('/\bCAST\(CONVERT\((output|\?) USING utf8mb4\) AS BINARY\)/', 'CAST($1 AS BLOB)', $sql);
    $sql = str_replace('FROM_UNIXTIME(?)', "datetime(?, 'unixepoch')", $sql);
    $GLOBALS['boost_delete_statements']++;
    if ($GLOBALS['boost_delete_statements'] === $GLOBALS['boost_delete_fail_at']) {
        return false;
    }
    $query = $GLOBALS['boost_delete_pdo']->prepare($sql);
    return $query->execute($params);
}

beforeEach(function () {
    $pdo = new \PDO('sqlite::memory:', null, null, array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
    $pdo->exec('CREATE TABLE poller_output_boost_arch_1 (local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT)');
    $GLOBALS['boost_delete_pdo'] = $pdo;
    $GLOBALS['boost_delete_statements'] = 0;
    $GLOBALS['boost_delete_fail_at'] = 0;
});

function queued_samples()
{
    return $GLOBALS['boost_delete_pdo']->query('SELECT local_data_id, rrd_name, output FROM poller_output_boost_arch_1 ORDER BY local_data_id, rrd_name, output')->fetchAll(\PDO::FETCH_NUM);
}

test('batched deletion removes only exact tuples and keeps concurrent replacements', function () {
    $insert = $GLOBALS['boost_delete_pdo']->prepare("INSERT INTO poller_output_boost_arch_1 VALUES (?, ?, datetime(?, 'unixepoch'), ?)");
    $rows = array();
    for ($id = 1; $id <= 1001; $id++) {
        $insert->execute(array($id, 'value', 1700000000, '42'));
        $rows[] = array('local_data_id' => $id, 'rrd_name' => 'value', 'timestamp' => 1700000000, 'output' => '42');
    }
    // A later sample and a replacement differing only by trailing space must survive.
    $insert->execute(array(1, 'value', 1700000060, '42'));
    $insert->execute(array(3, 'value', 1700000000, '42 '));

    expect(boost_delete_samples('poller_output_boost_arch_1', $rows))->toBeTrue()
        ->and($GLOBALS['boost_delete_statements'])->toBe(3)
        ->and(queued_samples())->toBe(array(array(1, 'value', '42'), array(3, 'value', '42 ')));
});

test('a failed chunk stops deletion and reports failure', function () {
    $rows = array_fill(0, 501, array('local_data_id' => 1, 'rrd_name' => 'value', 'timestamp' => 1700000000, 'output' => '42'));
    $GLOBALS['boost_delete_fail_at'] = 1;
    expect(boost_delete_samples('poller_output_boost_arch_1', $rows))->toBeFalse()
        ->and($GLOBALS['boost_delete_statements'])->toBe(1);
});

test('an empty selection issues no statement', function () {
    expect(boost_delete_samples('poller_output_boost_arch_1', array()))->toBeTrue()
        ->and($GLOBALS['boost_delete_statements'])->toBe(0);
});
