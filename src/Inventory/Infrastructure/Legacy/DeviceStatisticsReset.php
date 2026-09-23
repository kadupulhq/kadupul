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

        // A matched UPDATE does not prove that the requested values survived
        // database-side behavior such as BEFORE UPDATE triggers. Confirm the
        // persisted reset before the worker can emit action callbacks.
        $verification = $connection->prepare("SELECT id FROM host WHERE id = ? AND poller_id = ? AND deleted = '' AND min_time = '9.99999' AND max_time = 0 AND cur_time = 0 AND avg_time = 0 AND total_polls = 0 AND failed_polls = 0 AND availability = 100");
        if (!$verification->execute([$device->id, $device->pollerId]) || (int) $verification->fetchColumn() !== $device->id) {
            throw new \RuntimeException('Device statistics reset could not be confirmed.');
        }
    }
}
