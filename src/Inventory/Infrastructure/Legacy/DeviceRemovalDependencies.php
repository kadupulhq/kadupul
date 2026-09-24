<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

final class DeviceRemovalDependencies
{
    /** Deleted parents are allowed; surviving reviewed parents must retain ownership. */
    public static function ownsRemaining(\PDO $db, array $reviewed): bool
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('Ownership checks require a transaction');
        }
        foreach ($reviewed as $hostId => $scope) {
            foreach (['graph_local' => 'graphs', 'data_local' => 'data_sources'] as $table => $key) {
                $ids = $scope[$key];
                if ($ids === []) {
                    continue;
                }
                $query = $db->prepare("SELECT host_id FROM $table WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                if (!$query || !$query->execute($ids)) {
                    throw new \RuntimeException('Reviewed ownership unavailable');
                }
                $owners = $query->fetchAll(\PDO::FETCH_COLUMN);
                if ($query->errorCode() !== '00000') {
                    throw new \RuntimeException('Reviewed ownership unavailable');
                }
                foreach ($owners as $owner) {
                    if ((int) $owner !== (int) $hostId) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /** Guard cross-device graph/data ownership before any irreversible effects. */
    public static function exclusive(\PDO $db, array $graphIds, array $dataIds): bool
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('Dependency checks require a transaction');
        }
        // Zero denotes template rows, not an empty selection. Local object IDs
        // are positive, so -1 cannot match an unrelated template or instance.
        $graphs = implode(',', array_map('intval', $graphIds)) ?: '-1';
        $data = implode(',', array_map('intval', $dataIds)) ?: '-1';
        // Lock both the selected graphs' references and outside graphs that
        // reference selected data. Missing references never grant wider scope.
        // Separate indexed ownership ranges: a cross-table OR forces a broad
        // scan whose next-key locks can block unrelated graph writers.
        foreach ([
            "SELECT gti.local_graph_id, dtr.local_data_id FROM graph_templates_item gti LEFT JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id WHERE gti.local_graph_id IN ($graphs) FOR UPDATE",
            "SELECT gti.local_graph_id, dtr.local_data_id FROM data_template_rrd dtr INNER JOIN graph_templates_item gti ON gti.task_item_id = dtr.id WHERE dtr.local_data_id IN ($data) FOR UPDATE",
        ] as $sql) {
            $query = $db->query($sql);
            if (!$query) {
                throw new \RuntimeException('Dependency scope unavailable');
            }
            foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!in_array((int) $row['local_graph_id'], $graphIds, true)
                    || ((int) $row['local_data_id'] > 0 && !in_array((int) $row['local_data_id'], $dataIds, true))) {
                    return false;
                }
            }
            if ($query->errorCode() !== '00000') {
                throw new \RuntimeException('Dependency scope unavailable');
            }
        }
        foreach (["SELECT id FROM aggregate_graphs WHERE local_graph_id IN ($graphs) FOR UPDATE", "SELECT local_graph_id FROM aggregate_graphs_items WHERE local_graph_id IN ($graphs) FOR UPDATE"] as $sql) {
            $aggregates = $db->query($sql);
            if (!$aggregates) {
                throw new \RuntimeException('Dependency scope unavailable');
            }
            if ($aggregates->fetchColumn() !== false) {
                return false;
            }
        }
        return true;
    }
}
