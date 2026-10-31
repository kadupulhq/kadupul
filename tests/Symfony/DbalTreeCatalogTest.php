<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Application\Port\TreeNodeRow;
use Kadupul\Graphing\Infrastructure\Persistence\DbalTreeCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalTreeCatalogTest extends TestCase
{
    private Connection $db;
    private DbalTreeCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE graph_tree (id INTEGER PRIMARY KEY, sort_type INTEGER, name TEXT)',
            'CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY, parent INTEGER, position INTEGER, graph_tree_id INTEGER, local_graph_id INTEGER DEFAULT 0, title TEXT, host_id INTEGER DEFAULT 0, site_id INTEGER DEFAULT 0, host_grouping_type INTEGER DEFAULT 1, sort_children_type INTEGER DEFAULT 0)',
            'CREATE TABLE host (id INTEGER PRIMARY KEY, hostname TEXT, description TEXT, host_template_id INTEGER)',
            'CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, graph_template_id INTEGER, host_id INTEGER)',
            'CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE graph_templates_graph (local_graph_id INTEGER, title_cache TEXT)',
            "INSERT INTO graph_tree VALUES (1, 2, 'Default Tree'), (2, 1, 'Other')",
            "INSERT INTO host VALUES (7, 'core1', 'Core router', 2), (8, 'sw1', 'Switch', 1)",
            "INSERT INTO sites VALUES (2, 'Berlin'), (1, 'Oslo')",
            "INSERT INTO graph_templates VALUES (4, 'Traffic')",
            "INSERT INTO graph_local VALUES (3, 4, 7), (5, 0, 7), (6, 4, 0)",
            "INSERT INTO graph_templates_graph VALUES (3, 'core1 - Traffic'), (5, 'core1 - Loose'), (6, 'floating')",
            // A header with a graph, a device and a header below it, a site node at the root, and a node of the other tree.
            "INSERT INTO graph_tree_items (id, parent, position, graph_tree_id, local_graph_id, title, host_id, site_id, host_grouping_type, sort_children_type) VALUES
                (10, 0, 1, 1, 0, 'Core', 0, 0, 1, 2), (11, 10, 2, 1, 3, NULL, 0, 0, 1, 0), (12, 10, 1, 1, 0, NULL, 7, 0, 2, 0),
                (13, 10, 3, 1, 0, 'Inner', 0, 0, 1, 1), (14, 0, 2, 1, 0, NULL, 0, 2, 1, 0), (15, 0, 1, 2, 0, 'Elsewhere', 0, 0, 1, 0)",
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalTreeCatalog($this->db, $this->db);
    }

    public function testTreesAreFoundByNameAndIdAndListedInIdOrder(): void
    {
        self::assertSame(2, $this->catalog->treeIdByName(OperatorDatabase::Local, 'Other'));
        self::assertNull($this->catalog->treeIdByName(OperatorDatabase::Local, 'Nothing'));
        self::assertTrue($this->catalog->treeExists(OperatorDatabase::Local, 2));
        self::assertFalse($this->catalog->treeExists(OperatorDatabase::Local, 3));
        self::assertSame([[1, 2, 'Default Tree'], [2, 1, 'Other']], array_map(static fn($tree): array => [$tree->id, $tree->sortType, $tree->name], $this->catalog->trees(OperatorDatabase::Local)));
    }

    public function testOnlyAHeaderWithATitleCanBeAParent(): void
    {
        self::assertTrue($this->catalog->findItem(OperatorDatabase::Local, 1, 10)?->isHeader());
        self::assertFalse($this->catalog->findItem(OperatorDatabase::Local, 1, 11)?->isHeader(), 'a graph node');
        self::assertFalse($this->catalog->findItem(OperatorDatabase::Local, 1, 12)?->isHeader(), 'a device node');
        self::assertFalse($this->catalog->findItem(OperatorDatabase::Local, 1, 14)?->isHeader(), 'a site node');
        self::assertNull($this->catalog->findItem(OperatorDatabase::Local, 2, 10), 'a node of another tree');
    }

    public function testDevicesAndSitesAreFoundOnlyByTheCanonicalSpellingOfTheirId(): void
    {
        self::assertTrue($this->catalog->deviceExists(OperatorDatabase::Local, '7'));
        self::assertFalse($this->catalog->deviceExists(OperatorDatabase::Local, '07'));
        self::assertFalse($this->catalog->deviceExists(OperatorDatabase::Local, '7.0'));
        self::assertFalse($this->catalog->deviceExists(OperatorDatabase::Local, ' 7'));
        self::assertFalse($this->catalog->deviceExists(OperatorDatabase::Local, '9'));
        self::assertTrue($this->catalog->siteExists(OperatorDatabase::Local, '2'));
        self::assertFalse($this->catalog->siteExists(OperatorDatabase::Local, '02'));
        self::assertSame(3, $this->catalog->findGraph(OperatorDatabase::Local, '3.0'));
        self::assertNull($this->catalog->findGraph(OperatorDatabase::Local, '3 OR 1=1'));
        self::assertNull($this->catalog->findGraph(OperatorDatabase::Local, '9'));
    }

    public function testNodesAreWalkedDepthFirstInPositionOrderWithTheirParents(): void
    {
        $rows = array_map(static fn(TreeNodeRow $row): array => [$row->kind, $row->id, $row->parent, $row->title, $row->attribute], $this->catalog->nodes(OperatorDatabase::Local, '1', '', '0'));

        self::assertSame([
            ['header', 10, 'N/A', 'Core', 2],
            ['host', 12, '10', 'core1', 2],
            ['graph', 11, '10', 'core1 - Traffic', null],
            ['header', 13, '10', 'Inner', 1],
            ['header', 14, 'N/A', '', 0],
        ], $rows, 'a site node counts as a header, as in the original');
    }

    public function testANodeTypeFiltersWhatIsListedButNotWhatIsWalked(): void
    {
        self::assertSame([11], array_map(static fn(TreeNodeRow $row): int => $row->id, $this->catalog->nodes(OperatorDatabase::Local, '1', 'graph', '0')), 'the graph is under a header the walk still enters');
        self::assertSame([12], array_map(static fn(TreeNodeRow $row): int => $row->id, $this->catalog->nodes(OperatorDatabase::Local, '1', 'host', '0')));
        self::assertSame([10, 13, 14], array_map(static fn(TreeNodeRow $row): int => $row->id, $this->catalog->nodes(OperatorDatabase::Local, '1', 'header', '0')));
        self::assertSame([], array_map(static fn(TreeNodeRow $row): int => $row->id, $this->catalog->nodes(OperatorDatabase::Local, '1', 'leaf', '0')));
        self::assertSame(['10', '10', '10'], array_map(static fn(TreeNodeRow $row): string => $row->parent, $this->catalog->nodes(OperatorDatabase::Local, '1', '', '10')), 'below the root the parent is the one asked for');
    }

    public function testADeviceListsItsGraphsWithTheirTemplates(): void
    {
        self::assertSame([[3, 'core1 - Traffic', 'Traffic'], [5, 'core1 - Loose', '']], array_map(static fn($graph): array => [$graph->id, $graph->name, $graph->template], $this->catalog->deviceGraphs(OperatorDatabase::Local, '7')));
        self::assertSame([], $this->catalog->deviceGraphs(OperatorDatabase::Local, '8'));
    }

    public function testSitesAndDevicesAreListedInIdOrder(): void
    {
        self::assertSame([1, 2], array_map(static fn($site): int => $site->id, $this->catalog->sites(OperatorDatabase::Local)));
        self::assertSame([7, 8], array_map(static fn($device): int => $device->id, $this->catalog->devices(OperatorDatabase::Local)));
    }

    public function testTheMainConnectionIsUsedForMain(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $other->executeStatement('CREATE TABLE graph_tree (id INTEGER PRIMARY KEY, sort_type INTEGER, name TEXT)');
        $other->executeStatement("INSERT INTO graph_tree VALUES (9, 1, 'Main only')");
        $catalog = new DbalTreeCatalog($this->db, $other);

        self::assertSame(9, $catalog->treeIdByName(OperatorDatabase::Main, 'Main only'));
        self::assertNull($catalog->treeIdByName(OperatorDatabase::Local, 'Main only'));
    }
}
