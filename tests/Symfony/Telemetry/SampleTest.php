<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Contract\CollectionProtocol;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\CounterWidth;
use Kadupul\Telemetry\Contract\DataQuality;
use Kadupul\Telemetry\Contract\GaugeValue;
use Kadupul\Telemetry\Contract\InvalidTelemetryValue;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\SampleValue;
use Kadupul\Telemetry\Contract\Unit;
use Kadupul\Telemetry\Contract\UnknownValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SampleTest extends TestCase
{
    public function testTimestampIsConvertedToUtcAndKeepsMicroseconds(): void
    {
        $local = new \DateTimeImmutable('2026-03-01 12:00:00.123456', new \DateTimeZone('America/New_York'));

        $sample = self::sample(timestamp: $local);

        self::assertSame('UTC', $sample->timestamp->getTimezone()->getName());
        self::assertSame('2026-03-01T17:00:00.123456+00:00', $sample->timestamp->format('Y-m-d\TH:i:s.uP'));
        self::assertSame($local->getTimestamp(), $sample->timestamp->getTimestamp());
    }

    public function testDefaults(): void
    {
        $sample = self::sample();

        self::assertSame(DataQuality::Good, $sample->quality);
        self::assertFalse($sample->deviceRestarted);
        self::assertNull($sample->counterWidth);
        self::assertSame([], $sample->labels);
    }

    public function testLabelsAreSortedByKeySoEqualSetsShareASeriesKey(): void
    {
        $a = self::sample(labels: ['b' => '2', 'a' => '1']);
        $b = self::sample(labels: ['a' => '1', 'b' => '2']);

        self::assertSame(['a' => '1', 'b' => '2'], $a->labels);
        self::assertSame($a->seriesKey(), $b->seriesKey());
        self::assertNotSame($a->seriesKey(), self::sample(labels: ['a' => '1'])->seriesKey());
    }

    public function testSeriesKeySeparatesResources(): void
    {
        self::assertNotSame(
            self::sample()->seriesKey(),
            self::sample(resource: new ResourceId('interface', 'eth1'))->seriesKey(),
        );
    }

    public function testSeriesKeySeparatesDevicesWithEqualResourceNames(): void
    {
        self::assertNotSame(self::sample(deviceId: 1)->seriesKey(), self::sample(deviceId: 2)->seriesKey());
    }

    public function testSeriesKeyIsTheSameWhicheverProtocolOrSourceTextProducedTheReading(): void
    {
        self::assertSame(
            self::sample(protocol: CollectionProtocol::Snmp, source: 'snmp')->seriesKey(),
            self::sample(protocol: CollectionProtocol::Gnmi, source: 'collector-7')->seriesKey(),
        );
    }

    public function testSampleCarriesItsDeviceAndCollectionProtocol(): void
    {
        $sample = self::sample(deviceId: 57, protocol: CollectionProtocol::Gnmi, source: 'snmp');

        self::assertSame(57, $sample->deviceId);
        self::assertSame(CollectionProtocol::Gnmi, $sample->protocol);
        self::assertSame('snmp', $sample->source);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidDeviceIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    #[DataProvider('invalidDeviceIds')]
    public function testDeviceIdMustBePositive(int $deviceId): void
    {
        $this->expectException(InvalidTelemetryValue::class);
        $this->expectExceptionMessage('A sample needs the positive id of the device it was collected from; got ' . $deviceId . '.');

        self::sample(deviceId: $deviceId);
    }

    public function testEmptyLabelKeyIsRejected(): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        self::sample(labels: ['' => 'x']);
    }

    public function testSourceMustBeALowercaseToken(): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        self::sample(source: 'SNMP v2');
    }

    public function testValueMustFitTheDeclaredWidth(): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        self::sample(value: CounterValue::fromString('4294967296'), width: CounterWidth::Bits32);
    }

    public function testEachSampleHasAMetricTypeAndUnit(): void
    {
        $sample = self::sample();

        self::assertSame(MetricType::Counter, $sample->type);
        self::assertSame(Unit::Octets, $sample->unit);
    }

    public function testSampleCarriesAnyRawValueType(): void
    {
        $gauge = new Sample(42, CollectionProtocol::Snmp, new MetricId('device.temperature'), new ResourceId('sensor', 't1'), MetricType::Gauge, Unit::Count, new \DateTimeImmutable('2026-03-01', new \DateTimeZone('UTC')), GaugeValue::fromString('-12.5'));
        $unknown = new Sample(42, CollectionProtocol::Snmp, new MetricId('interface.in_octets'), new ResourceId('interface', 'eth0'), MetricType::Counter, Unit::Octets, new \DateTimeImmutable('2026-03-01', new \DateTimeZone('UTC')), UnknownValue::instance());

        self::assertSame('-12.5', $gauge->value->toWire());
        self::assertSame('U', $unknown->value->toWire());
    }

    public function testCounterMetricsRejectNonCounterValues(): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        self::sample(value: GaugeValue::fromString('1.5'));
    }

    public function testCounterWidthRequiresACounterValue(): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        self::sample(value: UnknownValue::instance(), width: CounterWidth::Bits32);
    }

    public function testMicrosecondsSurviveForEveryValueType(): void
    {
        $at = new \DateTimeImmutable('2026-03-01 00:00:00.000001', new \DateTimeZone('UTC'));

        self::assertSame('000001', self::sample(timestamp: $at, value: UnknownValue::instance())->timestamp->format('u'));
    }

    /** @param array<string, string> $labels */
    public static function sample(
        ?\DateTimeImmutable $timestamp = null,
        ?SampleValue $value = null,
        array $labels = [],
        string $source = 'snmp',
        ?CounterWidth $width = null,
        ?ResourceId $resource = null,
        int $deviceId = 42,
        CollectionProtocol $protocol = CollectionProtocol::Snmp,
    ): Sample {
        return new Sample(
            deviceId: $deviceId,
            protocol: $protocol,
            metric: new MetricId('interface.in_octets'),
            resource: $resource ?? new ResourceId('interface', 'eth0'),
            type: MetricType::Counter,
            unit: Unit::Octets,
            timestamp: $timestamp ?? new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('UTC')),
            value: $value ?? CounterValue::fromInt(1),
            labels: $labels,
            source: $source,
            counterWidth: $width,
        );
    }
}
