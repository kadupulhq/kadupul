<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalDependencies;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceRemovalDependenciesTest extends TestCase
{
    private function database(): PDO
    {
        // SQLite exercises the actual joins and filters, not MySQL locking.
        // The HTTP/database integration suite covers production transactions.
        $db = new class ('sqlite::memory:') extends PDO {
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                TestCase::assertStringEndsWith(' FOR UPDATE', $query);
                return parent::query(substr($query, 0, -strlen(' FOR UPDATE')));
            }
        };
        $db->exec('CREATE TABLE graph_templates_item (local_graph_id INTEGER, task_item_id INTEGER)');
        $db->exec('CREATE TABLE data_template_rrd (id INTEGER, local_data_id INTEGER)');
        $db->exec('CREATE TABLE aggregate_graphs (id INTEGER, local_graph_id INTEGER)');
        $db->exec('CREATE TABLE aggregate_graphs_items (aggregate_graph_id INTEGER, local_graph_id INTEGER)');
        return $db;
    }

    #[DataProvider('dependencies')]
    public function testDependencyOwnership(string $fixture, bool $exclusive): void
    {
        $db = $this->database();
        $db->exec('INSERT INTO data_template_rrd VALUES (100, 10), (200, 20)');
        $db->exec($fixture);
        $db->beginTransaction();
        self::assertSame($exclusive, DeviceRemovalDependencies::exclusive($db, [1], [10]));
        $db->rollBack();
    }

    public static function dependencies(): iterable
    {
        yield 'owned data' => ['INSERT INTO graph_templates_item VALUES (1, 100)', true];
        yield 'selected graph uses outside data' => ['INSERT INTO graph_templates_item VALUES (1, 200)', false];
        yield 'outside graph uses selected data' => ['INSERT INTO graph_templates_item VALUES (2, 100)', false];
        yield 'unrelated graph and data' => ['INSERT INTO graph_templates_item VALUES (2, 200)', true];
        yield 'selected aggregate output' => ['INSERT INTO aggregate_graphs VALUES (70, 1)', false];
        yield 'selected aggregate member' => ['INSERT INTO aggregate_graphs_items VALUES (70, 1)', false];
        yield 'aggregate id is not graph id' => ['INSERT INTO aggregate_graphs VALUES (1, 2)', true];
        yield 'membership owner id is not graph id' => ['INSERT INTO aggregate_graphs_items VALUES (1, 2)', true];
    }

    public function testDependencyCheckRequiresActiveTransaction(): void
    {
        $this->expectException(\LogicException::class);
        DeviceRemovalDependencies::exclusive($this->database(), [1], [10]);
    }

    public function testAFailedAggregateQueryIsReportedNotDereferenced(): void
    {
        // In silent error mode query() returns false, which must not be called on.
        $db = $this->createMock(PDO::class);
        $db->method('inTransaction')->willReturn(true);
        $db->method('query')->willReturnCallback(function (string $sql): PDOStatement|false {
            if (str_contains($sql, 'aggregate_graphs')) {
                return false;
            }
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetchAll')->willReturn([]);
            $statement->method('errorCode')->willReturn('00000');
            return $statement;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dependency scope unavailable');
        DeviceRemovalDependencies::exclusive($db, [1], [10]);
    }

    #[DataProvider('emptySelections')]
    public function testEmptySelectionsIgnoreTemplateRows(array $graphs, array $data, bool $outsideReference, bool $expected): void
    {
        $db = $this->database();
        $db->exec('INSERT INTO data_template_rrd VALUES (100, 0), (200, 10)');
        $db->exec('INSERT INTO graph_templates_item VALUES (0, 100)');
        if ($outsideReference) {
            $db->exec('INSERT INTO graph_templates_item VALUES (2, 200)');
        }
        $db->beginTransaction();
        self::assertSame($expected, DeviceRemovalDependencies::exclusive($db, $graphs, $data));
        $db->rollBack();
    }

    public static function emptySelections(): iterable
    {
        yield 'no graphs or data' => [[], [], false, true];
        yield 'ungraphed owned data' => [[], [10], false, true];
        yield 'graph without owned data' => [[1], [], false, true];
        yield 'ungraphed selection still protects outside reference' => [[], [10], true, false];
    }
}
