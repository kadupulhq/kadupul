<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceDiagnosticText;
use PHPUnit\Framework\TestCase;

final class DeviceMaintenanceTest extends TestCase
{
    public function testAuthorizationPrecedesMaintenanceReadsAndEffects(): void
    {
        foreach ([null, new \Kadupul\IdentityAccess\Contract\Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(\Kadupul\IdentityAccess\Contract\ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(\Kadupul\Inventory\Application\Port\DeviceMaintenance::class);
            $port->expects(self::never())->method('findVisible');
            $port->expects(self::never())->method('execute');
            try {
                (new \Kadupul\Inventory\Application\Command\MaintainDevice($access, $port))(7, new DeviceMaintenanceRequest('enable-debug'), str_repeat('a', 64));
                self::fail('Unauthorized maintenance accepted');
            } catch (\Kadupul\Inventory\Application\Query\InventoryAccessDenied $error) {
                self::assertSame($actor === null, $error->unauthenticated);
            }
        }
    }

    public function testOnlyBoundedSupportedActionsAreAccepted(): void
    {
        foreach ([['shell', 0], ['connectivity', 1], ['reload-query', 0], ['query-diagnostics', 16777216]] as [$operation, $query]) {
            try {
                new DeviceMaintenanceRequest($operation, $query);
                self::fail('Invalid maintenance request accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testReindexRequiresAnEnabledDevice(): void
    {
        $state = new DeviceMaintenanceState(new DeviceState(7, 'Router', 'router.invalid', false, 0, 1, 0), [], [], false);
        $this->expectException(\InvalidArgumentException::class);
        $state->assertRequest(new DeviceMaintenanceRequest('reindex'), $state->revision());
    }
    public function testUnassociatedQueriesAreRejected(): void
    {
        $state = new DeviceMaintenanceState(new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0), [3 => 'Query'], [3 => 2], false);
        $this->expectException(\InvalidArgumentException::class);
        $state->assertRequest(new DeviceMaintenanceRequest('reload-query', 4), $state->revision());
    }
    public function testQuotedAndRemoteCredentialsAreRedactedBeforeMarkupIsRemoved(): void
    {
        foreach (["quote'secret", 'quote"secret', 'tag<secret>', "line\nsecret"] as $secret) {
            $unix = "'" . str_replace("'", "'\\''", str_replace(["\n", "\r"], '', $secret)) . "'";
            $windows = '"' . str_replace('"', '\\"', $secret) . '"';
            foreach ([$secret, $unix, $windows] as $encoded) {
                $text = DeviceDiagnosticText::clean(htmlspecialchars($encoded, ENT_QUOTES, 'UTF-8'), ['snmp_password' => 'primary'], ['snmp_password' => $secret]);
                self::assertStringContainsString('[redacted]', $text);
                self::assertStringNotContainsString('secret', $text);
            }
        }
    }

    public function testDiagnosticsAreBoundedPlainTextWithoutCredentials(): void
    {
        $text = DeviceDiagnosticText::clean('<b>Result</b><br>community=a&amp;b password=private' . "\0", ['snmp_community' => 'a&b', 'snmp_password' => 'private']);
        self::assertSame("Result\ncommunity=[redacted] password=[redacted]", $text);
        $text = DeviceDiagnosticText::clean(str_repeat('界', 30000), []);
        self::assertLessThanOrEqual(65536, strlen($text));
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
    }
}
