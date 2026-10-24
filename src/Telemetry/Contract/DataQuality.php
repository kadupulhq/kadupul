<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * Ordered from best to worst so the weakest input to a derived value wins.
 */
enum DataQuality: string
{
    case Good = 'good';
    case Estimated = 'estimated';
    case Stale = 'stale';
    case Bad = 'bad';

    public function worst(self $other): self
    {
        return $this->severity() >= $other->severity() ? $this : $other;
    }

    private function severity(): int
    {
        return match ($this) {
            self::Good => 0,
            self::Estimated => 1,
            self::Stale => 2,
            self::Bad => 3,
        };
    }
}
