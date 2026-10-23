<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing\Fakes;

use Kadupul\Telemetry\Contract\SampleValue;

/**
 * SampleValue is an open interface, so a writer cannot assume the shipped
 * value classes validated the text. This one returns whatever it is given.
 */
final readonly class RawSampleValue implements SampleValue
{
    public function __construct(private string $wire) {}

    public function toWire(): string
    {
        return $this->wire;
    }
}
