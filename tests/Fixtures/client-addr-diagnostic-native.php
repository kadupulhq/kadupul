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
    $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Helpers/PhpSource.php', 'tests/Unit/Security/ClientAddrDiagnosticNativeTest.php', 'include/global_constants.php', 'include/session.php', 'lib/auth.php', 'lib/graph_item_choices.php', 'cacti.sql', 'remote_agent.php', 'lib/remote_agent_auth.php', 'lib/functions.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/client-addr-diagnostic-native.php', $argv[1], $sources);
    define('CLIENT_ADDR_TEST_COVERAGE', true);
    if ($scenario['audit'] ?? false) define('AUTH_POLICY_TEST_COVERAGE', true);
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
        'secpass_lockfailed' => 3, 'log_destination' => '1', 'path_cactilog' => $directory . '/diagnostics.log', 'client_timezone_support' => ''));
$allowed_proxy_headers = array_key_exists('allowed', $scenario) ? $scenario['allowed'] : array('HTTP_X_FORWARDED_FOR');
$_SERVER = $scenario['server'];
$_SESSION = array('sess_user_id' => 42);
$database = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$database->exec('CREATE TABLE poller(id INTEGER, hostname TEXT, disabled TEXT)');
$database->exec("INSERT INTO poller VALUES(1,'192.0.2.254',''),(2,'203.0.113.5','')");
$database->exec('CREATE TABLE sessions(id TEXT PRIMARY KEY, remote_addr TEXT, access INTEGER, data TEXT, user_id INTEGER, user_agent TEXT, start_time TEXT, transactions INTEGER)');
$sessionWrites = 0;
$auditParameters = [];
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
    // Preserve actual producer columns and parameters; adapt only native
    // MySQL session-upsert and audit INSERT IGNORE dialect for SQLite.
    if (($GLOBALS['scenario']['audit'] ?? false) && (str_starts_with($sql, 'UPDATE user_auth') || str_starts_with($sql, 'INSERT IGNORE INTO user_log'))) {
        if (str_starts_with($sql, 'INSERT IGNORE INTO user_log')) $GLOBALS['auditParameters'][] = $parameters;
        return $GLOBALS['database']->prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql))->execute($parameters);
    }
    if (!str_starts_with($sql, 'INSERT INTO sessions') || !str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
        throw new RuntimeException('Unexpected session mutation');
    }
    $GLOBALS['sessionWrites']++;
    return $GLOBALS['database']->prepare(explode('ON DUPLICATE KEY UPDATE', $sql, 2)[0])->execute($parameters);
}
function db_fetch_row_prepared($sql, $parameters = array())
{
    if (!($GLOBALS['scenario']['audit'] ?? false) || !str_contains($sql, 'FROM user_auth')) throw new RuntimeException('Unexpected audit lookup');
    $statement = $GLOBALS['database']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_cell_prepared($sql, $parameters = array())
{
    $row = db_fetch_row_prepared($sql, $parameters);
    return $row ? reset($row) : false;
}
function __(string $message, mixed ...$arguments): string
{
    return $arguments ? vsprintf($message, $arguments) : $message;
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
$audit = [];
if ($scenario['audit'] ?? false) {
    $database->sqliteCreateFunction('NOW', static fn(): string => '2026-10-05 12:00:00');
    $database->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,realm INTEGER,enabled TEXT,locked TEXT,lastfail INTEGER,failed_attempts INTEGER); INSERT INTO user_auth VALUES(42,'owned-user',0,'on','',0,0); CREATE TABLE user_log(username TEXT,user_id INTEGER,result INTEGER,ip TEXT,time TEXT)");
    require $root . '/lib/auth.php';
    auth_process_lockout('owned-user', 0);
    $audit = $database->query('SELECT username,user_id,result,ip FROM user_log')->fetchAll(PDO::FETCH_ASSOC);
}
$repeatedClient = get_client_addr();
$repeatedAuthorization = remote_client_authorized();
$session = $database->query('SELECT remote_addr, user_id, data FROM sessions')->fetch(PDO::FETCH_ASSOC);
$logs = is_file($directory . '/diagnostics.log') ? file_get_contents($directory . '/diagnostics.log') : '';
if (!is_string($logs) || $session === false || !$sessionResult || $sessionWrites !== 1) {
    throw new RuntimeException('Native caller/log readback failed');
}
$nativeChildCoverageMarkers = array('client-address-and-caller-outcomes-readback', 'actual-auth-log-readback', 'persisted-session-address-readback');
if ($scenario['audit'] ?? false) $nativeChildCoverageMarkers[] = 'actual-user-log-address-readback';
echo json_encode(array('audit' => $audit, 'audit_parameters' => $auditParameters, 'client' => $client, 'repeat' => $repeatedClient,
    'authorized' => $authorized, 'repeat_authorized' => $repeatedAuthorization, 'poller' => $poller,
    'session' => $session, 'writes' => $sessionWrites, 'logs' => $logs), JSON_THROW_ON_ERROR);
