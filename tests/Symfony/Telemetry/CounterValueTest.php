<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\CounterWidth;
use Kadupul\Telemetry\Contract\InvalidTelemetryValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CounterValueTest extends TestCase
{
    public function testMaximumUnsigned64BitValueRoundTripsWithoutFloatConversion(): void
    {
        $value = CounterValue::fromString('18446744073709551615');

        self::assertSame('18446744073709551615', $value->toString());
    }

    public function testValueAbovePhpIntMaxKeepsEveryDigit(): void
    {
        // PHP_INT_MAX + 1, the first value an int cannot hold.
        $text = '9223372036854775808';

        self::assertSame($text, CounterValue::fromString($text)->toString());
    }

    public function testLeadingZerosAreCanonicalised(): void
    {
        self::assertSame('7', CounterValue::fromString('007')->toString());
        self::assertTrue(CounterValue::fromString('007')->equals(CounterValue::fromInt(7)));
    }

    #[DataProvider('invalidValues')]
    public function testInvalidTextIsRejected(string $text): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        CounterValue::fromString($text);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'empty' => [''];
        yield 'decimal' => ['1.5'];
        yield 'exponent' => ['1e3'];
        yield 'hex' => ['0x10'];
        yield 'padded' => [' 1'];
        yield 'trailing newline' => ["1\n"];
        yield 'one above maximum' => ['18446744073709551616'];
        yield 'far above maximum' => [str_repeat('9', 40)];
    }

    public function testNegativeIntegerIsRejected(): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        CounterValue::fromInt(-1);
    }

    public function testMinusReturnsExactDifference(): void
    {
        $difference = CounterValue::fromString('18446744073709551615')->minus(CounterValue::fromString('18446744073709551614'));

        self::assertSame('1', $difference->toString());
    }

    public function testMinusRejectsANegativeResult(): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        CounterValue::fromInt(1)->minus(CounterValue::fromInt(2));
    }

    public function testCompareOrdersByMagnitudeNotByString(): void
    {
        self::assertLessThan(0, CounterValue::fromInt(9)->compare(CounterValue::fromInt(10)));
        self::assertSame(0, CounterValue::fromInt(5)->compare(CounterValue::fromInt(5)));
    }

    public function testWrappedDeltaAcrossA32BitRollover(): void
    {
        $delta = CounterValue::fromString('5')->wrappedDelta(CounterValue::fromString('4294967290'), CounterWidth::Bits32);

        self::assertSame('11', $delta->toString());
    }

    public function testWrappedDeltaAcrossA64BitRollover(): void
    {
        $delta = CounterValue::fromInt(2)->wrappedDelta(CounterValue::fromString('18446744073709551614'), CounterWidth::Bits64);

        self::assertSame('4', $delta->toString());
    }

    public function testFitsWidth(): void
    {
        self::assertTrue(CounterValue::fromString('4294967295')->fitsWidth(CounterWidth::Bits32));
        self::assertFalse(CounterValue::fromString('4294967296')->fitsWidth(CounterWidth::Bits32));
        self::assertTrue(CounterValue::fromString('4294967296')->fitsWidth(CounterWidth::Bits64));
    }
}
