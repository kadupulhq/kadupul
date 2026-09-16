<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Loaded only when the parent PHPUnit run is collecting real coverage.
$coverageRoot = dirname(__DIR__, 2);
require_once $coverageRoot . '/tests/vendor/autoload.php';
$coverageFilter = new SebastianBergmann\CodeCoverage\Filter();
$coverageFilter->includeFile($coverageRoot . '/lib/rrd.php');
$coverageFilter->includeFile($coverageRoot . '/lib/rrd_maintenance.php');
if (defined('RRD_TEST_CLI_COVERAGE_COPY')) {
    $coverageFilter->includeFile(RRD_TEST_CLI_COVERAGE_COPY);
}
$childCoverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
    (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($coverageFilter),
    $coverageFilter
);
$childCoverage->start('native RRD child ' . getmypid());
$childCoverageFile = RRD_TEST_COVERAGE_DIRECTORY . '/child-' . getmypid() . '.coverage';
register_shutdown_function(function () use ($childCoverage, $childCoverageFile) {
    // Append collection after application shutdown handlers so implicit pipe
    // close/drain is measured too, not just the main body of the child script.
    register_shutdown_function(function () use ($childCoverage, $childCoverageFile) {
        $childCoverage->stop();
        if (defined('RRD_TEST_CLI_COVERAGE_COPY')) {
            // Measure the real copied CLI, then map only its filename. Refuse
            // attribution unless every source byte (and thus line) is identical.
            $copyHash = hash_file('sha256', RRD_TEST_CLI_COVERAGE_COPY);
            $sourceHash = hash_file('sha256', RRD_TEST_CLI_COVERAGE_SOURCE);
            if ($copyHash === false || $sourceHash === false || !hash_equals($sourceHash, $copyHash)) {
                throw new RuntimeException('Copied CLI changed while measuring coverage');
            }
            $childCoverage->getData(true)->renameFile(RRD_TEST_CLI_COVERAGE_COPY, RRD_TEST_CLI_COVERAGE_SOURCE);
            $childCoverage->filter()->excludeFile(RRD_TEST_CLI_COVERAGE_COPY);
            $childCoverage->filter()->includeFile(RRD_TEST_CLI_COVERAGE_SOURCE);
        }
        if (file_put_contents($childCoverageFile, serialize($childCoverage)) === false) {
            throw new RuntimeException('Unable to preserve child process coverage');
        }
    });
});
