<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[, $root, $directory, $input] = $argv;
$case = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
require_once $root . '/tests/Helpers/NativeDevicePresentation.php';
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (getenv('DEVICE_PRESENTATION_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (['host.php', 'lib/html_form.php', 'lib/html.php', 'lib/functions.php'] as $source) $filter->includeFile($root . '/' . $source);
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/device-presentation-native.php', $input, NativeDevicePresentation::sources());
    $coverage->start('native device presentation');
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $testLoader): void {
            if (($GLOBALS['nativeDeviceMarkers'] ?? []) !== NativeDevicePresentation::markers()) throw new RuntimeException('Device outcomes did not complete');
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $serialized = serialize($coverage);
            $report = $directory . '/device.coverage';
            if (file_put_contents($report, $serialized) !== strlen($serialized)) throw new RuntimeException('Cannot preserve device coverage');
            NativeChildCoverageEvidence::write($report, $root, $snapshot, NativeDevicePresentation::markers());
        });
    });
}
$definitions = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
$scenario = $definitions['pages']['auth_profile'];
$scenario['page'] = 'host.php';
$scenario['request'] = ($case['request'] ?? []) + ['header' => 'false'];
if (($scenario['request']['action'] ?? '') === 'save') $scenario['request']['action'] = 'native_device_bootstrap';
$scenario['settings'] = ($case['settings'] ?? []) + ['poller_interval' => '300', 'num_rows_table' => '30'];
if (file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Cannot preserve owned device request');
}
$database = NativeDevicePresentation::database($root, $directory);
foreach ($case['rows'] ?? [] as $table => $rows) {
    foreach ($rows as $row) NativeDevicePresentation::insert($database, $table, $row);
}
$before = NativeDevicePresentation::snapshot($database);
$connection = new NativeDeviceConnection($database);
define('PRESENTATION_PAGE_NATIVE', true);
$GLOBALS['nativePresentationBootstrap'] = static function () use ($connection, $case): void {
    $GLOBALS['database_sessions'] = array_fill_keys(array_keys($GLOBALS['database_sessions']), $connection);
    if (isset($case['post'])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $case['post'] + ($case['request'] ?? []);
        $_POST['__csrf_magic'] = csrf_get_tokens();
        $_GET = [];
        foreach ($_POST as $name => $value) set_request_var($name, $value);
        cacti_require_post_actions(['save']);
    }
    $_SESSION = ($case['session'] ?? []) + $_SESSION;
};
$GLOBALS['nativePresentationObserver'] = static function (array &$result) use ($database, $before, $connection): void {
    if (LegacyFormGoldenFiles::$transformedIncludes !== 0) throw new RuntimeException('Device presentation executed transformed source');
    if ($before !== NativeDevicePresentation::snapshot($database)) throw new RuntimeException('Device presentation altered persisted records');
    if ($result['diagnostics'] !== []) throw new RuntimeException('Device presentation emitted unexpected diagnostics: ' . json_encode($result['diagnostics']));
    if ($connection->queries === []) throw new RuntimeException('Device page did not query actual persisted records');
    $allowed = array_flip(NativeDevicePresentation::sources());
    foreach (get_included_files() as $included) {
        $canonical = realpath($included);
        if ($canonical !== false && str_starts_with($canonical, $GLOBALS['root'] . '/')) {
            $relative = substr($canonical, strlen($GLOBALS['root']) + 1);
            if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($allowed[$relative])) {
                throw new RuntimeException('Device page loaded unregistered source: ' . $relative);
            }
        }
    }
    $result['device_queries'] = $connection->queries;
    $result['device_state'] = NativeDevicePresentation::snapshot($database);
    $result['row_cache'] = $database->query('SELECT * FROM user_auth_row_cache')->fetchAll(PDO::FETCH_ASSOC);
    $result['count_hashes'] = $connection->cacheHashes;
    $GLOBALS['nativeDeviceMarkers'] = NativeDevicePresentation::markers();
};
$argv = [__FILE__, $root, $directory];
require $root . '/tests/Fixtures/legacy-form-golden.php';
