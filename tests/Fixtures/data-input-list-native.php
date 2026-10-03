<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Full source-identical worker and native MySQL/MariaDB persistence. Bootstrap,
// logging and the plugin failure boundary are isolated; no worker code is copied.
$directory = dirname(__DIR__);
$root = json_decode(file_get_contents($directory . '/source.json'), true, flags: JSON_THROW_ON_ERROR);
$scenario = json_decode(file_get_contents($directory . '/scenario.json'), true, flags: JSON_THROW_ON_ERROR);
require $root . '/include/vendor/autoload.php';
$dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . file_get_contents($directory . '/database'), getenv('KADUPUL_TEST_MYSQL_DSN'));
$db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database_hostname = 'fixture';
$database_port = '0';
$database_default = 'fixture';
$database_sessions = ['fixture:0:fixture' => $db];
$config = ['poller_id' => 1, 'base_path' => $directory];
$_SESSION = [];
function read_config_option($name)
{
    return 25;
}
function db_execute_prepared($sql, $params)
{
    file_put_contents($GLOBALS['directory'] . '/preference-write', 'attempted');
    return $GLOBALS['db']->prepare($sql)->execute($params);
}
function api_plugin_hook_function($hook, $where)
{
    return ($GLOBALS['scenario']['fail_plugin'] ?? false) ? [] : $where;
}
function cacti_log($message, ...$args) {}
if (getenv('KADUPUL_LIST_NATIVE_COVERAGE')) {
    if (hash_file('sha256', __FILE__) !== hash_file('sha256', $root . '/tests/Fixtures/data-input-list-native.php')) {
        throw new RuntimeException('Copied native bootstrap source changed');
    }
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/bin/legacy-data-input.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/bin/legacy-data-input.php');
    define('DATA_INPUT_LIST_TEST_COVERAGE', true);
    define('DATA_INPUT_LIST_NATIVE_SCENARIO', json_encode($scenario, JSON_THROW_ON_ERROR));
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
    register_shutdown_function(static function () {
        define('DATA_INPUT_LIST_NATIVE_COMPLETED', ['actual-list-worker-terminated']);
    });
}
