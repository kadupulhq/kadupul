<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Domain\Installer;

/** Native mode numbers remain stable for persisted installation state. */
enum InstallationMode: int
{
    /** No selected mode; retain the native non-install path. */
    case None = 0;
    /** Initialize a primary server. */
    case Install = 1;
    /** Register a remote collector. */
    case Collector = 2;
    /** Apply the historical upgrade chain. */
    case Upgrade = 3;
    /** Preserve the native downgrade path without running upgrade scripts. */
    case Downgrade = 4;

    /** Retain the process labels consumed by native installation logs. */
    public function processName(): string
    {
        return match ($this) {
            self::Upgrade => 'UPGRADE',
            self::Downgrade => 'DOWNGRADE',
            default => 'INSTALL',
        };
    }
}
