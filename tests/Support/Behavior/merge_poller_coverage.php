<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Report\Clover;

$root = dirname(__DIR__, 3);
require $root . '/tests/vendor/autoload.php';

if ($argc !== 4) {
    throw new RuntimeException('Usage: merge_poller_coverage.php UNIT.php INTEGRATION_DIR OUTPUT.xml');
}

// The serialized object is produced locally by PHPUnit in this same CI job.
$coverage = require $argv[1];
if (!$coverage instanceof CodeCoverage) {
    throw new RuntimeException('Invalid unit coverage artifact');
}
$manifest = json_decode(file_get_contents($argv[2] . '/observations.json'), true, 512, JSON_THROW_ON_ERROR);
$expected = ['database/fresh-schema', 'upgrade/install', 'poller/run-reachable', 'graphs/definition',
    'poller/rrd-failure', 'poller/device-unreachable', 'faults/missing-rrd-file', 'faults/database-unreachable'];
$actual = array_keys($manifest['scenarios'] ?? []);
sort($expected);
sort($actual);
if ($actual !== $expected) {
    throw new RuntimeException('Incomplete integration scenario inventory');
}
$reports = glob($argv[2] . '/raw/coverage-*.json');
if (!$reports) {
    throw new RuntimeException('Missing integration coverage reports');
}
$mapped = [];
foreach ($reports as $report) {
    $data = json_decode(file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data['files'] ?? null) || !is_string($data['php'] ?? null)) {
        throw new RuntimeException('Malformed integration coverage report');
    }
    foreach ($data['files'] as $path => $file) {
        if (!str_starts_with($path, '/var/www/html/')) {
            throw new RuntimeException('Unexpected coverage source path');
        }
        $relative = substr($path, strlen('/var/www/html/'));
        $local = $root . '/' . $relative;
        if ($relative === 'include/config.php' || realpath($local) !== $local || !is_file($local)) {
            throw new RuntimeException('Invalid coverage source: ' . $relative);
        }
        if (!is_array($file) || ($file['sha256'] ?? null) !== hash_file('sha256', $local)) {
            throw new RuntimeException('Covered source differs from checkout: ' . $relative);
        }
        if (!is_array($file['lines'] ?? null)) {
            throw new RuntimeException('Invalid line coverage inventory');
        }
        // PCOV can record the implicit return on the empty line after the final newline.
        $lineCount = substr_count(file_get_contents($local), "\n") + 1;
        foreach ($file['lines'] as $line => $hit) {
            if (!is_int($line) || $line < 1 || $line > $lineCount || !in_array($hit, [-1, 1], true)) {
                throw new RuntimeException('Invalid PCOV line observation');
            }
            $mapped[$local][$line] = max($mapped[$local][$line] ?? -1, $hit);
        }
    }
}
if (!in_array(1, $mapped[$root . '/poller.php'] ?? [], true)) {
    throw new RuntimeException('No real poller execution recorded');
}
foreach (array_keys($mapped) as $path) {
    $coverage->filter()->includeFile($path);
}
$coverage->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($mapped), 'poller integration');
// Use PHPUnit's writer to recompute all metrics from measured line data.
$temp = tempnam(dirname($argv[3]), 'poller-clover-');
try {
    (new Clover())->process($coverage, $temp);
    if (!rename($temp, $argv[3])) {
        throw new RuntimeException('Cannot publish combined coverage');
    }
} finally {
    if (is_file($temp)) {
        unlink($temp);
    }
}
echo 'Combined unit and poller integration coverage written', PHP_EOL;
