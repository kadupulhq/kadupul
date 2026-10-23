<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/** An exact signed 64-bit reading for a DERIVE data source, which may fall as well as rise. */
final readonly class DeriveValue implements SampleValue
{
    private const MINIMUM = '-9223372036854775808';
    private const MAXIMUM = '9223372036854775807';

    private function __construct(private string $decimal) {}

    public static function fromString(string $text): self
    {
        if (preg_match('/\A-?[0-9]{1,30}\z/', $text) !== 1) {
            throw new InvalidTelemetryValue('A derive value must be a decimal integer.');
        }
        $number = gmp_init($text, 10);
        if (gmp_cmp($number, self::MINIMUM) < 0 || gmp_cmp($number, self::MAXIMUM) > 0) {
            throw new InvalidTelemetryValue('A derive value must lie between ' . self::MINIMUM . ' and ' . self::MAXIMUM . '.');
        }

        return new self(gmp_strval($number));
    }

    public static function fromInt(int $value): self
    {
        return new self((string) $value);
    }

    public function toWire(): string
    {
        return $this->decimal;
    }
}
