<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Tests\NativeCoverageStack;

$stackProbeRoot = dirname(__DIR__, 2);
require_once $stackProbeRoot . '/tests/Symfony/NativeCoverageStack.php';
$stackProbeDescription = json_decode((string) getenv('KADUPUL_NATIVE_COVERAGE_STACK'), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($stackProbeDescription)) throw new RuntimeException('Invalid coverage stack probe.');
define('RRD_TEST_PARENT_COVERAGE_STACK', $stackProbeDescription);
define('RRD_TEST_COVERAGE_DIRECTORY', (string) getenv('KADUPUL_NATIVE_COVERAGE_DIRECTORY'));
define('RRD_TEST_CLI_COVERAGE_COPY', (string) getenv('KADUPUL_NATIVE_COVERAGE_COPY'));
define('RRD_TEST_CLI_COVERAGE_SOURCE', $stackProbeRoot . '/tests/Fixtures/native-coverage-stack-target.php');
// This old flag defaults to the application vendor. The explicit stack must
// take precedence when a no-dev installation has only tests/' coverage library.
define('CONSOLE_HANDOFF_TEST_COVERAGE', 'tests/Fixtures/native-coverage-stack-target.php');
require $stackProbeRoot . '/tests/Fixtures/rrd-process-coverage.php';
require_once $stackProbeRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
$GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot($stackProbeRoot,
    'tests/Fixtures/native-coverage-stack-prepend.php', (string) getenv('KADUPUL_NATIVE_COVERAGE_SCENARIO'), [
        'composer.lock', 'tests/composer.lock', 'tests/Symfony/NativeCoverageStack.php', 'tests/Symfony/NativeCoverageStackTest.php',
        'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Fixtures/native-coverage-stack-target.php',
    ], NativeCoverageStack::capture($stackProbeRoot));
register_shutdown_function(static function (): void {
    $GLOBALS['nativeChildCoverageMarkers'] = defined('NATIVE_COVERAGE_STACK_TARGET_DONE') ? ['native-parent-stack-probe-terminated'] : [];
});
unset($stackProbeRoot, $stackProbeDescription);
