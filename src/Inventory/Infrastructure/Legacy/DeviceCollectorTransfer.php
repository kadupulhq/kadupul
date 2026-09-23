<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use PDO;
use RuntimeException;

/** Shared collector move effects; callers own locks, authorization and transactions. */
final class DeviceCollectorTransfer
{
    public function apply(PDO $connection, array $connections, int $deviceId, int $previous, int $target): void
    {
        if ($previous === $target) {
            return;
        }
        $pollers = array_unique([$previous, $target]);
        // A queued purge from an earlier move must not delete a returning device.
        $query = $connection->prepare('DELETE FROM poller_command WHERE poller_id = ? AND action = ? AND SUBSTRING_INDEX(command, ":", 1) = ?');
        if (!$query->execute([$target, POLLER_COMMAND_PURGE, (string) $deviceId])) {
            throw new RuntimeException('Stale collector cleanup could not be cancelled');
        }
        if ($target > 1) {
            // A previous purge may already have reached the destination queue.
            $query = $connections[$target]->prepare('DELETE FROM poller_command WHERE action = ? AND SUBSTRING_INDEX(command, ":", 1) = ?');
            if (!$query->execute([POLLER_COMMAND_PURGE, (string) $deviceId])) {
                throw new \RuntimeException('Destination purge cancellation failed');
            }
            if (api_device_replicate_out($deviceId, $target) === false) {
                throw new RuntimeException('Collector replication failed');
            }
            // Legacy bulk replication omits host_graph; preserve those associations too.
            $query = $connection->prepare('SELECT * FROM host_graph WHERE host_id = ?');
            $query->execute([$deviceId]);
            $graphs = $query->fetchAll(PDO::FETCH_ASSOC);
            replicate_table_to_poller($connections[$target], $graphs, 'host_graph');
        } else {
            foreach (['host' => 'id', 'poller_item' => 'host_id'] as $table => $column) {
                $query = $connection->prepare("UPDATE $table SET poller_id = ? WHERE $column = ?");
                if (!$query->execute([$target, $deviceId])) {
                    throw new RuntimeException('Primary collector assignment failed');
                }
            }
        }
        api_plugin_hook_function('host_save', ['host_id' => $deviceId]);
        if (is_error_message() || !$connection->inTransaction()) {
            throw new RuntimeException('Collector assignment failed');
        }
        $query = $connection->prepare('SELECT poller_id FROM host WHERE id = ?');
        $query->execute([$deviceId]);
        if ((int) $query->fetchColumn() !== $target) {
            throw new RuntimeException('Collector identity mismatch');
        }
        $query = $connection->prepare('SELECT COUNT(*) FROM poller_item WHERE host_id = ? AND poller_id != ?');
        $query->execute([$deviceId, $target]);
        if ((int) $query->fetchColumn() !== 0) {
            throw new RuntimeException('Polling ownership mismatch');
        }
        $verifier = new \Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorReplication();
        if ($target > 1) {
            $verifier->verifyTarget($connection, $connections[$target], $deviceId);
        }
        // Verify the target before removing the old collector's polling state.
        if ($previous > 1) {
            $verifier->purgeDependents($connections[$previous], $deviceId);
            api_device_purge_from_remote($deviceId, $previous);
            $verifier->verifyPurged($connections[$previous], $deviceId);
        }
        foreach ($pollers as $pollerId) {
            $stats = $connection->prepare('UPDATE poller SET snmp = (SELECT COUNT(*) FROM poller_item WHERE poller_id = ? AND action = 0), script = (SELECT COUNT(*) FROM poller_item WHERE poller_id = ? AND action = 1), server = (SELECT COUNT(*) FROM poller_item WHERE poller_id = ? AND action = 2) WHERE id = ?');
            if (!$stats->execute([$pollerId, $pollerId, $pollerId, $pollerId])) {
                throw new RuntimeException('Collector statistics update failed');
            }
        }
        $mark = $connection->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
        $markers = ['time_last_change_device' => (string) time()];
        foreach ($pollers as $pollerId) {
            $markers['poller_replicate_device_cache_crc_' . $pollerId] = bin2hex(random_bytes(20));
        }
        foreach ($markers as $name => $value) {
            if (!$mark->execute([$name, $value, $value])) {
                throw new RuntimeException('Device cache invalidation failed');
            }
        }
    }
}
