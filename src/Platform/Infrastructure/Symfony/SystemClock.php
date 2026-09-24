<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\Platform\Application\Port\Clock;
use Psr\Clock\ClockInterface;

final readonly class SystemClock implements Clock
{
    public function __construct(private ClockInterface $clock) {}

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
