<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Application\Command\RecordSamples;
use Kadupul\Graphing\Application\Command\WriteGate;
use Kadupul\Graphing\Infrastructure\Rrd\ConfiguredRootTargetLocator;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolInspector;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolProcess;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolUpdater;
use Kadupul\Graphing\Infrastructure\Rrd\RrdUpdateFormatter;
use Kadupul\Monitoring\Contract\RrdDataSourceType;
use Kadupul\Monitoring\Contract\WriterOwnership;
use Kadupul\Telemetry\Contract\AbsoluteValue;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\DeriveValue;
use Kadupul\Telemetry\Contract\GaugeValue;
use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\SampleOutcome;
use Kadupul\Telemetry\Contract\SampleValue;
use Kadupul\Telemetry\Contract\SinkReport;
use Kadupul\Telemetry\Contract\UnknownValue;
use Kadupul\Tests\Graphing\Fakes\FakeBindingCatalog;
use Kadupul\Tests\Graphing\Fakes\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * RecordSamples with the real rrdtool adapters against RRD files created in
 * a temporary directory. Each refusal is checked against the file itself:
 * its bytes, modification time and rrdtool lastupdate must not change.
 */
final class RecordSamplesRrdIntegrationTest extends TestCase
{
    private const DEVICE = 42;
    /** A multiple of the 300-second step, so each row closes exactly one interval and fetched rates are exact. */
    private const T = 1000000200;

    private string $rrdtool;
    private TemporaryRrdRoot $root;
    private TemporaryRrdRoot $outside;
    private FakeBindingCatalog $catalog;
    private string $traffic;
    private string $errors;
    private string $escaped;

    protected function setUp(): void
    {
        $this->rrdtool = TemporaryRrdRoot::rrdtoolOrSkip($this);
        $this->root = TemporaryRrdRoot::create();
        $this->outside = TemporaryRrdRoot::create();
        $this->traffic = $this->root->createRrd($this->rrdtool, 'device_42/traffic_7.rrd', [
            'DS:traffic_in:COUNTER:600:0:U',
            'DS:temp:GAUGE:600:U:U',
            'DS:delta:DERIVE:600:U:U',
            'DS:events:ABSOLUTE:600:0:U',
        ], self::T - 300);
        $this->errors = $this->root->createRrd($this->rrdtool, 'device_42/errors_8.rrd', ['DS:in_errors:COUNTER:600:0:U', 'DS:out_errors:COUNTER:600:0:U'], self::T - 300);
        $this->root->createRrd($this->rrdtool, 'device_42/capped_12.rrd', ['DS:traffic_in:COUNTER:600:0:1000000'], self::T - 300);
        $this->escaped = $this->outside->createRrd($this->rrdtool, 'outside.rrd', ['DS:traffic_in:COUNTER:600:0:U'], self::T - 300);
        $this->root->symlink($this->escaped, 'device_42/escape_11.rrd');

        $this->catalog = new FakeBindingCatalog();
        $this->bind('interface.in_octets', 'traffic_in', RrdDataSourceType::Counter, 'local-data:7');
        $this->bind('interface.temperature', 'temp', RrdDataSourceType::Gauge, 'local-data:7');
        $this->bind('interface.queue_delta', 'delta', RrdDataSourceType::Derive, 'local-data:7');
        $this->bind('interface.drops', 'events', RrdDataSourceType::Absolute, 'local-data:7');
        $this->bind('interface.in_errors', 'in_errors', RrdDataSourceType::Counter, 'local-data:8');
        $this->bind('interface.out_errors', 'out_errors', RrdDataSourceType::Counter, 'local-data:8');
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->root->remove();
            $this->outside->remove();
        }
    }

    public function testEachDataSourceTypeIsWrittenAndConfirmedByLastupdate(): void
    {
        $report = $this->sink()->accept([
            $this->sample(CounterValue::fromInt(1000), self::T, 'interface.in_octets'),
            $this->sample(GaugeValue::fromString('-12.5'), self::T, 'interface.temperature'),
            $this->sample(DeriveValue::fromInt(-40), self::T, 'interface.queue_delta'),
            $this->sample(AbsoluteValue::fromInt(7), self::T, 'interface.drops'),
        ]);

        self::assertTrue($report->allAccepted(), $this->describe($report));
        self::assertSame(
            [self::T, ['traffic_in' => '1000', 'temp' => '-12.5', 'delta' => '-40', 'events' => '7']],
            $this->root->lastUpdate($this->rrdtool, $this->traffic),
        );
    }

    public function testA64BitCounterAbove2To53IsAcceptedExactly(): void
    {
        // rrdtool keeps the raw text of the last reading (last_ds) exactly as
        // given, which lastupdate prints, and computes COUNTER deltas from the
        // decimal strings. The rates it stores are doubles. Both readings lie
        // between 2^64 - 4096 and 2^64, where a double cannot tell them apart;
        // a float conversion would have produced a rate of 0, not 1.
        $report = $this->sink()->accept([
            $this->sample(CounterValue::fromString('18446744073709551000'), self::T, 'interface.in_octets'),
            $this->sample(CounterValue::fromString('18446744073709551300'), self::T + 300, 'interface.in_octets'),
        ]);

        self::assertTrue($report->allAccepted(), $this->describe($report));
        self::assertSame('18446744073709551300', $this->root->lastUpdate($this->rrdtool, $this->traffic)[1]['traffic_in']);
        self::assertSame([[self::T + 300, '1.0000000000e+00']], $this->rates($this->traffic, self::T + 300, self::T + 300));
    }

    public function testAnUnknownReadingIsStoredAsUNotZero(): void
    {
        $report = $this->sink()->accept([$this->sample(UnknownValue::instance(), self::T, 'interface.in_octets')]);

        self::assertTrue($report->allAccepted(), $this->describe($report));
        self::assertSame('U', $this->root->lastUpdate($this->rrdtool, $this->traffic)[1]['traffic_in']);
    }

    public function testACounterResetIsLeftToRrdtoolAndStoresNoNegativeRate(): void
    {
        // The data source caps the rate at 1000000 per second. rrdtool treats
        // the drop from 2000 to 500 as a 32-bit wrap; the implied rate exceeds
        // the cap, so the interval is stored as unknown rather than negative.
        $this->bind('interface.in_octets', 'traffic_in', RrdDataSourceType::Counter, 'local-data:12');

        $report = $this->sink()->accept([
            $this->sample(CounterValue::fromInt(1000), self::T, 'interface.in_octets'),
            $this->sample(CounterValue::fromInt(2000), self::T + 300, 'interface.in_octets'),
            $this->sample(CounterValue::fromInt(500), self::T + 600, 'interface.in_octets'),
            $this->sample(CounterValue::fromInt(1500), self::T + 900, 'interface.in_octets'),
        ]);

        self::assertTrue($report->allAccepted(), $this->describe($report));
        self::assertSame(
            [[self::T + 300, '3.3333333333e+00'], [self::T + 600, 'nan'], [self::T + 900, '3.3333333333e+00']],
            $this->rates($this->root->path . '/device_42/capped_12.rrd', self::T + 300, self::T + 900),
        );
    }

    public function testSeveralDataSourcesOfOneFileShareARow(): void
    {
        $report = $this->sink()->accept([
            $this->sample(CounterValue::fromInt(3), self::T, 'interface.in_errors'),
            $this->sample(CounterValue::fromInt(4), self::T, 'interface.out_errors'),
        ]);

        self::assertTrue($report->allAccepted(), $this->describe($report));
        self::assertSame([self::T, ['in_errors' => '3', 'out_errors' => '4']], $this->root->lastUpdate($this->rrdtool, $this->errors));
    }

    public function testABindingExpectingAnotherTypeIsRejectedBeforeAnyWrite(): void
    {
        $this->bind('interface.in_octets', 'traffic_in', RrdDataSourceType::Gauge, 'local-data:7');

        $this->assertUntouched($this->traffic, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::TypeMismatch],
            [$this->sample(GaugeValue::fromString('1'), self::T, 'interface.in_octets')],
        ));
    }

    public function testAMissingFileAndAMissingDataSourceAreTargetMissing(): void
    {
        $this->bind('interface.in_octets', 'traffic_in', RrdDataSourceType::Counter, 'local-data:9');
        $this->bind('interface.in_errors', 'absent_ds', RrdDataSourceType::Counter, 'local-data:8');

        $this->assertUntouched($this->errors, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::TargetMissing, RejectionReason::TargetMissing],
            [$this->sample(CounterValue::fromInt(1), self::T, 'interface.in_octets'), $this->sample(CounterValue::fromInt(1), self::T, 'interface.in_errors')],
        ));
        self::assertFileDoesNotExist($this->root->path . '/device_42/missing_9.rrd');
    }

    public function testInvalidTimestampsAreRejectedWithoutWriting(): void
    {
        $this->assertUntouched($this->traffic, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::InvalidTimestamp, RejectionReason::InvalidTimestamp],
            [$this->sample(CounterValue::fromInt(1), 0, 'interface.in_octets'), $this->sample(CounterValue::fromInt(1), self::T + 90000, 'interface.in_octets')],
        ));
    }

    public function testOutOfOrderAndDuplicateSamplesAreRejectedWithoutWriting(): void
    {
        self::assertTrue($this->sink()->accept([$this->sample(CounterValue::fromInt(5), self::T + 600, 'interface.in_octets')])->allAccepted());

        $this->assertUntouched($this->traffic, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::OutOfOrder, RejectionReason::DuplicateSample],
            [$this->sample(CounterValue::fromInt(1), self::T + 300, 'interface.in_octets'), $this->sample(CounterValue::fromInt(6), self::T + 600, 'interface.in_octets')],
        ));
    }

    public function testResubmittingTheSameBatchDoesNotWriteTwice(): void
    {
        $batch = [
            $this->sample(CounterValue::fromInt(1), self::T, 'interface.in_errors'),
            $this->sample(CounterValue::fromInt(2), self::T + 300, 'interface.in_errors'),
            $this->sample(CounterValue::fromInt(9), self::T + 300, 'interface.out_errors'),
        ];
        self::assertTrue($this->sink()->accept($batch)->allAccepted());

        $this->assertUntouched($this->errors, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::OutOfOrder, RejectionReason::DuplicateSample, RejectionReason::DuplicateSample],
            $batch,
        ));
    }

    public function testAnRrdtoolUpdateFailureIsAStorageFailureWithoutThePath(): void
    {
        // Readable but not writable: inspection succeeds and the update itself fails.
        chmod($this->traffic, 0444);
        if (is_writable($this->traffic)) {
            self::markTestSkipped('Running as a user who can write read-only files, such as root; the update failure cannot be provoked this way.');
        }

        $report = $this->assertUntouched($this->traffic, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::StorageFailure],
            [$this->sample(CounterValue::fromInt(1), self::T, 'interface.in_octets')],
        ));

        self::assertStringContainsString('rrdtool update exited with status', $report->outcomes()[0]->detail);
        self::assertStringNotContainsString($this->root->path, $report->outcomes()[0]->detail);
    }

    public function testTraversalAndSymlinkEscapesAreRefusedAndTheOutsideFileIsUntouched(): void
    {
        $this->bind('interface.in_octets', 'traffic_in', RrdDataSourceType::Counter, 'local-data:10');
        $this->bind('interface.in_errors', 'traffic_in', RrdDataSourceType::Counter, 'local-data:11');

        $this->assertUntouched($this->escaped, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::StorageFailure, RejectionReason::StorageFailure],
            [$this->sample(CounterValue::fromInt(1), self::T, 'interface.in_octets'), $this->sample(CounterValue::fromInt(1), self::T, 'interface.in_errors')],
        ));
    }

    public function testAClosedWriteGateLeavesTheFileUntouched(): void
    {
        $this->assertUntouched($this->traffic, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::WritesDisabled],
            [$this->sample(CounterValue::fromInt(1), self::T, 'interface.in_octets')],
            new WriteGate(),
        ));
    }

    public function testLegacyOwnedAndConflictingFieldsAreNeverWritten(): void
    {
        $this->bind('interface.in_octets', 'traffic_in', RrdDataSourceType::Counter, 'local-data:7', WriterOwnership::LegacyPoller);
        $this->bind('interface.temperature', 'temp', RrdDataSourceType::Gauge, 'local-data:7', WriterOwnership::Conflict);

        $this->assertUntouched($this->traffic, fn(): SinkReport => $this->expectReasons(
            [RejectionReason::NotOwnedByModelDriven, RejectionReason::OwnershipConflict],
            [$this->sample(CounterValue::fromInt(1), self::T, 'interface.in_octets'), $this->sample(GaugeValue::fromString('1'), self::T, 'interface.temperature')],
        ));
    }

    private function sink(?WriteGate $gate = null): RecordSamples
    {
        $process = new RrdtoolProcess($this->rrdtool);

        return new RecordSamples(
            $this->catalog,
            $gate ?? WriteGate::enabled(),
            new ConfiguredRootTargetLocator($this->root->path, [
                7 => 'device_42/traffic_7.rrd',
                8 => 'device_42/errors_8.rrd',
                9 => 'device_42/missing_9.rrd',
                10 => '../' . basename($this->outside->path) . '/outside.rrd',
                11 => 'device_42/escape_11.rrd',
                12 => 'device_42/capped_12.rrd',
            ]),
            new RrdtoolInspector($process),
            new RrdUpdateFormatter(new FixedClock(GraphingSamples::at(self::T + 3600))),
            new RrdtoolUpdater($process),
        );
    }

    private function bind(string $metric, string $name, RrdDataSourceType $type, string $storage, WriterOwnership $ownership = WriterOwnership::ModelDriven): void
    {
        $this->catalog->bind(self::DEVICE, $metric, 'eth0', $name, $type, $storage, $ownership);
    }

    private function sample(SampleValue $value, int $seconds, string $metric): Sample
    {
        return GraphingSamples::sample($value, $seconds, $metric);
    }

    /**
     * @param list<RejectionReason|null> $reasons
     * @param list<Sample> $samples
     */
    private function expectReasons(array $reasons, array $samples, ?WriteGate $gate = null): SinkReport
    {
        $report = $this->sink($gate)->accept($samples);
        self::assertSame($reasons, array_map(static fn(SampleOutcome $o): ?RejectionReason => $o->reason, $report->outcomes()), $this->describe($report));

        return $report;
    }

    /** @param \Closure(): SinkReport $act */
    private function assertUntouched(string $file, \Closure $act): SinkReport
    {
        // Any rrdtool update rewrites the header's last-update field, so the hash
        // proves no write even where modification times have one-second resolution.
        $before = $this->fingerprint($file);
        $report = $act();
        self::assertSame($before, $this->fingerprint($file));

        return $report;
    }

    /** @return array{string, int|false, array{int, array<string, string>}} */
    private function fingerprint(string $file): array
    {
        clearstatcache(true, $file);

        return [(string) hash_file('sha256', $file), filemtime($file), $this->root->lastUpdate($this->rrdtool, $file)];
    }

    /** @return list<array{int, string}> the traffic_in rate of each interval ending in the range */
    private function rates(string $file, int $from, int $to): array
    {
        $rates = [];
        foreach ($this->root->fetch($this->rrdtool, $file, $from - 300, $to) as [$second, $values]) {
            if ($second >= $from && $second <= $to) {
                $rates[] = [$second, $values[0]];
            }
        }

        return $rates;
    }

    private function describe(SinkReport $report): string
    {
        return implode('; ', array_map(static fn(SampleOutcome $o): string => ($o->reason?->name ?? 'accepted') . ' ' . $o->detail, $report->outcomes()));
    }
}
