<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = getenv('BOOST_RECOVERY_ROOT');
$directory = getenv('BOOST_RECOVERY_DIRECTORY');
$scenario = getenv('BOOST_RECOVERY_SCENARIO');
if (getenv('BOOST_RECOVERY_COVERAGE') === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/poller_recovery.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/poller_recovery.php');
    $errorLevel = error_reporting();
    error_reporting($errorLevel & ~E_DEPRECATED);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
    error_reporting($errorLevel);
}
$mysql = getenv('BOOST_RECOVERY_MYSQL') === '1';
$connect = static function () use ($mysql) {
    $pdo = $mysql ? new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '') : new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    if ($mysql) {
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
};
$local_db_cnn_id = $connect();
$remote_db_cnn_id = $connect();
$local = $local_db_cnn_id;
$remote = $remote_db_cnn_id;
$tablePrefix = $mysql ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
foreach (array($local, $remote) as $connection) {
    $connection->exec($tablePrefix . 'poller_output_boost (local_data_id INTEGER, rrd_name VARCHAR(32), time DATETIME, output VARCHAR(255), PRIMARY KEY(local_data_id,rrd_name,time))' . ($mysql ? ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : ''));
    $connection->exec($tablePrefix . 'settings (name VARCHAR(64) PRIMARY KEY, value VARCHAR(255))');
    $connection->exec($tablePrefix . 'poller (id INTEGER PRIMARY KEY, status INTEGER)');
    $connection->exec('INSERT INTO poller (id,status) VALUES (2,2)');
}
$rows = array();
$count = match ($scenario) {
    'chunk-250' => 250,
    'chunk-251' => 251,
    'final-failure', 'oversized', 'exact-boundary', 'full-packet-exact', 'envelope-overflow' => 1,
    default => 3,
};
$insert = $local->prepare('INSERT INTO poller_output_boost (local_data_id,rrd_name,time,output) VALUES (?,?,?,?)');
for ($i = 1; $i <= $count; $i++) {
    $row = array('local_data_id' => $i, 'rrd_name' => 'in', 'time' => '2026-01-01 00:00:01', 'output' => 'sample' . $i);
    if (in_array($scenario, array('case-change','case-retry'), true)) {
        $row['output'] = 'U';
    } elseif (in_array($scenario, array('space-change','space-retry'), true)) {
        $row['output'] = '42';
    }
    if ($scenario === 'quote-output') {
        $row['output'] = "value'quoted\\\\data\n<x>";
    }
    $insert->execute(array_values($row));
    $rows[] = $row;
}
if ($scenario === 'main-success') {
    // Resume retained samples after the previous worker exited, including an acknowledged packet.
    $local->exec("INSERT INTO settings VALUES ('recovery_pid','2147483647')");
    $remote->exec('UPDATE poller SET status=5 WHERE id=2');
    $retryInsert = $remote->prepare('INSERT INTO poller_output_boost VALUES (?,?,?,?)');
    $retryInsert->execute(array_values($rows[0]));
}
$queries = array();
$logs = array();
$rejectedPackets = array();
$affected = array();
$insertCalls = 0;
$deleteCalls = 0;
$failInsert = match ($scenario) {
    'final-failure', 'main-failure' => 1,
    'partial-failure', 'retry' => 2,
    default => 0,
};
$config = array('base_path' => $directory, 'poller_id' => 2, 'connection' => 'recovery');
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log($message, ...$arguments)
{
    $GLOBALS['logs'][] = $message;
}
function poller_push_reindex_data_to_poller(...$arguments) {}
function db_qstr($value, $connection = false)
{
    return ($connection instanceof PDO ? $connection : $GLOBALS['local'])->quote((string) $value);
}
function recovery_native_statement($sql, $params, $connection)
{
    $connection = $connection instanceof PDO ? $connection : $GLOBALS['local'];
    $remote = $connection === $GLOBALS['remote'];
    $GLOBALS['queries'][] = array('sql' => $sql, 'params' => $params, 'remote' => $remote);
    if (str_starts_with($sql, 'INSERT INTO poller_output_boost')) {
        $GLOBALS['insertCalls']++;
        if ($GLOBALS['failInsert'] === $GLOBALS['insertCalls']) {
            // Make the real database reject the packet at the adapter boundary.
            try {
                $connection->exec('INSERT INTO poller_output_boost (unavailable_column) VALUES (1)');
            } catch (PDOException $exception) {
                $GLOBALS['rejectedPackets'][] = $exception->getCode();
                return false;
            }
            throw new RuntimeException('Expected native database rejection');
        }
        if (!$GLOBALS['mysql']) {
            $sql = str_replace(' ON DUPLICATE KEY UPDATE output=VALUES(output)', ' ON CONFLICT(local_data_id,rrd_name,time) DO UPDATE SET output=excluded.output', $sql);
        }
    }
    if (str_starts_with($sql, 'DELETE FROM poller_output_boost')) {
        $GLOBALS['deleteCalls']++;
        if ($GLOBALS['scenario'] === 'delete-failure') {
            return false;
        }
        if ($GLOBALS['deleteCalls'] === 1 && in_array($GLOBALS['scenario'], array('late-row', 'changed-row', 'case-change', 'space-change', 'case-retry', 'space-retry'), true)) {
            $GLOBALS['local']->exec("INSERT INTO poller_output_boost VALUES (999,'late','2025-01-01 00:00:01','late value')");
            if (str_starts_with($GLOBALS['scenario'], 'case-') || str_starts_with($GLOBALS['scenario'], 'space-')) {
                $replacement = str_starts_with($GLOBALS['scenario'], 'case-') ? 'u' : '42 ';
                $change = $GLOBALS['local']->prepare('UPDATE poller_output_boost SET output=? WHERE local_data_id=2');
                $change->execute(array($replacement));
            }
            if ($GLOBALS['scenario'] === 'changed-row') {
                $GLOBALS['local']->exec("UPDATE poller_output_boost SET output = 'changed value' WHERE local_data_id = 2");
            }
        }
    }
    if (!$GLOBALS['mysql']) {
        $sql = str_replace(array('CAST(CONVERT(output USING utf8mb4) AS BINARY)', 'CAST(CONVERT(? USING utf8mb4) AS BINARY)'), array('CAST(output AS BLOB)', 'CAST(? AS BLOB)'), $sql);
    }
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    $GLOBALS['affected'][spl_object_hash($connection)] = $statement->rowCount();
    return $statement;
}
function db_execute($sql, $log = true, $connection = false)
{
    return recovery_native_statement($sql, array(), $connection) !== false;
}
function db_execute_prepared($sql, $params = array(), $log = true, $connection = false)
{
    return recovery_native_statement($sql, $params, $connection) !== false;
}
function db_affected_rows($connection = false)
{
    return $GLOBALS['affected'][spl_object_hash($connection)];
}
function db_fetch_cell($sql, $column = '', $log = true, $connection = false)
{
    if ($GLOBALS['scenario'] === 'main-max-failure' && str_contains($sql, 'MAX(time)')) {
        return false;
    }
    return recovery_native_statement($sql, array(), $connection)->fetchColumn();
}
function db_fetch_row($sql, $log = true, $connection = false)
{
    if (str_starts_with($sql, 'SHOW GLOBAL VARIABLES')) {
        return array('Value' => 1000000);
    }
    return recovery_native_statement($sql, array(), $connection)->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared($sql, $params = array(), $log = true, $connection = false)
{
    if ($GLOBALS['scenario'] === 'main-read-failure' && str_contains($sql, 'poller_output_boost')) {
        return false;
    }
    return recovery_native_statement($sql, $params, $connection)->fetchAll(PDO::FETCH_ASSOC);
}
register_shutdown_function(function () use ($local, $remote, $directory) {
    $read = static fn($connection) => $connection->query('SELECT local_data_id,rrd_name,time,output FROM poller_output_boost ORDER BY local_data_id')->fetchAll(PDO::FETCH_ASSOC);
    $result = array('local' => $read($local), 'remote' => $read($remote), 'queries' => $GLOBALS['queries'], 'rejectedPackets' => $GLOBALS['rejectedPackets'], 'logs' => $GLOBALS['logs'], 'insertCalls' => $GLOBALS['insertCalls'], 'deleteCalls' => $GLOBALS['deleteCalls'], 'success' => $GLOBALS['success'] ?? null, 'records' => $GLOBALS['records_inserted'] ?? 0, 'retryBefore' => $GLOBALS['retryBefore'] ?? null, 'limit' => $GLOBALS['limit'] ?? 1000000, 'status' => (int) $remote->query('SELECT status FROM poller WHERE id=2')->fetchColumn(), 'pid' => $local->query("SELECT value FROM settings WHERE name='recovery_pid'")->fetchColumn());
    file_put_contents($directory . '/result.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
});
if (str_starts_with($scenario, 'main-')) {
    if ($scenario === 'main-missing-local' || $scenario === 'main-missing-remote') {
        $local->exec("INSERT INTO settings VALUES ('recovery_pid','2147483647')");
        $remote->exec('UPDATE poller SET status=5 WHERE id=2');
        if ($scenario === 'main-missing-local') {
            $local_db_cnn_id = false;
        } else {
            $remote_db_cnn_id = false;
        }
    }
    return;
}
$envelope = strlen('INSERT INTO poller_output_boost (local_data_id, rrd_name, time, output) VALUES ') + strlen(' ON DUPLICATE KEY UPDATE output=VALUES(output)') + 1;
$limit = in_array($scenario, array('partial-failure', 'retry'), true) ? $envelope + 65 : 1000000;
if ($scenario === 'oversized') {
    $limit = 10;
} elseif ($scenario === 'default-limit') {
    $limit = 0;
} elseif ($scenario === 'split-boundary') {
    $limit = $envelope;
    foreach (array_slice($rows, 0, 2) as $row) {
        $limit += strlen('(' . $row['local_data_id'] . ',' . db_qstr($row['rrd_name'], $remote) . ',' . db_qstr($row['time'], $remote) . ',' . db_qstr($row['output'], $remote) . ')');
    }
} elseif (in_array($scenario, array('exact-boundary', 'full-packet-exact', 'envelope-overflow'), true)) {
    $row = $rows[0];
    $limit = strlen('(' . $row['local_data_id'] . ',' . db_qstr($row['rrd_name'], $remote) . ',' . db_qstr($row['time'], $remote) . ',' . db_qstr($row['output'], $remote) . ')');
    if ($scenario !== 'exact-boundary') {
        $limit += $envelope - ($scenario === 'envelope-overflow' ? 1 : 0);
    }
}
$records_inserted = 0;
$success = poller_recovery_transfer_rows($rows, $limit, $scenario === 'missing-remote' ? false : $remote, $scenario === 'missing-local' ? false : $local, $records_inserted);
if (in_array($scenario, array('retry','case-retry','space-retry'), true)) {
    $retryBefore = array('success' => $success, 'records' => $records_inserted, 'local' => (int) $local->query('SELECT count(*) FROM poller_output_boost')->fetchColumn(), 'remote' => (int) $remote->query('SELECT count(*) FROM poller_output_boost')->fetchColumn());
    $failInsert = 0;
    $records_inserted = 0;
    $rows = $local->query('SELECT * FROM poller_output_boost ORDER BY local_data_id')->fetchAll(PDO::FETCH_ASSOC);
    $success = poller_recovery_transfer_rows($rows, $limit, $remote, $local, $records_inserted);
}
exit;
