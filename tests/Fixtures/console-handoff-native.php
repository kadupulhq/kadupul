<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Prepended to a console worker child only while the parent PHPUnit run
// collects coverage. A byte-identical copy of the shared handoff runs beside a
// stand-in include/auth.php and its lines are mapped back to the real file;
// a worker run in place under another SAPI is measured directly.
$consoleHandoffRoot = dirname(__DIR__, 2);
define('RRD_TEST_COVERAGE_DIRECTORY', (string) getenv('KADUPUL_CONSOLE_HANDOFF_COVERAGE'));
define('CONSOLE_HANDOFF_TEST_COVERAGE', (string) getenv('KADUPUL_CONSOLE_HANDOFF_MEASURED'));
if (getenv('KADUPUL_CONSOLE_HANDOFF_COPY') !== false) {
    define('RRD_TEST_CLI_COVERAGE_COPY', getenv('KADUPUL_CONSOLE_HANDOFF_COPY') . '/' . CONSOLE_HANDOFF_TEST_COVERAGE);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $consoleHandoffRoot . '/' . CONSOLE_HANDOFF_TEST_COVERAGE);
}
require $consoleHandoffRoot . '/tests/Fixtures/rrd-process-coverage.php';
require_once $consoleHandoffRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
$GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot($consoleHandoffRoot, 'tests/Fixtures/console-handoff-native.php',
    (string) getenv('KADUPUL_CONSOLE_HANDOFF_SCENARIO'), [
        'composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Fixtures/console-handoff-bootstrap.php', 'tests/Symfony/ConsoleHandoffWorkerTest.php', 'lib/functions.php',
        'src/Platform/Infrastructure/Legacy/BrowserWorkerRealmDenied.php', CONSOLE_HANDOFF_TEST_COVERAGE,
    ]);
// Registered after the collector's own shutdown hook, which defers the write
// to a later hook, so the marker exists only once the worker has finished.
register_shutdown_function(static function (): void {
    $GLOBALS['nativeChildCoverageMarkers'] = ['console-handoff-worker-terminated'];
});
unset($consoleHandoffRoot);
