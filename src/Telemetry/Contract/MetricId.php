<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

final readonly class MetricId
{
    public function __construct(private string $name)
    {
        if (preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\z/', $name) !== 1) {
            throw new InvalidTelemetryValue('A metric id must be dotted lowercase segments such as interface.in_octets.');
        }
    }

    public function toString(): string
    {
        return $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
