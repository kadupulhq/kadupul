<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use PDO;

final class DeviceMaintenanceRecords
{
    public const PUBLIC_FIELDS = ['id', 'description', 'hostname', 'disabled', 'site_id', 'poller_id', 'host_template_id', 'location', 'device_threads', 'snmp_port', 'snmp_timeout', 'max_oids', 'bulk_walk_size', 'availability_method', 'ping_method', 'ping_port', 'ping_timeout', 'ping_retries', 'snmp_version', 'snmp_auth_protocol', 'snmp_priv_protocol', 'snmp_context', 'snmp_engine_id'];
    public function snapshot(PDO $db, array $row, bool $lock = false): DeviceMaintenanceState
    {
        $associations = (new DeviceAssociationRecords())->snapshot($db, $row, 'query', $lock);
        $query = $db->query("SELECT value FROM settings WHERE name = 'selective_device_debug'" . ($lock ? ' FOR UPDATE' : ''));
        $ids = explode(',', (string) $query->fetchColumn());
        return new DeviceMaintenanceState(LegacyDeviceStates::state(array_replace(array_fill_keys(self::PUBLIC_FIELDS, ''), array_intersect_key($row, array_flip(self::PUBLIC_FIELDS)))), $associations->items, $associations->methods, in_array((string) $row['id'], $ids, true));
    }
}
