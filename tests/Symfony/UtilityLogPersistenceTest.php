<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class UtilityLogPersistenceTest extends TestCase
{
    /** @dataProvider retainedHistoryCases */
    public function testCleanupRetainsTheNewestLoginAndTokenForEachCurrentPrincipal(int $count, bool $current, string $identity = 'distinct'): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('A real MySQL or MariaDB connection is required.');
        }
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/utility-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/include', 0700);
        mkdir($directory . '/lib', 0700);
        $stubs = ['include/auth.php', 'lib/api_data_source.php', 'lib/boost.php', 'lib/rrd.php', 'lib/clog_webapi.php', 'lib/poller.php', 'lib/utility.php'];
        foreach ($stubs as $stub) {
            file_put_contents($directory . '/' . $stub, '<?php');
        }
        $coverage = \PHPUnit\Runner\CodeCoverage::instance()->isActive() ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage() : null;
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/utility-log-native.php', json_encode(['rows' => $count, 'current' => $current, 'identity' => $identity], JSON_THROW_ON_ERROR), $directory];
        if ($coverage !== null) {
            $command[] = 'coverage';
        }
        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            self::assertSame('', $stderr);
            $state = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            if ($current) {
                self::assertCount(4, $state['rows']);
                self::assertSame([1, 1, 2, 2], array_column($state['rows'], 'user_id'));
                self::assertSame([1, 2, 1, 2], array_column($state['rows'], 'result'));
                $names = $identity === 'same-name' ? ['shared principal', 'shared principal'] : ($identity === 'mismatched-pair' ? ['bob', 'sam'] : ["quote' principal", 'other principal']);
                self::assertSame([$names[0], $names[0], $names[1], $names[1]], array_column($state['rows'], 'username'));
                self::assertSame(array_fill(0, 4, sprintf('2026-09-%02d 12:00:00', $count)), array_column($state['rows'], 'time'));
            } else {
                self::assertSame([], $state['rows']);
            }
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Symfony/UtilityLogPersistenceTest.php', 'utilities.php', 'lib/html_utility.php', 'tests/Helpers/PhpSource.php');
                $scenario = json_encode(['rows' => $count, 'current' => $current, 'identity' => $identity], JSON_THROW_ON_ERROR);
                $markers = ['retained-history-readback'];
                $child = \NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/utility-log-native.php', $scenario, $sources, $markers, ['utilities.php']);
                if ($count === 1 && $current) {
                    self::assertSame(26, \NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/utility-log-native.php', $scenario, $sources, $markers, ['utilities.php'], 'lib/boost.php'));
                }
                $coverage->merge($child);
            }
        } finally {
            foreach (glob($directory . '/*.coverage*') as $report) {
                unlink($report);
            }
            foreach ($stubs as $stub) {
                unlink($directory . '/' . $stub);
            }
            rmdir($directory . '/include');
            rmdir($directory . '/lib');
            rmdir($directory);
        }
    }

    public static function retainedHistoryCases(): array
    {
        return ['single entries' => [1, true], 'multiple entries' => [4, true], 'no current accounts' => [4, false], 'same name in different realms' => [4, true, 'same-name'], 'mismatched current identity pair' => [4, true, 'mismatched-pair']];
    }
}
