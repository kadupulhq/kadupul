<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Port\Clock;
use Psr\Clock\ClockInterface;

final readonly class IdentityAccessClock implements Clock
{
    public function __construct(private ClockInterface $clock) {}

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
