<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$applicationLoader->unregister();
$applicationLoader->register(false);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeviceQueryConnectionDatabaseTest extends TestCase
{
    public function testLegacyRefreshFailureRemainsNonThrowingWithTheStrictFlagAbsentOrFalse(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        foreach (['add', 'change'] as $operation) {
            foreach (['absent', 'false-flag'] as $flag) {
                $process = new Process([PHP_BINARY, __DIR__ . '/../Fixtures/query-association-connection-native.php', $operation, 'local', 'commit', 'failed-refresh', $flag], dirname(__DIR__, 2));
                $process->mustRun();
                self::assertSame('', $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
                self::assertSame([[7, 9]], $result['reindexes']);
                self::assertSame(2, $result['rows'][0]['mapping']);
            }
        }
    }

    public function testLegacyQueryApisReuseTheWorkerConnectionAndPreserveItsTransaction(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        foreach (['add', 'change', 'remove'] as $operation) {
            foreach (['local', 'remote'] as $location) {
                foreach (['commit', 'rollback'] as $outcome) {
                    $process = new Process([PHP_BINARY, __DIR__ . '/../Fixtures/query-association-connection-native.php', $operation, $location, $outcome], dirname(__DIR__, 2));
                    $process->mustRun();
                    self::assertSame('', $process->getErrorOutput());
                    $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
                    self::assertTrue($result['same_connection']);
                    self::assertSame([true, true], $result['transactions']);
                    self::assertSame($result['expected_connections'], $result['connections']);
                    self::assertSame($operation === 'remove' ? [] : [[7, 9]], $result['reindexes']);
                    foreach ($result['rows'] as $index => $row) {
                        $changed = $outcome === 'commit' && ($index === 0 || $location === 'remote');
                        self::assertSame($changed ? ($operation === 'remove' ? false : 2) : 1, $row['mapping']);
                        self::assertSame($changed && $operation === 'remove' ? 0 : 1, $row['cache']);
                        self::assertSame($changed && $operation !== 'add' ? 0 : 1, $row['reindex']);
                        self::assertSame(3, $row['unrelated']);
                    }
                }
            }
        }
    }
}
