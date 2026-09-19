<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace ShutdownOrphanCleanupTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$root = dirname(__DIR__, 4);
$source = file_get_contents($root . '/poller.php');
$start = strpos($source, 'do {', strpos($source, '// Valid pending samples'));
$end = strpos($source, '} while (count($orphan_rows) === 40000);', $start);
$cleanup = substr($source, $start, $end - $start) . '} while (count($orphan_rows) === 40000);';
eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source(file_get_contents($root . '/lib/poller.php'), 'poller_delete_output_rows'));
function cacti_sizeof($rows)
{
    return count($rows);
}
function db_fetch_assoc_prepared($sql, $params)
{
    $GLOBALS['shutdown_selects']++;
    if ($GLOBALS['shutdown_query_fail'] ?? false) {
        return false;
    }
    $pdo = $GLOBALS['shutdown_orphan_pdo'];
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
    // A concurrent arrival replaces a selected orphan before the DELETE.
    $pdo->exec("UPDATE poller_output SET output = '99' WHERE local_data_id = 5");
    if ($GLOBALS['shutdown_partial_replace'] ?? false) {
        $pdo->exec("UPDATE poller_output SET output = '99' WHERE local_data_id = 10");
    }
    return $rows;
}
function db_execute_prepared($sql, $params)
{
    // SQLite expresses MySQL's binary cast as a BLOB cast.
    $sql = preg_replace('/\bCAST\(CONVERT\((output|\?) USING utf8mb4\) AS BINARY\)/', 'CAST($1 AS BLOB)', $sql);
    if ($GLOBALS['shutdown_delete_fail'] ?? false) {
        return false;
    }
    $query = $GLOBALS['shutdown_orphan_pdo']->prepare($sql);
    $query->execute($params);
    $GLOBALS['shutdown_orphan_affected'] = $query->rowCount();
    return true;
}
function db_affected_rows()
{
    return $GLOBALS['shutdown_orphan_affected'];
}

beforeEach(function () {
    $GLOBALS['shutdown_selects'] = 0;
    $GLOBALS['shutdown_query_fail'] = false;
    $GLOBALS['shutdown_delete_fail'] = false;
    $GLOBALS['shutdown_partial_replace'] = false;
});

test('shutdown removes observed orphans but retains replacements and hostless samples', function () use ($cleanup) {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $GLOBALS['shutdown_orphan_pdo'] = $pdo;
    $pdo->exec('CREATE TABLE poller_output (local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT)');
    $pdo->exec('CREATE INDEX sample_key ON poller_output(local_data_id,rrd_name,time)');
    $pdo->exec('CREATE TABLE data_local (id INTEGER, host_id INTEGER)');
    $pdo->exec('CREATE TABLE host (id INTEGER, poller_id INTEGER)');
    $pdo->exec('CREATE TABLE poller_item (local_data_id INTEGER, rrd_name TEXT)');
    $pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn($time) => gmdate('Y-m-d H:i:s', $time), 1);
    $pdo->exec('INSERT INTO host VALUES (1, 1), (2, 2)');
    $pdo->exec('INSERT INTO data_local VALUES (1, 1), (2, 2), (3, 0), (4, 99)');
    $pdo->exec("INSERT INTO poller_output VALUES (1,'v','now','10'), (2,'v','now','10'), (3,'v','now','10'), (4,'v','now','10'), (5,'v','now','10'), (6,'v','now','10')");
    $poller_id = 1;
    $poller_interval = 300;
    eval('namespace ' . __NAMESPACE__ . '; ' . $cleanup);
    expect(array_map('intval', $pdo->query('SELECT local_data_id FROM poller_output ORDER BY local_data_id')->fetchAll(\PDO::FETCH_COLUMN)))->toBe(array(1, 2, 3, 5))
        ->and($pdo->query('SELECT output FROM poller_output WHERE local_data_id = 5')->fetchColumn())->toBe('99');
    unset($GLOBALS['shutdown_orphan_pdo'], $GLOBALS['shutdown_orphan_affected']);
});


test('shutdown pages large queues and terminates safely on database failure', function ($failure) use ($cleanup) {
    $pdo = new \PDO('sqlite::memory:');
    $GLOBALS['shutdown_orphan_pdo'] = $pdo;
    $pdo->exec('CREATE TABLE poller_output (local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT)');
    $pdo->exec('CREATE INDEX sample_key ON poller_output(local_data_id,rrd_name,time)');
    $pdo->exec('CREATE TABLE data_local (id INTEGER, host_id INTEGER)');
    $pdo->exec('CREATE TABLE host (id INTEGER, poller_id INTEGER)');
    $pdo->exec('CREATE TABLE poller_item (local_data_id INTEGER, rrd_name TEXT)');
    $pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn($time) => gmdate('Y-m-d H:i:s', $time), 1);
    $pdo->beginTransaction();
    $insert = $pdo->prepare("INSERT INTO poller_output VALUES (?, 'v', 'now', '10')");
    for ($i = 10; $i < 40011; $i++) {
        $insert->execute(array($i));
    }
    $pdo->commit();
    $GLOBALS['shutdown_partial_replace'] = $failure === 'replace';
    $GLOBALS['shutdown_query_fail'] = $failure === 'query';
    $GLOBALS['shutdown_delete_fail'] = $failure === 'delete';
    $poller_id = 1;
    $poller_interval = 300;
    $rrd_cleanup_failed = false;
    eval('namespace ' . __NAMESPACE__ . '; ' . $cleanup);
    expect((int) $pdo->query('SELECT COUNT(*) FROM poller_output')->fetchColumn())->toBe($failure === 'replace' ? 2 : ($failure ? 40001 : 0))
        ->and($GLOBALS['shutdown_selects'])->toBe($failure ? 1 : 2)
        ->and($rrd_cleanup_failed)->toBe((bool) $failure);
})->with(array('', 'query', 'delete', 'replace'));

test('device samples without poller items expire only after several cycles', function () use ($cleanup) {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $GLOBALS['shutdown_orphan_pdo'] = $pdo;
    $pdo->exec('CREATE TABLE poller_output (local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT)');
    $pdo->exec('CREATE TABLE data_local (id INTEGER, host_id INTEGER)');
    $pdo->exec('CREATE TABLE host (id INTEGER, poller_id INTEGER)');
    $pdo->exec('CREATE TABLE poller_item (local_data_id INTEGER, rrd_name TEXT)');
    $pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn($time) => gmdate('Y-m-d H:i:s', $time), 1);
    $pdo->exec('INSERT INTO host VALUES (1, 1), (2, 2)');
    $pdo->exec('INSERT INTO data_local VALUES (10, 1), (11, 1), (12, 1), (13, 2), (14, 0)');
    // 10 is polled; 11 and 12 were disabled; 13 belongs to another poller; 14 is hostless.
    $pdo->exec("INSERT INTO poller_item VALUES (10, 'v')");
    $old = gmdate('Y-m-d H:i:s', time() - 3600);
    $recent = gmdate('Y-m-d H:i:s', time() - 60);
    $insert = $pdo->prepare("INSERT INTO poller_output VALUES (?, 'v', ?, '10')");
    foreach (array(array(10, $old), array(11, $old), array(12, $recent), array(13, $old), array(14, $old)) as $row) {
        $insert->execute($row);
    }
    $poller_id = 1;
    $poller_interval = 300;
    $rrd_cleanup_failed = false;
    eval('namespace ' . __NAMESPACE__ . '; ' . $cleanup);
    expect(array_map('intval', $pdo->query('SELECT local_data_id FROM poller_output ORDER BY local_data_id')->fetchAll(\PDO::FETCH_COLUMN)))->toBe(array(10, 12, 13, 14))
        ->and($rrd_cleanup_failed)->toBeFalse();
    unset($GLOBALS['shutdown_orphan_pdo'], $GLOBALS['shutdown_orphan_affected']);
});
