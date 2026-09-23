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
    public function lock(PDO $connection, int $actorId, array $ids, callable $markStatus): array
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
        $sites = array_unique(array_map(static fn($row) => (int) $row['site_id'], $associations));
        sort($sites, SORT_NUMERIC);
        foreach ($sites as $siteId) {
            if ($siteId > 0) {
                $read($connection, 'SELECT id FROM sites WHERE id = ? FOR UPDATE', [$siteId]);
            }
        }
        $rows = $read($connection, "SELECT id, description, hostname, disabled, status, site_id, poller_id, host_template_id FROM host WHERE id IN ($placeholders) AND deleted = '' ORDER BY id FOR UPDATE", $ids);
        if (count($rows) !== count($ids)) {
            $markStatus('missing');
            throw new RuntimeException('Devices unavailable');
        }
        $provider = new class ($connection) implements DatabaseConnection {
            public function __construct(private PDO $connection) {}
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
        $pollers = array_unique(array_map(static fn($row) => (int) $row['poller_id'], $rows));
        sort($pollers, SORT_NUMERIC);
        foreach ($pollers as $pollerId) {
            $read($connection, 'SELECT id FROM poller WHERE id = ? FOR UPDATE', [$pollerId]);
        }

        return ['associations' => $associations, 'rows' => $rows, 'pollers' => $pollers, 'read' => $read];
    }
}
