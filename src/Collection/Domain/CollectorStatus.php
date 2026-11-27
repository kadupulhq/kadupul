<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Domain;

/**
 * Poller runtime status matching POLLER_STATUS_* constants in include/global_constants.php.
 */
enum CollectorStatus: int
{
    case New = 0;
    case Running = 1;
    case Idle = 2;
    case Down = 3;
    case Disabled = 4;
    case Recovering = 5;
    case Heartbeat = 6;

    public function isHealthy(): bool
    {
        return match ($this) {
            self::Running, self::Idle, self::Heartbeat => true,
            self::New, self::Down, self::Disabled, self::Recovering => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'new',
            self::Running => 'running',
            self::Idle => 'idle',
            self::Down => 'down',
            self::Disabled => 'disabled',
            self::Recovering => 'recovering',
            self::Heartbeat => 'heartbeat',
        };
    }
}
