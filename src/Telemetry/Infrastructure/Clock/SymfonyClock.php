<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Infrastructure\Clock;

use Kadupul\Telemetry\Application\Contract\Clock;
use Symfony\Component\Clock\ClockInterface;

/**
 * The Telemetry clock port backed by Symfony's clock, so production time and
 * a test's MockClock come from one source. The instant is returned in UTC
 * as a plain DateTimeImmutable, keeping Symfony's DatePoint out of
 * Application code.
 */
final readonly class SymfonyClock implements Clock
{
    public function __construct(private ClockInterface $clock) {}

    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
