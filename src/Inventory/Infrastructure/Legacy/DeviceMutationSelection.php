<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;
use RuntimeException;

/** Read and lock the common device scope before a legacy mutation worker acts. */
final class DeviceMutationSelection
{
    /**
     * Authorizes the actor and locks the sites, devices and collectors an operation will touch.
     * Sites are locked in id order, then devices are re-read and checked for visibility, then collectors in id order, so concurrent workers lock in the same order. Reports "denied" or "missing" through $markStatus before throwing.
     *
     * @param array $ids The devices to lock.
     * @param callable(string): mixed $markStatus Records the worker status ("denied" or "missing") on failure.
     * @param array $additionalSiteIds Extra sites to lock, such as an assignment target.
     * @param array $additionalPollerIds Extra collectors to lock; each must exist and be enabled.
     * @param array $cleanupPollers Collectors with pending cleanup; each must exist and be enabled.
     * @throws RuntimeException When access is denied, a device is missing or invisible, or a collector is unavailable.
     */
    public function lock(PDO $connection, int $actorId, array $ids, callable $markStatus, array $additionalSiteIds = [], array $additionalPollerIds = [], array $cleanupPollers = []): array
    {
        if (!(new DeviceWriteAuthorization())->allows($connection, $actorId)) {
            $markStatus('denied');
            throw new RuntimeException('Access denied');
        }
        $read = static function (PDO $db, string $sql, array $parameters): array {
            $query = $db->prepare($sql);
            if (!$query->execute($parameters)) {
                throw new RuntimeException('Device query failed');
            }
            return $query->fetchAll(PDO::FETCH_ASSOC);
        };
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $associations = $read($connection, "SELECT id, site_id FROM host WHERE id IN ($placeholders) AND deleted = '' ORDER BY id", $ids);
        if (count($associations) !== count($ids)) {
            $markStatus('missing');
            throw new RuntimeException('Devices unavailable');
        }
        $sites = array_unique([...array_map(static fn($row) => (int) $row['site_id'], $associations), ...array_map('intval', $additionalSiteIds)]);
        sort($sites, SORT_NUMERIC);
        $siteLocks = [];
        foreach ($sites as $siteId) {
            if ($siteId > 0) {
                $siteLocks[$siteId] = $read($connection, 'SELECT id FROM sites WHERE id = ? FOR UPDATE', [$siteId]);
            }
        }
        $rows = $read($connection, "SELECT id, description, hostname, disabled, status, site_id, poller_id, host_template_id, location, device_threads, snmp_port, snmp_timeout, max_oids, bulk_walk_size, availability_method, ping_method, ping_port, ping_timeout, ping_retries, snmp_version, snmp_auth_protocol, snmp_priv_protocol, snmp_context, snmp_engine_id FROM host WHERE id IN ($placeholders) AND deleted = '' ORDER BY id FOR UPDATE", $ids);
        if (count($rows) !== count($ids)) {
            $markStatus('missing');
            throw new RuntimeException('Devices unavailable');
        }
        $provider = new class ($connection) implements DatabaseConnection {
            /**
             * Wraps an existing connection.
             */
            public function __construct(private PDO $connection) {}
            /**
             * Returns the wrapped connection.
             */
            public function get(): PDO
            {
                return $this->connection;
            }
        };
        $predicate = (new LegacyDeviceVisibility($provider))->predicate($actorId, true);
        $visible = $read($connection, "SELECT DISTINCT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id IN ($placeholders) AND ($predicate) ORDER BY h.id LOCK IN SHARE MODE", $ids);
        if (count($visible) !== count($ids)) {
            $markStatus('missing');
            throw new RuntimeException('Devices unavailable');
        }
        $pollers = array_unique([...array_map(static fn($row) => (int) $row['poller_id'], $rows), ...array_map('intval', $additionalPollerIds), ...array_map('intval', $cleanupPollers)]);
        sort($pollers, SORT_NUMERIC);
        $pollerLocks = [];
        foreach ($pollers as $pollerId) {
            $lockedPoller = $read($connection, 'SELECT id, disabled FROM poller WHERE id = ? FOR UPDATE', [$pollerId]);
            if (in_array($pollerId, array_map('intval', $additionalPollerIds), true) && (count($lockedPoller) !== 1 || $lockedPoller[0]['disabled'] !== '')) {
                throw new RuntimeException('Assignment collector unavailable');
            }
            if (in_array($pollerId, $cleanupPollers, true) && (count($lockedPoller) !== 1 || $lockedPoller[0]['disabled'] !== '')) {
                throw new RuntimeException('Pending cleanup collector unavailable');
            }
            $pollerLocks[$pollerId] = $lockedPoller;
        }

        return ['associations' => $associations, 'rows' => $rows, 'pollers' => $pollers, 'read' => $read, 'site_locks' => $siteLocks, 'poller_locks' => $pollerLocks];
    }
}
