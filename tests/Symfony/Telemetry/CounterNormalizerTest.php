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
use Kadupul\Telemetry\Application\NormalizationReport;
use Kadupul\Telemetry\Application\UnusableSeries;
use Kadupul\Telemetry\Contract\CollectionErrorCode;
use Kadupul\Telemetry\Contract\CollectionProtocol;
use Kadupul\Telemetry\Contract\CollectionStatus;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\CounterWidth;
use Kadupul\Telemetry\Contract\DataQuality;
use Kadupul\Telemetry\Contract\InvalidTelemetryValue;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\Unit;
use Kadupul\Telemetry\Contract\UnknownValue;
use Kadupul\Telemetry\Domain\CardinalityGuard;
use Kadupul\Telemetry\Domain\CardinalityLimitExceeded;
use PHPUnit\Framework\TestCase;

final class CounterNormalizerTest extends TestCase
{
    private const NOW = '2026-03-01 00:10:00';

    public function testConsecutiveSamplesYieldAnExactDelta(): void
    {
        $report = $this->normalize([self::at(0, '100'), self::at(60, '460')]);

        self::assertCount(1, $report->intervals);
        $interval = $report->intervals[0];
        self::assertSame(IntervalKind::Delta, $interval->kind);
        self::assertSame('360', $interval->delta?->toString());
        self::assertSame(60_000_000, $interval->elapsedMicros);
        self::assertSame(CollectionStatus::Succeeded, $report->status());
    }

    public function testDeltaAbovePhpIntMaxIsExact(): void
    {
        $report = $this->normalize([self::at(0, '9223372036854775808'), self::at(60, '18446744073709551615')]);

        self::assertSame('9223372036854775807', $report->intervals[0]->delta?->toString());
    }

    public function testUnknownReadingsCannotBeNormalizedAsCounters(): void
    {
        $unknown = new Sample(
            deviceId: 42,
            protocol: CollectionProtocol::Snmp,
            metric: new MetricId('interface.in_octets'),
            resource: new ResourceId('interface', 'eth0'),
            type: MetricType::Counter,
            unit: Unit::Octets,
            timestamp: self::time(60),
            value: UnknownValue::instance(),
            source: 'snmp',
        );

        $this->expectException(UnusableSeries::class);

        $this->normalize([self::at(0, '1'), $unknown]);
    }

    public function testMicrosecondElapsedTimeIsPreserved(): void
    {
        $report = $this->normalize([self::at(0, '1', micro: 250_000), self::at(60, '2', micro: 750_000)]);

        self::assertSame(60_500_000, $report->intervals[0]->elapsedMicros);
    }

    public function testSingleSampleAndEmptyInputProduceNoIntervals(): void
    {
        self::assertSame([], $this->normalize([])->intervals);
        self::assertSame([], $this->normalize([self::at(0, '1')])->intervals);
        self::assertSame(CollectionStatus::Succeeded, $this->normalize([])->status());
    }

    public function testRolloverOf32BitCounterDeclaredOnTheSample(): void
    {
        $report = $this->normalize([
            self::at(0, '4294967290', width: CounterWidth::Bits32),
            self::at(60, '5', width: CounterWidth::Bits32),
        ]);

        $interval = $report->intervals[0];
        self::assertSame(IntervalKind::Delta, $interval->kind);
        self::assertSame('11', $interval->delta?->toString());
        self::assertTrue($interval->rolledOver);
    }

    public function testDecreaseOnADeclared64BitCounterIsAResetNotARollover(): void
    {
        $report = $this->normalize([
            self::at(0, '18446744073709551614', width: CounterWidth::Bits64),
            self::at(60, '2', width: CounterWidth::Bits64),
        ]);

        $interval = $report->intervals[0];
        self::assertSame(IntervalKind::Reset, $interval->kind);
        self::assertNull($interval->delta);
        self::assertFalse($interval->rolledOver);
    }

    public function testDecreaseOnACounterDeclared64BitByTheMetricIsAReset(): void
    {
        $policy = new CounterNormalizationPolicy(300, 600, ['interface.in_octets' => CounterWidth::Bits64]);

        $report = $this->normalize([self::at(0, '4294967290'), self::at(60, '5')], $policy);

        self::assertSame(IntervalKind::Reset, $report->intervals[0]->kind);
        self::assertNull($report->intervals[0]->delta);
        self::assertFalse($report->intervals[0]->rolledOver);
    }

    public function testDecreaseNearTheTopOf64BitsWithNoWidthAndNoRestartMarkerIsAReset(): void
    {
        $report = $this->normalize([self::at(0, '18446744073709551614'), self::at(60, '2')]);

        self::assertSame(IntervalKind::Reset, $report->intervals[0]->kind);
        self::assertNull($report->intervals[0]->delta);
        self::assertFalse($report->intervals[0]->rolledOver);
    }

    public function testAValueAbove32BitsCannotBeDeclaredAs32Bit(): void
    {
        $this->expectException(InvalidTelemetryValue::class);
        $this->expectExceptionMessage('does not fit a 32-bit counter');

        self::at(60, '4294967296', width: CounterWidth::Bits32);
    }

    public function testWidthDeclaredOnTheMetricAppliesWhenTheSampleDeclaresNone(): void
    {
        $policy = new CounterNormalizationPolicy(300, 600, ['interface.in_octets' => CounterWidth::Bits32]);

        $report = $this->normalize([self::at(0, '4294967290'), self::at(60, '5')], $policy);

        self::assertSame('11', $report->intervals[0]->delta?->toString());
    }

    public function testWidthOnTheSampleWinsOverTheMetricDefault(): void
    {
        $policy = new CounterNormalizationPolicy(300, 600, ['interface.in_octets' => CounterWidth::Bits32]);

        // These values fit 32 bits, so the metric default alone would make this an 11-octet rollover.
        $report = $this->normalize([
            self::at(0, '4294967290', width: CounterWidth::Bits64),
            self::at(60, '5', width: CounterWidth::Bits64),
        ], $policy);

        self::assertSame(IntervalKind::Reset, $report->intervals[0]->kind);
        self::assertNull($report->intervals[0]->delta);
    }

    public function testDecreaseWithNoDeclaredWidthIsAResetNotANegativeDelta(): void
    {
        $report = $this->normalize([self::at(0, '1000'), self::at(60, '10')]);

        $interval = $report->intervals[0];
        self::assertSame(IntervalKind::Reset, $interval->kind);
        self::assertNull($interval->delta);
        self::assertFalse($interval->rolledOver);
    }

    public function testDecreaseOnAValueTooWideForTheMetricWidthIsAReset(): void
    {
        $policy = new CounterNormalizationPolicy(300, 600, ['interface.in_octets' => CounterWidth::Bits32]);

        $report = $this->normalize([self::at(0, '8000000000'), self::at(60, '10')], $policy);

        self::assertSame(IntervalKind::Reset, $report->intervals[0]->kind);
    }

    public function testRestartMarkerMakesAnIncreasingCounterAReset(): void
    {
        $report = $this->normalize([self::at(0, '100'), self::at(60, '5000', restarted: true)]);

        self::assertSame(IntervalKind::Reset, $report->intervals[0]->kind);
        self::assertNull($report->intervals[0]->delta);
    }

    public function testRestartMarkerOverridesRolloverInterpretation(): void
    {
        $report = $this->normalize([
            self::at(0, '4294967290', width: CounterWidth::Bits32),
            self::at(60, '5', width: CounterWidth::Bits32, restarted: true),
        ]);

        self::assertSame(IntervalKind::Reset, $report->intervals[0]->kind);
        self::assertFalse($report->intervals[0]->rolledOver);
    }

    public function testIntervalAfterAResetUsesTheNewBaseline(): void
    {
        $report = $this->normalize([self::at(0, '1000'), self::at(60, '10'), self::at(120, '70')]);

        self::assertSame([IntervalKind::Reset, IntervalKind::Delta], array_map(static fn($i) => $i->kind, $report->intervals));
        self::assertSame('60', $report->intervals[1]->delta?->toString());
    }

    public function testExactDuplicatesAreDroppedAndCounted(): void
    {
        $report = $this->normalize([self::at(0, '100'), self::at(60, '160'), self::at(60, '160')]);

        self::assertCount(1, $report->intervals);
        self::assertSame(1, $report->duplicatesDropped);
        self::assertSame([], $report->errors);
    }

    public function testSameTimestampWithDifferentValuesIsReportedAsAConflict(): void
    {
        $report = $this->normalize([self::at(0, '100'), self::at(60, '160'), self::at(60, '170'), self::at(120, '220')]);

        self::assertCount(1, $report->errors);
        self::assertSame(CollectionErrorCode::Conflict, $report->errors[0]->code);
        self::assertSame('2026-03-01T00:01:00.000000+00:00', $report->errors[0]->at?->format('Y-m-d\TH:i:s.uP'));
        // Neither conflicting value is trusted, so the interval spans the gap they leave.
        self::assertCount(1, $report->intervals);
        self::assertSame('120', $report->intervals[0]->delta?->toString());
        self::assertSame(CollectionStatus::Partial, $report->status());
    }

    public function testConflictWithNothingUsableFails(): void
    {
        $report = $this->normalize([self::at(0, '1'), self::at(0, '2')]);

        self::assertSame([], $report->intervals);
        self::assertSame(CollectionStatus::Failed, $report->status());
    }

    public function testOutOfOrderInputIsSortedByTimestampSoArrivalOrderDoesNotMatter(): void
    {
        $ordered = $this->normalize([self::at(0, '100'), self::at(60, '160'), self::at(120, '300')]);
        $shuffled = $this->normalize([self::at(120, '300'), self::at(0, '100'), self::at(60, '160')]);

        self::assertEquals($ordered, $shuffled);
        self::assertSame(['60', '140'], array_map(static fn($i) => $i->delta?->toString(), $shuffled->intervals));
    }

    public function testGapLargerThanTheMaximumIsUnknownNotZero(): void
    {
        $report = $this->normalize([self::at(0, '100'), self::at(301, '100')]);

        $interval = $report->intervals[0];
        self::assertSame(IntervalKind::Gap, $interval->kind);
        self::assertNull($interval->delta);
        self::assertSame(DataQuality::Bad, $interval->quality);
    }

    public function testGapExactlyAtTheMaximumIsStillADelta(): void
    {
        $report = $this->normalize([self::at(0, '100'), self::at(300, '400')]);

        self::assertSame(IntervalKind::Delta, $report->intervals[0]->kind);
    }

    public function testGapBeatsDecreaseBecauseNeitherRolloverNorResetCanBeKnown(): void
    {
        $report = $this->normalize([
            self::at(0, '4294967290', width: CounterWidth::Bits32),
            self::at(1000, '5', width: CounterWidth::Bits32),
        ]);

        self::assertSame(IntervalKind::Gap, $report->intervals[0]->kind);
    }

    public function testGapBeatsADecreaseOnA64BitCounter(): void
    {
        $report = $this->normalize([
            self::at(0, '18446744073709551614', width: CounterWidth::Bits64),
            self::at(1000, '2', width: CounterWidth::Bits64),
        ]);

        self::assertSame(IntervalKind::Gap, $report->intervals[0]->kind);
    }

    public function testRestartInsideAGapIsStillReportedAsAReset(): void
    {
        $report = $this->normalize([self::at(0, '100'), self::at(1000, '5', restarted: true)]);

        self::assertSame(IntervalKind::Reset, $report->intervals[0]->kind);
    }

    public function testDataOlderThanTheStalenessThresholdIsStale(): void
    {
        // NOW is 600 s after the first sample; the newest sample is 540 s old.
        $report = $this->normalize([self::at(0, '1'), self::at(60, '2')], new CounterNormalizationPolicy(300, 500));

        self::assertSame(DataQuality::Stale, $report->intervals[0]->quality);
    }

    public function testFreshDataKeepsGoodQuality(): void
    {
        $report = $this->normalize([self::at(0, '1'), self::at(60, '2')], new CounterNormalizationPolicy(300, 600));

        self::assertSame(DataQuality::Good, $report->intervals[0]->quality);
    }

    public function testWorstInputQualityIsKept(): void
    {
        $report = $this->normalize([self::at(0, '1'), self::at(60, '2', quality: DataQuality::Estimated)]);

        self::assertSame(DataQuality::Estimated, $report->intervals[0]->quality);
    }

    public function testStalenessUsesTheInjectedClockOnly(): void
    {
        $later = new CounterNormalizer(new FixedClock(new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'))), new CardinalityGuard(10));

        $report = $later->normalize([self::at(0, '1'), self::at(60, '2')], new CounterNormalizationPolicy(300, 600));

        self::assertSame(DataQuality::Stale, $report->intervals[0]->quality);
    }

    public function testSamplesFromDifferentSeriesAreRejected(): void
    {
        $this->expectException(UnusableSeries::class);

        $this->normalize([self::at(0, '1'), self::at(60, '2', identity: 'eth1')]);
    }

    public function testEqualResourceNamesOnTwoDevicesAreNeverOneSeries(): void
    {
        $this->expectException(UnusableSeries::class);

        $this->normalize([self::at(0, '1', device: 1), self::at(60, '2', device: 2)]);
    }

    public function testReadingsCollectedOverDifferentProtocolsAreNeverOneSeries(): void
    {
        $this->expectException(UnusableSeries::class);

        $this->normalize([self::at(0, '1'), self::at(60, '2', protocol: CollectionProtocol::Gnmi)]);
    }

    public function testEachDeviceAndProtocolCountsAsItsOwnSeriesAgainstTheCardinalityLimit(): void
    {
        $normalizer = new CounterNormalizer(new FixedClock(self::time(600)), new CardinalityGuard(1));
        $policy = new CounterNormalizationPolicy(300, 600);
        $normalizer->normalize([self::at(0, '1', device: 1)], $policy);

        foreach ([[2, CollectionProtocol::Snmp], [1, CollectionProtocol::Gnmi]] as [$device, $protocol]) {
            try {
                $normalizer->normalize([self::at(0, '1', device: $device, protocol: $protocol)], $policy);
                self::fail('Device ' . $device . ' over ' . $protocol->value . ' was admitted as the already known series.');
            } catch (CardinalityLimitExceeded) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testGaugeSamplesAreRejected(): void
    {
        $this->expectException(UnusableSeries::class);

        $this->normalize([self::at(0, '1', type: MetricType::Gauge), self::at(60, '2', type: MetricType::Gauge)]);
    }

    public function testNonSampleInputIsRejected(): void
    {
        $this->expectException(UnusableSeries::class);

        $this->normalize(['not a sample']);
    }

    public function testNewSeriesBeyondTheCardinalityLimitFailsExplicitly(): void
    {
        $normalizer = new CounterNormalizer(new FixedClock(self::time(600)), new CardinalityGuard(1));
        $policy = new CounterNormalizationPolicy(300, 600);
        $normalizer->normalize([self::at(0, '1'), self::at(60, '2')], $policy);

        $this->expectException(CardinalityLimitExceeded::class);

        $normalizer->normalize([self::at(0, '1', identity: 'eth1'), self::at(60, '2', identity: 'eth1')], $policy);
    }

    public function testKnownSeriesStaysAdmittedOnLaterRuns(): void
    {
        $normalizer = new CounterNormalizer(new FixedClock(self::time(600)), new CardinalityGuard(1));
        $policy = new CounterNormalizationPolicy(300, 600);
        $normalizer->normalize([self::at(0, '1')], $policy);

        $report = $normalizer->normalize([self::at(60, '2'), self::at(120, '3')], $policy);

        self::assertCount(1, $report->intervals);
    }

    /**
     * @param list<mixed> $samples
     */
    private function normalize(array $samples, ?CounterNormalizationPolicy $policy = null): NormalizationReport
    {
        $normalizer = new CounterNormalizer(new FixedClock(self::time(600)), new CardinalityGuard(10));

        return $normalizer->normalize($samples, $policy ?? new CounterNormalizationPolicy(300, 600));
    }

    private static function time(int $seconds, int $micro = 0): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('UTC')))
            ->modify('+' . $seconds . ' seconds')
            ->modify('+' . $micro . ' microseconds');
    }

    private static function at(
        int $seconds,
        string $value,
        int $micro = 0,
        ?CounterWidth $width = null,
        bool $restarted = false,
        DataQuality $quality = DataQuality::Good,
        string $identity = 'eth0',
        MetricType $type = MetricType::Counter,
        int $device = 42,
        CollectionProtocol $protocol = CollectionProtocol::Snmp,
    ): Sample {
        return new Sample(
            deviceId: $device,
            protocol: $protocol,
            metric: new MetricId('interface.in_octets'),
            resource: new ResourceId('interface', $identity),
            type: $type,
            unit: Unit::Octets,
            timestamp: self::time($seconds, $micro),
            value: CounterValue::fromString($value),
            source: 'snmp',
            quality: $quality,
            counterWidth: $width,
            deviceRestarted: $restarted,
        );
    }
}
