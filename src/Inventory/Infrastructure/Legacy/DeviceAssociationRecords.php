<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceAssociations;
use PDO;

final class DeviceAssociationRecords
{
    public function snapshot(PDO $db, array $row, string $kind, bool $lock = false): DeviceAssociations
    {
        if ($kind === 'query') {
            $query = $db->prepare("SELECT hq.snmp_query_id, COALESCE(sq.name, '') AS name, hq.reindex_method FROM host_snmp_query hq LEFT JOIN snmp_query sq ON sq.id = hq.snmp_query_id WHERE hq.host_id = ? ORDER BY hq.snmp_query_id" . ($lock ? ' FOR UPDATE' : ''));
            $rows = $this->read($query, (int) $row['id'], PDO::FETCH_ASSOC);
            $items = $methods = [];
            foreach ($rows as $item) {
                $items[(int) $item['snmp_query_id']] = $item['name'];
                $methods[(int) $item['snmp_query_id']] = (int) $item['reindex_method'];
            }
            return new DeviceAssociations((int) $row['id'], (string) $row['description'], (int) $row['site_id'], (int) $row['poller_id'], (int) $row['host_template_id'], $items, $methods, $kind, (int) $row['snmp_version']);
        }
        if ($kind !== 'graph') {
            throw new \InvalidArgumentException('Invalid association kind.');
        }
        $query = $db->prepare("SELECT hg.graph_template_id, COALESCE(gt.name, '') AS name FROM host_graph hg LEFT JOIN graph_templates gt ON gt.id = hg.graph_template_id WHERE hg.host_id = ? ORDER BY hg.graph_template_id" . ($lock ? ' FOR UPDATE' : ''));
        $items = $this->read($query, (int) $row['id'], PDO::FETCH_KEY_PAIR);
        return new DeviceAssociations((int) $row['id'], (string) $row['description'], (int) $row['site_id'], (int) $row['poller_id'], (int) $row['host_template_id'], $items, [], 'graph', (int) $row['snmp_version']);
    }

    private function read(\PDOStatement|false $query, int $deviceId, int $mode): array
    {
        if ($query === false || !$query->execute([$deviceId])) {
            throw new \RuntimeException('Association snapshot unavailable');
        }
        $rows = $query->fetchAll($mode);
        if (!is_array($rows) || $query->errorCode() !== '00000') {
            throw new \RuntimeException('Association snapshot unavailable');
        }
        return $rows;
    }
    public function available(PDO $db, string $kind, bool $lock = false): array
    {
        if ($kind === 'query') {
            return $db->query("SELECT id, COALESCE(name, '') AS name FROM snmp_query WHERE id > 0 ORDER BY id" . ($lock ? ' LOCK IN SHARE MODE' : ''))->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        if ($kind !== 'graph') {
            throw new \InvalidArgumentException('Invalid association kind.');
        }
        // Preserve the legacy catalog: query-owned templates are attached via data queries.
        return $db->query("SELECT DISTINCT gt.id, gt.name FROM graph_templates gt LEFT JOIN snmp_query_graph sqg ON sqg.graph_template_id = gt.id INNER JOIN graph_templates_item gti ON gti.graph_template_id = gt.id INNER JOIN data_template_rrd dtr ON gti.task_item_id = dtr.id INNER JOIN data_template_data dtd ON dtd.data_template_id = dtr.data_template_id WHERE sqg.name IS NULL AND gti.local_graph_id = 0 AND dtr.local_data_id = 0 ORDER BY gt.id" . ($lock ? ' LOCK IN SHARE MODE' : ''))->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
