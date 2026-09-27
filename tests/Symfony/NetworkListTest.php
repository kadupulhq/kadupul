<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Collection\Application\Port\NetworkCatalog;
use Kadupul\Collection\Application\Query\AutomationAccessDenied;
use Kadupul\Collection\Application\Query\ListNetworks;
use Kadupul\Collection\Application\ReadModel\NetworkPage;
use Kadupul\Collection\Domain\NetworkListCriteria;
use Kadupul\Collection\Infrastructure\Symfony\NetworkListParameters;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use PHPUnit\Framework\TestCase;

final class NetworkListTest extends TestCase
{
    public function testAutomationRealmIsCheckedBeforeCatalogAccess(): void
    {
        $actor = new Actor(23, 'operator');
        $access = $this->createMock(ConsoleAccess::class);
        $access->expects(self::once())->method('consoleActor')->willReturn($actor);
        $access->expects(self::once())->method('canManageAutomation')->with($actor)->willReturn(false);
        $catalog = $this->createMock(NetworkCatalog::class);
        $catalog->expects(self::never())->method('list');

        try {
            (new ListNetworks($access, $catalog))(new NetworkListCriteria());
            self::fail('Expected access denial.');
        } catch (AutomationAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        }
    }

    public function testNetworkListParametersRejectUnrecognizedAndMalformedValues(): void
    {
        $form = NetworkListParameters::formData(['network_filter' => ['q' => 'edge', 'size' => '50', 'sort' => 'threads', 'direction' => 'desc']]);
        $criteria = NetworkListParameters::parse(['page' => '2'], $form);
        self::assertSame('edge', $criteria->search);
        self::assertSame(2, $criteria->page);
        self::assertSame(50, $criteria->pageSize);
        self::assertSame('threads', $criteria->sort);
        self::assertSame('desc', $criteria->direction);

        foreach ([['admin' => '1'], ['network_filter' => ['q' => ['invalid']]], ['page' => '0']] as $query) {
            try {
                if (isset($query['page'])) {
                    NetworkListParameters::parse($query, []);
                } else {
                    NetworkListParameters::formData($query);
                }
                self::fail('Expected invalid input rejection.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
