<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/NativeChildCoverageEvidence.php';

final class AuditClientNativeEvidence
{
    public const PRODUCER = 'tests/Fixtures/audit-client-coverage.php';
    public const SOURCES = [
        'tests/Helpers/AuditClientNativeEvidence.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Unit/Core/Cli/AuditDatabaseClientHandoffTest.php',
        'cli/audit_database.php', 'lib/database.php', 'composer.lock', 'tests/composer.lock',
    ];
    public const COPIES = ['cli/audit_database.php' => 'cli/audit_database.php'];
    public const HITS = ['cli/audit_database.php', self::PRODUCER];
    public const MARKERS = ['native-cli-completed'];

    public static function verifyCopies(string $root, string $directory): void
    {
        foreach (self::COPIES as $source => $file) {
            if (!is_file($root . '/' . $source) || !is_file($directory . '/' . $file)) {
                throw new RuntimeException('Measured audit client copy differs: ' . $file);
            }
            $original = hash_file('sha256', $root . '/' . $source);
            $copy = hash_file('sha256', $directory . '/' . $file);
            if ($original === false || $copy === false || !hash_equals($original, $copy)) {
                throw new RuntimeException('Measured audit client copy differs: ' . $file);
            }
        }
    }

    public static function start(string $root, string $directory, string $scenario): SebastianBergmann\CodeCoverage\CodeCoverage
    {
        self::verifyCopies($root, $directory);
        $filter = new SebastianBergmann\CodeCoverage\Filter();
        foreach (self::HITS as $file) {
            $filter->includeFile(($file === self::PRODUCER ? $root : $directory) . '/' . $file);
        }
        $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
            (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
            $filter
        );
        $coverage->start($scenario);
        return $coverage;
    }

    public static function finish(SebastianBergmann\CodeCoverage\CodeCoverage $coverage, string $root, string $directory, array $snapshot): void
    {
        $coverage->stop();
        self::verifyCopies($root, $directory);
        $filter = new SebastianBergmann\CodeCoverage\Filter();
        $data = $coverage->getData(true);
        foreach (self::HITS as $file) {
            if (isset(self::COPIES[$file])) {
                $data->renameFile(realpath($directory . '/' . $file), realpath($root . '/' . $file));
            }
            $filter->includeFile($root . '/' . $file);
        }
        // Rebuild the allowlist to prevent copied paths being rediscovered as uncovered.
        $canonical = new SebastianBergmann\CodeCoverage\CodeCoverage(
            (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
            $filter
        );
        $canonical->setData($data);
        $canonical->setTests($coverage->getTests());
        $report = $directory . '/audit-client.coverage';
        $bytes = serialize($canonical);
        if (file_put_contents($report, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot preserve audit client coverage');
        }
        NativeChildCoverageEvidence::write($report, $root, $snapshot, self::MARKERS);
    }
}
