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
            CREATE UNIQUE INDEX graph_tree_device_placement ON graph_tree_items (graph_tree_id, parent, host_id);");
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
        $writer = static function (int $treeId, int $parentId, int $deviceId) use ($sqlite): bool {
            $statement = $sqlite->prepare("INSERT INTO graph_tree_items VALUES (11,?, ?, ?,0,0,'',1,1)");
            return $statement->execute([$treeId, $parentId, $deviceId]);
        };
        $placement = new LegacyDeviceTreePlacement($db, $access, $writer);
        $receipt = $placement->place(42, [7], 1, 10);
        self::assertCount(1, $receipt);
        self::assertSame(10, $receipt[0]['parent']);
        $placement->verify(42, [7], 1, 10, $receipt);
    }

    public function testReportPlacementAppendsOnlyMissingDevicesAndVerifiesTheReceipt(): void
    {
        $sqlite = new \PDO('sqlite::memory:');
        $sqlite->exec("CREATE TABLE reports (id INTEGER, name TEXT, user_id INTEGER);
            INSERT INTO reports VALUES (1,'Owned',42),(2,'Foreign',43);
            CREATE TABLE host (id INTEGER, host_template_id INTEGER, site_id INTEGER, deleted TEXT);
            INSERT INTO host VALUES (7,3,9,''),(8,4,0,''),(9,4,0,'on');
            CREATE TABLE reports_items (id INTEGER PRIMARY KEY AUTOINCREMENT, report_id INTEGER, item_type INTEGER, host_template_id INTEGER, site_id INTEGER,
                host_id INTEGER, graph_template_id INTEGER, local_graph_id INTEGER, timespan INTEGER, align INTEGER, sequence INTEGER, item_text TEXT, tree_id INTEGER DEFAULT 0);
            INSERT INTO reports_items (report_id, item_type, host_template_id, site_id, host_id, graph_template_id, local_graph_id, timespan, align, sequence, item_text)
                VALUES (1,1,0,0,0,0,11,7,1,4,'graph'),(1,5,4,0,8,-1,0,7,1,2,'')");
        // Row locks are covered by the MariaDB placement probe; SQLite checks the SQL.
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('prepare')->willReturnCallback(static fn(string $sql): \PDOStatement => $sqlite->prepare((string) preg_replace('/ FOR UPDATE$/', '', $sql)));
        $db = $this->createMock(DatabaseConnection::class);
        $db->method('get')->willReturn($pdo);
        $access = $this->createMock(ResourceAccess::class);
        $access->method('canManageReport')->willReturnCallback(static fn(int $actor, int $owner): bool => $actor === $owner);
        $placement = new LegacyDeviceReportPlacement($db, $access);

        $receipt = $placement->place(42, [7, 8], 1, 7, 2);

        // Device 8 keeps its alignment and sequence; device 7 is appended after the highest sequence.
        self::assertSame([[8, 1, 2], [7, 2, 5]], array_map(
            static fn(array $row): array => [(int) $row['host_id'], (int) $row['align'], (int) $row['sequence']],
            $receipt,
        ));
        self::assertSame(['3', '9', '-1', '0'], array_map('strval', [$receipt[1]['host_template_id'], $receipt[1]['site_id'], $receipt[1]['graph_template_id'], $receipt[1]['local_graph_id']]));
        self::assertSame($receipt, $placement->place(42, [7, 8], 1, 7, 2), 'A repeated placement adds nothing.');
        $placement->verify(42, [7, 8], 1, 7, 2, $receipt);

        $sqlite->exec('UPDATE reports_items SET sequence = 9 WHERE host_id = 7');
        try {
            $placement->verify(42, [7, 8], 1, 7, 2, $receipt);
            self::fail('A placement changed by a callback must be refused.');
        } catch (\RuntimeException $error) {
            self::assertSame('Placement changed during callbacks', $error->getMessage());
        }
        foreach ([[2, [7]], [3, [7]], [1, [9]]] as [$report, $devices]) {
            try {
                $placement->place(42, $devices, $report, 7, 2);
                self::fail('Foreign, missing reports and deleted devices must be refused.');
            } catch (\RuntimeException) {
            }
        }
        self::assertSame(0, (int) $sqlite->query('SELECT COUNT(*) FROM reports_items WHERE report_id <> 1 OR host_id = 9')->fetchColumn());
    }

    public static function legacyBranches(): iterable
    {
        yield 'site branch' => [9, 'Site'];
        yield 'empty header' => [0, ''];
    }

}
