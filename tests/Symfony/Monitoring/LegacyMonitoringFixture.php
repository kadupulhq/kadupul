<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;

/**
 * An in-memory SQLite copy of the legacy tables discovery reads, using the
 * column names from cacti.sql (only the columns the adapter needs). SQLite
 * is not the production engine: MariaDB/MySQL typing and collation are not
 * exercised here.
 *
 * Device 42, data query 1 (an indexed SNMP query):
 *   10  index 1 (ifName eth0, ifDescr Ethernet0)  traffic_in + traffic_out, both polled, graph 100
 *   11  index 2 (ifName eth1)                     errors_in, no poller_item row
 *   12  index 3 (ifName dup)                      traffic_in
 *   13  index 4 (ifName dup)                      traffic_in
 *   14  index 5 (old0, cache row no longer present) traffic_in
 *   15  index 9 (no cache rows)                   in_octets, a name that only resembles the metric
 *   17  script data source, one poller_item row with an empty rrd_name for both fields
 * Device 43:
 *   16  index 1 (no cache rows for device 43)     traffic_in
 */
final class LegacyMonitoringFixture
{
    /** The data_input hash of "Get SNMP Data (Indexed)" in cacti.sql. */
    public const SNMP_INDEXED_HASH = 'bf566c869ac6443b0c75d1c32b5a350e';

    public const TABLES = ['data_input', 'snmp_query', 'data_local', 'data_template_data', 'data_template_rrd', 'poller_item', 'host_snmp_cache', 'graph_local', 'graph_templates_item'];

    /** @var list<string> */
    public array $statements = [];

    public readonly Connection $connection;

    public function __construct()
    {
        $statements = &$this->statements;
        $logger = new class ($statements) extends AbstractLogger {
            /** @param list<string> $statements */
            public function __construct(private array &$statements) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->statements[] = (string) $context['sql'];
                }
            }
        };
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new Middleware($logger)]));
        $this->create();
        $this->seed();
        $this->statements = [];
    }

    /** @return array<string, list<array<string, mixed>>> every row of every fixture table */
    public function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY 1, 2, 3');
        }

        return $rows;
    }

    private function create(): void
    {
        foreach ([
            'CREATE TABLE data_input (id INTEGER PRIMARY KEY, hash VARCHAR(32) NOT NULL DEFAULT \'\', name VARCHAR(200) NOT NULL DEFAULT \'\')',
            'CREATE TABLE snmp_query (id INTEGER PRIMARY KEY, hash VARCHAR(32) NOT NULL DEFAULT \'\', name VARCHAR(100) NOT NULL DEFAULT \'\', data_input_id INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE data_local (id INTEGER PRIMARY KEY, data_template_id INTEGER NOT NULL DEFAULT 0, host_id INTEGER NOT NULL DEFAULT 0, snmp_query_id INTEGER NOT NULL DEFAULT 0, snmp_index VARCHAR(255) NOT NULL DEFAULT \'\', orphan INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE data_template_data (id INTEGER PRIMARY KEY, local_data_template_data_id INTEGER NOT NULL DEFAULT 0, local_data_id INTEGER NOT NULL DEFAULT 0, data_template_id INTEGER NOT NULL DEFAULT 0, data_input_id INTEGER NOT NULL DEFAULT 0, name_cache VARCHAR(255) NOT NULL DEFAULT \'\', data_source_path VARCHAR(255) DEFAULT \'\', active CHAR(2) DEFAULT NULL, rrd_step INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, local_data_template_rrd_id INTEGER NOT NULL DEFAULT 0, local_data_id INTEGER NOT NULL DEFAULT 0, data_template_id INTEGER NOT NULL DEFAULT 0, rrd_maximum VARCHAR(20) NOT NULL DEFAULT \'0\', rrd_minimum VARCHAR(20) NOT NULL DEFAULT \'0\', rrd_heartbeat INTEGER NOT NULL DEFAULT 0, data_source_type_id INTEGER NOT NULL DEFAULT 0, data_source_name VARCHAR(19) NOT NULL DEFAULT \'\', UNIQUE (local_data_id, data_source_name, data_template_id))',
            'CREATE TABLE poller_item (local_data_id INTEGER NOT NULL DEFAULT 0, poller_id INTEGER NOT NULL DEFAULT 1, host_id INTEGER NOT NULL DEFAULT 0, action INTEGER NOT NULL DEFAULT 1, rrd_name VARCHAR(19) NOT NULL DEFAULT \'\', rrd_path VARCHAR(255) NOT NULL DEFAULT \'\', rrd_num INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (local_data_id, rrd_name))',
            'CREATE TABLE host_snmp_cache (host_id INTEGER NOT NULL DEFAULT 0, snmp_query_id INTEGER NOT NULL DEFAULT 0, field_name VARCHAR(50) NOT NULL DEFAULT \'\', field_value VARCHAR(512) DEFAULT NULL, snmp_index VARCHAR(255) NOT NULL DEFAULT \'\', oid TEXT NOT NULL, present INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (host_id, snmp_query_id, field_name, snmp_index))',
            'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, graph_template_id INTEGER NOT NULL DEFAULT 0, host_id INTEGER NOT NULL DEFAULT 0, snmp_query_id INTEGER NOT NULL DEFAULT 0, snmp_index VARCHAR(255) NOT NULL DEFAULT \'\')',
            'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, local_graph_id INTEGER NOT NULL DEFAULT 0, graph_template_id INTEGER NOT NULL DEFAULT 0, task_item_id INTEGER NOT NULL DEFAULT 0, sequence INTEGER NOT NULL DEFAULT 0)',
        ] as $ddl) {
            $this->connection->executeStatement($ddl);
        }
    }

    private function seed(): void
    {
        $db = $this->connection;
        $db->insert('data_input', ['id' => 2, 'hash' => self::SNMP_INDEXED_HASH, 'name' => 'Get SNMP Data (Indexed)']);
        $db->insert('snmp_query', ['id' => 1, 'name' => 'SNMP - Interface Statistics', 'data_input_id' => 2]);

        $cache = [
            ['1', 'ifName', 'eth0', 1], ['1', 'ifDescr', 'Ethernet0', 1], ['1', 'ifIndex', '1', 1],
            ['2', 'ifName', 'eth1', 1], ['2', 'ifIndex', '2', 1],
            ['3', 'ifName', 'dup', 1], ['4', 'ifName', 'dup', 1],
            ['5', 'ifName', 'old0', 0],
        ];
        foreach ($cache as [$index, $field, $value, $present]) {
            $db->insert('host_snmp_cache', ['host_id' => 42, 'snmp_query_id' => 1, 'field_name' => $field, 'field_value' => $value, 'snmp_index' => $index, 'oid' => '.1.3.6.1.2.1.31.1.1.1.1.' . $index, 'present' => $present]);
        }

        $sources = [
            // id, host, query, index, path, [[rrd id, name, type id]], poller rrd names
            [10, 42, 1, '1', '<path_rra>/core-sw-01_traffic_in_10.rrd', [[100, 'traffic_in', 2], [101, 'traffic_out', 2]], ['traffic_in', 'traffic_out']],
            [11, 42, 1, '2', '<path_rra>/core-sw-01_errors_in_11.rrd', [[110, 'errors_in', 3]], []],
            [12, 42, 1, '3', '<path_rra>/core-sw-01_traffic_in_12.rrd', [[120, 'traffic_in', 2]], ['traffic_in']],
            [13, 42, 1, '4', '<path_rra>/core-sw-01_traffic_in_13.rrd', [[130, 'traffic_in', 2]], ['traffic_in']],
            [14, 42, 1, '5', '<path_rra>/core-sw-01_traffic_in_14.rrd', [[140, 'traffic_in', 2]], ['traffic_in']],
            [15, 42, 1, '9', '<path_rra>/core-sw-01_in_octets_15.rrd', [[150, 'in_octets', 2]], ['in_octets']],
            [16, 43, 1, '1', '<path_rra>/edge-01_traffic_in_16.rrd', [[160, 'traffic_in', 2]], ['traffic_in']],
            [17, 42, 0, '', '<path_rra>/core-sw-01_load_1min_17.rrd', [[170, 'load_1min', 1], [171, 'load_5min', 1]], ['']],
        ];
        foreach ($sources as [$id, $host, $query, $index, $path, $fields, $polled]) {
            $db->insert('data_local', ['id' => $id, 'data_template_id' => 5, 'host_id' => $host, 'snmp_query_id' => $query, 'snmp_index' => $index]);
            $db->insert('data_template_data', ['id' => $id, 'local_data_id' => $id, 'data_template_id' => 5, 'data_source_path' => $path, 'active' => 'on', 'rrd_step' => 300]);
            foreach ($fields as [$rrdId, $name, $type]) {
                $db->insert('data_template_rrd', ['id' => $rrdId, 'local_data_id' => $id, 'data_template_id' => 5, 'data_source_name' => $name, 'data_source_type_id' => $type, 'rrd_heartbeat' => 600]);
            }
            foreach ($polled as $rrdName) {
                $db->insert('poller_item', ['local_data_id' => $id, 'host_id' => $host, 'rrd_name' => $rrdName, 'rrd_path' => str_replace('<path_rra>', '/var/lib/rra', $path), 'rrd_num' => $rrdName === '' ? count($fields) : 1]);
            }
        }
        // Template rows (local_data_id 0) must never be mistaken for a device's data source.
        $db->insert('data_template_rrd', ['id' => 900, 'local_data_id' => 0, 'data_template_id' => 5, 'data_source_name' => 'traffic_in', 'data_source_type_id' => 2]);
        $db->insert('data_template_data', ['id' => 900, 'local_data_id' => 0, 'data_template_id' => 5, 'data_source_path' => '']);

        $db->insert('graph_local', ['id' => 100, 'graph_template_id' => 2, 'host_id' => 42, 'snmp_query_id' => 1, 'snmp_index' => '1']);
        $db->insert('graph_templates_item', ['id' => 1000, 'local_graph_id' => 100, 'graph_template_id' => 2, 'task_item_id' => 100, 'sequence' => 1]);
        $db->insert('graph_templates_item', ['id' => 1001, 'local_graph_id' => 100, 'graph_template_id' => 2, 'task_item_id' => 101, 'sequence' => 2]);
        $db->insert('graph_templates_item', ['id' => 1002, 'local_graph_id' => 100, 'graph_template_id' => 2, 'task_item_id' => 100, 'sequence' => 3]);
        // A graph template item (local_graph_id 0) is not a device graph.
        $db->insert('graph_templates_item', ['id' => 1003, 'local_graph_id' => 0, 'graph_template_id' => 2, 'task_item_id' => 100, 'sequence' => 1]);
    }
}
