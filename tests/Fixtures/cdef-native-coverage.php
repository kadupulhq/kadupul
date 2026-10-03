<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$coverageRoot = dirname(__DIR__, 2);
require_once $coverageRoot . '/include/vendor/autoload.php';
require_once $coverageRoot . '/tests/Helpers/CdefNativeCoverageRegistration.php';
require_once $coverageRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
$coverageCase = getenv('KADUPUL_NATIVE_COVERAGE_CASE');
$registration = CdefNativeCoverageRegistration::cases()[$coverageCase] ?? null;
if (!is_array($registration)) throw new RuntimeException('Unregistered native coverage case.');
$coverageProbe = 'tests/security/' . $registration[0];
// A probe may launch actual CLI children. Only its original entry point owns
// this evidence; never let descendants overwrite the parent measurement.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath($coverageRoot . '/' . $coverageProbe)) return;
$coverageDirectory = getenv('KADUPUL_NATIVE_COVERAGE_DIRECTORY');
if (!is_string($coverageDirectory) || !is_dir($coverageDirectory) || !is_writable($coverageDirectory)) {
    throw new RuntimeException('An owned writable native coverage directory is required.');
}
$coverageSnapshot = NativeChildCoverageEvidence::snapshot($coverageRoot, $coverageProbe, $coverageCase, CdefNativeCoverageRegistration::sources());
$coverageFilter = new SebastianBergmann\CodeCoverage\Filter();
foreach (CdefNativeCoverageRegistration::measured() as $file) $coverageFilter->includeFile($coverageRoot . '/' . $file);
$nativeCoverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($coverageFilter), $coverageFilter);
$nativeCoverage->start('native-' . $coverageCase);
ob_start();
register_shutdown_function(static function () use ($nativeCoverage, $coverageDirectory, $coverageRoot, $coverageSnapshot, $registration): void {
    $output = ob_get_clean();
    if (is_string($output)) echo $output;
    $error = error_get_last();
    if (is_array($error) && in_array($error['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) return;
    if (!is_string($output) || !in_array($registration[1], explode("\n", $output), true)) {
        throw new RuntimeException('The original native probe did not confirm completion.');
    }
    $nativeCoverage->stop();
    $report = $coverageDirectory . '/native.coverage';
    $serialized = serialize($nativeCoverage);
    if (file_put_contents($report, $serialized) !== strlen($serialized)) throw new RuntimeException('Cannot persist actual native coverage.');
    NativeChildCoverageEvidence::write(
        $report,
        $coverageRoot,
        $coverageSnapshot,
        [$registration[1],'probe-completed-and-cleanup-returned',CdefNativeCoverageRegistration::runtimeMarker()]
    );
});
