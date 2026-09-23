<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalDependencyReceipt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceRemovalDependentsTest extends TestCase
{
    #[DataProvider('orphans')]
    public function testFinalChecksDoNotDependOnDeletedParents(?string $insertion): void
    {
        $sqlite = new \PDO('sqlite::memory:');
        $sqlite->exec('CREATE TABLE graph_templates_graph (id INTEGER, local_graph_id INTEGER)');
        $sqlite->exec('CREATE TABLE data_template_data (id INTEGER, local_data_id INTEGER); CREATE TABLE data_template_rrd (id INTEGER, local_data_id INTEGER); CREATE TABLE graph_templates_item (id INTEGER, local_graph_id INTEGER, task_item_id INTEGER); CREATE TABLE data_input_data (data_template_data_id INTEGER, data_input_field_id INTEGER)');
        $sqlite->exec('INSERT INTO data_template_data VALUES (101,12); INSERT INTO data_template_rrd VALUES (102,12); INSERT INTO graph_templates_item VALUES (103,11,102)');
        // Execute real predicates in SQLite; separately assert MySQL locking intent.
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturn(true);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($sqlite): \PDOStatement {
            self::assertStringEndsWith(' FOR UPDATE', $sql);
            return $sqlite->prepare(substr($sql, 0, -strlen(' FOR UPDATE')));
        });
        $receipt = DeviceRemovalDependencyReceipt::capture($db, [11], [12]);
        $sqlite->exec('DELETE FROM data_template_data; DELETE FROM data_template_rrd; DELETE FROM graph_templates_item');
        if ($insertion !== null) {
            $sqlite->exec($insertion);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Reviewed primary dependent remains');
        }
        $receipt->assertPurged($db);
    }

    public static function orphans(): iterable
    {
        yield 'complete removal' => [null];
        yield 'outside graph references deleted RRD' => ['INSERT INTO graph_templates_item VALUES (999,99,102)'];
        yield 'new graph item on deleted graph' => ['INSERT INTO graph_templates_item VALUES (999,11,999)'];
        yield 'new template on deleted data' => ['INSERT INTO data_template_data VALUES (999,12)'];
        yield 'new RRD on deleted data' => ['INSERT INTO data_template_rrd VALUES (999,12)'];
        yield 'input points to deleted template' => ['INSERT INTO data_input_data VALUES (101, 1)'];
        yield 'reassigned template' => ['INSERT INTO data_template_data VALUES (101,99)'];
        yield 'reassigned RRD' => ['INSERT INTO data_template_rrd VALUES (102,99)'];
        yield 'reassigned graph item' => ['INSERT INTO graph_templates_item VALUES (103,99,999)'];
    }
}
