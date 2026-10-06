<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__, 2);
$directory = $argv[1];
require $root . '/tests/Helpers/IncludeNotificationNativeEvidence.php';
foreach (IncludeNotificationNativeEvidence::COPIES as $source => $file) {
    if (!is_dir(dirname($directory . '/' . $file))) {
        mkdir(dirname($directory . '/' . $file), 0700, true);
    }
    if (!copy($root . '/' . $source, $directory . '/' . $file)) {
        throw new RuntimeException('Cannot create owned notification source copy');
    }
}
IncludeNotificationNativeEvidence::verifyCopies($root, $directory);
$snapshot = NativeChildCoverageEvidence::snapshot($root, IncludeNotificationNativeEvidence::PRODUCER, IncludeNotificationNativeEvidence::SCENARIO, IncludeNotificationNativeEvidence::SOURCES);
if (isset($argv[2])) {
    require $root . '/tests/vendor/autoload.php';
    $testLoader = Composer\Autoload\ClassLoader::getRegisteredLoaders()[$root . '/tests/vendor'];
    class_exists(SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::class);
    $coverage = IncludeNotificationNativeEvidence::start($root, $directory);
}
require $root . '/include/global_constants.php';
require $directory . '/lib/functions.php';
require $root . '/include/vendor/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Actual settings persistence; only the MySQL upsert syntax is adapted to SQLite.
$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT NOT NULL)');
function db_execute_prepared(string $sql, array $values): bool
{
    if (!str_contains($sql, 'INSERT INTO settings') || !str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
        throw new RuntimeException('Unexpected notification write');
    }
    return $GLOBALS['database']->prepare('INSERT INTO settings (name,value) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value')->execute($values);
}
function __(string $text, mixed ...$values): string
{
    return $values ? vsprintf($text, $values) : $text;
}
$config = ['base_path' => $directory, 'is_web' => false, 'poller_id' => 1, 'cacti_server_os' => 'unix',
    'config_options_array' => ['debounce_missing:missing.js' => '', 'client_timezone_support' => '',
        'selective_debug' => '', 'selective_plugin_debug' => '', 'log_destination' => 1,
        'log_verbosity' => POLLER_VERBOSITY_LOW, 'path_cactilog' => $directory . '/notification.log']];
$_SESSION = [];
$GLOBALS['notificationCalls'] = [];
$GLOBALS['notificationLifecycle'] = [];
$result = [];
$result['first'] = get_include_relpath('missing.js');
$result['saved'] = $database->query("SELECT value FROM settings WHERE name='debounce_missing:missing.js'")->fetchColumn();
$result['first_log'] = file_get_contents($directory . '/notification.log');
$result['repeat'] = get_include_relpath('missing.js');
$result['repeat_log'] = file_get_contents($directory . '/notification.log');
$result['repeat_calls'] = $GLOBALS['notificationCalls'];
$result['repeat_saved'] = $database->query("SELECT value FROM settings WHERE name='debounce_missing:missing.js'")->fetchColumn();
$config['config_options_array']['debounce_missing:missing.js'] = time() - 7201;
$result['expired'] = get_include_relpath('missing.js');
$result['expired_log'] = file_get_contents($directory . '/notification.log');
$result['calls'] = $GLOBALS['notificationCalls'];
$result['lifecycle'] = $GLOBALS['notificationLifecycle'];
$result['saved_expired'] = $database->query("SELECT value FROM settings WHERE name='debounce_missing:missing.js'")->fetchColumn();
$warning = 'WARNING: Key Kadupul Include File ' . $directory . '/missing.js missing.  Please locate and replace this file';
$notification = ['Kadupul System Warning', 'WARNING:  Key Kadupul Include File ' . $directory . '/missing.js missing.  Please locate and replace this file'];
if ($result['first'] !== 'missing.js' || $result['expired'] !== 'missing.js' || $result['repeat'] !== ''
    || substr_count($result['first_log'], $warning) !== 1 || $result['repeat_log'] !== $result['first_log']
    || substr_count($result['expired_log'], $warning) !== 2
    || $result['repeat_calls'] !== [$notification] || $result['calls'] !== [$notification, $notification]
    || $result['lifecycle'] !== ['boot', 'bridge', 'shutdown', 'boot', 'bridge', 'shutdown']
    || !ctype_digit($result['saved']) || $result['repeat_saved'] !== $result['saved'] || !ctype_digit($result['saved_expired'])) {
    throw new RuntimeException('Native notification assertions failed');
}
restore_error_handler();
if (file_put_contents($directory . '/result.json', json_encode($result, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Cannot preserve notification result');
}
if (isset($coverage)) {
    // Keep the unit PHPUnit 12 coverage stack after the application autoloader runs.
    $testLoader->unregister();
    $testLoader->register(true);
    IncludeNotificationNativeEvidence::finish($coverage, $root, $directory, $snapshot);
}
