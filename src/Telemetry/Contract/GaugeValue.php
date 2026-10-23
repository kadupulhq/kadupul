<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * An exact decimal reading for a GAUGE data source. The text is kept as given
 * so a value such as 9223372036854775809.123456789 survives unchanged.
 * Exponents, signs other than a leading minus, and the RRD field separator
 * are rejected so the text cannot alter an update string.
 */
final readonly class GaugeValue implements SampleValue
{
    private const MAX_LENGTH = 64;

    private function __construct(private string $decimal) {}

    public static function fromString(string $text): self
    {
        // \z, not $, so a trailing newline is not accepted.
        if (strlen($text) > self::MAX_LENGTH || preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/', $text) !== 1) {
            throw new InvalidTelemetryValue('A gauge value must be a decimal number of at most ' . self::MAX_LENGTH . ' characters, such as -12.5.');
        }

        return new self($text);
    }

    public function toWire(): string
    {
        return $this->decimal;
    }
}
