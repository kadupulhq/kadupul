<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Tests\NativeCoverageComposition;
use SebastianBergmann\CodeCoverage\Report\Clover;

$root = dirname(__DIR__, 2);
require $root . '/tests/vendor/autoload.php';
require __DIR__ . '/NativeCoverageComposition.php';
if ($argc !== 4) throw new RuntimeException('Usage: compose_native_coverage.php LEGACY.native.json APPLICATION.native.json OUTPUT.xml');
$parts = [];
foreach (['legacy' => $argv[1], 'application' => $argv[2]] as $part => $path) {
    if (is_link($path) || !is_file($path)) throw new RuntimeException('A required native coverage sidecar is missing.');
    $json = file_get_contents($path);
    if ($json === false) throw new RuntimeException('Cannot read native coverage sidecar.');
    $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($raw)) throw new RuntimeException('Invalid native coverage sidecar.');
    $parts[$part] = $raw;
}
$coverage = NativeCoverageComposition::compose($root, $parts);
$temporary = tempnam(dirname($argv[3]), 'native-composed-');
if ($temporary === false) throw new RuntimeException('Cannot create composed native coverage report.');
try {
    (new Clover())->process($coverage, $temporary);
    if (!rename($temporary, $argv[3])) throw new RuntimeException('Cannot publish composed native coverage report.');
} finally {
    if (is_file($temporary)) unlink($temporary);
}
echo 'Both source-bound native line producers composed before uncovered-file expansion.', PHP_EOL;
