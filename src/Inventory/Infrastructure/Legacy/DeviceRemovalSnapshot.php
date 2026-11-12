<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Domain\DeviceRemoval;

/**
 * Reads the graphs and data sources a device removal would affect.
 */
final class DeviceRemovalSnapshot
{
    /**
     * Returns the device with the ids of its graphs and data sources.
     * With $lock, the range locks also block concurrent inserts and reassignments for the device, even when it has none.
     *
     * @param bool $lock Whether to lock the rows for update; requires a transaction.
     * @throws \LogicException When locking is requested outside a transaction.
     * @throws \RuntimeException When the rows cannot be read.
     */
    public static function read(\PDO $db, DeviceState $device, bool $lock = false): DeviceRemoval
    {
        if ($lock && !$db->inTransaction()) {
            throw new \LogicException('Removal locks require a transaction');
        }
        $ids = [];
        // The removal worker uses REPEATABLE READ. InnoDB next-key locks on
        // these host_id ranges also exclude concurrent inserts/reassignments,
        // even for an empty set; a host-row lock alone would not provide that.
        foreach (['graph_local', 'data_local'] as $table) {
            $query = $db->prepare("SELECT id FROM $table WHERE host_id = ? ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
            if (!$query->execute([$device->id])) {
                throw new \RuntimeException('Removal preview unavailable');
            }
            $ids[] = array_map('intval', $query->fetchAll(\PDO::FETCH_COLUMN));
        }
        return new DeviceRemoval($device, ...$ids);
    }
}
