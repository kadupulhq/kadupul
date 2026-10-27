<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Kernel;
use Kadupul\Navigation\Application\Port\AdministrationListAccess;
use Kadupul\Navigation\Application\Query\ViewAdministrationList;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use PHPUnit\Framework\TestCase;

final class AdministrationCatalogWiringTest extends TestCase
{
    public function testTheContainerWiresEveryListToItsFeatureCatalogAndWebConnection(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(AdministrationListAccess::class);
            $access->expects(self::exactly(10))->method('authorize');
            $container->set(AdministrationListAccess::class, $access);
            $database = $this->createMock(Connection::class);
            $database->expects(self::exactly(10))->method('fetchAllAssociative')->willReturn([]);
            $container->set('doctrine.dbal.web_connection', $database);
            $view = $container->get(ViewAdministrationList::class);
            foreach (ListScreen::cases() as $screen) {
                $result = $view($screen, []);
                self::assertSame([], $result['records'], $screen->value);
                self::assertFalse($result['hasNext']);
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
