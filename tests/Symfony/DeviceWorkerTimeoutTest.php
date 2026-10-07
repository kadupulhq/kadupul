<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceWorkerTimeout;
use PHPUnit\Framework\TestCase;

final class DeviceWorkerTimeoutTest extends TestCase
{
    public function testRemoteReindexBudgetsEveryAssociatedQueryAndKeepsTheLocalMargin(): void
    {
        $remote = new DeviceMaintenanceState(new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 3, 0), [1 => 'one', 2 => 'two', 3 => 'three'], [1 => 2, 2 => 2, 3 => 2], false);
        $local = new DeviceMaintenanceState(new DeviceState(8, 'fixture', '192.0.2.2', true, 0, 1, 0), [1 => 'one'], [1 => 2], false);
        foreach (['reindex' => 1020, 'reload-query' => 420, 'query-diagnostics' => 420, 'connectivity' => 420, 'refresh-cache' => 120, 'enable-debug' => 120, 'disable-debug' => 120] as $operation => $expected) {
            $request = new DeviceMaintenanceRequest($operation, in_array($operation, ['reload-query', 'query-diagnostics'], true) ? 1 : 0);
            self::assertSame($expected, DeviceWorkerTimeout::maintenance($remote, $request));
            self::assertSame(120, DeviceWorkerTimeout::maintenance($local, $request));
        }
        $empty = new DeviceMaintenanceState($remote->device, [], [], false);
        self::assertSame(120, DeviceWorkerTimeout::maintenance($empty, new DeviceMaintenanceRequest('reindex')));
        $single = new DeviceMaintenanceState($remote->device, [4 => 'four'], [4 => 2], false);
        self::assertSame(420, DeviceWorkerTimeout::maintenance($single, new DeviceMaintenanceRequest('reindex')));
    }

    public function testQueryAssociationAddAndChangeHaveTimeForTheAllowedRemoteRequest(): void
    {
        foreach (['add' => 420, 'change' => 420, 'remove' => 120] as $operation => $expected) {
            self::assertSame($expected, DeviceWorkerTimeout::assignment('associations', ['kind' => 'query', 'operation' => $operation]));
            self::assertSame(120, DeviceWorkerTimeout::assignment('associations', ['kind' => 'graph', 'operation' => $operation]));
            self::assertSame(120, DeviceWorkerTimeout::assignment('template', ['kind' => 'query', 'operation' => $operation]));
        }
        foreach ([1, 30, 120, 300] as $configuredTimeout) {
            self::assertGreaterThanOrEqual($configuredTimeout * 3 + 120, DeviceWorkerTimeout::forRemoteCalls(3));
        }
    }

    public function testNegativeCountsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeviceWorkerTimeout::forRemoteCalls(-1);
    }
}
