<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Application\CounterNormalizationPolicy;
use Kadupul\Telemetry\Application\CounterNormalizer;
use Kadupul\Telemetry\Application\IntervalKind;
use Kadupul\Telemetry\Application\UnusableSeries;
use Kadupul\Telemetry\Contract\CollectionProtocol;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\CounterWidth;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\Unit;
use Kadupul\Telemetry\Domain\CardinalityGuard;
use PHPUnit\Framework\TestCase;

/**
 * Collectors for different protocols map their native names onto the same
 * metric and resource ids; nothing downstream should need to know which one ran.
 */
final class ProtocolEquivalenceTest extends TestCase
{
    // Above PHP_INT_MAX (9223372036854775807), so any int or float round trip changes it.
    private const SNMP_VALUE = '12345678901234567890';
    private const GNMI_VALUE = '12345678901234567899';

    public function testSnmpAndGnmiSamplesShareIdentityAndKeepTheirOwnTimestampsAndValues(): void
    {
        // ifHCInOctets for ifDescr eth0, read by a poller.
        $snmp = new Sample(
            42,
            CollectionProtocol::Snmp,
            new MetricId('interface.in_octets'),
            new ResourceId('interface', 'eth0'),
            MetricType::Counter,
            Unit::Octets,
            new \DateTimeImmutable('2026-03-01 12:00:00.250000', new \DateTimeZone('UTC')),
            CounterValue::fromString(self::SNMP_VALUE),
            source: 'snmp',
            counterWidth: CounterWidth::Bits64,
        );
        // /interfaces/interface[name=eth0]/state/counters/in-octets, streamed with a device-side timestamp.
        $gnmi = new Sample(
            42,
            CollectionProtocol::Gnmi,
            new MetricId('interface.in_octets'),
            new ResourceId('interface', 'eth0'),
            MetricType::Counter,
            Unit::Octets,
            new \DateTimeImmutable('2026-03-01 07:00:10.123456', new \DateTimeZone('America/Chicago')),
            CounterValue::fromString(self::GNMI_VALUE),
            source: 'gnmi',
            counterWidth: CounterWidth::Bits64,
        );

        self::assertTrue($snmp->metric->equals($gnmi->metric));
        self::assertTrue($snmp->resource->equals($gnmi->resource));
        self::assertSame($snmp->seriesKey(), $gnmi->seriesKey());
        self::assertSame('2026-03-01T12:00:00.250000+00:00', $snmp->timestamp->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-03-01T13:00:10.123456+00:00', $gnmi->timestamp->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(self::SNMP_VALUE, $snmp->value->toString());
        self::assertSame(self::GNMI_VALUE, $gnmi->value->toString());
        self::assertSame(CollectionProtocol::Snmp, $snmp->protocol);
        self::assertSame(CollectionProtocol::Gnmi, $gnmi->protocol);
        self::assertSame('snmp', $snmp->source);
        self::assertSame('gnmi', $gnmi->source);
    }

    public function testReadingsOfOneMetricOverTwoProtocolsAreNormalizedSeparatelyNeverDifferencedAgainstEachOther(): void
    {
        // Two protocols read two counters that only share a meaning: a delta
        // between an SNMP baseline and a gNMI reading would measure their
        // offset, not traffic.
        $normalizer = $this->normalizer();
        $policy = new CounterNormalizationPolicy(300, 600);

        try {
            $normalizer->normalize([
                $this->reading(CollectionProtocol::Snmp, '2026-03-01 12:00:00', self::SNMP_VALUE),
                $this->reading(CollectionProtocol::Gnmi, '2026-03-01 12:01:00.500000', self::GNMI_VALUE),
            ], $policy);
            self::fail('Readings from two protocols were normalized as one series.');
        } catch (UnusableSeries) {
            self::addToAssertionCount(1);
        }

        $snmp = $normalizer->normalize([
            $this->reading(CollectionProtocol::Snmp, '2026-03-01 12:00:00', self::SNMP_VALUE),
            $this->reading(CollectionProtocol::Snmp, '2026-03-01 12:01:00.500000', self::GNMI_VALUE),
        ], $policy);
        $gnmi = $normalizer->normalize([
            $this->reading(CollectionProtocol::Gnmi, '2026-03-01 12:00:00', self::SNMP_VALUE),
            $this->reading(CollectionProtocol::Gnmi, '2026-03-01 12:01:00.500000', self::GNMI_VALUE),
        ], $policy);

        foreach ([$snmp, $gnmi] as $report) {
            self::assertCount(1, $report->intervals);
            self::assertSame('9', $report->intervals[0]->delta?->toString());
            self::assertSame(60_500_000, $report->intervals[0]->elapsedMicros);
        }
    }

    public function testEveryProtocolClassifiesADecreaseByTheSameWidthRule(): void
    {
        $policy = new CounterNormalizationPolicy(300, 600);
        foreach (CollectionProtocol::cases() as $protocol) {
            $wide = $this->normalizer()->normalize([
                $this->reading($protocol, '2026-03-01 12:00:00', '18446744073709551614', CounterWidth::Bits64),
                $this->reading($protocol, '2026-03-01 12:01:00', '2', CounterWidth::Bits64),
            ], $policy);
            $narrow = $this->normalizer()->normalize([
                $this->reading($protocol, '2026-03-01 12:00:00', '4294967290', CounterWidth::Bits32),
                $this->reading($protocol, '2026-03-01 12:01:00', '5', CounterWidth::Bits32),
            ], $policy);

            self::assertSame(IntervalKind::Reset, $wide->intervals[0]->kind, $protocol->value);
            self::assertNull($wide->intervals[0]->delta, $protocol->value);
            self::assertTrue($narrow->intervals[0]->rolledOver, $protocol->value);
            self::assertSame('11', $narrow->intervals[0]->delta?->toString(), $protocol->value);
        }
    }

    private function normalizer(): CounterNormalizer
    {
        return new CounterNormalizer(
            new FixedClock(new \DateTimeImmutable('2026-03-01 12:02:00', new \DateTimeZone('UTC'))),
            new CardinalityGuard(10),
        );
    }

    private function reading(CollectionProtocol $protocol, string $time, string $value, ?CounterWidth $width = null): Sample
    {
        return new Sample(
            42,
            $protocol,
            new MetricId('interface.in_octets'),
            new ResourceId('interface', 'eth0'),
            MetricType::Counter,
            Unit::Octets,
            new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
            CounterValue::fromString($value),
            source: $protocol->value,
            counterWidth: $width,
        );
    }
}
