<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Domain;

/**
 * Lifecycle states of a plugin in the Extensions bounded context.
 */
enum PluginStatus: string
{
    case Discovered = 'discovered';
    case Installed = 'installed';
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    /**
     * Maps legacy plugin_config.status integer codes (plugins.php).
     */
    public static function fromLegacyStatus(int $status): self
    {
        return match ($status) {
            0 => self::Discovered,
            1 => self::Enabled,
            2, 3 => self::Installed,
            default => self::Disabled,
        };
    }

    public function isActive(): bool
    {
        return $this === self::Enabled;
    }
}
