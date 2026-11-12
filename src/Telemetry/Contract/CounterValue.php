<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * An unsigned 64-bit counter reading. Device counters regularly exceed
 * PHP_INT_MAX, so the value lives as a canonical decimal string and every
 * operation goes through GMP; a float conversion would drop low-order digits.
 */
final readonly class CounterValue implements SampleValue
{
    private const MAXIMUM = '18446744073709551615';

    /**
     * Keeps the validated decimal text.
     */
    private function __construct(private string $decimal) {}

    /**
     * Parses a non-negative decimal integer up to 18446744073709551615.
     *
     * @throws InvalidTelemetryValue when the text is not such an integer
     */
    public static function fromString(string $text): self
    {
        // \z, not $, so a trailing newline is not accepted as a digit string.
        if (preg_match('/\A[0-9]{1,30}\z/', $text) !== 1) {
            throw new InvalidTelemetryValue('A counter value must be a non-negative decimal integer.');
        }
        $number = gmp_init($text, 10);
        if (gmp_cmp($number, self::MAXIMUM) > 0) {
            throw new InvalidTelemetryValue('A counter value must not exceed 18446744073709551615.');
        }

        return new self(gmp_strval($number));
    }

    /**
     * Builds a value from a non-negative integer.
     *
     * @throws InvalidTelemetryValue when the integer is negative
     */
    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw new InvalidTelemetryValue('A counter value must be non-negative.');
        }

        return new self((string) $value);
    }

    /**
     * Returns the decimal text.
     */
    public function toString(): string
    {
        return $this->decimal;
    }

    /**
     * Returns the decimal text, which is the form an RRD update accepts.
     */
    public function toWire(): string
    {
        return $this->decimal;
    }

    /**
     * Tells whether both readings are the same number.
     */
    public function equals(self $other): bool
    {
        return $this->decimal === $other->decimal;
    }

    /**
     * Returns -1, 0 or 1 as this reading is less than, equal to or greater than the other.
     */
    public function compare(self $other): int
    {
        return gmp_cmp($this->decimal, $other->decimal) <=> 0;
    }

    /**
     * Returns this reading less the other.
     *
     * @throws InvalidTelemetryValue when the other reading is larger
     */
    public function minus(self $other): self
    {
        $difference = gmp_sub($this->decimal, $other->decimal);
        if (gmp_sign($difference) < 0) {
            throw new InvalidTelemetryValue('A counter difference must not be negative.');
        }

        return new self(gmp_strval($difference));
    }

    /**
     * Tells whether the reading can be held by a counter of the given width.
     */
    public function fitsWidth(CounterWidth $width): bool
    {
        return $this->compare($width->maximum()) <= 0;
    }

    /**
     * Forward distance from $previous to this value on a counter that wraps at
     * 2^width, which is how a rollover is turned into a delta.
     */
    public function wrappedDelta(self $previous, CounterWidth $width): self
    {
        $modulus = gmp_pow(2, $width->value);

        return new self(gmp_strval(gmp_mod(gmp_add(gmp_sub($this->decimal, $previous->decimal), $modulus), $modulus)));
    }
}
