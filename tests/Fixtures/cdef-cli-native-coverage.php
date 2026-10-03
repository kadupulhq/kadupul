<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$cliCoverageRoot = dirname(__DIR__, 2);
require_once $cliCoverageRoot . '/include/vendor/autoload.php';
require_once $cliCoverageRoot . '/tests/Helpers/CdefCliCoverageRegistration.php';
require_once $cliCoverageRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath($cliCoverageRoot . '/cli/upgrade_database.php')) {
    throw new RuntimeException('CLI coverage requires the original upgrade entrypoint.');
}
$cliCoverageDirectory = getenv('KADUPUL_CLI_COVERAGE_DIRECTORY');
$cliCoverageId = getenv('KADUPUL_CLI_COVERAGE_INVOCATION');
$cliCoverageArguments = ['cli/upgrade_database.php', ...array_slice($_SERVER['argv'], 1)];
$cliCoverageHash = hash('sha256', json_encode($cliCoverageArguments, JSON_THROW_ON_ERROR));
if (!is_string($cliCoverageDirectory) || !is_dir($cliCoverageDirectory) || !is_writable($cliCoverageDirectory)
    || realpath($cliCoverageDirectory) !== $cliCoverageDirectory
    || !is_string($cliCoverageId) || preg_match('/^[a-f0-9]{32}$/D', $cliCoverageId) !== 1
    || getenv('KADUPUL_CLI_COVERAGE_ARGV_HASH') !== $cliCoverageHash) {
    throw new RuntimeException('Invalid original CLI invocation binding.');
}
$cliCoverageScenario = $cliCoverageId . ':' . $cliCoverageHash;
$cliCoverageSnapshot = NativeChildCoverageEvidence::snapshot(
    $cliCoverageRoot,
    'cli/upgrade_database.php',
    $cliCoverageScenario,
    CdefCliCoverageRegistration::sources()
);
$cliCoverageFilter = new SebastianBergmann\CodeCoverage\Filter();
$cliCoverageFilter->includeFile($cliCoverageRoot . '/cli/upgrade_database.php');
$cliCoverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
    (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($cliCoverageFilter),
    $cliCoverageFilter
);
$cliCoverage->start('actual-cli-' . $cliCoverageId);
register_shutdown_function(static function () use (
    $cliCoverage,
    $cliCoverageDirectory,
    $cliCoverageId,
    $cliCoverageHash,
    $cliCoverageRoot,
    $cliCoverageSnapshot
): void {
    $error = error_get_last();
    if (is_array($error) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    $cliCoverage->stop();
    $report = $cliCoverageDirectory . '/' . $cliCoverageId . '.coverage';
    $serialized = serialize($cliCoverage);
    if (file_put_contents($report, $serialized, LOCK_EX) !== strlen($serialized)) {
        throw new RuntimeException('Cannot persist original CLI coverage.');
    }
    // This records collection only. The parent separately confirms exit,
    // original SQL assertions, and completion after owned schema cleanup.
    NativeChildCoverageEvidence::write(
        $report,
        $cliCoverageRoot,
        $cliCoverageSnapshot,
        ['actual-cli-collector-finished', 'argv:' . $cliCoverageHash, CdefNativeCoverageRegistration::runtimeMarker()]
    );
});
