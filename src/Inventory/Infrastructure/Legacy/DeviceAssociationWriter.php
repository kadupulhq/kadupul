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
        if ($change->operation === 'remove') {
            api_device_gt_remove($device->id, $change->targetId);
        } else {
            foreach (array_filter([$primary, $remote]) as $database) {
                $query = $database->prepare('SELECT COUNT(*) FROM graph_templates WHERE id = ?');
                if (!$query->execute([$change->targetId]) || (int) $query->fetchColumn() !== 1) {
                    throw new \RuntimeException('Graph template unavailable');
                }
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
            if ($change->operation === 'add') {
                $query = $database->prepare('SELECT COUNT(*) FROM graph_templates WHERE id = ?');
                if (!$query->execute([$change->targetId]) || (int) $query->fetchColumn() !== 1) {
                    throw new \RuntimeException('Graph template unavailable');
                }
            }
            // Orphaned mappings still count: removal must confirm their absence
            // independently of whether the template catalog contains the target.
            $query = $database->prepare('SELECT COUNT(*) FROM host_graph WHERE host_id = ? AND graph_template_id = ?');
            if (!$query->execute([$device->id, $change->targetId]) || (int) $query->fetchColumn() !== ($change->operation === 'add' ? 1 : 0)) {
                throw new \RuntimeException('Association could not be confirmed');
            }
        }
    }
}
