<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use PDO;

/** Verifies the legacy transfer inside the isolated worker; never exports row data. */
final class DeviceCollectorReplication
{
    public function verifyTarget(PDO $primary, PDO $target, int $deviceId): void
    {
        $queries = [
            'host' => 'id = ?',
            'host_graph' => 'host_id = ?',
            'host_snmp_query' => 'host_id = ?',
            'host_snmp_cache' => 'host_id = ?',
            'poller_item' => 'host_id = ?',
            'poller_reindex' => 'host_id = ?',
            'data_local' => 'host_id = ?',
            'graph_local' => 'host_id = ?',
            'data_template_data' => 'local_data_id IN (SELECT id FROM data_local WHERE host_id = ?)',
            'data_template_rrd' => 'local_data_id IN (SELECT id FROM data_local WHERE host_id = ?)',
            'graph_templates_item' => 'local_graph_id IN (SELECT id FROM graph_local WHERE host_id = ?)',
            'data_input_data' => 'data_template_data_id IN (SELECT id FROM data_template_data WHERE local_data_id IN (SELECT id FROM data_local WHERE host_id = ?))',
        ];
        foreach ($queries as $table => $where) {
            $read = static function (PDO $db) use ($table, $where, $deviceId): array {
                $query = $db->prepare("SELECT * FROM $table WHERE $where");
                if (!$query->execute([$deviceId])) {
                    throw new \RuntimeException('Collector replication verification failed');
                }
                $rows = [];
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    ksort($row);
                    $rows[] = json_encode(array_map(static fn($value) => $value === null ? null : (string) $value, $row), JSON_THROW_ON_ERROR);
                }
                sort($rows, SORT_STRING);
                return $rows;
            };
            if ($read($primary) !== $read($target)) {
                throw new \RuntimeException('Collector replication could not be confirmed');
            }
        }
    }

    public function verifyPurged(PDO $source, int $deviceId): void
    {
        foreach (['host' => 'id', 'host_graph' => 'host_id', 'host_snmp_query' => 'host_id', 'host_snmp_cache' => 'host_id', 'poller_item' => 'host_id', 'poller_reindex' => 'host_id', 'graph_tree_items' => 'host_id', 'reports_items' => 'host_id', 'data_local' => 'host_id', 'graph_local' => 'host_id'] as $table => $column) {
            $query = $source->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
            if (!$query->execute([$deviceId]) || (int) $query->fetchColumn() !== 0) {
                throw new \RuntimeException('Previous collector cleanup could not be confirmed');
            }
        }
        $query = $source->prepare("SELECT COUNT(*) FROM poller_command WHERE SUBSTRING_INDEX(command, ':', 1) = ?");
        if (!$query->execute([(string) $deviceId]) || (int) $query->fetchColumn() !== 0) {
            throw new \RuntimeException('Previous collector commands remain');
        }
    }
}
