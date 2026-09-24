<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceRemoval;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorReplication;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceCollectorReplicationTest extends TestCase
{
    #[DataProvider('missingIdentities')]
    public function testMissingIdentityCannotPassEvenWithEmptyRows(string $table, string $column, bool $onPrimary): void
    {
        $complete = $this->connection();
        $incomplete = $this->connection($table, $column);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Collector schema lacks required identity');
        (new DeviceCollectorReplication())->verifyTarget($onPrimary ? $incomplete : $complete, $onPrimary ? $complete : $incomplete, 7);
    }

    public static function missingIdentities(): iterable
    {
        $columns = [
            'host' => ['id', 'poller_id', 'host_template_id', 'hostname', 'disabled', 'deleted'],
            'host_graph' => ['host_id', 'graph_template_id'],
            'host_snmp_query' => ['host_id', 'snmp_query_id'],
            'host_snmp_cache' => ['host_id', 'snmp_query_id', 'field_name', 'snmp_index'],
            'poller_item' => ['host_id', 'poller_id', 'local_data_id', 'rrd_name'],
            'poller_reindex' => ['host_id', 'data_query_id', 'arg1'],
            'data_local' => ['id', 'host_id', 'data_template_id', 'snmp_query_id', 'snmp_index'],
            'graph_local' => ['id', 'host_id', 'graph_template_id', 'snmp_query_id', 'snmp_query_graph_id', 'snmp_index'],
            'data_template_data' => ['id', 'local_data_id', 'local_data_template_data_id', 'data_template_id', 'data_input_id'],
            'data_template_rrd' => ['id', 'local_data_id', 'local_data_template_rrd_id', 'data_template_id', 'data_source_name', 'data_input_field_id'],
            'graph_templates_item' => ['id', 'local_graph_id', 'local_graph_template_item_id', 'graph_template_id', 'task_item_id'],
            'data_input_data' => ['data_template_data_id', 'data_input_field_id'],
        ];
        foreach ($columns as $table => $required) {
            foreach ($required as $column) {
                foreach ([false, true] as $onPrimary) {
                    yield "$table.$column:" . ($onPrimary ? 'primary' : 'target') => [$table, $column, $onPrimary];
                }
            }
        }
    }

    public function testOptionalSchemaDifferencesRemainCompatible(): void
    {
        (new DeviceCollectorReplication())->verifyTarget($this->connection(), $this->connection('host', 'notes'), 7);
        $this->addToAssertionCount(1);
    }

    public function testReviewedCleanupCannotFollowAssociationsReassignedToAnotherDevice(): void
    {
        $db = $this->removalDatabase();
        $db->exec('INSERT INTO data_local VALUES (12, 99); INSERT INTO graph_local VALUES (11, 99); INSERT INTO data_template_data VALUES (101, 12); INSERT INTO data_template_rrd VALUES (102, 12); INSERT INTO data_input_data VALUES (101,1); INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES (11,102)');
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [11], [12]);
        $db->beginTransaction();
        $replication = new DeviceCollectorReplication();
        $replication->purgeReviewedDependents($db, $snapshot);
        foreach (['data_template_data', 'data_template_rrd', 'data_input_data', 'graph_templates_item'] as $table) {
            self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn(), "$table followed a reassigned parent");
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed collector association remains');
        $replication->verifyPurged($db, 7, $snapshot);
    }

    public function testReviewedCleanupRequiresWorkerOwnedTransaction(): void
    {
        $db = $this->removalDatabase();
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [], []);
        $this->expectException(\LogicException::class);
        (new DeviceCollectorReplication())->purgeReviewedDependents($db, $snapshot);
    }

    public function testCollectorRemovalScopeRequiresTransactionAndRejectsScopeChanges(): void
    {
        $db = $this->removalDatabase();
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [11], [12]);
        $replication = new DeviceCollectorReplication();
        try {
            $replication->assertRemovalScope($db, $snapshot);
            self::fail('Collector scope was checked outside its transaction');
        } catch (\LogicException $error) {
            self::assertSame('Collector removal scope requires a transaction', $error->getMessage());
        }
        $db->exec('INSERT INTO graph_local VALUES (11,7); INSERT INTO data_local VALUES (12,7)');
        $db->beginTransaction();
        $replication->assertRemovalScope($db, $snapshot);
        $db->exec('INSERT INTO graph_local VALUES (13,7)');
        try {
            $replication->assertRemovalScope($db, $snapshot);
            self::fail('Changed collector scope was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('Collector removal scope changed', $error->getMessage());
        }
    }

    #[DataProvider('lateDependentDuringCleanup')]
    public function testLateReviewedChildIsNeverDeletedByParentScope(string $trigger, string $table): void
    {
        $db = $this->removalDatabase();
        $db->exec('INSERT INTO data_local VALUES (12,7); INSERT INTO graph_local VALUES (11,7); INSERT INTO data_template_data VALUES (101,12); INSERT INTO data_template_rrd VALUES (102,12); INSERT INTO graph_templates_item VALUES (103,11,102); INSERT INTO poller_output VALUES (12); ' . $trigger);
        $db->beginTransaction();
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [11], [12]);
        try {
            (new DeviceCollectorReplication())->purgeReviewedDependents($db, $snapshot);
            self::fail('Late dependent was silently included in reviewed cleanup');
        } catch (\RuntimeException $error) {
            self::assertSame('Collector dependent scope changed', $error->getMessage());
            self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM $table WHERE id = 104")->fetchColumn());
        }
    }

    public static function lateDependentDuringCleanup(): iterable
    {
        yield ['CREATE TRIGGER insert_late_template AFTER DELETE ON poller_output BEGIN INSERT INTO data_template_data VALUES (104,12); END', 'data_template_data'];
        yield ['CREATE TRIGGER insert_late_graph_item AFTER DELETE ON poller_output BEGIN INSERT INTO graph_templates_item VALUES (104,11,102); END', 'graph_templates_item'];
    }

    public function testReassignedInputFieldCannotEscapeReviewedDeletion(): void
    {
        $db = $this->removalDatabase();
        $db->exec('INSERT INTO data_local VALUES (12,7); INSERT INTO data_template_data VALUES (101,12); INSERT INTO data_input_data VALUES (101,1); INSERT INTO poller_output VALUES (12)');
        $db->exec('CREATE TRIGGER reassign_input AFTER DELETE ON poller_output BEGIN UPDATE data_input_data SET data_template_data_id = 999 WHERE data_template_data_id = 101; END');
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [], [12]);
        $db->beginTransaction();
        try {
            (new DeviceCollectorReplication())->purgeReviewedDependents($db, $snapshot);
            self::fail('Reassigned input field escaped reviewed cleanup');
        } catch (\RuntimeException $error) {
            self::assertSame('Previous collector input field cleanup failed', $error->getMessage());
            self::assertSame(999, (int) $db->query('SELECT data_template_data_id FROM data_input_data')->fetchColumn());
        } finally {
            $db->rollBack();
        }
        self::assertSame(101, (int) $db->query('SELECT data_template_data_id FROM data_input_data')->fetchColumn());
    }

    #[DataProvider('lateDependents')]
    public function testFinalVerificationRejectsDependentsInsertedAfterCleanup(string $insert): void
    {
        $db = $this->removalDatabase();
        $db->exec('INSERT INTO data_local VALUES (12,7); INSERT INTO graph_local VALUES (11,7); INSERT INTO data_template_data VALUES (101,12); INSERT INTO data_input_data VALUES (101,1)');
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [11], [12]);
        $db->beginTransaction();
        $replication = new DeviceCollectorReplication();
        $receipt = $replication->purgeReviewedDependents($db, $snapshot);
        self::assertSame(['templates' => [101], 'rrds' => [], 'graph_items' => [], 'tree_items' => [], 'report_items' => [], 'poller_items' => []], $receipt);
        $db->exec('DELETE FROM data_local; DELETE FROM graph_local');
        $db->exec($insert);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed collector dependents remain');
        $replication->verifyPurged($db, 7, $snapshot, $receipt);
    }

    public static function lateDependents(): iterable
    {
        yield ['INSERT INTO poller_output VALUES (12)'];
        yield ['INSERT INTO poller_output_boost VALUES (12)'];
        yield ['INSERT INTO data_template_data VALUES (102,12)'];
        yield ['INSERT INTO data_template_rrd VALUES (103,12)'];
        yield ['INSERT INTO data_input_data VALUES (101,1)'];
        yield ['INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES (11,102)'];
    }

    #[DataProvider('referenceTiming')]
    public function testRemoteSharedReferencesFailBeforeCleanupOrDuringFinalVerification(bool $late): void
    {
        $db = $this->removalDatabase();
        $db->exec('INSERT INTO data_local VALUES (12,7); INSERT INTO graph_local VALUES (11,7); INSERT INTO data_template_data VALUES (101,12); INSERT INTO data_template_rrd VALUES (102,12)');
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [11], [12]);
        $replication = new DeviceCollectorReplication();
        if ($late) {
            $db->beginTransaction();
            $receipt = $replication->purgeReviewedDependents($db, $snapshot);
            self::assertSame([102], $receipt['rrds']);
            $db->exec('DELETE FROM data_local; DELETE FROM graph_local');
        }
        $db->exec('INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES (99,102)');
        if (!$late) {
            $db->beginTransaction();
        }
        try {
            if ($late) {
                $replication->verifyPurged($db, 7, $snapshot, $receipt);
            } else {
                $replication->purgeReviewedDependents($db, $snapshot);
            }
            self::fail('Shared collector reference was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('Collector data is shared with an unreviewed graph', $error->getMessage());
            self::assertSame($late ? 0 : 1, (int) $db->query('SELECT COUNT(*) FROM data_template_rrd')->fetchColumn());
            self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM graph_templates_item')->fetchColumn());
        }
    }

    public static function referenceTiming(): iterable
    {
        yield 'existing outside graph' => [false];
        yield 'outside reference added after cleanup' => [true];
    }

    #[DataProvider('reassignedDependents')]
    public function testFinalVerificationRejectsReassignedCapturedIdentities(string $insert): void
    {
        $db = $this->removalDatabase();
        $db->exec('INSERT INTO data_local VALUES (12,7); INSERT INTO graph_local VALUES (11,7); INSERT INTO data_template_data VALUES (101,12); INSERT INTO data_template_rrd VALUES (102,12); INSERT INTO graph_templates_item VALUES (103,11,102)');
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [11], [12]);
        $db->beginTransaction();
        $replication = new DeviceCollectorReplication();
        $receipt = $replication->purgeReviewedDependents($db, $snapshot);
        self::assertSame(['templates' => [101], 'rrds' => [102], 'graph_items' => [103], 'tree_items' => [], 'report_items' => [], 'poller_items' => []], $receipt);
        $db->exec('DELETE FROM data_local; DELETE FROM graph_local');
        $db->exec($insert);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed collector dependent identity remains');
        $replication->verifyPurged($db, 7, $snapshot, $receipt);
    }

    public static function reassignedDependents(): iterable
    {
        yield ['INSERT INTO data_template_data VALUES (101,99)'];
        yield ['INSERT INTO data_template_rrd VALUES (102,99)'];
        yield ['INSERT INTO graph_templates_item VALUES (103,99,999)'];
    }

    public function testFinalTransactionalChecksUseCurrentReads(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('inTransaction')->willReturn(true);
        $db->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $db->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            self::assertStringEndsWith(' FOR UPDATE', $sql);
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchColumn')->willReturn(0);
            return $statement;
        });
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [11], [12]);
        (new DeviceCollectorReplication())->verifyPurged($db, 7, $snapshot, ['templates' => [101], 'rrds' => [102], 'graph_items' => [103]]);
    }

    #[DataProvider('placementIdentities')]
    public function testReassignedPlacementCannotEscapeCapturedReceipt(string $table, string $insert): void
    {
        $db = $this->removalDatabase();
        $db->exec($insert);
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [], []);
        $db->beginTransaction();
        $replication = new DeviceCollectorReplication();
        $receipt = $replication->purgeReviewedDependents($db, $snapshot);
        $db->exec("UPDATE $table SET host_id = 99 WHERE host_id = 7");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed collector placement identity remains');
        $replication->verifyPurged($db, 7, $snapshot, $receipt);
    }
    public static function placementIdentities(): iterable
    {
        yield ['graph_tree_items', 'INSERT INTO graph_tree_items VALUES (7,101)'];
        yield ['reports_items', 'INSERT INTO reports_items VALUES (7,102)'];
        yield ['poller_item', "INSERT INTO poller_item VALUES (7,12,'traffic')"];
    }

    #[DataProvider('outputTables')]
    public function testRemoteOutputCleanupStaysWithinReviewedOwnership(string $table): void
    {
        $db = $this->removalDatabase();
        $db->exec("INSERT INTO data_local VALUES (12,7),(13,99); INSERT INTO $table VALUES (12),(13)");
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [], [12,13]);
        $db->beginTransaction();
        (new DeviceCollectorReplication())->purgeReviewedDependents($db, $snapshot);
        self::assertSame([13], array_map('intval', $db->query("SELECT local_data_id FROM $table")->fetchAll(PDO::FETCH_COLUMN)));
    }

    public static function outputTables(): iterable
    {
        yield ['poller_output'];
        yield ['poller_output_boost'];
    }

    private function removalDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        foreach ([
            'host' => 'id INTEGER', 'host_graph' => 'host_id INTEGER', 'host_snmp_query' => 'host_id INTEGER',
            'host_snmp_cache' => 'host_id INTEGER', 'poller_item' => 'host_id INTEGER, local_data_id INTEGER, rrd_name TEXT', 'poller_reindex' => 'host_id INTEGER',
            'graph_tree_items' => 'host_id INTEGER, id INTEGER', 'reports_items' => 'host_id INTEGER, id INTEGER', 'poller_command' => 'command TEXT',
            'poller_output' => 'local_data_id INTEGER',
            'poller_output_boost' => 'local_data_id INTEGER',
            'data_local' => 'id INTEGER, host_id INTEGER', 'graph_local' => 'id INTEGER, host_id INTEGER',
            'data_template_data' => 'id INTEGER, local_data_id INTEGER', 'data_template_rrd' => 'id INTEGER, local_data_id INTEGER',
            'data_input_data' => 'data_template_data_id INTEGER, data_input_field_id INTEGER DEFAULT 1', 'graph_templates_item' => 'id INTEGER PRIMARY KEY, local_graph_id INTEGER, task_item_id INTEGER',
        ] as $table => $columns) {
            $db->exec("CREATE TABLE $table ($columns)");
        }
        return $db;
    }

    private function connection(?string $missingTable = null, ?string $missingColumn = null): PDO
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $db = $this->createMock(PDO::class);
        $db->method('query')->willReturnCallback(function (string $query) use ($schema, $missingTable, $missingColumn): PDOStatement {
            self::assertMatchesRegularExpression('/^SHOW COLUMNS FROM [a-z_]+$/', $query);
            $table = substr($query, strlen('SHOW COLUMNS FROM '));
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \((.*?)\n\) ENGINE=/s', $schema, $match));
            preg_match_all('/^  `?([a-zA-Z_][a-zA-Z0-9_]*)`?\s/m', $match[1], $names);
            $columns = $table === $missingTable ? array_values(array_diff($names[1], [$missingColumn])) : $names[1];
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetchAll')->willReturn($columns);
            return $statement;
        });
        $read = $this->createMock(PDOStatement::class);
        $read->method('execute')->willReturn(true);
        $read->method('fetchAll')->willReturn([]);
        $db->method('prepare')->willReturn($read);
        return $db;
    }
}
