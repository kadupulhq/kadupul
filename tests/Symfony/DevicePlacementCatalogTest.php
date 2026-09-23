<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\IdentityAccess\Contract\ResourceAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyDeviceTreePlacement;
use Kadupul\Reporting\Infrastructure\Legacy\LegacyDeviceReportPlacement;

final class DevicePlacementCatalogTest extends TestCase
{
    public function testCatalogsRetainOwnershipAndTreeLocksWithBoundedAuthorizationReads(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE graph_tree (id INTEGER, name TEXT, user_id INTEGER, locked INTEGER, modified_by INTEGER);
            INSERT INTO graph_tree VALUES (1,'Owned',42,0,0),(2,'Foreign',43,0,0),(3,'Locked',42,1,43),(4,'My lock',42,1,42);
            CREATE TABLE graph_tree_items (id INTEGER, title TEXT, graph_tree_id INTEGER, host_id INTEGER, local_graph_id INTEGER, site_id INTEGER);
            INSERT INTO graph_tree_items VALUES (10,'Branch',1,0,0,0),(11,'Site',1,0,0,9),(12,'',1,0,0,0),(13,'Device',1,7,0,0),(14,'Graph',1,0,8,0),(20,'Hidden',2,0,0,0),(30,'Locked branch',3,0,0,0);
            CREATE TABLE reports (id INTEGER, name TEXT, user_id INTEGER);
            INSERT INTO reports VALUES (1,'Owned',42),(2,'Foreign',43);
            CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)");
        $db = $this->createMock(DatabaseConnection::class);
        $db->method('get')->willReturn($pdo);
        $access = $this->createMock(ResourceAccess::class);
        $access->expects(self::exactly(2))->method('canManageTree')->willReturnCallback(fn($actor, $owner) => $actor === $owner);
        $access->expects(self::exactly(2))->method('canManageReport')->willReturnCallback(fn($actor, $owner) => $actor === $owner);
        self::assertSame(['4:0' => 'My lock (#4)', '1:0' => 'Owned (#1)', '1:12' => 'Owned /  (#12)', '1:10' => 'Owned / Branch (#10)', '1:11' => 'Owned / Site (#11)'], (new LegacyDeviceTreePlacement($db, $access))->destinations(42));
        $reports = new LegacyDeviceReportPlacement($db, $access);
        self::assertSame([1 => 'Owned (#1)'], $reports->destinations(42));
        self::assertSame(7, $reports->defaultTimespan(42));
        $pdo->exec("INSERT INTO settings_user VALUES (42,'default_timespan','11'),(43,'default_timespan','28')");
        self::assertSame(11, $reports->defaultTimespan(42));
        $pdo->exec("UPDATE settings_user SET value='999' WHERE user_id=42");
        self::assertSame(7, $reports->defaultTimespan(42));
    }
    #[DataProvider('legacyBranches')]
    public function testSiteAndEmptyTitleParentsAreAcceptedDuringPlacementAndVerification(int $site, string $title): void
    {
        $sqlite = new \PDO('sqlite::memory:');
        $sqlite->exec("CREATE TABLE graph_tree (id INTEGER, name TEXT, user_id INTEGER, locked INTEGER, modified_by INTEGER);
            INSERT INTO graph_tree VALUES (1,'Owned',42,0,0);
            CREATE TABLE graph_tree_items (id INTEGER, graph_tree_id INTEGER, parent INTEGER, host_id INTEGER, local_graph_id INTEGER, site_id INTEGER, title TEXT, host_grouping_type INTEGER, sort_children_type INTEGER);
            INSERT INTO graph_tree_items VALUES (11,1,10,7,0,0,'',1,1)");
        $sqlite->prepare('INSERT INTO graph_tree_items VALUES (10,1,0,0,0,?,?,1,1)')->execute([$site, $title]);
        // SQL semantics run against SQLite; row locking is covered by the
        // disposable MariaDB contention probe rather than simulated here.
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('prepare')->willReturnCallback(static fn(string $sql): \PDOStatement => $sqlite->prepare(preg_replace('/ FOR UPDATE$/', '', $sql)));
        $db = $this->createMock(DatabaseConnection::class);
        $db->method('get')->willReturn($pdo);
        $access = $this->createMock(ResourceAccess::class);
        $access->method('canManageTree')->willReturn(true);
        $placement = new LegacyDeviceTreePlacement($db, $access);
        $receipt = $placement->place(42, [7], 1, 10);
        self::assertCount(1, $receipt);
        self::assertSame(10, $receipt[0]['parent']);
        $placement->verify(42, [7], 1, 10, $receipt);
    }

    public static function legacyBranches(): iterable
    {
        yield 'site branch' => [9, 'Site'];
        yield 'empty header' => [0, ''];
    }

}
