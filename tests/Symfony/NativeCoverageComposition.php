<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/NativeCoverageStack.php';

/** Compose measured line data before a single library expands uncovered files. */
final class NativeCoverageComposition
{
    /** @return array<string,mixed> */
    public static function export(CodeCoverage $coverage, string $root, string $part): array
    {
        $data = $coverage->getData(true);
        if ($data->functionCoverage() !== []) throw new \RuntimeException('Native composition does not support branch coverage.');
        $lines = [];
        $sources = [];
        $filter = [];
        foreach ($coverage->filter()->files() as $file) {
            $relative = self::relative($root, $file);
            $filter[] = $relative;
            $sources[$relative] = self::digest($file);
        }
        foreach ($data->lineCoverage() as $file => $measured) {
            $relative = self::relative($root, $file);
            $lines[$relative] = $measured;
            $sources[$relative] = self::digest($file);
        }
        return ['version' => 1, 'part' => $part, 'source' => self::revision($root), 'tools' => self::tools($root),
            'stack' => NativeCoverageStack::capture($root), 'locks' => self::locks($root),
            'filter' => $filter, 'sources' => $sources, 'lines' => $lines,
            'functions' => [], 'tests' => $coverage->getTests()];
    }

    public static function write(CodeCoverage $coverage, string $root, string $part, string $output): void
    {
        $json = json_encode(self::export($coverage, $root, $part), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (file_put_contents($output, $json) !== strlen($json)) throw new \RuntimeException('Cannot publish native coverage sidecar.');
    }

    /** @param array<string,array<string,mixed>> $parts */
    public static function compose(string $root, array $parts): CodeCoverage
    {
        if (array_keys($parts) !== ['legacy', 'application']) throw new \RuntimeException('Both native coverage producers are required.');
        $active = NativeCoverageStack::capture($root);
        if ($active['stack'] !== 'tests' || !str_starts_with($active['version'], '12.')) throw new \RuntimeException('Composition requires the locked tests coverage library.');
        $filter = new Filter();
        $data = new ProcessedCodeCoverageData();
        $tests = [];
        foreach ($parts as $part => $raw) {
            if (($raw['version'] ?? null) !== 1 || ($raw['part'] ?? null) !== $part
                || ($raw['source'] ?? null) !== self::revision($root) || ($raw['locks'] ?? null) !== self::locks($root)
                || ($raw['tools'] ?? null) !== self::tools($root)) {
                throw new \RuntimeException('Native coverage provenance is missing or stale.');
            }
            $expectedStack = $part === 'legacy' ? 'tests' : 'application';
            if (($raw['stack'] ?? null) !== NativeCoverageStack::installed($root, $expectedStack)) throw new \RuntimeException('Native coverage producer library is missing or stale.');
            if (($raw['functions'] ?? null) !== []) throw new \RuntimeException('Native composition does not support branch coverage.');
            foreach (['filter', 'sources', 'lines', 'tests'] as $key) {
                if (!isset($raw[$key]) || !is_array($raw[$key]) || $raw[$key] === []) throw new \RuntimeException('Native coverage contains an incomplete producer.');
            }
            if (!array_is_list($raw['filter'])) throw new \RuntimeException('Invalid native coverage filter.');
            foreach ($raw['filter'] as $relative) {
                if (!is_string($relative) || $relative === '') throw new \RuntimeException('Invalid native coverage filter.');
            }
            if (count(array_unique($raw['filter'])) !== count($raw['filter'])) throw new \RuntimeException('Invalid native coverage filter.');
            $expectedSources = array_fill_keys(array_merge($raw['filter'], array_keys($raw['lines'])), true);
            if (array_diff_key($expectedSources, $raw['sources']) !== [] || array_diff_key($raw['sources'], $expectedSources) !== []) throw new \RuntimeException('Native coverage source inventory is incomplete.');
            foreach ($raw['sources'] as $relative => $hash) {
                if (!is_string($relative) || self::relative($root, $root . '/' . $relative) !== $relative
                    || !is_string($hash) || self::digest($root . '/' . $relative) !== $hash) throw new \RuntimeException('Native coverage source is missing or stale.');
            }
            foreach ($raw['tests'] as $id => $metadata) {
                // Coverage10 records size/status; coverage12 additionally records
                // time. Preserve both original shapes without inventing values.
                if ((!is_string($id) && !is_int($id)) || $id === '' || !is_array($metadata) || !is_string($metadata['size'] ?? null)
                    || !is_string($metadata['status'] ?? null) || array_diff_key($metadata, ['size' => true, 'status' => true, 'time' => true]) !== []) throw new \RuntimeException('Invalid native test identity.');
                if (array_key_exists('time', $metadata) && ((!is_int($metadata['time']) && !is_float($metadata['time']))
                    || !is_finite((float) $metadata['time']) || $metadata['time'] < 0)) throw new \RuntimeException('Invalid native test duration.');
                if (array_key_exists($id, $tests) && $tests[$id] !== $metadata) throw new \RuntimeException('Conflicting native test identity.');
                $tests[$id] = $metadata;
            }
            $incoming = [];
            $executed = 0;
            foreach ($raw['lines'] as $relative => $measured) {
                if (!is_array($measured)) throw new \RuntimeException('Invalid native measured source.');
                foreach ($measured as $line => $hits) {
                    if (!is_int($line) || $line < 1 || $hits !== null && (!is_array($hits) || !array_is_list($hits))) throw new \RuntimeException('Invalid native measured line.');
                    foreach ($hits ?? [] as $id) {
                        if (!is_string($id) || !array_key_exists($id, $raw['tests'])) throw new \RuntimeException('Native line has no originating test.');
                        ++$executed;
                    }
                }
                $incoming[$root . '/' . $relative] = $measured;
            }
            if ($executed === 0) throw new \RuntimeException('Native coverage producer contains no executed tests.');
            foreach ($raw['filter'] as $relative) $filter->includeFile($root . '/' . $relative);
            $processed = new ProcessedCodeCoverageData();
            $processed->setLineCoverage($incoming);
            $processed->setFunctionCoverage([]);
            $data->merge($processed);
        }
        $coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
        $coverage->setData($data);
        $coverage->setTests($tests);
        return $coverage;
    }

    private static function relative(string $root, string $file): string
    {
        $actual = realpath($file);
        if ($actual === false || !is_file($actual) || $actual !== $file || !str_starts_with($actual, $root . '/')) throw new \RuntimeException('Native coverage source is outside its canonical checkout.');
        return substr($actual, strlen($root) + 1);
    }

    private static function digest(string $file): string
    {
        $hash = hash_file('sha256', $file);
        if ($hash === false) throw new \RuntimeException('Cannot read native coverage source.');
        return $hash;
    }

    /** @return array<string,string> */
    private static function locks(string $root): array
    {
        return ['composer.lock' => self::digest($root . '/composer.lock'), 'tests/composer.lock' => self::digest($root . '/tests/composer.lock')];
    }

    /** @return array<string,string> */
    private static function tools(string $root): array
    {
        $hashes = [];
        foreach (['tests/Symfony/NativeCoverageComposition.php', 'tests/Symfony/NativeCoverageStack.php',
            'tests/Symfony/compose_native_coverage.php', 'tests/tools/sonar_coverage_artifacts.py',
            'tests/Symfony/merge_coverage.php', 'tests/Support/Behavior/merge_poller_coverage.php'] as $path) $hashes[$path] = self::digest($root . '/' . $path);
        return $hashes;
    }

    /** @return list<string> */
    private static function revision(string $root): array
    {
        $process = new Process(['git', '-C', $root, 'rev-parse', 'HEAD', 'HEAD^{tree}']);
        $process->mustRun();
        $revision = explode("\n", trim($process->getOutput()));
        if (count($revision) !== 2 || preg_match('/\A[0-9a-f]{40}\z/D', $revision[0]) !== 1 || preg_match('/\A[0-9a-f]{40}\z/D', $revision[1]) !== 1) throw new \RuntimeException('Cannot identify native coverage source revision.');
        return $revision;
    }
}
