<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// #1088: an installation or upgrade that leaves a core table missing must not
// publish the version marker as if the schema were complete.

namespace InstallerCoreSchemaValidation;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/lib/installer.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read the installer.');
}
$methods = '';
foreach (['confirmInstallationVersion', 'validateCoreSchema', 'coreSchemaTables'] as $method) {
    // Loaded when present so a run against the old installer reaches the assertions.
    if (preg_match('/function\s+' . $method . '\s*\(/', $source) === 1) {
        $methods .= (str_contains(\test_php_function_source($source, $method), 'static function') ? '' : 'public ') . \test_php_function_source($source, $method) . "\n";
    }
}
$methods = preg_replace('/^public (function coreSchemaTables)/m', 'public static $1', $methods);
// The production code finds cacti.sql next to lib/; keep that root for the extracted methods.
$methods = str_replace('__DIR__', var_export($root . '/lib', true), $methods);
// The production methods run inside a stand-in that records the native calls they make.
eval('namespace ' . __NAMESPACE__ . '; class Installer {
    const STEP_ERROR = 98;
    const PROGRESS_VERSION_BEGIN = 90;
    const PROGRESS_VERSION_END = 95;
    public array $calls = [];
    public function setProgress($progress) { $this->calls[] = ["progress", $progress]; }
    public function addError($step, $section, $item, $text = false) { $this->calls[] = ["error", $section, $item]; }
    public function recordInstalledVersion() { $this->calls[] = ["version"]; return true; }
    ' . $methods . '
}');

if (!defined('CACTI_VERSION')) {
    define('CACTI_VERSION', '1.3.0');
}

function __(string $text, mixed ...$arguments): string
{
    return vsprintf($text, $arguments);
}

function db_fetch_assoc(string $sql, bool $log = true, mixed $connection = false): array|false
{
    $GLOBALS['installer_core_schema']['queries'][] = $sql;

    return $GLOBALS['installer_core_schema']['tables'];
}

function set_install_config_option(string $name, mixed $value): void
{
    $GLOBALS['installer_core_schema']['options'][] = [$name, $value];
}

function core_tables(): array
{
    preg_match_all('/^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\(/m', (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql'), $matches);

    return array_values(array_unique($matches[1]));
}

function confirm(array|false $tables): array
{
    $GLOBALS['installer_core_schema'] = ['tables' => $tables, 'queries' => [], 'options' => []];
    $installer = new Installer();
    $failure = $installer->confirmInstallationVersion();

    return [$failure, $installer->calls, $GLOBALS['installer_core_schema']['options']];
}

test('cacti.sql declares the core tables the check expects', function () {
    expect(core_tables())->toHaveCount(121)
        ->and(Installer::coreSchemaTables("CREATE TABLE `Host` (\nCREATE TABLE IF NOT EXISTS poller (\n-- CREATE TABLE skipped (\n"))->toBe(['host', 'poller']);
});

test('a complete schema publishes the version marker', function () {
    [$failure, $calls, $options] = confirm(array_map(static fn(string $table): array => ['TABLE_NAME' => $table], [...core_tables(), 'plugin_extra']));

    expect($failure)->toBe('')
        ->and($calls)->toBe([['progress', 90], ['version'], ['progress', 95]])
        ->and($options)->toBe([['install_version', CACTI_VERSION]]);
});

test('a missing core table stops before the version marker', function () {
    $tables = array_values(array_diff(core_tables(), ['poller_item', 'settings']));
    [$failure, $calls, $options] = confirm(array_map(static fn(string $table): array => ['TABLE_NAME' => $table], $tables));

    expect($failure)->toBe('Required core database tables are missing: poller_item, settings')
        ->and($calls)->toBe([['progress', 90], ['error', 'Database schema', $failure]])
        ->and($options)->toBe([]);
});

test('an unreadable table list fails closed', function () {
    [$failure, $calls, $options] = confirm(false);

    expect($failure)->toBe('The installed database could not be checked because its table list could not be read.')
        ->and($calls)->toBe([['progress', 90], ['error', 'Database schema', $failure]])
        ->and($options)->toBe([]);
});

test('a missing or empty cacti.sql fails closed', function (string $case) {
    $path = sys_get_temp_dir() . '/kadupul-schema-' . bin2hex(random_bytes(8)) . '.sql';
    if ($case === 'empty') {
        file_put_contents($path, "-- no tables\n");
    }
    $GLOBALS['installer_core_schema'] = ['tables' => [], 'queries' => [], 'options' => []];

    try {
        expect((new Installer())->validateCoreSchema($path))->toBe('The installed database could not be checked because the core table list could not be read from cacti.sql.')
            ->and($GLOBALS['installer_core_schema']['queries'])->toBe([]);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
})->with(['missing', 'empty']);
