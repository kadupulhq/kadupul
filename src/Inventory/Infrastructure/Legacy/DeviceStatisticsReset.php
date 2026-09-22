<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceState;

/** Atomic reset of live observations; a poller may immediately start new samples. */
final class DeviceStatisticsReset
{
    public function apply(\PDO $connection, DeviceState $device): void
    {
        $query = $connection->prepare("UPDATE host SET min_time = '9.99999', max_time = 0, cur_time = 0, avg_time = 0, total_polls = 0, failed_polls = 0, availability = 100 WHERE id = ? AND poller_id = ? AND deleted = ''");
        // Legacy PDO connections use MYSQL_ATTR_FOUND_ROWS, including resets of
        // already-zero counters. Check identity at the write, not just preflight.
        if (!$query->execute([$device->id, $device->pollerId]) || $query->rowCount() !== 1) {
            throw new \RuntimeException('Device statistics reset could not be confirmed.');
        }
    }
}
