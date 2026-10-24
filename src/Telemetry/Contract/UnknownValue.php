<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/** A reading that could not be taken. It renders as U so storage records a gap, never a zero. */
final readonly class UnknownValue implements SampleValue
{
    private function __construct() {}

    public static function instance(): self
    {
        return new self();
    }

    public function toWire(): string
    {
        return 'U';
    }
}
