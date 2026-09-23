<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceOptionsChange;
use Kadupul\Inventory\Domain\DeviceState;

final class DeviceOptionsWriter
{
    public function apply(\PDO $connection, DeviceState $device, DeviceOptionsChange $change): void
    {
        $assignments = implode(', ', array_map(static fn($field) => $field . ' = ?', array_keys($change->fields)));
        $query = $connection->prepare("UPDATE host SET $assignments WHERE id = ? AND poller_id = ? AND deleted = ''");
        if (!$query->execute([...array_values($change->fields), $device->id, $device->pollerId]) || $query->rowCount() !== 1) {
            throw new \RuntimeException('Device options could not be saved.');
        }
    }
    public function verify(\PDO $connection, DeviceState $device, DeviceOptionsChange $change): void
    {
        $columns = implode(', ', array_keys($change->fields));
        $query = $connection->prepare("SELECT $columns FROM host WHERE id = ? AND poller_id = ? AND deleted = ''");
        if (!$query->execute([$device->id, $device->pollerId]) || !($row = $query->fetch(\PDO::FETCH_ASSOC))) {
            throw new \RuntimeException('Device options could not be confirmed.');
        }
        foreach ($change->fields as $field => $value) {
            if ((string) $row[$field] !== $value) {
                throw new \RuntimeException('Device options could not be confirmed.');
            }
        }
    }
}
