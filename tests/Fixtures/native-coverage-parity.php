<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Filter;

$root = dirname(__DIR__, 2);
require $root . '/' . $argv[1] . '/vendor/autoload.php';
require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
$producer = 'tests/Fixtures/native-coverage-parity.php';
$sources = ['src/Alerting/Domain/AdministratorRecipient.php', 'lib/auth.php', 'lib/rrd.php'];
$filter = new Filter();
foreach ($sources as $source) {
    $filter->includeFile($root . '/' . $source);
}
// Bare Unit runs still exercise serialized-library admission. When a native
// driver is installed, use it without substituting recorded hits on failure.
$native = extension_loaded('pcov') || extension_loaded('xdebug');
final class NativeCoverageParityRecordedDriver extends Driver
{
    public function nameAndVersion(): string
    {
        return 'Recorded library-parity data';
    }

    public function start(): void {}

    public function stop(): RawCodeCoverageData
    {
        $constructor = new ReflectionMethod(Kadupul\Alerting\Domain\AdministratorRecipient::class, '__construct');
        return RawCodeCoverageData::fromXdebugWithoutPathCoverage([
            $constructor->getFileName() => [$constructor->getStartLine() => self::LINE_EXECUTED],
        ]);
    }
}
$driver = $native ? (new Selector())->forLineCoverage($filter) : new NativeCoverageParityRecordedDriver();
$coverage = new CodeCoverage($driver, $filter);
if ($argv[2] === 'excluded') {
    $coverage->excludeUncoveredFiles();
}
$coverage->start('actual administrator recipient construction');
require $root . '/src/Alerting/Domain/AdministratorRecipient.php';
$result = new Kadupul\Alerting\Domain\AdministratorRecipient('operator@example.test', 'Operator');
$coverage->stop();
if ($result->email !== 'operator@example.test' || $result->name !== 'Operator') {
    throw new RuntimeException('Production recipient construction failed.');
}
$directory = sys_get_temp_dir() . '/native-coverage-parity-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create owned evidence directory.');
}
$report = $directory . '/child.coverage';
$serialized = serialize($coverage);
try {
    if (file_put_contents($report, $serialized) !== strlen($serialized)) {
        throw new RuntimeException('Cannot write complete native report.');
    }
    $scenario = $argv[1] . ':' . $argv[2];
    $markers = ['recipient-construction-complete'];
    $hits = [$sources[0]];
    NativeChildCoverageEvidence::write($report, $root, NativeChildCoverageEvidence::snapshot($root, $producer, $scenario, $sources), $markers);
    $evidence = file_get_contents($report . '.json');
    $reference = unserialize($serialized);
    $reference->getData(); // Independent original admission API.
    $admitted = NativeChildCoverageEvidence::load($report, $root, $producer, $scenario, $sources, $markers, $hits);
    $rawFiles = count($admitted->getData(true)->lineCoverage());
    $parents = [];
    foreach ([$reference, $admitted] as $child) {
        $parentFilter = new Filter();
        $parent = new CodeCoverage($driver, $parentFilter);
        $parent->merge($child);
        $parents[] = ['filter' => $parent->filter()->files(), 'lines' => $parent->getData()->lineCoverage()];
    }
    $controls = NativeChildCoverageEvidence::verifyRejections($report, $root, $producer, $scenario, $sources, $markers, $hits, 'lib/rrd.php');
    $restored = file_get_contents($report) === $serialized && file_get_contents($report . '.json') === $evidence;
    $extra = unserialize($serialized);
    $extra->filter()->includeFile($root . '/index.php');
    $bytes = serialize($extra);
    if (file_put_contents($report, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Cannot write filtered-source control.');
    }
    NativeChildCoverageEvidence::write($report, $root, NativeChildCoverageEvidence::snapshot($root, $producer, $scenario, $sources), $markers);
    $filteredRejected = false;
    try {
        NativeChildCoverageEvidence::load($report, $root, $producer, $scenario, $sources, $markers, $hits);
    } catch (RuntimeException $error) {
        $filteredRejected = str_contains($error->getMessage(), 'unregistered source');
    }
    $output = json_encode(['driver' => $native ? 'native' : 'recorded-library-data', 'version' => Composer\InstalledVersions::getPrettyVersion('phpunit/php-code-coverage'), 'parity' => $parents[0] === $parents[1],
        'rawFiles' => $rawFiles, 'finalFiles' => count($parents[1]['lines']), 'filterFiles' => count($parents[1]['filter']),
        'uncovered' => !array_filter($parents[1]['lines'][$root . '/lib/rrd.php'], static fn($value) => is_array($value) && $value !== []),
        'controls' => $controls, 'restored' => $restored, 'filteredRejected' => $filteredRejected], JSON_THROW_ON_ERROR);
    if (fwrite(STDOUT, $output) !== strlen($output)) {
        throw new RuntimeException('Cannot write complete parity result.');
    }
} finally {
    foreach ([$report, $report . '.json'] as $file) {
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException('Cannot clean owned evidence file.');
        }
    }
    if (!rmdir($directory)) {
        throw new RuntimeException('Cannot clean owned evidence directory.');
    }
}
