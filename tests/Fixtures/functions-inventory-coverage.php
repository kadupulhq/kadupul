<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Tests\FunctionsInventoryCoverage;
use Kadupul\Tests\NativeCoverageStack;

$inventoryRoot = dirname(__DIR__, 2);
require_once $inventoryRoot . '/tests/Symfony/NativeCoverageStack.php';
$inventoryStack = json_decode((string) getenv('KADUPUL_INVENTORY_COVERAGE_STACK'), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($inventoryStack)) throw new RuntimeException('Invalid inventory coverage stack.');
require_once NativeCoverageStack::bootstrap($inventoryRoot, $inventoryStack) . '/autoload.php';
require_once $inventoryRoot . '/tests/Symfony/FunctionsInventoryCoverage.php';
FunctionsInventoryCoverage::verifyInvocation($inventoryRoot, $_SERVER['SCRIPT_FILENAME'] ?? null, $argv ?? null);
$inventoryScenario = (string) getenv('KADUPUL_INVENTORY_COVERAGE_SCENARIO');
FunctionsInventoryCoverage::verifyInputs($inventoryRoot, $inventoryScenario);
define('RRD_TEST_PARENT_COVERAGE_STACK', $inventoryStack);
define('RRD_TEST_COVERAGE_DIRECTORY', (string) getenv('KADUPUL_INVENTORY_COVERAGE_DIRECTORY'));
define('FUNCTIONS_INVENTORY_TEST_COVERAGE', true);
define('FUNCTIONS_INVENTORY_COVERAGE_SCENARIO', $inventoryScenario);
require $inventoryRoot . '/tests/Fixtures/rrd-process-coverage.php';
require_once $inventoryRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
$GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot(
    $inventoryRoot,
    FunctionsInventoryCoverage::PRODUCER,
    $inventoryScenario,
    FunctionsInventoryCoverage::SOURCES,
    $inventoryStack
);
register_shutdown_function(static function () use ($inventoryRoot, $inventoryScenario): void {
    FunctionsInventoryCoverage::verifyInputs($inventoryRoot, $inventoryScenario);
    $GLOBALS['nativeChildCoverageMarkers'] = [FunctionsInventoryCoverage::MARKER];
});
unset($inventoryRoot, $inventoryStack, $inventoryScenario);
