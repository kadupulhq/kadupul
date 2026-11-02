<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Automation\Infrastructure\Persistence\DbalNetworkDeviceCatalog;
use Kadupul\Graphing\Infrastructure\Persistence\DbalInstanceCatalog;
use Kadupul\IdentityAccess\Contract\ManagementVisibility;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalManagementVisibility;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Kadupul\Platform\Infrastructure\Persistence\DbalCollectorDeviceCatalog;
use Kadupul\Reporting\Infrastructure\Persistence\DbalReportItemCatalog;
use PHPUnit\Framework\TestCase;

final class InstanceCatalogBoundaryTest extends TestCase
{
    public function testReadersRefuseMismatchedScreensActorsAndSortsBeforeDatabaseAccess(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchOne');
        $database->expects(self::never())->method('fetchAllAssociative');
        $visibility = $this->createMock(ManagementVisibility::class);
        $visibility->expects(self::never())->method('forActor');
        $readers = [
            [new DbalInstanceCatalog($database, $visibility), CatalogScreen::LocalGraphItems],
            [new DbalReportItemCatalog($database), CatalogScreen::ReportItems],
            [new DbalCollectorDeviceCatalog($database, $visibility), CatalogScreen::CollectorDevices],
            [new DbalNetworkDeviceCatalog($database), CatalogScreen::NetworkDevices],
        ];
        foreach ($readers as [$reader, $screen]) {
            foreach ([[CatalogScreen::Plugins, 42, CatalogFilters::fromQuery(CatalogScreen::Plugins, [])],
                [$screen, 0, CatalogFilters::fromQuery($screen, [])],
                [$screen, 42, CatalogFilters::fromQuery(CatalogScreen::Plugins, ['sort_column' => 'version'])]] as [$requested, $actor, $filters]) {
                try {
                    $reader->read($requested, $filters, $actor, 1);
                    self::fail('Invalid reader inputs must not start a query.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function testVisibilityRejectsMissingActorsBeforeQuerying(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchAllAssociative');
        $this->expectException(\InvalidArgumentException::class);
        (new DbalManagementVisibility($database))->forActor(0);
    }

    public function testVisibilityRefusesAnUnavailableActor(): void
    {
        $database = $this->createStub(Connection::class);
        $database->method('fetchAllAssociative')->willReturn([]);
        $this->expectException(\RuntimeException::class);
        (new DbalManagementVisibility($database))->forActor(42);
    }

    public function testMalformedPolicyCountersCannotBeCoercedIntoAnAllowAllShortcut(): void
    {
        foreach ([null, '1.0'] as $policy) {
            $database = $this->createStub(Connection::class);
            $database->method('fetchAllAssociative')->willReturn([['id' => 42, 'kind' => 'user', 'policy_graphs' => $policy, 'policy_hosts' => 1, 'policy_graph_templates' => 1]]);
            $database->method('fetchAllKeyValue')->willReturn([]);
            try {
                (new DbalManagementVisibility($database))->forActor(42);
                self::fail('Malformed policy counters must not admit an allow-all scope.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }
}
