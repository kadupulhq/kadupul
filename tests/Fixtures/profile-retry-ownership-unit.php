<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 2);
$case = $argv[1];
$directory = $argv[2];
$device = str_starts_with($case, 'device-');
$relative = $device ? 'lib/api_device.php' : 'lib/poller.php';
$copy = $directory . '/source.php';
copy($root . '/' . $relative, $copy);
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $copy);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/' . $relative);
    require __DIR__ . '/rrd-process-coverage.php';
}
$config = ['poller_id' => 1, 'is_web' => false];
define('POLLER_VERBOSITY_NONE', 0);
$database = new PDO('sqlite::memory:');
$database->exec('CREATE TABLE poller (id INTEGER PRIMARY KEY, requires_sync TEXT, last_sync TEXT)');
$database->exec("INSERT INTO poller VALUES (2,'','previous successful sync')");
if (str_contains($case, 'mark-failure')) {
    $database->exec("CREATE TRIGGER reject_mark BEFORE UPDATE ON poller BEGIN SELECT RAISE(FAIL,'Retry marker rejected'); END");
}
$writes = $connections = $availability = 0;
$messages = [];
function db_execute_prepared($sql, $params)
{
    $GLOBALS['writes']++;
    try {
        return $GLOBALS['database']->prepare($sql)->execute($params);
    } catch (PDOException $error) {
        return false;
    }
}
function cacti_log($message, ...$args)
{
    $GLOBALS['messages'][] = $message;
}
function cacti_sizeof($rows)
{
    return count($rows);
}
function db_fetch_row_prepared($sql, $params)
{
    $GLOBALS['connections']++;
    // The actual bulk connector refuses a local-only collector.
    return ['dbhost' => 'localhost'];
}
if ($device) {
    function remote_poller_up($id)
    {
        $GLOBALS['availability']++;
        return $GLOBALS['case'] === 'device-connect-failure';
    }
    function poller_connect_to_remote($id)
    {
        $GLOBALS['connections']++;
        return false;
    }
}
require $copy;
$result = $device ? api_device_replicate_out(77, $case === 'device-invalid' ? 1 : 2)
    : replicate_out(2, str_starts_with($case, 'all-') ? 'all' : 'data');
file_put_contents($directory . '/result.json', json_encode([
    'result' => $result, 'writes' => $writes, 'connections' => $connections, 'availability' => $availability, 'messages' => $messages,
    'poller' => $database->query('SELECT * FROM poller')->fetch(PDO::FETCH_ASSOC),
], JSON_THROW_ON_ERROR));
