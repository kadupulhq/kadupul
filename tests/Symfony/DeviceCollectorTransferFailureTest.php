<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\CollectorTransfer;

use PHPUnit\Framework\TestCase;
use RuntimeException;

function api_device_replicate_out($device, $target)
{
    TestCase::assertSame([7, 3], [$device, $target]);
    return false;
}

final class DeviceCollectorTransferFailureTest extends TestCase
{
    public function testExplicitReplicationFailureStopsBeforeGraphReadsAndWrites(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/bin/legacy-device-collector.php');
        $start = strpos($source, '            if (api_device_replicate_out(');
        self::assertNotFalse($start);
        $end = strpos($source, '        } else {', $start);
        self::assertNotFalse($end);
        $transfer = substr($source, $start, $end - $start);
        self::assertStringContainsString('replicate_table_to_poller(', $transfer);
        $connection = $this->createMock(\PDO::class);
        $connection->expects(self::never())->method('prepare');
        $assignment = (object) ['id' => 7];
        $target = 3;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Collector replication unavailable');
        eval('namespace ' . __NAMESPACE__ . '; use RuntimeException; use PDO;' . $transfer);
    }
}
