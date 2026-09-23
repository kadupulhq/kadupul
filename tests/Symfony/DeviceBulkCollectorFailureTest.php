<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy {
    function api_device_replicate_out(int $deviceId, int $target): bool
    {
        return false;
    }
}

namespace Kadupul\Tests {
    use Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorTransfer;
    use PHPUnit\Framework\TestCase;

    final class DeviceBulkCollectorFailureTest extends TestCase
    {
        public function testExplicitTransferFailureStopsBeforeGraphReplication(): void
        {
            if (!defined('POLLER_COMMAND_PURGE')) {
                define('POLLER_COMMAND_PURGE', 4);
            }
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $primary = $this->createMock(\PDO::class);
            $remote = $this->createMock(\PDO::class);
            $primary->expects(self::once())->method('prepare')->with(self::stringStartsWith('DELETE FROM poller_command'))->willReturn($statement);
            $remote->expects(self::once())->method('prepare')->with(self::stringStartsWith('DELETE FROM poller_command'))->willReturn($statement);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Collector replication failed');
            (new DeviceCollectorTransfer())->apply($primary, [2 => $remote], 7, 1, 2);
        }
    }
}
