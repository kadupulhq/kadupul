<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__, 2);
$directory = getenv('AUDIT_CLIENT_COVERAGE_DIRECTORY');
$scenario = getenv('AUDIT_CLIENT_COVERAGE_SCENARIO');
if (!is_string($directory) || $directory === '' || !is_string($scenario) || $scenario === '') {
    throw new RuntimeException('Missing native audit client coverage identity');
}
require $root . '/tests/vendor/autoload.php';
$testLoader = Composer\Autoload\ClassLoader::getRegisteredLoaders()[$root . '/tests/vendor'];
class_exists(SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::class);
require $root . '/tests/Helpers/AuditClientNativeEvidence.php';
$snapshot = NativeChildCoverageEvidence::snapshot($root, AuditClientNativeEvidence::PRODUCER, $scenario, AuditClientNativeEvidence::SOURCES);
$nativeCoverage = AuditClientNativeEvidence::start($root, $directory, $scenario);
register_shutdown_function(static function () use ($nativeCoverage, $root, $directory, $snapshot, $testLoader): void {
    $testLoader->unregister();
    $testLoader->register(true);
    AuditClientNativeEvidence::finish($nativeCoverage, $root, $directory, $snapshot);
});
