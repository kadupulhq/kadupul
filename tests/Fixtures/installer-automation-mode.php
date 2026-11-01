<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

[$program, $root, $encoded] = $argv;
$scenario = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
require $root . '/tests/Helpers/PhpSource.php';
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->exec("CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value VARCHAR(4096) NOT NULL DEFAULT '')");
$database->exec("CREATE TABLE automation_networks (id INTEGER PRIMARY KEY, enabled CHAR(2) DEFAULT '', subnet_range VARCHAR(1024) NOT NULL DEFAULT '')");
$database->exec('CREATE TABLE data_source_profiles (id INTEGER PRIMARY KEY, name VARCHAR(100), step INTEGER, heartbeat INTEGER, `default` CHAR(2))');
$database->exec('CREATE TABLE data_template_data (rrd_step INTEGER, data_source_profile_id INTEGER)');
$database->exec('CREATE TABLE data_template_rrd (rrd_heartbeat INTEGER)');
$database->exec("INSERT INTO data_source_profiles VALUES (1, 'Fixture', 300, 600, 'on')");
$database->prepare('INSERT INTO automation_networks VALUES (7, ?, ?)')->execute([$scenario['network_enabled'], '192.0.2.0/24']);
if (array_key_exists('stored_mode', $scenario)) {
    $database->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['install_automation_mode', $scenario['stored_mode']]);
}
$config = ['cacti_server_os' => 'unix', 'is_web' => $scenario['web'] ?? false, 'config_options_array' => ['install_automation_mode' => 'on']];
$_SESSION = ['sess_config_array' => ['install_automation_mode' => 'on']];
$database_hostname = 'fixture';
$database_port = 0;
$database_default = 'fixture';
$database_sessions = ['fixture:0:fixture' => $database];
$local_db_cnn_id = $database;
$settings = [];

final class InstallerNetworkHandoff extends RuntimeException {}
function log_install_debug(mixed ...$arguments): void {}
function log_install_medium(mixed ...$arguments): void {}
function log_install_high(mixed ...$arguments): void {}
function log_install_always(mixed ...$arguments): void {}
function clean_up_lines(mixed $value): string { return (string) $value; }
function __(string $text, mixed ...$values): string { return $values ? vsprintf($text, $values) : $text; }
function db_table_exists($table, $log = true, $db_conn = false): bool
{
    return (bool) db_fetch_row_prepared('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);
}
function db_fetch_row($sql, $log = true, $db_conn = false): array
{
    return db_fetch_row_prepared($sql, [], $log, $db_conn);
}
function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false): array
{
    $statement = ($db_conn instanceof PDO ? $db_conn : $GLOBALS['database'])->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_execute($sql, $log = true, $db_conn = false): bool
{
    return db_execute_prepared($sql, [], $log, $db_conn);
}
function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false,
    $execute_name = 'Exec', $default_value = true, $return_func = 'no_return_function', $return_params = []): bool {
    $statement = ($db_conn instanceof PDO ? $db_conn : $GLOBALS['database'])->prepare($sql);
    $result = $statement->execute($params);
    // Stop before unrelated device creation, cache rebuilding and key generation.
    if (str_contains($sql, 'UPDATE automation_networks SET')) {
        throw new InstallerNetworkHandoff();
    }
    return $result;
}

$functions = file_get_contents($root . '/lib/functions.php');
$installFunctions = file_get_contents($root . '/install/functions.php');
if ($functions === false || $installFunctions === false) {
    throw new RuntimeException('Cannot read installer settings helpers');
}
if (is_file($root . '/src/Platform/Infrastructure/Legacy/LegacySettingsStorage.php')) {
    require $root . '/src/Platform/Infrastructure/Legacy/LegacyLockRetry.php';
    require $root . '/src/Platform/Infrastructure/Legacy/LegacySettingsStorage.php';
}
$source = '';
foreach (['cacti_sizeof', 'read_default_config_option', 'read_config_option'] as $name) {
    $source .= test_php_function_source($functions, $name);
}
$source .= test_php_function_source($installFunctions, 'set_install_config_option');
eval('declare(strict_types=0); use Kadupul\Platform\Infrastructure\Legacy\LegacySettingsStorage;' . $source);
require $root . '/lib/installer.php';
$reflection = new ReflectionClass(Installer::class);
$installer = $reflection->newInstanceWithoutConstructor();
foreach (['profile' => 1, 'automationRange' => '192.0.2.0/24'] as $name => $value) {
    $reflection->getProperty($name)->setValue($installer, $value);
}
$setter = $reflection->getMethod('setAutomationMode');
$setter->invoke($installer, $installer->getAutomationMode());
$reflection->getMethod('processParameters')->invoke($installer, $scenario['parameters']);
$afterPayload = $reflection->getProperty('automationMode')->getValue($installer);
$storedAfterPayload = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', ['install_automation_mode'])['value'] ?? null;
$setter->invoke($installer, $installer->getAutomationMode());
$afterReload = $reflection->getProperty('automationMode')->getValue($installer);
$observed = false;
try {
    $reflection->getMethod('installServer')->invoke($installer);
} catch (InstallerNetworkHandoff $boundary) {
    $observed = true;
}
if (!$observed) {
    throw new RuntimeException('Installer network mutation was not reached');
}
echo json_encode(['after_payload' => $afterPayload, 'after_reload' => $afterReload,
    'stored_after_payload' => $storedAfterPayload,
    'network' => db_fetch_row('SELECT * FROM automation_networks WHERE id = 7'),
    'errors' => $installer->getErrors()], JSON_THROW_ON_ERROR);
