<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Contract\AbsoluteValue;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\DeriveValue;
use Kadupul\Telemetry\Contract\GaugeValue;
use Kadupul\Telemetry\Contract\InvalidTelemetryValue;
use Kadupul\Telemetry\Contract\SampleValue;
use Kadupul\Telemetry\Contract\UnknownValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SampleValueTest extends TestCase
{
    public function testExactValuesBeyondFloatPrecisionRoundTripUnchanged(): void
    {
        // 2^53 + 1 is the first integer a float cannot hold; 2^63 and 2^64 - 1 bound the 64-bit ranges.
        self::assertSame('9007199254740993', CounterValue::fromString('9007199254740993')->toWire());
        self::assertSame('18446744073709551615', CounterValue::fromString('18446744073709551615')->toWire());
        self::assertSame('9223372036854775808', AbsoluteValue::fromString('9223372036854775808')->toWire());
        self::assertSame('18446744073709551615', AbsoluteValue::fromString('18446744073709551615')->toWire());
        self::assertSame('9007199254740993', DeriveValue::fromString('9007199254740993')->toWire());
        self::assertSame('9223372036854775807', DeriveValue::fromString('9223372036854775807')->toWire());
        self::assertSame('-9223372036854775808', DeriveValue::fromString('-9223372036854775808')->toWire());
        self::assertSame('9223372036854775809.123456789', GaugeValue::fromString('9223372036854775809.123456789')->toWire());
        self::assertSame('-9007199254740993.000000000001', GaugeValue::fromString('-9007199254740993.000000000001')->toWire());
    }

    public function testUnknownRendersAsUAndNeverZero(): void
    {
        self::assertSame('U', UnknownValue::instance()->toWire());
        self::assertNotSame('0', UnknownValue::instance()->toWire());
    }

    public function testEveryValueTypeIsASampleValue(): void
    {
        self::assertInstanceOf(SampleValue::class, CounterValue::fromInt(1));
        self::assertInstanceOf(SampleValue::class, AbsoluteValue::fromInt(1));
        self::assertInstanceOf(SampleValue::class, DeriveValue::fromInt(-1));
        self::assertInstanceOf(SampleValue::class, GaugeValue::fromString('1.5'));
        self::assertInstanceOf(SampleValue::class, UnknownValue::instance());
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function invalidValues(): iterable
    {
        yield 'counter negative' => [CounterValue::class, '-1'];
        yield 'counter 2^64' => [CounterValue::class, '18446744073709551616'];
        yield 'absolute negative' => [AbsoluteValue::class, '-1'];
        yield 'absolute 2^64' => [AbsoluteValue::class, '18446744073709551616'];
        yield 'absolute fraction' => [AbsoluteValue::class, '1.5'];
        yield 'derive 2^63' => [DeriveValue::class, '9223372036854775808'];
        yield 'derive below min' => [DeriveValue::class, '-9223372036854775809'];
        yield 'derive fraction' => [DeriveValue::class, '1.5'];
        yield 'derive plus sign' => [DeriveValue::class, '+1'];
        yield 'derive trailing newline' => [DeriveValue::class, "1\n"];
        yield 'gauge empty' => [GaugeValue::class, ''];
        yield 'gauge U' => [GaugeValue::class, 'U'];
        yield 'gauge NaN' => [GaugeValue::class, 'NaN'];
        yield 'gauge infinity' => [GaugeValue::class, 'INF'];
        yield 'gauge exponent' => [GaugeValue::class, '1e5'];
        yield 'gauge hex' => [GaugeValue::class, '0x10'];
        yield 'gauge plus sign' => [GaugeValue::class, '+1.5'];
        yield 'gauge bare dot' => [GaugeValue::class, '.'];
        yield 'gauge trailing dot' => [GaugeValue::class, '1.'];
        yield 'gauge double dot' => [GaugeValue::class, '1.2.3'];
        yield 'gauge space' => [GaugeValue::class, '1 '];
        yield 'gauge trailing newline' => [GaugeValue::class, "1.5\n"];
        yield 'gauge colon (rrd update separator)' => [GaugeValue::class, '1:2'];
        yield 'gauge too long' => [GaugeValue::class, str_repeat('1', 65)];
    }

    /** @param class-string $class */
    #[DataProvider('invalidValues')]
    public function testInvalidGrammarIsRejected(string $class, string $text): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        $class::fromString($text);
    }

    public function testGaugeAcceptsNegativesAndFractionsAtTheLengthBound(): void
    {
        self::assertSame('-0.5', GaugeValue::fromString('-0.5')->toWire());
        self::assertSame('0.000', GaugeValue::fromString('0.000')->toWire());
        $boundary = str_repeat('1', 64);
        self::assertSame($boundary, GaugeValue::fromString($boundary)->toWire());
    }

    public function testFromIntCoversTheSignedAndUnsignedRanges(): void
    {
        self::assertSame((string) PHP_INT_MIN, DeriveValue::fromInt(PHP_INT_MIN)->toWire());
        self::assertSame((string) PHP_INT_MAX, AbsoluteValue::fromInt(PHP_INT_MAX)->toWire());
        $this->expectException(InvalidTelemetryValue::class);
        AbsoluteValue::fromInt(-1);
    }
}
