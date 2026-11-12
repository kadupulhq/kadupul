<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\ReadModel\DeviceSummary;

/**
 * Converts legacy device rows to read models.
 */
final class LegacyDeviceProjection
{
    /**
     * Builds a list row from a device row.
     * The numeric status is shown as "Down", "Recovering", "Up" or "Error", and any other value as "Unknown".
     *
     * @param array $row Map from string to mixed.
     */
    public static function summary(array $row): DeviceSummary
    {
        return new DeviceSummary(
            (int) $row['id'],
            $row['description'],
            (string) $row['hostname'],
            $row['disabled'] === 'on',
            match ((int) $row['status']) {
                1 => 'Down', 2 => 'Recovering', 3 => 'Up', 4 => 'Error', default => 'Unknown'
            },
            (string) $row['location'],
            (string) $row['external_id']
        );
    }
}
