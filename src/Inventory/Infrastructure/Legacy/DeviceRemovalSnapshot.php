<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Domain\DeviceRemoval;

final class DeviceRemovalSnapshot
{
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
