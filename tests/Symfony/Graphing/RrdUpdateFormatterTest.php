<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Application\Port\PlannedUpdate;
use Kadupul\Graphing\Application\Port\UpdateEntry;
use Kadupul\Graphing\Application\Port\UpdatePlan;
use Kadupul\Graphing\Infrastructure\Rrd\RrdUpdateFormatter;
use Kadupul\Monitoring\Contract\RrdDataSourceType;
use Kadupul\Telemetry\Contract\AbsoluteValue;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\DeriveValue;
use Kadupul\Telemetry\Contract\GaugeValue;
use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\SampleValue;
use Kadupul\Telemetry\Contract\UnknownValue;
use Kadupul\Tests\Graphing\Fakes\FixedClock;
use Kadupul\Tests\Graphing\Fakes\RawSampleValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RrdUpdateFormatterTest extends TestCase
{
    private const T = GraphingSamples::EPOCH;

    private RrdUpdateFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new RrdUpdateFormatter(new FixedClock(GraphingSamples::at(self::T + 3600)), 300);
    }

    public function testOneGaugeSampleBecomesATemplatedRowOfSeparateArguments(): void
    {
        $plan = $this->format([$this->entry(0, GaugeValue::fromString('-12.5'), self::T + 300, 'temp', RrdDataSourceType::Gauge)]);

        self::assertSame([], $plan->rejections);
        self::assertCount(1, $plan->updates);
        self::assertSame('update', $plan->updates[0]->command->verb);
        self::assertSame(['--template', 'temp', '1000000300:-12.5'], $plan->updates[0]->command->arguments);
        self::assertSame([self::T + 300 => [0]], $plan->updates[0]->positionsByTimestamp);
    }

    public function testCountersAbove2To53StayExactAndUnknownIsUNeverZero(): void
    {
        $plan = $this->format([
            $this->entry(0, CounterValue::fromString('18446744073709551615'), self::T + 300, 'traffic_in'),
            $this->entry(1, UnknownValue::instance(), self::T + 600, 'traffic_in'),
            $this->entry(2, AbsoluteValue::fromString('9007199254740993'), self::T + 300, 'events', RrdDataSourceType::Absolute),
        ]);

        self::assertSame([], $plan->rejections);
        self::assertSame(['--template', 'events:traffic_in', '1000000300:9007199254740993:18446744073709551615'], $plan->updates[0]->command->arguments);
        self::assertSame(['--template', 'traffic_in', '1000000600:U'], $plan->updates[1]->command->arguments);
    }

    public function testSamplesForSeveralDataSourcesAtOneSecondShareARowUnderASortedTemplate(): void
    {
        $plan = $this->format([
            $this->entry(0, CounterValue::fromInt(20), self::T + 300, 'traffic_out'),
            $this->entry(1, CounterValue::fromInt(10), self::T + 300, 'traffic_in'),
            $this->entry(2, CounterValue::fromInt(30), self::T + 600, 'traffic_in'),
            $this->entry(3, CounterValue::fromInt(40), self::T + 600, 'traffic_out'),
        ]);

        self::assertCount(1, $plan->updates);
        self::assertSame(['--template', 'traffic_in:traffic_out', '1000000300:10:20', '1000000600:30:40'], $plan->updates[0]->command->arguments);
        self::assertSame([self::T + 300 => [0, 1], self::T + 600 => [2, 3]], $plan->updates[0]->positionsByTimestamp);
    }

    public function testRowsWithDifferentDataSourceSetsSplitIntoConsecutiveCallsInTimeOrder(): void
    {
        // Calls run in sequence and RRDtool needs every call newer than the last,
        // so a template change starts a new call rather than regrouping by template.
        $plan = $this->format([
            $this->entry(0, CounterValue::fromInt(5), self::T + 900, 'traffic_in'),
            $this->entry(1, CounterValue::fromInt(6), self::T + 900, 'traffic_out'),
            $this->entry(2, CounterValue::fromInt(3), self::T + 600, 'traffic_in'),
            $this->entry(3, CounterValue::fromInt(1), self::T + 300, 'traffic_in'),
            $this->entry(4, CounterValue::fromInt(2), self::T + 300, 'traffic_out'),
        ]);

        self::assertSame([], $plan->rejections);
        self::assertSame(
            [
                ['--template', 'traffic_in:traffic_out', '1000000300:1:2'],
                ['--template', 'traffic_in', '1000000600:3'],
                ['--template', 'traffic_in:traffic_out', '1000000900:5:6'],
            ],
            array_map(static fn(PlannedUpdate $u): array => $u->command->arguments, $plan->updates),
        );
    }

    public function testOutOfOrderInputIsSortedSoTimestampsIncrease(): void
    {
        $plan = $this->format([
            $this->entry(0, CounterValue::fromInt(3), self::T + 900, 'traffic_in'),
            $this->entry(1, CounterValue::fromInt(1), self::T + 300, 'traffic_in'),
            $this->entry(2, CounterValue::fromInt(2), self::T + 600, 'traffic_in'),
        ]);

        self::assertSame(['--template', 'traffic_in', '1000000300:1', '1000000600:2', '1000000900:3'], $plan->updates[0]->command->arguments);
    }

    public function testTimestampsAreTruncatedToSecondsAndASubSecondCollisionIsADuplicate(): void
    {
        $plan = $this->format([
            $this->entry(0, CounterValue::fromInt(1), self::T + 300, 'traffic_in', RrdDataSourceType::Counter, 250000),
            $this->entry(1, CounterValue::fromInt(2), self::T + 300, 'traffic_in', RrdDataSourceType::Counter, 750000),
        ]);

        self::assertSame(['--template', 'traffic_in', '1000000300:1'], $plan->updates[0]->command->arguments);
        self::assertSame([1], array_keys($plan->rejections));
        self::assertSame(RejectionReason::DuplicateSample, $plan->rejections[1]->reason);
        self::assertStringContainsString('1000000300', $plan->rejections[1]->detail);
    }

    public function testSamplesAtOrBeforeTheFilesLastUpdateAreDuplicatesOrOutOfOrder(): void
    {
        $plan = $this->format([
            $this->entry(0, CounterValue::fromInt(1), self::T + 300, 'traffic_in'),
            $this->entry(1, CounterValue::fromInt(2), self::T + 600, 'traffic_in'),
            $this->entry(2, CounterValue::fromInt(3), self::T + 900, 'traffic_in'),
        ], self::T + 600);

        self::assertSame(RejectionReason::OutOfOrder, $plan->rejections[0]->reason);
        self::assertSame(RejectionReason::DuplicateSample, $plan->rejections[1]->reason);
        self::assertSame(['--template', 'traffic_in', '1000000900:3'], $plan->updates[0]->command->arguments);
    }

    public function testNothingToWriteYieldsNoUpdates(): void
    {
        $plan = $this->format([$this->entry(0, CounterValue::fromInt(1), self::T + 300, 'traffic_in')], self::T + 300);

        self::assertSame([], $plan->updates);
        self::assertSame(RejectionReason::DuplicateSample, $plan->rejections[0]->reason);
    }

    public function testTimestampsMustBePositiveAndNotFarInTheFuture(): void
    {
        $now = self::T + 3600;
        $plan = $this->format([
            $this->entry(0, CounterValue::fromInt(1), 0, 'traffic_in'),
            $this->entry(1, CounterValue::fromInt(2), $now + 301, 'traffic_in'),
            $this->entry(2, CounterValue::fromInt(3), $now + 300, 'traffic_in'),
        ]);

        self::assertSame(RejectionReason::InvalidTimestamp, $plan->rejections[0]->reason);
        self::assertSame(RejectionReason::InvalidTimestamp, $plan->rejections[1]->reason);
        self::assertArrayNotHasKey(2, $plan->rejections);
        self::assertSame(['--template', 'traffic_in', '1000003900:3'], $plan->updates[0]->command->arguments);
    }

    /** @return iterable<string, array{SampleValue, RrdDataSourceType}> */
    public static function mismatchedTypes(): iterable
    {
        yield 'gauge into counter' => [GaugeValue::fromString('1'), RrdDataSourceType::Counter];
        yield 'derive into counter' => [DeriveValue::fromInt(1), RrdDataSourceType::Counter];
        yield 'counter into gauge' => [CounterValue::fromInt(1), RrdDataSourceType::Gauge];
        yield 'counter into derive' => [CounterValue::fromInt(1), RrdDataSourceType::Derive];
        yield 'derive into absolute' => [DeriveValue::fromInt(1), RrdDataSourceType::Absolute];
        yield 'gauge into absolute' => [GaugeValue::fromString('1'), RrdDataSourceType::Absolute];
        yield 'absolute into gauge' => [AbsoluteValue::fromInt(1), RrdDataSourceType::Gauge];
    }

    #[DataProvider('mismatchedTypes')]
    public function testAValueOfAnotherDataSourceFamilyIsATypeMismatch(SampleValue $value, RrdDataSourceType $type): void
    {
        $plan = $this->format([$this->entry(0, $value, self::T + 300, 'field', $type)]);

        self::assertSame([], $plan->updates);
        self::assertSame(RejectionReason::TypeMismatch, $plan->rejections[0]->reason);
    }

    /** @return iterable<string, array{SampleValue, RrdDataSourceType}> */
    public static function compatibleTypes(): iterable
    {
        yield 'counter' => [CounterValue::fromInt(1), RrdDataSourceType::Counter];
        yield 'absolute into counter' => [AbsoluteValue::fromInt(1), RrdDataSourceType::Counter];
        yield 'absolute' => [AbsoluteValue::fromInt(1), RrdDataSourceType::Absolute];
        yield 'counter into absolute' => [CounterValue::fromInt(1), RrdDataSourceType::Absolute];
        yield 'negative derive' => [DeriveValue::fromString('-9223372036854775808'), RrdDataSourceType::Derive];
        yield 'gauge' => [GaugeValue::fromString('0.25'), RrdDataSourceType::Gauge];
        yield 'unknown counter' => [UnknownValue::instance(), RrdDataSourceType::Counter];
        yield 'unknown derive' => [UnknownValue::instance(), RrdDataSourceType::Derive];
        yield 'unknown gauge' => [UnknownValue::instance(), RrdDataSourceType::Gauge];
        yield 'unknown absolute' => [UnknownValue::instance(), RrdDataSourceType::Absolute];
    }

    #[DataProvider('compatibleTypes')]
    public function testCompatibleValuesAreWritten(SampleValue $value, RrdDataSourceType $type): void
    {
        $plan = $this->format([$this->entry(0, $value, self::T + 300, 'field', $type)]);

        self::assertSame([], $plan->rejections);
        self::assertSame(['--template', 'field', '1000000300:' . $value->toWire()], $plan->updates[0]->command->arguments);
    }

    /** @return iterable<string, array{string, RrdDataSourceType}> */
    public static function smuggledValues(): iterable
    {
        yield 'field separator' => ['1:2', RrdDataSourceType::Gauge];
        yield 'space' => ['1 2', RrdDataSourceType::Gauge];
        yield 'newline' => ["1\n", RrdDataSourceType::Gauge];
        yield 'NUL' => ["1\0", RrdDataSourceType::Gauge];
        yield 'option' => ['--daemon', RrdDataSourceType::Gauge];
        yield 'empty' => ['', RrdDataSourceType::Gauge];
        yield 'exponent' => ['1e5', RrdDataSourceType::Gauge];
        yield 'float counter' => ['1.5', RrdDataSourceType::Counter];
        yield 'negative counter' => ['-1', RrdDataSourceType::Counter];
        yield 'counter beyond 2^64' => ['18446744073709551616', RrdDataSourceType::Counter];
        yield 'absolute beyond 2^64' => ['18446744073709551616', RrdDataSourceType::Absolute];
        yield 'derive beyond 2^63' => ['9223372036854775808', RrdDataSourceType::Derive];
        yield 'derive below -2^63' => ['-9223372036854775809', RrdDataSourceType::Derive];
        yield 'long gauge' => [str_repeat('9', 65), RrdDataSourceType::Gauge];
        yield 'lowercase unknown' => ['u', RrdDataSourceType::Gauge];
    }

    #[DataProvider('smuggledValues')]
    public function testAValueThatIsNotValidUpdateTextIsRejected(string $wire, RrdDataSourceType $type): void
    {
        $plan = $this->format([$this->entry(0, new RawSampleValue($wire), self::T + 300, 'field', $type)]);

        self::assertSame([], $plan->updates);
        self::assertSame(RejectionReason::InvalidValue, $plan->rejections[0]->reason);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'twenty characters' => [str_repeat('a', 20)];
        yield 'field separator' => ['in:out'];
        yield 'option' => ['-x'];
        yield 'space' => ['in out'];
        yield 'NUL' => ["in\0"];
    }

    #[DataProvider('invalidNames')]
    public function testADataSourceNameRrdtoolCannotHoldIsRejected(string $name): void
    {
        $plan = $this->format([$this->entry(0, CounterValue::fromInt(1), self::T + 300, $name)]);

        self::assertSame([], $plan->updates);
        self::assertSame(RejectionReason::TargetMissing, $plan->rejections[0]->reason);
    }

    public function testEveryPositionEndsUpInExactlyOneRejectionOrUpdate(): void
    {
        $plan = $this->format([
            $this->entry(4, CounterValue::fromInt(1), self::T + 300, 'traffic_in'),
            $this->entry(7, CounterValue::fromInt(2), self::T + 300, 'traffic_in'),
            $this->entry(9, GaugeValue::fromString('1'), self::T + 300, 'traffic_out'),
            $this->entry(11, CounterValue::fromInt(3), self::T + 600, 'traffic_out'),
        ]);

        $placed = array_keys($plan->rejections);
        foreach ($plan->updates as $update) {
            $placed = array_merge($placed, ...array_values($update->positionsByTimestamp));
        }
        sort($placed);
        self::assertSame([4, 7, 9, 11], $placed);
    }

    /** @param list<UpdateEntry> $entries */
    private function format(array $entries, ?int $lastUpdate = null): UpdatePlan
    {
        return $this->formatter->format($entries, $lastUpdate);
    }

    private function entry(
        int $position,
        SampleValue $value,
        int $seconds,
        string $name,
        RrdDataSourceType $type = RrdDataSourceType::Counter,
        int $microseconds = 0,
    ): UpdateEntry {
        return new UpdateEntry($position, GraphingSamples::sample($value, $seconds, microseconds: $microseconds), $name, $type);
    }
}
