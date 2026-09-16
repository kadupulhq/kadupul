<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace ShutdownOrphanCleanupTest;
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$root = dirname(__DIR__, 4);
$source = file_get_contents($root . '/poller.php');
$start = strpos($source, '$orphan_rows = db_fetch_assoc_prepared(');
$end = strpos($source, 'poller_delete_output_rows($orphan_keys);', $start);
$cleanup = substr($source, $start, $end - $start) . 'poller_delete_output_rows($orphan_keys);';
eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source(file_get_contents($root . '/lib/poller.php'), 'poller_delete_output_rows'));
function cacti_sizeof($rows) { return count($rows); }
function db_fetch_assoc_prepared($sql, $params) {
    $pdo = $GLOBALS['shutdown_orphan_pdo'];
    $query = $pdo->prepare($sql); $query->execute($params);
    $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
    // A concurrent arrival replaces a selected orphan before the DELETE.
    $pdo->exec("UPDATE poller_output SET output = '99' WHERE local_data_id = 5");
    return $rows;
}
function db_execute_prepared($sql, $params) {
    $query = $GLOBALS['shutdown_orphan_pdo']->prepare($sql);
    $query->execute($params); $GLOBALS['shutdown_orphan_affected'] = $query->rowCount();
    return true;
}
function db_affected_rows() { return $GLOBALS['shutdown_orphan_affected']; }

test('shutdown removes observed orphans but retains replacements and hostless samples', function () use ($cleanup) {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $GLOBALS['shutdown_orphan_pdo'] = $pdo;
    $pdo->exec('CREATE TABLE poller_output (local_data_id INTEGER, rrd_name TEXT, time TEXT, output TEXT)');
    $pdo->exec('CREATE TABLE data_local (id INTEGER, host_id INTEGER)');
    $pdo->exec('CREATE TABLE host (id INTEGER, poller_id INTEGER)');
    $pdo->exec('INSERT INTO host VALUES (1, 1), (2, 2)');
    $pdo->exec('INSERT INTO data_local VALUES (1, 1), (2, 2), (3, 0), (4, 99)');
    $pdo->exec("INSERT INTO poller_output VALUES (1,'v','now','10'), (2,'v','now','10'), (3,'v','now','10'), (4,'v','now','10'), (5,'v','now','10'), (6,'v','now','10')");
    $poller_id = 1;
    eval('namespace ' . __NAMESPACE__ . '; ' . $cleanup);
    expect(array_map('intval', $pdo->query('SELECT local_data_id FROM poller_output ORDER BY local_data_id')->fetchAll(\PDO::FETCH_COLUMN)))->toBe(array(1, 2, 3, 5))
        ->and($pdo->query('SELECT output FROM poller_output WHERE local_data_id = 5')->fetchColumn())->toBe('99');
    unset($GLOBALS['shutdown_orphan_pdo'], $GLOBALS['shutdown_orphan_affected']);
});
