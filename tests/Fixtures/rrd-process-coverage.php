<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Loaded only when the parent PHPUnit run is collecting real coverage.
$coverageRoot = dirname(__DIR__, 2);
require_once $coverageRoot . '/tests/vendor/autoload.php';
$coverageFilter = new SebastianBergmann\CodeCoverage\Filter();
$coverageFilter->includeFile($coverageRoot . '/lib/rrd.php');
$coverageFilter->includeFile($coverageRoot . '/lib/rrd_maintenance.php');
$childCoverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
    (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($coverageFilter),
    $coverageFilter
);
$childCoverage->start('native RRD child ' . getmypid());
$childCoverageFile = dirname($_SERVER['SCRIPT_FILENAME']) . '/child-' . getmypid() . '.coverage';
register_shutdown_function(function () use ($childCoverage, $childCoverageFile) {
    // Append collection after application shutdown handlers so implicit pipe
    // close/drain is measured too, not just the main body of the child script.
    register_shutdown_function(function () use ($childCoverage, $childCoverageFile) {
        $childCoverage->stop();
        if (file_put_contents($childCoverageFile, serialize($childCoverage)) === false) {
            throw new RuntimeException('Unable to preserve child process coverage');
        }
    });
});
