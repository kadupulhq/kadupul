<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
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

// PHPUnit 12 retains copied CLI files in the coverage filter even after the
// child fixture remaps measured data to the checked-in source path. Native
// tests remove their scratch directories before this merger runs, so restore
// only copies referenced by this artifact, using an exact hash-bound manifest
// for renamed entrypoints or an unambiguous source suffix for older artifacts.
$temporaryRoot = rtrim(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$sourceFiles = array_keys($coverage->getData(true)->lineCoverage());
$filteredSources = array_fill_keys($coverage->filter()->files(), true);
$temporaryCoverageSources = array();
$sourceMapManifests = array();
foreach (glob($temporaryRoot . 'kadupul-coverage-source-map-*.json') ?: array() as $manifest) {
    $sourceMap = json_decode(file_get_contents($manifest), true);
    // A different coverage job may retain manifests in the same temp root.
    // Consume only copies actually referenced by this unit coverage artifact.
    if (!is_array($sourceMap) || !is_string($sourceMap['copy'] ?? null)
        || !isset($filteredSources[$sourceMap['copy']])) {
        continue;
    }
    if (!is_array($sourceMap)
        || !is_string($sourceMap['copy'] ?? null)
        || !is_string($sourceMap['source'] ?? null)
        || !is_string($sourceMap['sha256'] ?? null)
        || !str_starts_with($sourceMap['copy'], $temporaryRoot)
        || !str_starts_with($sourceMap['source'], $root . DIRECTORY_SEPARATOR)
        || realpath($sourceMap['source']) !== $sourceMap['source']
        || !is_file($sourceMap['source'])) {
        throw new RuntimeException('Invalid associated coverage source mapping');
    }

    $relativeCopy = substr($sourceMap['copy'], strlen($temporaryRoot));
    $sourceHash = hash_file('sha256', $sourceMap['source']);
    $copySegments = explode(DIRECTORY_SEPARATOR, $relativeCopy);
    if (in_array('..', $copySegments, true)
        || in_array('.', $copySegments, true)
        || in_array('', $copySegments, true)
        || !is_string($sourceHash)
        || !hash_equals($sourceMap['sha256'], $sourceHash)) {
        throw new RuntimeException('Invalid associated coverage source mapping');
    }

    $component = rtrim($temporaryRoot, DIRECTORY_SEPARATOR);
    foreach ($copySegments as $segment) {
        $component .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($component)) {
            throw new RuntimeException('Linked associated coverage source mapping');
        }
    }
    if (file_exists($sourceMap['copy']) && (!is_file($sourceMap['copy'])
        || hash_file('sha256', $sourceMap['copy']) !== $sourceHash)) {
        throw new RuntimeException('Retained associated coverage source differs');
    }

    $temporaryCoverageSources[$sourceMap['copy']] = $sourceMap['source'];
    $sourceMapManifests[] = $manifest;
}

// Restore manifest-backed paths before consulting the filter. The path is
// already present in the filter, so the fallback loop below skips it; without
// this eager restoration that skip leaves PHPUnit's analyser opening a file
// that the native test fixture has already deleted.
foreach ($temporaryCoverageSources as $path => $source) {
    if (is_file($path)) {
        continue;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to restore temporary coverage source directory');
    }
    if (!copy($source, $path)) {
        throw new RuntimeException('Unable to restore temporary coverage source');
    }
}

foreach ($coverage->filter()->files() as $path) {
    if (!str_starts_with($path, $temporaryRoot) || isset($temporaryCoverageSources[$path])) {
        continue;
    }

    $matches = array();
    foreach ($sourceFiles as $source) {
        if (!str_starts_with($source, $root . DIRECTORY_SEPARATOR) || !is_file($source)) {
            continue;
        }

        $relative = substr($source, strlen($root) + 1);
        if (str_ends_with($path, DIRECTORY_SEPARATOR . $relative)) {
            $matches[] = $source;
        }
    }

    if (count($matches) !== 1) {
        if (!is_file($path)) {
            throw new RuntimeException('Unmapped temporary coverage source');
        }
        continue;
    }

    if (!is_file($path)) {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to restore temporary coverage source directory');
        }
        if (!copy($matches[0], $path)) {
            throw new RuntimeException('Unable to restore temporary coverage source');
        }
    }

    $temporaryCoverageSources[$path] = $matches[0];
}

// Retain measured test identities while replacing copied paths in both data
// and the allowlist. A temporary entry must never inflate Clover metrics or
// reappear as uncovered after its fixture has removed it.
$canonicalData = clone $coverage->getData(true);
$lineCoverage = $canonicalData->lineCoverage();
$functionCoverage = $canonicalData->functionCoverage();
foreach ($temporaryCoverageSources as $path => $source) {
    $copiedData = new ProcessedCodeCoverageData();
    $copiedData->setLineCoverage(isset($lineCoverage[$path]) ? [$source => $lineCoverage[$path]] : []);
    $copiedData->setFunctionCoverage(isset($functionCoverage[$path]) ? [$source => $functionCoverage[$path]] : []);
    $canonicalData->merge($copiedData);
}
$lineCoverage = $canonicalData->lineCoverage();
$functionCoverage = $canonicalData->functionCoverage();
foreach ($temporaryCoverageSources as $path => $source) {
    unset($lineCoverage[$path], $functionCoverage[$path]);
}
$canonicalData->setLineCoverage($lineCoverage);
$canonicalData->setFunctionCoverage($functionCoverage);
$canonicalFilter = new Filter();
foreach ($coverage->filter()->files() as $path) {
    $canonicalFilter->includeFile($temporaryCoverageSources[$path] ?? $path);
}
$canonicalCoverage = new CodeCoverage((new Selector())->forLineCoverage($canonicalFilter), $canonicalFilter);
$canonicalCoverage->setData($canonicalData);
$canonicalCoverage->setTests($coverage->getTests());
$coverage = $canonicalCoverage;

$rawCoverageClass = class_exists(\SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::class)
    ? \SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::class
    : \SebastianBergmann\CodeCoverage\RawCodeCoverageData::class;
$coverage->append($rawCoverageClass::fromXdebugWithoutPathCoverage($mapped), 'poller integration');
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

// Remove retained or restored scratch sources after append() and Clover
// analysis have completed. Remove only empty directories under the temp root.
foreach ($temporaryCoverageSources as $path => $source) {
    if (is_file($path)) {
        unlink($path);
    }

    $directory = dirname($path);
    while ($directory !== rtrim($temporaryRoot, DIRECTORY_SEPARATOR)
        && str_starts_with($directory, $temporaryRoot)
        && @rmdir($directory)) {
        $directory = dirname($directory);
    }
}
foreach ($sourceMapManifests as $manifest) {
    unlink($manifest);
}

echo 'Combined unit and poller integration coverage written', PHP_EOL;
