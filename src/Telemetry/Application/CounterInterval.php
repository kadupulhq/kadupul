<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Application;

use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\DataQuality;

/**
 * Only a Delta interval carries a value. Reset and Gap leave it null so a
 * missing measurement can never be read back as zero traffic.
 */
final readonly class CounterInterval
{
    /**
     * Holds the interval fields as given.
     *
     * @param int $elapsedMicros microseconds between the two readings
     * @param bool $rolledOver true when the delta was computed across a 32-bit counter wrap
     */
    public function __construct(
        public IntervalKind $kind,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $elapsedMicros,
        public ?CounterValue $delta,
        public DataQuality $quality,
        public bool $rolledOver = false,
    ) {}
}
