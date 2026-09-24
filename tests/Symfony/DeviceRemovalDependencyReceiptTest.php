<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalDependencyReceipt;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DependencyReceiptSqlite extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // Real SQLite rows test identity semantics; MariaDB integration tests locks.
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

final class DeviceRemovalDependencyReceiptTest extends TestCase
{
    private function database(): PDO
    {
        $db = new DependencyReceiptSqlite('sqlite::memory:');
        $db->exec('CREATE TABLE data_template_data (id INTEGER,local_data_id INTEGER);
            CREATE TABLE data_template_rrd (id INTEGER,local_data_id INTEGER);
            CREATE TABLE graph_templates_item (id INTEGER,local_graph_id INTEGER,task_item_id INTEGER);
            CREATE TABLE graph_templates_graph (id INTEGER,local_graph_id INTEGER);
            CREATE TABLE data_local (id INTEGER);
            CREATE TABLE graph_local (id INTEGER);
            CREATE TABLE data_input_data (data_template_data_id INTEGER, data_input_field_id INTEGER DEFAULT 1);
            INSERT INTO data_local VALUES (12); INSERT INTO graph_local VALUES (11);
            INSERT INTO data_template_data VALUES (101,12); INSERT INTO data_template_rrd VALUES (102,12);
            INSERT INTO graph_templates_item VALUES (103,11,102); INSERT INTO graph_templates_graph VALUES (104,11);
            INSERT INTO data_input_data (data_template_data_id) VALUES (101)');
        $db->beginTransaction();
        return $db;
    }

    public function testOutsideReferenceRemainsVisibleAfterRrdParentDeletion(): void
    {
        $db = $this->database();
        $receipt = DeviceRemovalDependencyReceipt::capture($db, [11], [12]);
        $db->exec('DELETE FROM data_input_data; DELETE FROM data_template_data; DELETE FROM data_template_rrd; DELETE FROM data_local; INSERT INTO graph_templates_item VALUES (105,99,102)');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside graph reference');
        $receipt->assertExclusive($db);
    }

    public function testReparentedInputFieldCannotEscapeReviewedScope(): void
    {
        $db = $this->database();
        $receipt = DeviceRemovalDependencyReceipt::capture($db, [11], [12]);
        $db->exec('UPDATE data_input_data SET data_template_data_id = 999 WHERE data_template_data_id = 101');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed input field scope changed');
        $receipt->assertExclusive($db);
    }

    public function testNewInputFieldOnReviewedEmptyTemplateChangesScope(): void
    {
        $db = $this->database();
        $db->exec('DELETE FROM data_input_data');
        $receipt = DeviceRemovalDependencyReceipt::capture($db, [11], [12]);
        $db->exec('INSERT INTO data_input_data VALUES (101, 2)');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed input field scope changed');
        $receipt->assertExclusive($db);
    }

    public function testNewRrdOnReviewedDataSourceChangesScope(): void
    {
        $db = $this->database();
        $receipt = DeviceRemovalDependencyReceipt::capture($db, [11], [12]);
        $db->exec('INSERT INTO data_template_rrd VALUES (999,12)');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed dependent scope changed');
        $receipt->assertExclusive($db);
    }

    public function testNewGraphItemOnReviewedGraphChangesScope(): void
    {
        $db = $this->database();
        $receipt = DeviceRemovalDependencyReceipt::capture($db, [11], [12]);
        $db->exec('INSERT INTO graph_templates_item VALUES (999,11,102)');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed dependent scope changed');
        $receipt->assertExclusive($db);
    }

    #[DataProvider('lateRows')]
    public function testFinalCheckRejectsOrphanedOrReassignedDependent(string $sql): void
    {
        $db = $this->database();
        $receipt = DeviceRemovalDependencyReceipt::capture($db, [11], [12]);
        $db->exec('DELETE FROM data_template_data; DELETE FROM data_template_rrd; DELETE FROM graph_templates_item; DELETE FROM graph_templates_graph; DELETE FROM data_input_data');
        $receipt->assertPurged($db);
        $db->exec($sql);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed primary dependent remains');
        $receipt->assertPurged($db);
    }

    public static function lateRows(): iterable
    {
        yield ['INSERT INTO data_template_data VALUES (101,99)'];
        yield ['INSERT INTO data_template_rrd VALUES (102,99)'];
        yield ['INSERT INTO graph_templates_item VALUES (103,99,999)'];
        yield ['INSERT INTO graph_templates_graph VALUES (104,99)'];
        yield ['INSERT INTO data_template_data VALUES (201,12)'];
        yield ['INSERT INTO data_template_rrd VALUES (202,12)'];
        yield ['INSERT INTO graph_templates_item VALUES (203,11,999)'];
        yield ['INSERT INTO graph_templates_graph VALUES (204,11)'];
        yield ['INSERT INTO graph_templates_item VALUES (205,99,102)'];
        yield ['INSERT INTO data_input_data (data_template_data_id) VALUES (101)'];
    }
}
