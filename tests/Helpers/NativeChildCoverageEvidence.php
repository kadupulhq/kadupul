<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Bind native child coverage to its producer, inputs, measured sources and completion. */
final class NativeChildCoverageEvidence
{
    public static function snapshot(string $root, string $producer, string $scenario, array $sources, ?array $coverageStack = null): array
    {
        $hashes = [];
        foreach (array_unique(array_merge([$producer], $sources)) as $path) {
            $hash = hash_file('sha256', $root . '/' . $path);
            if ($hash === false) {
                throw new RuntimeException('Cannot hash native coverage source: ' . $path);
            }
            $hashes[$path] = $hash;
        }
        $snapshot = ['producer' => $producer, 'scenario' => hash('sha256', $scenario), 'sources' => $hashes];
        if ($coverageStack !== null) {
            require_once $root . '/tests/Symfony/NativeCoverageStack.php';
            Kadupul\Tests\NativeCoverageStack::verify($root, $coverageStack);
            $snapshot['coverage_stack'] = $coverageStack;
        }
        return $snapshot;
    }

    public static function write(string $report, string $root, array $snapshot, array $markers): void
    {
        self::verifySources($root, $snapshot['sources'], array_keys($snapshot['sources']));
        $digest = hash_file('sha256', $report);
        if ($digest === false || filesize($report) === 0) {
            throw new RuntimeException('Native coverage report is missing or empty.');
        }
        $snapshot['report'] = $digest;
        $snapshot['markers'] = $markers;
        if (file_put_contents($report . '.json', json_encode($snapshot, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot write native coverage evidence.');
        }
    }

    public static function load(string $report, string $root, string $producer, string $scenario, array $sources, array $markers, array $hitSources, ?array $coverageStack = null): SebastianBergmann\CodeCoverage\CodeCoverage
    {
        if (!is_file($report) || !is_file($report . '.json') || filesize($report) === 0) {
            throw new RuntimeException('Native coverage report or evidence is missing.');
        }
        $evidence = json_decode(file_get_contents($report . '.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($evidence['producer'] ?? null) !== $producer || ($evidence['scenario'] ?? null) !== hash('sha256', $scenario)
            || ($evidence['report'] ?? null) !== hash_file('sha256', $report)) {
            throw new RuntimeException('Native coverage identity or report digest is stale.');
        }
        self::verifySources($root, $evidence['sources'] ?? [], array_merge([$producer], $sources, $hitSources));
        foreach ($markers as $marker) {
            if (!in_array($marker, $evidence['markers'] ?? [], true)) {
                throw new RuntimeException('Native coverage completion marker is missing: ' . $marker);
            }
        }
        if ($coverageStack === null && array_key_exists('coverage_stack', $evidence)) {
            require_once $root . '/tests/Symfony/NativeCoverageStack.php';
            $coverageStack = Kadupul\Tests\NativeCoverageStack::capture($root);
        }
        if ($coverageStack !== null) {
            if (($evidence['coverage_stack'] ?? null) !== $coverageStack) {
                throw new RuntimeException('Native coverage stack is missing or stale.');
            }
            require_once $root . '/tests/Symfony/NativeCoverageStack.php';
            Kadupul\Tests\NativeCoverageStack::verify($root, $coverageStack);
        }
        // Check the opted-in library identity before deserializing an object
        // whose class layout may differ between the installed test stacks.
        $coverage = unserialize(file_get_contents($report));
        if (!$coverage instanceof SebastianBergmann\CodeCoverage\CodeCoverage) {
            throw new RuntimeException('Native coverage report is invalid.');
        }
        // The final parent report adds uncovered lines from the merged filter.
        // Admission inspects physical child data without reparsing that filter
        // for every imported report or evidence rejection control.
        $data = $coverage->getData(true)->lineCoverage();
        $allowed = [];
        $canonicalRoot = realpath($root);
        foreach (array_merge([$producer], $sources, $hitSources) as $path) {
            $canonical = realpath($root . '/' . $path);
            if ($canonicalRoot === false || $canonical === false || !str_starts_with($canonical, $canonicalRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Native coverage source is outside the verified root.');
            }
            $allowed[$canonical] = true;
        }
        foreach (array_unique(array_merge(array_keys($data), $coverage->filter()->files())) as $file) {
            if (!isset($allowed[$file])) {
                throw new RuntimeException('Native coverage measured an unregistered source: ' . $file);
            }
        }
        if (!array_filter($data, static fn($lines) => array_filter($lines, static fn($hits) => is_array($hits) && $hits !== []))) {
            throw new RuntimeException('Native coverage discovered no measured source.');
        }
        foreach ($hitSources as $source) {
            $lines = $data[realpath($root . '/' . $source)] ?? [];
            if (!array_filter($lines, static fn($hits) => is_array($hits) && $hits !== [])) {
                throw new RuntimeException('Required native source was not executed: ' . $source);
            }
        }
        return $coverage;
    }

    /** Negative self-tests mutate only the owned report and always restore it. */
    public static function verifyRejections(string $report, string $root, string $producer, string $scenario, array $sources, array $markers, array $hitSources, string $unexecutedSource, ?array $coverageStack = null): int
    {
        $originalReport = file_get_contents($report);
        $originalEvidence = file_get_contents($report . '.json');
        $evidence = json_decode($originalEvidence, true, 512, JSON_THROW_ON_ERROR);
        $count = 0;
        $reject = static function (array $requiredHits, string $message) use ($report, $root, $producer, $scenario, $sources, $markers, $coverageStack, &$count): void {
            try {
                self::load($report, $root, $producer, $scenario, $sources, $markers, $requiredHits, $coverageStack);
            } catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(), $message)) {
                    throw $error;
                }
                $count++;
                return;
            }
            throw new RuntimeException('Incomplete native coverage evidence was accepted.');
        };
        try {
            if ($coverageStack !== null) {
                foreach (['missing', 'stack', 'version', 'class_sha256', 'autoload'] as $field) {
                    $changed = $evidence;
                    if ($field === 'missing') unset($changed['coverage_stack']);
                    else $changed['coverage_stack'][$field] = 'foreign';
                    file_put_contents($report . '.json', json_encode($changed, JSON_THROW_ON_ERROR));
                    $reject($hitSources, 'coverage stack');
                }
            }
            foreach (['producer', 'scenario', 'report', 'markers', ...$sources, $producer] as $missing) {
                $changed = $evidence;
                if (in_array($missing, array_merge($sources, [$producer]), true)) {
                    unset($changed['sources'][$missing]);
                } else {
                    unset($changed[$missing]);
                }
                file_put_contents($report . '.json', json_encode($changed, JSON_THROW_ON_ERROR));
                $reject($hitSources, 'Native coverage');
            }
            foreach ($markers as $marker) {
                $changed = $evidence;
                $changed['markers'] = array_values(array_diff($changed['markers'], [$marker]));
                file_put_contents($report . '.json', json_encode($changed, JSON_THROW_ON_ERROR));
                $reject($hitSources, 'completion marker is missing');
            }
            file_put_contents($report . '.json', $originalEvidence);
            // Required-source rejection must not depend on which library
            // declaration lines the coverage driver marks during bootstrap.
            $withoutRequiredSource = unserialize($originalReport);
            $withoutRequiredData = $withoutRequiredSource->getData(true);
            $withoutRequiredLines = $withoutRequiredData->lineCoverage();
            unset($withoutRequiredLines[realpath($root . '/' . $unexecutedSource)]);
            $withoutRequiredData->setLineCoverage($withoutRequiredLines);
            $withoutRequiredSource->setData($withoutRequiredData);
            file_put_contents($report, serialize($withoutRequiredSource));
            $changed = $evidence;
            $changed['report'] = hash_file('sha256', $report);
            file_put_contents($report . '.json', json_encode($changed, JSON_THROW_ON_ERROR));
            // A producer with one measured file becomes completely empty;
            // admission rejects it before reaching the per-source check.
            $remainingHits = array_filter($withoutRequiredLines, static fn($lines) => array_filter($lines, static fn($hits) => is_array($hits) && $hits !== []));
            $reject([$unexecutedSource], $remainingHits ? 'was not executed' : 'discovered no measured source');
            file_put_contents($report, $originalReport);
            file_put_contents($report . '.json', $originalEvidence);
            $reject(['index.php'], 'source is missing or stale');
            $injected = unserialize($originalReport);
            $injectedData = $injected->getData(true);
            $lines = $injectedData->lineCoverage();
            $lines[realpath($root . '/index.php')] = [1 => ['negative unregistered source probe']];
            $injectedData->setLineCoverage($lines);
            $injected->setData($injectedData);
            file_put_contents($report, serialize($injected));
            $changed = $evidence;
            $changed['report'] = hash_file('sha256', $report);
            file_put_contents($report . '.json', json_encode($changed, JSON_THROW_ON_ERROR));
            $reject($hitSources, 'unregistered source');
            file_put_contents($report, $originalReport);
            file_put_contents($report . '.json', $originalEvidence);
            $empty = unserialize($originalReport);
            $empty->clear();
            file_put_contents($report, serialize($empty));
            $changed = $evidence;
            $changed['report'] = hash_file('sha256', $report);
            file_put_contents($report . '.json', json_encode($changed, JSON_THROW_ON_ERROR));
            $reject($hitSources, 'discovered no measured source');
            file_put_contents($report, $originalReport);
            file_put_contents($report . '.json', $originalEvidence);
            unlink($report);
            $reject($hitSources, 'report or evidence is missing');
        } finally {
            file_put_contents($report, $originalReport);
            file_put_contents($report . '.json', $originalEvidence);
        }
        return $count;
    }

    private static function verifySources(string $root, array $hashes, array $required): void
    {
        foreach ($required as $path) {
            $actual = hash_file('sha256', $root . '/' . $path);
            if ($actual === false || ($hashes[$path] ?? null) !== $actual) {
                throw new RuntimeException('Native coverage source is missing or stale: ' . $path);
            }
        }
    }
}
