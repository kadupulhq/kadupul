<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Prepend collection before the worker loads its shared helper or bootstrap.
$workerCoverageRoot = dirname(__DIR__, 2);
require_once $workerCoverageRoot . '/tests/Symfony/WorkerIoCoverage.php';
$workerCoverageWorker = (string) getenv('KADUPUL_WORKER_COVERAGE_WORKER');
Kadupul\Tests\WorkerIoCoverage::sources($workerCoverageWorker);
$workerCoverageStack = json_decode((string) getenv('KADUPUL_WORKER_COVERAGE_STACK'), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($workerCoverageStack)) {
    throw new RuntimeException('Invalid copied worker coverage stack.');
}
define('RRD_TEST_PARENT_COVERAGE_STACK', $workerCoverageStack);
define('RRD_TEST_COVERAGE_DIRECTORY', (string) getenv('KADUPUL_WORKER_COVERAGE_DIRECTORY'));
define('RRD_TEST_CLI_COVERAGE_COPY', RRD_TEST_COVERAGE_DIRECTORY . '/' . $workerCoverageWorker);
define('RRD_TEST_CLI_COVERAGE_SOURCE', $workerCoverageRoot . '/' . $workerCoverageWorker);
define('WORKER_IO_COPY_TEST_COVERAGE', $workerCoverageWorker);
require $workerCoverageRoot . '/tests/Fixtures/rrd-process-coverage.php';
$GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot(
    $workerCoverageRoot,
    Kadupul\Tests\WorkerIoCoverage::PRODUCER,
    (string) getenv('KADUPUL_WORKER_COVERAGE_SCENARIO'),
    Kadupul\Tests\WorkerIoCoverage::sources($workerCoverageWorker),
    Kadupul\Tests\NativeCoverageStack::capture($workerCoverageRoot)
);
register_shutdown_function(static function (): void {
    $GLOBALS['nativeChildCoverageMarkers'] = [Kadupul\Tests\WorkerIoCoverage::MARKER];
});
unset($workerCoverageRoot, $workerCoverageWorker, $workerCoverageStack);
