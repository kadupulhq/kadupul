<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Kadupul\Monitoring\Application\Port\WriterOwnershipProbe;
use Kadupul\Monitoring\Domain\RrdDataSourceType;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Monitoring\Domain\WriterOwnership;

/**
 * Reads a device's legacy data sources, their fields, writers, index cache
 * and graphs. It issues SELECT statements with bound values only and has no
 * method that writes.
 *
 * Every field defined in data_template_rrd is owned by the legacy poller,
 * whether or not a poller_item row currently exists: a missing row is more
 * often a poller cache waiting to be rebuilt than a field nobody writes.
 */
final class LegacyDataSourceDiscovery implements WriterOwnershipProbe
{
    /** data_input.hash of "Get SNMP Data (Indexed)" in cacti.sql; ids can differ between installs. */
    private const SNMP_INDEXED_INPUT = 'bf566c869ac6443b0c75d1c32b5a350e';

    /** data_source_type_id values a binding can expect; 5 is COMPUTE, 6 and 7 are DCOUNTER and DDERIVE. */
    private const TYPES = [1 => RrdDataSourceType::Gauge, 2 => RrdDataSourceType::Counter, 3 => RrdDataSourceType::Derive, 4 => RrdDataSourceType::Absolute];

    /** Cache fields whose value names an interface; the same name on two indexes is not an identity. */
    public const IDENTITY_FIELDS = ['interface' => ['ifName', 'ifDescr', 'ifIndex']];

    public function __construct(private readonly Connection $database) {}

    /** @return list<LegacyDataSource> ordered by local data id */
    public function dataSources(int $deviceId): array
    {
        $rows = $this->database->fetchAllAssociative(
            'SELECT dl.id, dl.snmp_query_id, dl.snmp_index, dtd.data_source_path, di.hash AS input_hash
            FROM data_local dl
            LEFT JOIN data_template_data dtd ON dtd.local_data_id = dl.id
            LEFT JOIN snmp_query sq ON sq.id = dl.snmp_query_id
            LEFT JOIN data_input di ON di.id = sq.data_input_id
            WHERE dl.host_id = ? AND dl.id > 0
            ORDER BY dl.id',
            [$deviceId],
        );
        $fields = [];
        foreach ($this->database->fetchAllAssociative(
            'SELECT dtr.local_data_id, dtr.data_source_name, dtr.data_source_type_id
            FROM data_template_rrd dtr
            INNER JOIN data_local dl ON dl.id = dtr.local_data_id
            WHERE dl.host_id = ?
            ORDER BY dtr.local_data_id, dtr.data_source_name',
            [$deviceId],
        ) as $field) {
            $fields[(int) $field['local_data_id']][] = $field;
        }
        $writers = [];
        foreach ($this->database->fetchAllAssociative(
            'SELECT pi.local_data_id, pi.rrd_name
            FROM poller_item pi
            INNER JOIN data_local dl ON dl.id = pi.local_data_id
            WHERE dl.host_id = ?',
            [$deviceId],
        ) as $item) {
            $writers[(int) $item['local_data_id']][(string) $item['rrd_name']] = true;
        }
        $hints = [];
        foreach ($this->cache($deviceId) as $entry) {
            if ($entry['present']) {
                $hints[$entry['query'] . '|' . $entry['index']][$entry['field']] = $entry['value'];
            }
        }
        $graphs = [];
        foreach ($this->database->fetchAllAssociative(
            'SELECT DISTINCT dtr.local_data_id, gti.local_graph_id
            FROM graph_templates_item gti
            INNER JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id
            INNER JOIN data_local dl ON dl.id = dtr.local_data_id
            WHERE dl.host_id = ? AND gti.local_graph_id > 0
            ORDER BY dtr.local_data_id, gti.local_graph_id',
            [$deviceId],
        ) as $graph) {
            $graphs[(int) $graph['local_data_id']][] = (int) $graph['local_graph_id'];
        }

        $sources = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $query = (int) $row['snmp_query_id'];
            $polled = $writers[$id] ?? [];
            $resourceHints = $query > 0 ? ($hints[$query . '|' . $row['snmp_index']] ?? []) : [];
            ksort($resourceHints);
            $sources[] = new LegacyDataSource(
                $deviceId,
                $id,
                $query,
                (string) $row['snmp_index'],
                $row['input_hash'] === self::SNMP_INDEXED_INPUT ? 'snmp' : 'legacy',
                $resourceHints,
                array_map(static fn(array $f): LegacyDataSourceField => new LegacyDataSourceField(
                    (string) $f['data_source_name'],
                    (int) $f['data_source_type_id'],
                    self::TYPES[(int) $f['data_source_type_id']] ?? null,
                    // A multi-output script writes every field of its file through one row with an empty rrd_name.
                    isset($polled[(string) $f['data_source_name']]) || isset($polled['']),
                ), $fields[$id] ?? []),
                (string) $row['data_source_path'],
                $graphs[$id] ?? [],
            );
        }

        return $sources;
    }

    /** @return list<IdentityConcern> ordered by local data id */
    public function identityConcerns(int $deviceId): array
    {
        $indexesByName = [];
        foreach ($this->cache($deviceId) as $entry) {
            if ($entry['present'] && in_array($entry['field'], ['ifName', 'ifDescr'], true)) {
                $indexesByName[$entry['query'] . '|' . $entry['field'] . '|' . $entry['value']][$entry['index']] = true;
            }
        }
        $concerns = [];
        foreach ($this->dataSources($deviceId) as $source) {
            $id = $source->localDataId;
            if ($source->dataQueryId > 0) {
                if ($source->resourceHints === []) {
                    $concerns[] = new IdentityConcern($id, IdentityConcern::REINDEXED_SNMP_INDEX, sprintf('Index %s of data query %d has no present cache entry; the device may have been reindexed.', $source->index, $source->dataQueryId));
                }
                foreach (['ifName', 'ifDescr'] as $field) {
                    $value = $source->resourceHints[$field] ?? null;
                    if ($value !== null && count($indexesByName[$source->dataQueryId . '|' . $field . '|' . $value] ?? []) > 1) {
                        $concerns[] = new IdentityConcern($id, IdentityConcern::DUPLICATE_RESOURCE_NAME, sprintf('%s %s is reported by more than one index.', $field, $value));
                        break;
                    }
                }
            }
            if ($source->fields !== [] && !array_filter($source->fields, static fn(LegacyDataSourceField $f): bool => $f->legacyWriter)) {
                $concerns[] = new IdentityConcern($id, IdentityConcern::NO_POLLER_ITEM, 'No poller_item row writes this data source; the poller cache may need rebuilding.');
            }
            if (count($source->fields) > 1) {
                $concerns[] = new IdentityConcern($id, IdentityConcern::MULTIPLE_FIELDS_PER_RRD, sprintf('The file holds %d fields: %s.', count($source->fields), implode(', ', array_map(static fn(LegacyDataSourceField $f): string => $f->name, $source->fields))));
            }
        }

        return $concerns;
    }

    public function ownership(StorageField $field): WriterOwnership
    {
        $defined = $this->database->fetchOne(
            'SELECT COUNT(*) FROM data_template_rrd WHERE local_data_id = ? AND data_source_name = ?',
            [$field->localDataId, $field->name->value],
        );

        return (int) $defined > 0 ? WriterOwnership::LegacyPoller : WriterOwnership::Unassigned;
    }

    /**
     * Every cache row for the device, present or not, so callers can tell a
     * vanished index from one that never existed.
     *
     * @return list<array{query: int, index: string, field: string, value: string, present: bool}>
     */
    public function cache(int $deviceId): array
    {
        return array_map(static fn(array $row): array => [
            'query' => (int) $row['snmp_query_id'],
            'index' => (string) $row['snmp_index'],
            'field' => (string) $row['field_name'],
            'value' => (string) $row['field_value'],
            'present' => (int) $row['present'] > 0,
        ], $this->database->fetchAllAssociative(
            'SELECT snmp_query_id, snmp_index, field_name, field_value, present
            FROM host_snmp_cache
            WHERE host_id = ?
            ORDER BY snmp_query_id, snmp_index, field_name',
            [$deviceId],
        ));
    }
}
