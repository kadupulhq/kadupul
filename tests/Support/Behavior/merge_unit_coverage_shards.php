<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP;

$root = dirname(__DIR__, 3);
require $root . '/tests/vendor/autoload.php';

if ($argc < 4) {
    throw new RuntimeException('Usage: merge_unit_coverage_shards.php OUTPUT.php SHARD.php SHARD.php [...]');
}

// The merged object must look like one serial run, because
// merge_poller_coverage.php restores and remaps copied CLI sources through the
// filter. CodeCoverage::merge() re-adds filter entries through realpath(), which
// silently drops temporary copies that only existed on the shard's runner, so
// the filter lists are joined directly instead.
$files = new ReflectionProperty(Filter::class, 'files');
$merged = null;
$temporaryRoot = rtrim(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
foreach (array_slice($argv, 2) as $shard) {
    // Each serialized object was produced by PHPUnit in a shard job of this run.
    $coverage = require $shard;
    if (!$coverage instanceof CodeCoverage) {
        throw new RuntimeException('Invalid unit coverage shard: ' . $shard);
    }
    $measured = $coverage->getData(true)->lineCoverage();
    if ($measured === []) {
        throw new RuntimeException('Unit coverage shard recorded nothing: ' . $shard);
    }
    // Shards and this job must share one checkout path, or the merged report
    // would describe files that do not exist here.
    foreach (array_keys($measured) as $path) {
        if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR) && !str_starts_with($path, $temporaryRoot)) {
            throw new RuntimeException('Unit coverage shard measured a file outside this checkout: ' . $path);
        }
    }
    if ($merged === null) {
        $merged = $coverage;
        continue;
    }
    $merged->getData(true)->merge($coverage->getData(true));
    $files->setValue($merged->filter(), $files->getValue($merged->filter()) + $files->getValue($coverage->filter()));
    $merged->setTests(array_merge($merged->getTests(), $coverage->getTests()));
}

(new PHP())->process($merged, $argv[1]);
echo 'Merged ', $argc - 2, ' unit coverage shards into ', $argv[1], PHP_EOL;
