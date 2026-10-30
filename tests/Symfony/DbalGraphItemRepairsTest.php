<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphItemRepairs;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalGraphItemRepairsTest extends TestCase
{
    private Connection $db;
    private DbalGraphItemRepairs $repairs;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER)',
            'CREATE TABLE data_local (id INTEGER PRIMARY KEY, host_id INTEGER, data_template_id INTEGER)',
            'CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, local_data_template_rrd_id INTEGER, local_data_id INTEGER, data_template_id INTEGER)',
            'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, graph_template_id INTEGER, local_graph_id INTEGER, local_graph_template_item_id INTEGER, task_item_id INTEGER)',
            // Template 5 holds graphs 10 (device 1), 11 (device 1) and 20 (device 2); graph 99 belongs to template 6.
            'INSERT INTO graph_local VALUES (10, 1, 5), (11, 1, 5), (20, 2, 5), (99, 1, 6)',
            // Data template 6: device 1 has data sources 100 and 101 (the first wins); device 2 has 200 with no RRD item.
            'INSERT INTO data_local VALUES (101, 1, 6), (100, 1, 6), (200, 2, 6), (300, 1, 7)',
            // 900 is the template's RRD item (data_template_id 6, no data source); 500 and 501 belong to data source 100.
            'INSERT INTO data_template_rrd VALUES (900, 0, 0, 6), (501, 900, 100, 6), (500, 900, 100, 6), (700, 0, 101, 6)',
            // Template item 800 follows RRD item 900. Graph 10 has items 7 (wrong) and 8 (right) on it, and 9 on another item.
            'INSERT INTO graph_templates_item VALUES (800, 5, 0, 0, 900), (7, 5, 10, 800, 90), (8, 5, 10, 800, 500), (9, 5, 10, 801, 91), (12, 5, 11, 800, 500), (13, 5, 11, 801, 92)',
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->repairs = new DbalGraphItemRepairs($this->db, $this->db);
    }

    public function testGraphsOfATemplateInIdOrderOptionallyOnOneDevice(): void
    {
        $ids = static fn(array $graphs): array => array_map(static fn($graph): array => [$graph->id, $graph->hostId], $graphs);

        self::assertSame([[10, 1], [11, 1], [20, 2]], $ids($this->repairs->graphs(OperatorDatabase::Local, 5, null)));
        self::assertSame([[20, 2]], $ids($this->repairs->graphs(OperatorDatabase::Local, 5, 2)));
        self::assertSame([], $this->repairs->graphs(OperatorDatabase::Local, 5, 9));
    }

    public function testTheFirstDataSourceAndItsFirstRrdItemAreTheTarget(): void
    {
        $target = $this->repairs->rrdTarget(OperatorDatabase::Local, 1, 6);

        self::assertSame([100, 500], [$target?->dataSourceId, $target?->rrdId]);
    }

    public function testADataSourceWithNoRrdItemKeepsANullRrdAndAMissingOneIsNull(): void
    {
        self::assertNull($this->repairs->rrdTarget(OperatorDatabase::Local, 2, 6)?->rrdId);
        self::assertSame(200, $this->repairs->rrdTarget(OperatorDatabase::Local, 2, 6)->dataSourceId);
        self::assertNull($this->repairs->rrdTarget(OperatorDatabase::Local, 3, 6));
    }

    public function testOnlyItemsFollowingTheDataTemplateWithAnotherRrdItemAreWrong(): void
    {
        $wrong = $this->repairs->wrongItems(OperatorDatabase::Local, 10, 5, 6, 500);

        self::assertSame([[7, 90]], array_map(static fn($item): array => [$item->id, $item->taskItemId], $wrong));
        self::assertSame([], $this->repairs->wrongItems(OperatorDatabase::Local, 11, 5, 6, 500));
    }

    public function testRepointChangesOnlyTheNamedItemsOfThatGraph(): void
    {
        // Item 13 is named but belongs to graph 11, and item 8 is already right.
        $this->repairs->repoint(OperatorDatabase::Local, 10, 5, 500, [7, 8, 9, 13]);

        self::assertSame([[7, 500], [8, 500], [9, 500], [13, 92]], $this->db->fetchAllNumeric('SELECT id, task_item_id FROM graph_templates_item WHERE id IN (7, 8, 9, 13) ORDER BY id'));
    }
}
