<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

final class DeviceRemovalDependencies
{
    /** Guard cross-device graph/data ownership before any irreversible effects. */
    public static function exclusive(\PDO $db, array $graphIds, array $dataIds): bool
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('Dependency checks require a transaction');
        }
        $graphs = implode(',', array_map('intval', $graphIds)) ?: '0';
        $data = implode(',', array_map('intval', $dataIds)) ?: '0';
        // Lock both the selected graphs' references and outside graphs that
        // reference selected data. Missing references never grant wider scope.
        $query = $db->query("SELECT gti.local_graph_id, dtr.local_data_id FROM graph_templates_item gti LEFT JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id WHERE gti.local_graph_id IN ($graphs) OR dtr.local_data_id IN ($data) FOR UPDATE");
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!in_array((int) $row['local_graph_id'], $graphIds, true)
                || ((int) $row['local_data_id'] > 0 && !in_array((int) $row['local_data_id'], $dataIds, true))) {
                return false;
            }
        }
        foreach (["SELECT id FROM aggregate_graphs WHERE local_graph_id IN ($graphs) FOR UPDATE", "SELECT local_graph_id FROM aggregate_graphs_items WHERE local_graph_id IN ($graphs) FOR UPDATE"] as $sql) {
            if ($db->query($sql)->fetchColumn() !== false) {
                return false;
            }
        }
        return true;
    }
}
