<?php

declare(strict_types=1);

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
    public function apply(PDO $connection, array $connections, int $deviceId, int $previous, int $target, bool $deferPreviousCleanup = false): void
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
        if (!$deferPreviousCleanup) {
            $this->cleanupPrevious($connections, $deviceId, $previous, $target);
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
    /**
     * Serialize cleanup with later moves; primary ownership is already committed.
     *
     * @param array<int, PDO> $connections
     * @param array<int, int> $previousOwners
     */
    public function finish(PDO $connection, int $actorId, array $connections, array $previousOwners, int $target, ?array $receipts = null): void
    {
        $previousOwners = array_filter($previousOwners, static fn(int $previous): bool => $previous > 1 && $previous !== $target);
        if ($receipts === [] || ($receipts === null && $previousOwners === [])) {
            return;
        }
        if ($connection->inTransaction() || !$connection->beginTransaction()) {
            throw new RuntimeException("Collector cleanup transaction unavailable");
        }
        try {
            (new \Kadupul\Platform\Infrastructure\Legacy\LegacyReferenceWriteTransaction($connection))->run(function () use ($connection, $actorId, $connections, $previousOwners, $target, $receipts): bool {
                $pending = $receipts ?? array_map(static fn(int $owner): array => [$owner => $target], $previousOwners);
                $ids = array_keys($pending);
                sort($ids, SORT_NUMERIC);
                $owners = [];
                foreach ($pending as $receipt) {
                    $owners = [...$owners, ...array_keys($receipt)];
                }
                $owners = array_values(array_unique($owners));
                sort($owners, SORT_NUMERIC);
                $locked = (new DeviceMutationSelection())->lock($connection, $actorId, $ids, static function (string $status): void {}, [], [$target], $owners);
                foreach ($locked["rows"] as $index => $row) {
                    if ((int) $row["poller_id"] !== $target || (int) $row["site_id"] !== (int) $locked["associations"][$index]["site_id"]) {
                        throw new RuntimeException("Collector ownership changed before cleanup");
                    }
                }
                $journal = new DeviceCollectorCleanup();
                if ($receipts !== null && $journal->pending($connection, $ids, true) !== $receipts) {
                    throw new RuntimeException("Collector cleanup ownership changed");
                }
                foreach ($pending as $id => $receipt) {
                    foreach ($receipt as $owner => $expected) {
                        if ($expected !== $target || $owner === $target || !isset($connections[$owner])) {
                            throw new RuntimeException("Collector cleanup ownership changed");
                        }
                        $this->cleanupPrevious($connections, $id, $owner, $target, $receipts === null);
                        if ($receipts !== null) {
                            $journal->acknowledge($connection, $id, $owner, $target);
                        }
                    }
                }
                return true;
            }, ["host", "poller", "settings"]);
            if (!$connection->commit() || $connection->inTransaction()) {
                throw new RuntimeException("Collector cleanup commit failed");
            }
        } catch (\Throwable $failure) {
            try {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
                // Preserve the original cleanup failure, including state-probe errors.
            }
            throw $failure;
        }
    }

    /** Remove the old copy only after the caller has committed primary ownership. */
    public function cleanupPrevious(array $connections, int $deviceId, int $previous, int $target, bool $queuePurge = true): void
    {
        if ($previous <= 1 || $previous === $target) {
            return;
        }
        $verifier = new DeviceCollectorReplication();
        $verifier->purgeDependents($connections[$previous], $deviceId);
        api_device_purge_from_remote($deviceId, $previous, null, $connections[$previous], $queuePurge);
        $verifier->verifyPurged($connections[$previous], $deviceId);
    }

}
