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

if (!in_array($argc, [3, 4], true) || !in_array($argv[3] ?? 'renamed', ['named', 'renamed'], true)) {
    throw new RuntimeException('Usage: coverage_source_map_fixture.php UNIT.php OUTPUT.php [named|renamed]');
}

$root = dirname(__DIR__, 3);
require $root . '/tests/vendor/autoload.php';

$coverage = require $argv[1];
if (!$coverage instanceof CodeCoverage) {
    throw new RuntimeException('Invalid unit coverage artifact');
}

$source = $root . '/lib/functions.php';
$measured = $coverage->getData(true)->lineCoverage()[$source] ?? [];
if (!array_filter($measured, static fn($tests) => is_array($tests) && $tests !== [])) {
    throw new RuntimeException('Actual measured helper coverage is required for the source-map fixture');
}
$temporaryRoot = rtrim(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(), DIRECTORY_SEPARATOR);
$named = ($argv[3] ?? 'renamed') === 'named';
$copy = $temporaryRoot . '/coverage-source-map-' . bin2hex(random_bytes(8))
    . ($named ? '/lib/functions.php' : '/renamed-native.php');
$directory = dirname($copy);
if (!mkdir($directory, 0700, true) || !copy($source, $copy)) {
    throw new RuntimeException('Unable to create the temporary coverage source');
}

// The self-test owns this fixture copy only. Do not inherit temporary copies
// referenced by the real unit artifact, which the later CI merge still needs.
$fixtureFilter = new Filter();
$fixtureFilter->includeFile($source);
$fixtureFilter->includeFile($copy);
$fixtureData = new ProcessedCodeCoverageData();
$fixtureData->setLineCoverage([$source => $measured, $copy => $measured]);
$fixtureCoverage = new CodeCoverage((new Selector())->forLineCoverage($fixtureFilter), $fixtureFilter);
$fixtureCoverage->setData($fixtureData);
$fixtureCoverage->setTests($coverage->getTests());
$coverage = $fixtureCoverage;
$serializedCoverage = base64_encode(serialize($coverage));
$coverageArtifact = '<?php return unserialize(base64_decode(' . var_export($serializedCoverage, true) . '));';
if (file_put_contents($argv[2], $coverageArtifact) === false) {
    throw new RuntimeException('Unable to write unit coverage fixture');
}

$manifest = $temporaryRoot . '/kadupul-coverage-source-map-' . bin2hex(random_bytes(8)) . '.json';
$data = json_encode(array('copy' => $copy, 'source' => $source, 'sha256' => hash_file('sha256', $source)), JSON_THROW_ON_ERROR);
if (file_put_contents($manifest, $data, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write coverage source mapping');
}

unlink($copy);
rmdir($directory);
if ($named) {
    rmdir(dirname($directory));
}
echo json_encode(array('copy' => $copy, 'manifest' => $manifest), JSON_THROW_ON_ERROR), PHP_EOL;
