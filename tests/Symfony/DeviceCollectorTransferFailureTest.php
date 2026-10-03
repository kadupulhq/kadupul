<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeviceCollectorTransferFailureTest extends TestCase
{
    public function testExplicitTransferFailureStopsBeforeGraphReplication(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/collector-transfer-failure-native.php', 'single']);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('Collector replication failed', $result['error']);
        self::assertSame([[7, 3]], $result['calls']);
        self::assertCount(1, $result['primary_queries']);
        self::assertCount(1, $result['remote_queries']);
        foreach ([$result['primary_queries'][0], $result['remote_queries'][0]] as $query) {
            self::assertStringStartsWith('DELETE FROM poller_command', $query);
        }
        self::assertTrue($result['transaction_active']);
    }
}
