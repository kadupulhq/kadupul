<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/NativeChildCoverageEvidence.php';

final class IncludeNotificationNativeEvidence
{
    public const PRODUCER = 'tests/Fixtures/include-notification-native.php';
    public const SCENARIO = 'missing-include-first-repeat-expired';
    public const SOURCES = [
        'tests/Helpers/IncludeNotificationNativeEvidence.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Unit/Core/Helpers/IncludeNotificationNativeTest.php',
        'tests/Fixtures/include-notification/config/bootstrap.php', 'composer.lock', 'tests/composer.lock',
        'lib/functions.php', 'lib/path_helpers.php', 'lib/graph_fonts.php', 'lib/poller.php',
        'include/admin_notifications.php', 'include/global_constants.php',
        'src/Platform/Infrastructure/Legacy/LegacyIncludePathResolver.php',
    ];
    public const COPIES = [
        'lib/functions.php' => 'lib/functions.php', 'lib/path_helpers.php' => 'lib/path_helpers.php',
        'lib/graph_fonts.php' => 'lib/graph_fonts.php', 'lib/poller.php' => 'lib/poller.php',
        'include/admin_notifications.php' => 'include/admin_notifications.php',
        'tests/Fixtures/include-notification/config/bootstrap.php' => 'config/bootstrap.php',
    ];
    public const HITS = ['lib/functions.php', 'include/admin_notifications.php'];
    public const MARKERS = ['first-notification-and-log', 'repeat-debounced', 'expired-notification-and-log'];

    public static function verifyCopies(string $root, string $directory): void
    {
        foreach (self::COPIES as $source => $file) {
            if (!is_file($root . '/' . $source) || !is_file($directory . '/' . $file)) {
                throw new RuntimeException('Measured notification copy differs: ' . $file);
            }
            $original = hash_file('sha256', $root . '/' . $source);
            $copy = hash_file('sha256', $directory . '/' . $file);
            if ($original === false || $copy === false || !hash_equals($original, $copy)) {
                throw new RuntimeException('Measured notification copy differs: ' . $file);
            }
        }
    }

    public static function start(string $root, string $directory): SebastianBergmann\CodeCoverage\CodeCoverage
    {
        self::verifyCopies($root, $directory);
        $filter = new SebastianBergmann\CodeCoverage\Filter();
        foreach (self::HITS as $file) {
            $filter->includeFile($directory . '/' . $file);
        }
        $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
            (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
            $filter
        );
        $coverage->start(self::SCENARIO);
        return $coverage;
    }

    public static function finish(SebastianBergmann\CodeCoverage\CodeCoverage $coverage, string $root, string $directory, array $snapshot): void
    {
        $coverage->stop();
        self::verifyCopies($root, $directory);
        $filter = new SebastianBergmann\CodeCoverage\Filter();
        $data = $coverage->getData(true);
        foreach (self::HITS as $file) {
            $data->renameFile(realpath($directory . '/' . $file), realpath($root . '/' . $file));
            $filter->includeFile($root . '/' . $file);
        }
        // Rebuild the allowlist to prevent copied paths being rediscovered as uncovered.
        $canonical = new SebastianBergmann\CodeCoverage\CodeCoverage(
            (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
            $filter
        );
        $canonical->setData($data);
        $canonical->setTests($coverage->getTests());
        $report = $directory . '/notification.coverage';
        $bytes = serialize($canonical);
        if (file_put_contents($report, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot preserve notification coverage');
        }
        NativeChildCoverageEvidence::write($report, $root, $snapshot, self::MARKERS);
    }
}
