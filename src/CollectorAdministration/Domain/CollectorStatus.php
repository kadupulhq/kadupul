<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Domain;

final class CollectorStatus
{
    public static function fromLegacy(int $status, bool $disabled, int $heartbeat): string
    {
        if ($disabled) {
            return 'Disabled';
        }
        if ($heartbeat > 310) {
            return 'Heartbeat';
        }

        return match ($status) {
            1 => 'Running',
            2 => 'Idle',
            3 => 'Down',
            4 => 'Disabled',
            5 => 'Recovering',
            6 => 'Heartbeat',
            default => 'New/Idle',
        };
    }
}
