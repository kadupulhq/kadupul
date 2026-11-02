<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

use Kadupul\Tests\NativeCoverageStack;
use Kadupul\Tests\NativeSessionCoverageRegistration;

$sessionCoverageRoot = dirname(__DIR__, 2);
$sessionCoverageFile = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
// cli-server exposes the fallback index in SCRIPT_FILENAME while executing its
// fixed router. The orchestrator pins that router; parent evidence also requires
// measured execution of the actual endpoint source, not just its pathname.
$sessionCoverageRouter = PHP_SAPI === 'cli-server' ? getenv('NATIVE_SESSION_COVERAGE_ROUTER') : false;
if ($sessionCoverageRouter !== false) {
    if (realpath($sessionCoverageRouter) !== realpath(__DIR__ . '/session-engine-endpoint.php')) throw new RuntimeException('Invalid native coverage router.');
    $sessionCoverageFile = 'session-engine-endpoint.php';
}
if ($sessionCoverageFile === 'session-engine-native.php') return;
require_once $sessionCoverageRoot . '/tests/Symfony/NativeCoverageStack.php';
require_once $sessionCoverageRoot . '/tests/Symfony/NativeSessionCoverageRegistration.php';
if (!in_array($sessionCoverageFile, NativeSessionCoverageRegistration::FIXTURES, true)
    || ($sessionCoverageRouter === false && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(__DIR__ . '/' . $sessionCoverageFile))) throw new RuntimeException('Invalid native session coverage source.');
$sessionCoverageStack = json_decode((string) getenv('NATIVE_SESSION_COVERAGE_STACK'), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($sessionCoverageStack)) throw new RuntimeException('Invalid native session coverage stack.');
$sessionCoverageDirectory = (string) getenv('NATIVE_SESSION_COVERAGE_DIRECTORY');
$sessionCoverageScenario = (string) getenv('NATIVE_SESSION_COVERAGE_SCENARIO');
if ($sessionCoverageFile === 'session-engine-endpoint.php') {
    $sessionCoverageMode = $_GET['mode'] ?? null;
    $sessionCoverageAction = $_GET['action'] ?? null;
    if (!in_array($sessionCoverageMode, ['php', 'php_binary', 'php_serialize'], true)
        || !in_array($sessionCoverageAction, ['login', 'older', 'read', 'logout', 'destroy'], true)) throw new RuntimeException('Invalid native coverage request.');
    $sessionCoverageScenario .= ':' . $sessionCoverageMode . ':' . $sessionCoverageAction;
    $sessionCoverageDirectory .= '/request-' . bin2hex(random_bytes(12));
    if (!mkdir($sessionCoverageDirectory, 0700)) throw new RuntimeException('Unable to preserve native request coverage.');
}
define('RRD_TEST_PARENT_COVERAGE_STACK', $sessionCoverageStack);
define('RRD_TEST_COVERAGE_DIRECTORY', $sessionCoverageDirectory);
define('NATIVE_SESSION_PERSISTENCE_TEST_COVERAGE', 'tests/Fixtures/' . $sessionCoverageFile);
require $sessionCoverageRoot . '/tests/Fixtures/rrd-process-coverage.php';
require_once $sessionCoverageRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
$GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot($sessionCoverageRoot,
    NativeSessionCoverageRegistration::PRODUCER, $sessionCoverageScenario, NativeSessionCoverageRegistration::SOURCES,
    NativeCoverageStack::capture($sessionCoverageRoot));
register_shutdown_function(static function (): void {
    $GLOBALS['nativeChildCoverageMarkers'] = defined('NATIVE_SESSION_SCENARIO_COMPLETED') ? NativeSessionCoverageRegistration::MARKERS : [];
});
unset($sessionCoverageRouter, $sessionCoverageRoot, $sessionCoverageFile, $sessionCoverageStack, $sessionCoverageDirectory, $sessionCoverageScenario, $sessionCoverageMode, $sessionCoverageAction);
