<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (isset($argv[3])) {
    $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Helpers/PhpSource.php', 'tests/Unit/Security/ClientAddrDiagnosticNativeTest.php', 'include/global_constants.php', 'include/session.php', 'remote_agent.php', 'lib/remote_agent_auth.php', 'lib/functions.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/client-addr-diagnostic-native.php', $argv[1], $sources);
    define('CLIENT_ADDR_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/remote_agent_auth.php';
require $root . '/tests/Helpers/PhpSource.php';

$config = array('is_web' => false, 'base_path' => $root,
    'proxy_headers' => $scenario['headers'], 'proxy_trusted_addresses' => $scenario['trusted'],
    'config_options_array' => array('selective_debug' => '', 'selective_plugin_debug' => '',
        'log_verbosity' => $scenario['debug'] ? POLLER_VERBOSITY_DEBUG : POLLER_VERBOSITY_NONE,
        'log_destination' => '1', 'path_cactilog' => $directory . '/diagnostics.log', 'client_timezone_support' => ''));
$allowed_proxy_headers = array_key_exists('allowed', $scenario) ? $scenario['allowed'] : array('HTTP_X_FORWARDED_FOR');
$_SERVER = $scenario['server'];
$_SESSION = array('sess_user_id' => 42);
$database = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$database->exec('CREATE TABLE poller(id INTEGER, hostname TEXT, disabled TEXT)');
$database->exec("INSERT INTO poller VALUES(1,'192.0.2.254',''),(2,'203.0.113.5','')");
$database->exec('CREATE TABLE sessions(id TEXT PRIMARY KEY, remote_addr TEXT, access INTEGER, data TEXT, user_id INTEGER, user_agent TEXT, start_time TEXT, transactions INTEGER)');
$sessionWrites = 0;
function db_fetch_assoc($sql, $cache = true, $connection = false)
{
    if ($sql !== 'SELECT * FROM poller WHERE disabled = ""') {
        throw new RuntimeException('Unexpected protected lookup');
    }
    return $GLOBALS['database']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_column_exists($table, $column)
{
    if ($table !== 'sessions') {
        throw new RuntimeException('Unexpected schema lookup');
    }
    return in_array($column, array_column($GLOBALS['database']->query('PRAGMA table_info(sessions)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
}
function db_execute_prepared($sql, $parameters)
{
    // Only adapt the MySQL upsert suffix; the real session producer supplies
    // its actual insert columns and values to this SQLite transport.
    if (!str_starts_with($sql, 'INSERT INTO sessions') || !str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
        throw new RuntimeException('Unexpected session mutation');
    }
    $GLOBALS['sessionWrites']++;
    return $GLOBALS['database']->prepare(explode('ON DUPLICATE KEY UPDATE', $sql, 2)[0])->execute($parameters);
}
function remote_agent_auth_cache_get($key)
{
    throw new RuntimeException('Numeric poller identities must not use DNS/cache');
}
function remote_agent_auth_cache_set($key, $value, $ttl = 30)
{
    throw new RuntimeException('Numeric poller identities must not use DNS/cache');
}

// Including these entire entry points would start a web session or dispatch a
// remote action. Extract their unchanged functions with the shared tokenizer.
foreach (array('remote_agent.php' => array('remote_client_authorized'),
    'include/session.php' => array('cacti_db_session_check', 'cacti_db_session_write')) as $file => $functions) {
    $source = file_get_contents($root . '/' . $file);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read caller source');
    }
    foreach ($functions as $function) {
        eval(test_php_function_source($source, $function));
    }
}
$poller_db_cnn_id = false;
$client = get_client_addr();
$authorized = remote_client_authorized();
$poller = $remote_agent_authorized_poller_id;
$sessionResult = cacti_db_session_write('owned-session', 'fixture-session');
$repeatedClient = get_client_addr();
$repeatedAuthorization = remote_client_authorized();
$session = $database->query('SELECT remote_addr, user_id, data FROM sessions')->fetch(PDO::FETCH_ASSOC);
$logs = is_file($directory . '/diagnostics.log') ? file_get_contents($directory . '/diagnostics.log') : '';
if (!is_string($logs) || $session === false || !$sessionResult || $sessionWrites !== 1) {
    throw new RuntimeException('Native caller/log readback failed');
}
$nativeChildCoverageMarkers = array('client-address-and-caller-outcomes-readback', 'actual-auth-log-readback', 'persisted-session-address-readback');
echo json_encode(array('client' => $client, 'repeat' => $repeatedClient,
    'authorized' => $authorized, 'repeat_authorized' => $repeatedAuthorization, 'poller' => $poller,
    'session' => $session, 'writes' => $sessionWrites, 'logs' => $logs), JSON_THROW_ON_ERROR);
