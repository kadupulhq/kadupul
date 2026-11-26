<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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

    public function testSimpleDeviceShortcutAppliesOnlyToModesWhereDevicePolicyIsAnUnconditionalDisjunct(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
            'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, policy_graphs INTEGER NOT NULL, policy_hosts INTEGER NOT NULL, policy_graph_templates INTEGER NOT NULL)',
            'CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT NOT NULL, policy_graphs INTEGER NOT NULL, policy_hosts INTEGER NOT NULL, policy_graph_templates INTEGER NOT NULL)',
            'CREATE TABLE user_auth_group_members (group_id INTEGER NOT NULL, user_id INTEGER NOT NULL)',
            'CREATE TABLE user_auth_perms (user_id INTEGER NOT NULL, item_id INTEGER NOT NULL, type INTEGER NOT NULL)',
            'CREATE TABLE user_auth_group_perms (group_id INTEGER NOT NULL, item_id INTEGER NOT NULL, type INTEGER NOT NULL)',
            'CREATE TABLE host (id INTEGER PRIMARY KEY)',
            'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER NOT NULL, graph_template_id INTEGER NOT NULL)',
            'CREATE TABLE graph_templates_graph (local_graph_id INTEGER PRIMARY KEY)',
        ] as $ddl) {
            $database->executeStatement($ddl);
        }
        $database->executeStatement('INSERT INTO host VALUES (1), (2), (3)');
        $database->executeStatement('INSERT INTO graph_local VALUES (101, 1, 10), (102, 2, 20)');
        $database->executeStatement('INSERT INTO graph_templates_graph VALUES (101), (102)');
        // policy_hosts = 1 with zero type=3 exceptions, but graph and template defaults deny.
        $database->executeStatement('INSERT INTO user_auth VALUES (42, 2, 1, 2)');
        $database->executeStatement('INSERT INTO user_auth_perms VALUES (42, 10, 4)');
        $visibility = new DbalManagementVisibility($database);

        foreach ([1 => [1, 2, 3], 2 => [1], 3 => [1, 2, 3], 4 => [1]] as $mode => $expectedDevices) {
            $database->executeStatement("DELETE FROM settings WHERE name = 'graph_auth_method'");
            $database->executeStatement("INSERT INTO settings VALUES ('graph_auth_method', ?)", [(string) $mode]);
            $scope = $visibility->forActor(42);
            $devices = array_map('intval', $database->fetchFirstColumn($scope['devices']));
            sort($devices);
            self::assertSame($expectedDevices, $devices, 'mode=' . $mode);
        }

        // Without any template exception, modes 2 and 4 must not expose any device.
        $database->executeStatement('DELETE FROM user_auth_perms WHERE user_id = 42');
        foreach ([2, 4] as $mode) {
            $database->executeStatement("UPDATE settings SET value = ? WHERE name = 'graph_auth_method'", [(string) $mode]);
            $scope = $visibility->forActor(42);
            self::assertSame([], $database->fetchFirstColumn($scope['devices']), 'no exceptions mode=' . $mode);
        }
    }
}
