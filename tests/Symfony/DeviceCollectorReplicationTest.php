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
        $db = new PDO('sqlite::memory:');
        foreach ([
            'host' => 'id INTEGER', 'host_graph' => 'host_id INTEGER', 'host_snmp_query' => 'host_id INTEGER',
            'host_snmp_cache' => 'host_id INTEGER', 'poller_item' => 'host_id INTEGER', 'poller_reindex' => 'host_id INTEGER',
            'graph_tree_items' => 'host_id INTEGER', 'reports_items' => 'host_id INTEGER', 'poller_command' => 'command TEXT',
            'data_local' => 'id INTEGER, host_id INTEGER', 'graph_local' => 'id INTEGER, host_id INTEGER',
            'data_template_data' => 'id INTEGER, local_data_id INTEGER', 'data_template_rrd' => 'id INTEGER, local_data_id INTEGER',
            'data_input_data' => 'data_template_data_id INTEGER', 'graph_templates_item' => 'local_graph_id INTEGER',
        ] as $table => $columns) {
            $db->exec("CREATE TABLE $table ($columns)");
        }
        $db->exec('INSERT INTO data_local VALUES (12, 99); INSERT INTO graph_local VALUES (11, 99); INSERT INTO data_template_data VALUES (101, 12); INSERT INTO data_template_rrd VALUES (102, 12); INSERT INTO data_input_data VALUES (101); INSERT INTO graph_templates_item VALUES (11)');
        $snapshot = new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), [11], [12]);
        $replication = new DeviceCollectorReplication();
        $replication->purgeReviewedDependents($db, $snapshot);
        foreach (['data_template_data', 'data_template_rrd', 'data_input_data', 'graph_templates_item'] as $table) {
            self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn(), "$table followed a reassigned parent");
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reviewed collector association remains');
        $replication->verifyPurged($db, 7, $snapshot);
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
