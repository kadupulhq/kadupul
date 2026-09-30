<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Domain;

enum CollectorBulkAction: string
{
    case Delete = 'delete';
    case Disable = 'disable';
    case Enable = 'enable';
    case FullSync = 'full-sync';
    case ClearStatistics = 'clear-statistics';

    public function confirmation(): string
    {
        return match ($this) {
            self::Delete => 'Delete selected data collectors?',
            self::Disable => 'Disable selected data collectors?',
            self::Enable => 'Enable selected data collectors?',
            self::FullSync => 'Synchronize selected remote data collectors?',
            self::ClearStatistics => 'Clear statistics for selected data collectors?',
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::Delete => 'Deleting these data collectors moves their devices and polling data back to the Main Kadupul Data Collector.',
            self::Disable => 'Disabling these data collectors stops their polling.',
            self::Enable => 'Enabling these data collectors allows their polling to resume.',
            self::FullSync => 'This synchronizes selected remote data collectors for offline operation.',
            self::ClearStatistics => 'This resets the selected data collectors’ collection statistics.',
        };
    }

    public function protectsPrimary(): bool
    {
        return true;
    }
}
