<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy {
    function run_data_query(int $id, int $query): bool
    {
        return false;
    }
    function debug_log_return(string $kind): string
    {
        return '<b>Failed</b><br>fixture-secret';
    }
}

namespace Kadupul\Tests {
    use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
    use Kadupul\Inventory\Domain\DeviceMaintenanceState;
    use Kadupul\Inventory\Domain\DeviceState;
    use Kadupul\Inventory\Infrastructure\Legacy\DeviceMaintenanceExecutor;
    use PHPUnit\Framework\TestCase;

    final class DeviceMaintenanceExecutionTest extends TestCase
    {
        public function testFailedDiscoveryIsNotReportedAsCompletedAndRedactsDiagnostics(): void
        {
            $state = new DeviceMaintenanceState(new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0), [3 => 'Query'], [3 => 2], false);
            $result = (new DeviceMaintenanceExecutor())->execute($this->createMock(\PDO::class), null, $state, new DeviceMaintenanceRequest('query-diagnostics', 3), ['snmp_password' => 'fixture-secret']);
            self::assertFalse($result->completed);
            self::assertSame("Failed\n[redacted]", $result->output);
        }
    }
}
