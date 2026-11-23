<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Clock;

use Kadupul\Alerting\Application\Port\Clock;
use Symfony\Component\Clock\ClockInterface;

/**
 * The Alerting clock port backed by Symfony's clock, returned in UTC as a
 * plain DateTimeImmutable, as the Graphing adapter does.
 */
final readonly class SymfonyClock implements Clock
{
    public function __construct(private ClockInterface $clock) {}

    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
