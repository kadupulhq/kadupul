<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $root, $directory, $case] = $argv;
$cases = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
if (!isset($cases['pages'][$case]) || !is_dir($directory)) {
    throw new RuntimeException('Unknown native presentation page or missing owned directory');
}
$scenario = $cases['pages'][$case];
if (file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) {
    throw new RuntimeException('Cannot preserve native page scenario');
}
define('PRESENTATION_PAGE_NATIVE', true);
require_once $root . '/tests/Helpers/PresentationPageEvidence.php';
$GLOBALS['nativePresentationObserver'] = static function (array $result) use ($root, $case): void {
    if (LegacyFormGoldenFiles::$transformedIncludes !== 0) {
        throw new RuntimeException('Clock-patched sources cannot establish native coverage');
    }
    $allowed = array_flip(array_merge(PresentationPageEvidence::sources(), array('tests/Fixtures/presentation-pages-native.php')));
    $unregistered = array();
    foreach (get_included_files() as $included) {
        $canonical = realpath($included);
        if ($canonical !== false && str_starts_with($canonical, $root . '/')) {
            $relative = substr($canonical, strlen($root) + 1);
            if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($allowed[$relative])) {
                $unregistered[] = $relative;
            }
        }
    }
    if ($unregistered !== array()) {
        throw new RuntimeException('Native page loaded unregistered worker sources: ' . implode(', ', $unregistered));
    }
    $golden = file_get_contents($root . '/tests/Golden/forms/pages/' . $case . '.html');
    if ($golden === false) {
        throw new RuntimeException('Native presentation recorded oracle missing');
    }
    $expected = PresentationPageEvidence::contracts($golden);

    if ($expected['controls'] === array() || $expected !== PresentationPageEvidence::contracts($result['html']) || $result['diagnostics'] !== array()) {
        throw new RuntimeException('Native page control/label/link contract changed: ' . $case);
    }
    $GLOBALS['nativePresentationMarkers'] = PresentationPageEvidence::markers($case);
};
if (getenv('PRESENTATION_PAGE_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    require_once $root . '/tests/Helpers/PresentationPageEvidence.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (PresentationPageEvidence::measuredSources() as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
        (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter
    );
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/presentation-pages-native.php', $case, PresentationPageEvidence::sources());
    $coverage->start('native presentation ' . $case);
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
        // Run after the application's output and shutdown handlers.
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
            $error = error_get_last();
            if (($GLOBALS['nativePresentationMarkers'] ?? array()) !== PresentationPageEvidence::markers($case)) {
                throw new RuntimeException('Native page outcome assertions did not complete');
            }
            if ($error !== null && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                throw new RuntimeException('Native presentation did not complete');
            }
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $report = $directory . '/page.coverage';
            $serialized = serialize($coverage);
            if (file_put_contents($report, $serialized) !== strlen($serialized)) {
                throw new RuntimeException('Cannot preserve native page coverage');
            }
            NativeChildCoverageEvidence::write($report, $root, $snapshot, PresentationPageEvidence::markers($case));
        });
    });
}
$argv = array(__FILE__, $root, $directory);
require $root . '/tests/Fixtures/legacy-form-golden.php';
