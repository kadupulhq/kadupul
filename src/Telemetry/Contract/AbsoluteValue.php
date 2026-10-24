<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/** An exact unsigned 64-bit reading for an ABSOLUTE data source, where each reading counts events since the previous one. */
final readonly class AbsoluteValue implements SampleValue
{
    private const MAXIMUM = '18446744073709551615';

    private function __construct(private string $decimal) {}

    public static function fromString(string $text): self
    {
        if (preg_match('/\A[0-9]{1,30}\z/', $text) !== 1) {
            throw new InvalidTelemetryValue('An absolute value must be a non-negative decimal integer.');
        }
        $number = gmp_init($text, 10);
        if (gmp_cmp($number, self::MAXIMUM) > 0) {
            throw new InvalidTelemetryValue('An absolute value must not exceed ' . self::MAXIMUM . '.');
        }

        return new self(gmp_strval($number));
    }

    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw new InvalidTelemetryValue('An absolute value must be non-negative.');
        }

        return new self((string) $value);
    }

    public function toWire(): string
    {
        return $this->decimal;
    }
}
