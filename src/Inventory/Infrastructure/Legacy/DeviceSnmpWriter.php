<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceSnmpConfiguration;
use Kadupul\Inventory\Domain\DeviceState;

final class DeviceSnmpWriter
{
    public function apply(\PDO $connection, DeviceState $device, #[\SensitiveParameter] DeviceSnmpConfiguration $change): void
    {
        $ownsTransaction = !$connection->inTransaction();
        try {
            if ($ownsTransaction && !$connection->beginTransaction()) {
                throw new \RuntimeException('SNMP transaction unavailable.');
            }
            $assignments = implode(', ', array_map(static fn($field) => $field . ' = ?', array_keys($change->fields)));
            $query = $connection->prepare("UPDATE host SET $assignments WHERE id = ? AND poller_id = ? AND deleted = ''");
            if (!$query->execute([...array_values($change->fields), $device->id, $device->pollerId]) || $query->rowCount() !== 1) {
                throw new \RuntimeException('Device SNMP settings could not be saved.');
            }
            if ($change->fields['snmp_version'] === '0') {
                foreach (['UPDATE host_snmp_query SET reindex_method = 0 WHERE host_id = ?', 'DELETE FROM poller_reindex WHERE host_id = ?'] as $sql) {
                    if (!$connection->prepare($sql)->execute([$device->id])) {
                        throw new \RuntimeException('SNMP reindex cleanup failed.');
                    }
                }
            }
            $this->verify($connection, $device, $change);
            if ($ownsTransaction && !$connection->commit()) {
                throw new \RuntimeException('SNMP commit failed.');
            }
        } finally {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
        }
    }

    public function verify(\PDO $connection, DeviceState $device, #[\SensitiveParameter] DeviceSnmpConfiguration $change): void
    {
        $columns = implode(', ', array_keys($change->fields));
        $query = $connection->prepare("SELECT $columns FROM host WHERE id = ? AND poller_id = ? AND deleted = ''");
        if (!$query->execute([$device->id, $device->pollerId]) || !($row = $query->fetch(\PDO::FETCH_ASSOC))) {
            throw new \RuntimeException('Device SNMP settings could not be confirmed.');
        }
        if ($change->fields['snmp_version'] === '0') {
            foreach (['SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ? AND reindex_method <> 0', 'SELECT COUNT(*) FROM poller_reindex WHERE host_id = ?'] as $sql) {
                $query = $connection->prepare($sql);
                if (!$query->execute([$device->id]) || (int) $query->fetchColumn() !== 0) {
                    throw new \RuntimeException('SNMP reindex cleanup could not be confirmed.');
                }
            }
        }
        foreach ($change->fields as $field => $value) {
            if ((string) $row[$field] !== $value) {
                throw new \RuntimeException('Device SNMP settings could not be confirmed.');
            }
        }
    }
}
