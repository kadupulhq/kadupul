<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Collection\Application\Port\DiscoveredDeviceCatalog;
use Kadupul\Collection\Application\Query\AutomationAccessDenied;
use Kadupul\Collection\Application\Query\ListDiscoveredDevices;
use Kadupul\Collection\Domain\DiscoveredDeviceCriteria;
use Kadupul\Collection\Infrastructure\Symfony\DiscoveredDeviceListParameters;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use PHPUnit\Framework\TestCase;

final class DiscoveredDeviceListTest extends TestCase
{
    public function testAutomationRealmIsCheckedBeforeCatalogAccess(): void
    {
        $actor = new Actor(23, 'operator');
        $access = $this->createMock(ConsoleAccess::class);
        $access->expects(self::once())->method('consoleActor')->willReturn($actor);
        $access->expects(self::once())->method('canManageAutomation')->with($actor)->willReturn(false);
        $catalog = $this->createMock(DiscoveredDeviceCatalog::class);
        $catalog->expects(self::never())->method('list');

        try {
            (new ListDiscoveredDevices($access, $catalog))(new DiscoveredDeviceCriteria());
            self::fail('Expected access denial.');
        } catch (AutomationAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        }
    }

    public function testParametersPreserveSearchAndBoundPageSizeAndSort(): void
    {
        $form = DiscoveredDeviceListParameters::formData(['discovery_filter' => [
            'q' => 'router', 'network' => '4', 'status' => 'up', 'snmp' => 'all', 'os' => 'Linux',
            'size' => '50', 'sort' => 'ip', 'direction' => 'desc',
        ]]);
        $criteria = DiscoveredDeviceListParameters::parse(['page' => '2'], $form);
        self::assertSame('router', $criteria->search);
        self::assertSame(4, $criteria->networkId);
        self::assertSame(50, $criteria->pageSize);
        self::assertSame('ip', $criteria->sort);
        self::assertSame('desc', $criteria->direction);

        foreach ([['unexpected' => 'x'], ['page' => '0'], ['page' => '9999999']] as $query) {
            try {
                isset($query['page']) ? DiscoveredDeviceListParameters::parse($query, []) : DiscoveredDeviceListParameters::formData($query);
                self::fail('Expected invalid input rejection.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
