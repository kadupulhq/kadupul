<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Domain\DeviceAssociationChange;
use PDO;

final class DeviceAssociationWriter
{
    public function apply(PDO $primary, ?PDO $remote, DeviceAssociations $device, DeviceAssociationChange $change): void
    {
        if ($change->kind === 'query') {
            match ($change->operation) {
                'add' => api_device_dq_add($device->id, $change->targetId, $change->reindexMethod),
                'change' => api_device_dq_change($device->id, $change->targetId, $change->reindexMethod),
                'remove' => api_device_dq_remove($device->id, $change->targetId),
            };
            return;
        }
        if ($change->operation === 'remove') {
            api_device_gt_remove($device->id, $change->targetId);
        } else {
            foreach (array_filter([$primary, $remote]) as $database) {
                $query = $database->prepare('REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)');
                if (!$query->execute([$device->id, $change->targetId])) {
                    throw new \RuntimeException('Graph association failed');
                }
            }
            automation_hook_graph_template($device->id, $change->targetId);
            api_plugin_hook_function('add_graph_template_to_host', ['host_id' => $device->id, 'graph_template_id' => $change->targetId]);
        }
    }
    public function verify(PDO $primary, ?PDO $remote, DeviceAssociations $device, DeviceAssociationChange $change): void
    {
        foreach (array_filter([$primary, $remote]) as $database) {
            $query = $database->prepare("SELECT site_id, poller_id, host_template_id FROM host WHERE id = ? AND deleted = ''");
            $query->execute([$device->id]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['site_id'] !== $device->siteId || (int) $row['poller_id'] !== $device->pollerId || (int) $row['host_template_id'] !== $device->templateId) {
                throw new \RuntimeException('Device identity changed');
            }
            if ($change->kind === 'query') {
                $query = $database->prepare('SELECT reindex_method FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?');
                $query->execute([$device->id, $change->targetId]);
                $method = $query->fetchColumn();
                if ($change->operation === 'remove' ? $method !== false : ($method === false || (int) $method !== $change->reindexMethod)) {
                    throw new \RuntimeException('Data-query association could not be confirmed');
                }
                if ($change->operation === 'remove') {
                    foreach (['host_snmp_cache' => 'snmp_query_id', 'poller_reindex' => 'data_query_id'] as $table => $column) {
                        $query = $database->prepare("SELECT COUNT(*) FROM $table WHERE host_id = ? AND $column = ?");
                        $query->execute([$device->id, $change->targetId]);
                        if ((int) $query->fetchColumn() !== 0) {
                            throw new \RuntimeException('Data-query cache cleanup could not be confirmed');
                        }
                    }
                }
                continue;
            }
            $query = $database->prepare('SELECT COUNT(*) FROM host_graph hg INNER JOIN graph_templates gt ON gt.id = hg.graph_template_id WHERE hg.host_id = ? AND hg.graph_template_id = ?');
            if (!$query->execute([$device->id, $change->targetId]) || (int) $query->fetchColumn() !== ($change->operation === 'add' ? 1 : 0)) {
                throw new \RuntimeException('Association could not be confirmed');
            }
        }
    }
}
