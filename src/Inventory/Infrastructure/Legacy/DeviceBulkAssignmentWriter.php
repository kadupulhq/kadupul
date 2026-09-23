<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Domain\DeviceBulkAssignment;
use PDO;
use RuntimeException;

/** Legacy effects; authorization, locks and the primary transaction belong to the worker. */
final class DeviceBulkAssignmentWriter
{
    public function apply(PDO $connection, array $connections, DeviceState $device, DeviceBulkAssignment $change): void
    {
        $target = $change->targetId;
        if ($change->kind === 'collector') {
            (new DeviceCollectorTransfer())->apply($connection, $connections, $device->id, $device->pollerId, $target);
            return;
        }
        $remote = $connections[$device->pollerId] ?? null;
        $column = $change->kind === 'site' ? 'site_id' : 'host_template_id';
        $previous = $change->kind === 'site' ? $device->siteId : $device->templateId;
        if ($previous === $target) {
            return;
        }
        if ($change->kind === 'template' && $target > 0) {
            api_device_update_host_template($device->id, $target);
        } else {
            foreach (array_filter([$connection, $remote]) as $database) {
                $query = $database->prepare("UPDATE host SET $column = ? WHERE id = ? AND deleted = '' AND poller_id = ?");
                if (!$query->execute([$target, $device->id, $device->pollerId]) || $query->rowCount() !== 1) {
                    throw new RuntimeException('Assignment failed');
                }
            }
            if ($change->kind === 'template') {
                api_plugin_hook_function('device_template_change', ['device_id' => $device->id, 'device_template_id' => 0]);
            }
        }
        api_plugin_hook_function('host_save', ['host_id' => $device->id]);
    }

    public function verify(PDO $connection, array $connections, DeviceState $device, DeviceBulkAssignment $change): void
    {
        $poller = $change->kind === 'collector' ? $change->targetId : $device->pollerId;
        $template = $change->kind === 'template' ? $change->targetId : $device->templateId;
        $site = $change->kind === 'site' ? $change->targetId : $device->siteId;
        foreach (array_filter([$connection, $connections[$poller] ?? null]) as $database) {
            $query = $database->prepare("SELECT poller_id, host_template_id, site_id, disabled FROM host WHERE id = ? AND deleted = ''");
            $query->execute([$device->id]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['poller_id'] !== $poller || (int) $row['host_template_id'] !== $template
                || (int) $row['site_id'] !== $site || ($row['disabled'] !== 'on') !== $device->enabled) {
                throw new RuntimeException('Assignment could not be confirmed');
            }
        }
        if ($change->kind === 'template') {
            (new DeviceCreationVerifier())->verify($connection, $connections[$poller] ?? null, $device->id, $template, true);
        }
        if ($change->kind === 'collector') {
            $verifier = new DeviceCollectorReplication();
            if ($poller > 1) {
                $verifier->verifyTarget($connection, $connections[$poller], $device->id);
            }
            if ($device->pollerId > 1 && $device->pollerId !== $poller) {
                $verifier->verifyPurged($connections[$device->pollerId], $device->id);
            }
            $query = $connection->prepare('SELECT COUNT(*) FROM poller_item WHERE host_id = ? AND poller_id != ?');
            $query->execute([$device->id, $poller]);
            if ((int) $query->fetchColumn() !== 0) {
                throw new RuntimeException('Polling ownership mismatch');
            }
        }
    }

}
