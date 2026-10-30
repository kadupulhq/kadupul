<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Infrastructure\Persistence\DbalBrokenGraphCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalBrokenGraphCatalogTest extends TestCase
{
    public function testItGroupsGraphsWithAMissingDataSourceByTemplateInIdOrder(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE graph_templates_graph (id INTEGER PRIMARY KEY, local_graph_id INTEGER, title_cache TEXT)',
            'CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, local_data_id INTEGER)',
            'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, graph_template_id INTEGER, local_graph_id INTEGER, task_item_id INTEGER)',
            "INSERT INTO graph_templates VALUES (1, 'Traffic'), (2, 'Load')",
            "INSERT INTO graph_templates_graph VALUES (1, 30, 'Core load'), (2, 10, 'Core traffic'), (3, 11, 'Edge traffic'), (4, 12, 'Mixed'), (5, 13, 'Template row')",
            // RRD item 500 belongs to a data source; 501 is a template item (no data source), so it does not count as present.
            'INSERT INTO data_template_rrd VALUES (500, 77), (501, 0)',
            // Graph 11 breaks twice and is listed once. Graph 12 has one good item (500) and one pointing at a template RRD item (501), so it is broken too.
            // Graph 13 has no data source link (task_item_id 0), item 7 has no graph and item 8 no template, so none of those count.
            'INSERT INTO graph_templates_item VALUES (1, 2, 30, 999), (2, 1, 11, 998), (3, 1, 10, 997), (4, 1, 11, 996), (5, 1, 12, 500), (6, 1, 13, 0), (7, 1, 0, 995), (8, 0, 14, 994), (9, 1, 12, 501)',
        ] as $statement) {
            $db->executeStatement($statement);
        }

        $groups = (new DbalBrokenGraphCatalog($db, $db))->find(OperatorDatabase::Local);

        self::assertSame(
            [[1, 'Traffic', [[10, 'Core traffic'], [11, 'Edge traffic'], [12, 'Mixed']]], [2, 'Load', [[30, 'Core load']]]],
            array_map(static fn($group): array => [$group->graphTemplateId, $group->name, array_map(static fn($graph): array => [$graph->localGraphId, $graph->title], $group->graphs)], $groups)
        );
    }

    public function testNothingBrokenIsAnEmptyList(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE graph_templates_graph (id INTEGER PRIMARY KEY, local_graph_id INTEGER, title_cache TEXT)',
            'CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, local_data_id INTEGER)',
            'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, graph_template_id INTEGER, local_graph_id INTEGER, task_item_id INTEGER)',
        ] as $statement) {
            $db->executeStatement($statement);
        }

        self::assertSame([], (new DbalBrokenGraphCatalog($db, $db))->find(OperatorDatabase::Local));
    }
}
