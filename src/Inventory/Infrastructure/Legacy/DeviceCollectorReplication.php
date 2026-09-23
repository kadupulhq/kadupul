<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceRemoval;
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
            // Match the legacy transfer's schema compatibility: only columns
            // present on both ends are copied. Identity is always mandatory.
            $columns = static fn(PDO $db): array => $db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
            $common = array_values(array_intersect($columns($primary), $columns($target)));
            $required = match ($table) {
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
            };
            if ($common === [] || array_diff($required, $common) !== []) {
                throw new \RuntimeException('Collector schema lacks required identity');
            }
            foreach ($common as $column) {
                if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $column)) {
                    throw new \RuntimeException('Unexpected collector column');
                }
            }
            $projection = implode(', ', array_map(static fn($column) => "`$column`", $common));
            $read = static function (PDO $db) use ($table, $where, $deviceId, $projection): array {
                $query = $db->prepare("SELECT $projection FROM $table WHERE $where");
                if (!$query->execute([$deviceId])) {
                    throw new \RuntimeException('Collector replication verification failed');
                }
                $rows = [];
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    // Pollers own these observations; they can change immediately
                    // after replication without changing device configuration.
                    $volatile = match ($table) {
                        'host' => ['status', 'status_event_count', 'status_fail_date', 'status_rec_date', 'status_last_error', 'min_time', 'max_time', 'cur_time', 'avg_time', 'polling_time', 'total_polls', 'failed_polls', 'availability', 'last_updated', 'snmp_sysDescr', 'snmp_sysObjectID', 'snmp_sysUpTimeInstance', 'snmp_sysContact', 'snmp_sysName', 'snmp_sysLocation'],
                        'poller_item' => ['rrd_next_step', 'last_updated', 'present'],
                        'host_snmp_cache' => ['last_updated', 'present', 'field_value', 'oid'],
                        'poller_reindex' => ['assert_value', 'present'],
                        default => [],
                    };
                    $row = array_diff_key($row, array_flip($volatile));
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

    public function assertRemovalScope(PDO $source, DeviceRemoval $snapshot): void
    {
        foreach (['graph_local' => $snapshot->graphIds, 'data_local' => $snapshot->dataSourceIds] as $table => $expected) {
            $query = $source->prepare("SELECT id FROM $table WHERE host_id = ? ORDER BY id");
            if (!$query->execute([$snapshot->device->id]) || array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)) !== $expected) {
                throw new \RuntimeException('Collector removal scope changed');
            }
        }
    }

    public function purgeDependents(PDO $source, int $deviceId): void
    {
        foreach ([
            'data_input_data' => 'data_template_data_id IN (SELECT id FROM data_template_data WHERE local_data_id IN (SELECT id FROM data_local WHERE host_id = ?))',
            'data_template_rrd' => 'local_data_id IN (SELECT id FROM data_local WHERE host_id = ?)',
            'data_template_data' => 'local_data_id IN (SELECT id FROM data_local WHERE host_id = ?)',
            'graph_templates_item' => 'local_graph_id IN (SELECT id FROM graph_local WHERE host_id = ?)',
        ] as $table => $where) {
            $delete = $source->prepare("DELETE FROM $table WHERE $where");
            if (!$delete->execute([$deviceId])) {
                throw new \RuntimeException('Previous collector dependent cleanup failed');
            }
            $verify = $source->prepare("SELECT COUNT(*) FROM $table WHERE $where");
            if (!$verify->execute([$deviceId]) || (int) $verify->fetchColumn() !== 0) {
                throw new \RuntimeException('Previous collector dependents remain');
            }
        }
    }

    public function purgeReviewedDependents(PDO $source, DeviceRemoval $snapshot): void
    {
        // Delete children while their ownership can still be discovered. The
        // legacy purge removes the parent rows without foreign-key cascades.
        foreach ([
            'data_input_data' => ['data_template_data_id IN (SELECT id FROM data_template_data WHERE local_data_id IN (%s))', $snapshot->dataSourceIds],
            'data_template_rrd' => ['local_data_id IN (%s)', $snapshot->dataSourceIds],
            'data_template_data' => ['local_data_id IN (%s)', $snapshot->dataSourceIds],
            'graph_templates_item' => ['local_graph_id IN (%s)', $snapshot->graphIds],
        ] as $table => [$where, $ids]) {
            if ($ids === []) {
                continue;
            }
            $where = sprintf($where, implode(',', array_fill(0, count($ids), '?')));
            $delete = $source->prepare("DELETE FROM $table WHERE $where");
            if (!$delete->execute($ids)) {
                throw new \RuntimeException('Previous collector dependent cleanup failed');
            }
            $verify = $source->prepare("SELECT COUNT(*) FROM $table WHERE $where");
            if (!$verify->execute($ids) || (int) $verify->fetchColumn() !== 0) {
                throw new \RuntimeException('Previous collector dependents remain');
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
