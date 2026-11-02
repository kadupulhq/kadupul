<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

use Kadupul\Tests\NativeSessionCoverageRegistration;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\PHP;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
require_once __DIR__ . '/NativeSessionCoverageRegistration.php';
if ($argc !== 5 || !in_array($argv[3], NativeSessionCoverageRegistration::MEASURED, true)
    || !in_array($argv[4], ['omitted', 'unexecuted'], true)) {
    throw new RuntimeException('Invalid native session coverage mutation.');
}
$coverage = require $argv[1];
if (!$coverage instanceof CodeCoverage) {
    throw new RuntimeException('Invalid native session unit coverage.');
}
$root = dirname(__DIR__, 2);
NativeSessionCoverageRegistration::requireMeasured($coverage->getData()->lineCoverage(), $root);
$lines = $coverage->getData()->lineCoverage();
$path = realpath($root . '/' . $argv[3]);
if ($argv[4] === 'omitted') {
    unset($lines[$path]);
} else {
    $lines[$path] = array_map(static fn(?array $tests): ?array => $tests === null ? null : [], $lines[$path]);
}
$coverage->getData()->setLineCoverage($lines);
(new PHP())->process($coverage, $argv[2]);
