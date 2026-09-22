<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;
use RuntimeException;

/** Site-before-device locking and visibility checks shared by assignment workers. */
final class DeviceAssignmentLock
{
    public static function findVisible(PDO $connection, int $actorId, int $deviceId): ?array
    {
        // Match the site-before-host lock order used by site deletion and device saves.
        $site = db_fetch_row_prepared("SELECT site_id FROM host WHERE id = ? AND deleted = ''", [$deviceId]);
        if (!$site) {
            return null;
        }
        if ((int) $site['site_id'] > 0) {
            $lock = $connection->prepare('SELECT id FROM sites WHERE id = ? FOR UPDATE');
            if (!$lock->execute([(int) $site['site_id']])) {
                throw new RuntimeException('Site lock unavailable');
            }
            $lock->fetchColumn();
        }
        $row = db_fetch_row_prepared("SELECT id, description, host_template_id, poller_id, site_id FROM host WHERE id = ? AND deleted = '' FOR UPDATE", [$deviceId]);
        $provider = new class ($connection) implements DatabaseConnection {
            public function __construct(private PDO $connection) {}
            public function get(): PDO
            {
                return $this->connection;
            }
        };
        $visibility = new LegacyDeviceVisibility($provider);
        $allowed = db_fetch_cell_prepared('SELECT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND (' . $visibility->predicate($actorId, true) . ') LIMIT 1 LOCK IN SHARE MODE', [$deviceId]);
        if (!$row || !$allowed) {
            return null;
        }
        if ((int) $row['site_id'] !== (int) $site['site_id']) {
            throw new DeviceEditConflict('Device site changed');
        }
        return $row;
    }
}
