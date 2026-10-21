<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

date_default_timezone_set('UTC');

$root = getenv('REPORT_POLLER_SOURCE_ROOT');
$directory = dirname(__DIR__);
$scenario = getenv('REPORT_POLLER_SCENARIO');
if (!is_string($root) || !is_string($scenario)) {
    throw new RuntimeException('Explicit report poller fixture configuration is required.');
}
require_once $root . '/tests/Helpers/PhpSource.php';
require_once $root . '/tests/Helpers/NativeReportConsumers.php';
require_once $root . '/include/global_constants.php';

// Reuse the existing canonical-schema SQLite port; no shared database is used.
putenv('NATIVE_DEVICE_MYSQL_DSN');
$database = NativeReportConsumers::database($root, $directory);
$schema = file_get_contents($root . '/cacti.sql');
if ($schema === false || preg_match('/CREATE TABLE settings \(.*?\) ENGINE=[^;]+;/s', $schema, $match) !== 1) {
    throw new RuntimeException('Canonical settings schema unavailable.');
}
$settings = preg_replace('/varchar\([0-9]+\)/i', 'TEXT', $match[0]);
$settings = preg_replace('/ ENGINE=.*;/s', ';', $settings);
if (!is_string($settings)) {
    throw new RuntimeException('Canonical settings schema could not be adapted.');
}
$database->exec($settings);
$database->exec('INSERT INTO user_auth (id, username) VALUES (1, \'fixture\')');
$due = time() - 60;
$count = in_array($scenario, ['mixed', 'missing-owner', 'owner-read-failure', 'forced'], true) ? 2 : 1;
if ($scenario !== 'empty') {
    for ($id = 1; $id <= $count; $id++) {
        $row = [
            'id' => $id, 'name' => 'Report ' . $id, 'user_id' => $scenario === 'missing-owner' && $id === 1 ? 999 : 1,
            'from_name' => 'Fixture', 'bcc' => '', 'enabled' => 'on', 'email' => 'report' . $id . '@example.invalid', 'from_email' => 'sender@example.invalid',
            'intrvl' => REPORTS_SCHED_INTVL_HOUR, 'count' => 1, 'mailtime' => $scenario === 'forced' && $id === 2 ? $due + 86400 : $due,
        ];
        $database->prepare('INSERT INTO reports (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }
}
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$config = ['base_path' => $directory];
$reports_interval = [REPORTS_SCHED_INTVL_HOUR => 'hour'];
$_REQUEST = [];
$evidence = ['logs' => [], 'mailer' => [], 'registered' => 0, 'unregistered' => 0, 'due' => $due, 'queries' => []];
register_shutdown_function(static function () use ($directory): void {
    $result = $GLOBALS['evidence'];
    $result['reports'] = $GLOBALS['database']->query('SELECT id, enabled, mailtime, lastsent FROM reports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $result['stats'] = $GLOBALS['database']->query('SELECT value FROM settings WHERE name=\'stats_reports\'')->fetchColumn();
    if (file_put_contents($directory . '/result.json', json_encode($result, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Report poller evidence could not be written.');
    }
});

foreach (['lib/reports.php' => ['generate_report', 'reports_interval_start', 'utime_add', 'reports_log'],
    'lib/html_reports.php' => ['reports_html_account_exists', 'reports_html_report_disable']] as $file => $functions) {
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        throw new RuntimeException('Production report source unavailable.');
    }
    foreach ($functions as $name) {
        eval(test_php_function_source($source, $name));
    }
}

function report_poller_query(string $sql, array $parameters = []): PDOStatement|false
{
    $GLOBALS['evidence']['queries'][] = $sql;
    if (($GLOBALS['scenario'] === 'report-read-failure' && str_contains($sql, 'SELECT * FROM reports'))
        || ($GLOBALS['scenario'] === 'owner-read-failure' && str_contains($sql, 'SELECT * FROM user_auth') && count($GLOBALS['evidence']['queries']) === 2)
        || ($GLOBALS['scenario'] === 'stats-write-failure' && str_contains($sql, 'REPLACE INTO settings'))) {
        $sql = 'SELECT * FROM owned_missing_report_poller_table';
        $parameters = [];
    }
    $query = $GLOBALS['database']->prepare($sql);

    return $query !== false && $query->execute($parameters) ? $query : false;
}
function db_fetch_assoc_prepared(string $sql, array $parameters = []): array|false
{
    $query = report_poller_query($sql, $parameters);

    return $query === false ? false : $query->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc(string $sql): array|false
{
    return db_fetch_assoc_prepared($sql);
}
function db_fetch_row_prepared(string $sql, array $parameters = []): array|false
{
    $query = report_poller_query($sql, $parameters);

    return $query === false ? false : ($query->fetch(PDO::FETCH_ASSOC) ?: []);
}
function db_fetch_cell_prepared(string $sql, array $parameters = []): mixed
{
    $query = report_poller_query($sql, $parameters);

    return $query === false ? false : $query->fetchColumn();
}
function db_execute_prepared(string $sql, array $parameters = []): bool
{
    return report_poller_query($sql, $parameters) !== false;
}
function db_table_exists(string $table): bool
{
    return $GLOBALS['scenario'] !== 'missing-table';
}
function register_process_start(mixed ...$arguments): bool
{
    $GLOBALS['evidence']['registered']++;

    return $GLOBALS['scenario'] !== 'busy';
}
function unregister_process(mixed ...$arguments): void
{
    $GLOBALS['evidence']['unregistered']++;
}
function cacti_sizeof(mixed $value): int
{
    return is_countable($value) ? count($value) : 0;
}
function read_config_option(string $name): int
{
    return $name === 'reports_log_verbosity' ? 5 : 300;
}
function read_user_setting(mixed ...$arguments): int
{
    return 0;
}
function reports_generate_html(mixed ...$arguments): string
{
    return '<html><body>Controlled report body</body></html>';
}
function mailer(array|string $from, string $to, mixed ...$arguments): string
{
    $GLOBALS['evidence']['mailer'][] = $to;

    return in_array($GLOBALS['scenario'], ['smtp-failure', 'mixed'], true) && $to === 'report1@example.invalid' ? 'Controlled SMTP rejection' : '';
}
function cacti_log(string $message, mixed ...$arguments): void
{
    $GLOBALS['evidence']['logs'][] = $message;
}
function get_cacti_version(): string
{
    return 'fixture';
}
function isset_request_var(string $name): bool
{
    return false;
}
function raise_message(mixed ...$arguments): void {}
function __esc(string $message, mixed ...$arguments): string
{
    return vsprintf($message, $arguments);
}
