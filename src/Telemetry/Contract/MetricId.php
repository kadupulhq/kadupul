<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * Name of a metric as dotted lowercase segments, for example interface.in_octets.
 */
final readonly class MetricId
{
    /**
     * Validates the name.
     *
     * @throws InvalidTelemetryValue when the name is not dotted lowercase segments
     */
    public function __construct(private string $name)
    {
        if (preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\z/', $name) !== 1) {
            throw new InvalidTelemetryValue('A metric id must be dotted lowercase segments such as interface.in_octets.');
        }
    }

    /**
     * Returns the metric name.
     */
    public function toString(): string
    {
        return $this->name;
    }

    /**
     * Tells whether both ids have the same name.
     */
    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
