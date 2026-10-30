<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

// Runs the shipped legacy profile writer and statement counter in a fresh
// process. include/global.php needs a database, so this loads only the two
// files Phase 0 added to that bootstrap path.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
[, $directory, $scenario, $collect] = $argv + [null, '', '', ''];
require $root . '/include/vendor/autoload.php';
$coverageSources = ['tests/Helpers/NativeChildCoverageEvidence.php', 'lib/database.php', 'include/request_profile.php', 'src/Platform/Infrastructure/Profiling/RequestProfileLog.php'];
if ($collect === 'coverage') {
    require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/legacy-request-profile-native.php', $scenario, $coverageSources);
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (['lib/database.php', 'include/request_profile.php', 'src/Platform/Infrastructure/Profiling/RequestProfileLog.php'] as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $coverage->start('legacy request profile');
    // Nested so the report is written after the profile writer's own
    // shutdown function, which include/request_profile.php registers later.
    register_shutdown_function(static function () use ($coverage, $directory, $snapshot, $root, $scenario): void {
        register_shutdown_function(static function () use ($coverage, $directory, $snapshot, $root, $scenario): void {
            $coverage->stop();
            $report = "$directory/$scenario.coverage";
            if (file_put_contents($report, serialize($coverage)) === false || !isset($GLOBALS['completed'])) {
                throw new RuntimeException('Legacy request profile scenario did not complete.');
            }
            NativeChildCoverageEvidence::write($report, $root, $snapshot, ['legacy-profile-scenario-completed']);
        });
    });
}
if ($scenario === 'counted') {
    require $root . '/lib/database.php';
    db_statement_count(true);
    db_statement_count(true);
}
include $root . '/include/request_profile.php';
kadupul_register_request_profile();
$GLOBALS['completed'] = true;
