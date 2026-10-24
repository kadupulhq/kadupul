<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Application\Command\RecordSamples;
use Kadupul\Graphing\Application\Command\WriteGate;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Infrastructure\Rrd\RrdUpdateFormatter;
use Kadupul\Monitoring\Contract\BindingResolution;
use Kadupul\Monitoring\Contract\ResolutionStatus;
use Kadupul\Monitoring\Contract\RrdDataSourceType;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Monitoring\Contract\WriterOwnership;
use Kadupul\Telemetry\Contract\CollectionProtocol;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\GaugeValue;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\SampleOutcome;
use Kadupul\Telemetry\Contract\SampleSink;
use Kadupul\Telemetry\Contract\SampleValue;
use Kadupul\Telemetry\Contract\SinkReport;
use Kadupul\Tests\Graphing\Fakes\FakeBindingCatalog;
use Kadupul\Tests\Graphing\Fakes\FakeRrdFiles;
use Kadupul\Tests\Graphing\Fakes\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordSamplesTest extends TestCase
{
    private const DEVICE = 42;
    private const T = GraphingSamples::EPOCH;
    private const TRAFFIC = 'local-data:7';
    private const ERRORS = 'local-data:8';

    private FakeBindingCatalog $catalog;
    private FakeRrdFiles $files;

    protected function setUp(): void
    {
        $this->catalog = new FakeBindingCatalog();
        $this->files = new FakeRrdFiles();
        $this->files->add(self::TRAFFIC, ['traffic_in' => 'COUNTER', 'traffic_out' => 'COUNTER']);
        $this->files->add(self::ERRORS, ['errors_in' => 'COUNTER']);
        $this->catalog->bind(self::DEVICE, 'interface.in_octets', 'eth0', 'traffic_in', RrdDataSourceType::Counter, self::TRAFFIC);
        $this->catalog->bind(self::DEVICE, 'interface.out_octets', 'eth0', 'traffic_out', RrdDataSourceType::Counter, self::TRAFFIC);
        $this->catalog->bind(self::DEVICE, 'interface.in_errors', 'eth0', 'errors_in', RrdDataSourceType::Counter, self::ERRORS);
    }

    public function testItIsATelemetrySampleSink(): void
    {
        self::assertInstanceOf(SampleSink::class, $this->sink());
    }

    public function testTheWriteGateIsClosedUnlessOpenedExplicitly(): void
    {
        self::assertFalse((new WriteGate())->permitsWrites());
        self::assertFalse(WriteGate::disabled()->permitsWrites());
        self::assertTrue(WriteGate::enabled()->permitsWrites());
    }

    public function testAClosedGateRejectsEverySampleBeforeAnyLookupOrStorageAccess(): void
    {
        $report = $this->sink(new WriteGate())->accept([
            $this->in(1, self::T + 300),
            $this->sample(CounterValue::fromInt(2), self::T + 300, 'interface.unbound'),
        ]);

        self::assertSame([RejectionReason::WritesDisabled, RejectionReason::WritesDisabled], $this->reasons($report));
        self::assertSame([], $this->catalog->calls);
        self::assertSame([], $this->files->log);
    }

    public function testAResolvedModelDrivenBindingIsWrittenAndReportedAccepted(): void
    {
        $report = $this->sink()->accept([$this->in(100, self::T + 300), $this->in(200, self::T + 600)]);

        self::assertTrue($report->allAccepted());
        self::assertSame([[self::DEVICE, 'interface.in_octets', 'interface/eth0', 'snmp'], [self::DEVICE, 'interface.in_octets', 'interface/eth0', 'snmp']], $this->catalog->calls);
        self::assertSame([['--template', 'traffic_in', '1000000300:100', '1000000600:200']], array_merge(...array_values($this->files->updates)));
        self::assertSame(self::T + 600, $this->files->lastUpdate(self::TRAFFIC));
    }

    /** @return iterable<string, array{ResolutionStatus, RejectionReason}> */
    public static function unresolved(): iterable
    {
        yield 'no binding' => [ResolutionStatus::NoBinding, RejectionReason::NoBinding];
        yield 'ambiguous' => [ResolutionStatus::Ambiguous, RejectionReason::AmbiguousBinding];
        yield 'not approved' => [ResolutionStatus::NotApproved, RejectionReason::NoBinding];
    }

    #[DataProvider('unresolved')]
    public function testAnUnresolvedBindingIsRejectedWithoutTouchingStorage(ResolutionStatus $status, RejectionReason $reason): void
    {
        $this->catalog->answer('interface.in_octets', 'eth0', new BindingResolution(
            $status,
            self::DEVICE,
            new MetricId('interface.in_octets'),
            new ResourceId('interface', 'eth0'),
            'snmp',
            'Binding device 42 interface/eth0 has a reason with a slash in it.',
        ));

        $report = $this->sink()->accept([$this->in(1, self::T + 300)]);

        self::assertSame([$reason], $this->reasons($report));
        self::assertSame([], $this->files->log);
    }

    /** @return iterable<string, array{WriterOwnership, RejectionReason}> */
    public static function notOwned(): iterable
    {
        yield 'legacy poller' => [WriterOwnership::LegacyPoller, RejectionReason::NotOwnedByModelDriven];
        yield 'unassigned' => [WriterOwnership::Unassigned, RejectionReason::NotOwnedByModelDriven];
        yield 'conflict' => [WriterOwnership::Conflict, RejectionReason::OwnershipConflict];
    }

    #[DataProvider('notOwned')]
    public function testAFieldNotOwnedByModelDrivenCollectionIsNeverWritten(WriterOwnership $ownership, RejectionReason $reason): void
    {
        $this->catalog->bind(self::DEVICE, 'interface.in_octets', 'eth0', 'traffic_in', RrdDataSourceType::Counter, self::TRAFFIC, $ownership);

        $report = $this->sink()->accept([$this->in(1, self::T + 300)]);

        self::assertSame([$reason], $this->reasons($report));
        self::assertSame([], $this->files->log);
    }

    /** @return iterable<string, array{int, string, string, string}> */
    public static function otherSeries(): iterable
    {
        yield 'metric' => [self::DEVICE, 'interface.out_octets', 'eth0', 'snmp'];
        yield 'resource' => [self::DEVICE, 'interface.in_octets', 'eth1', 'snmp'];
        yield 'device' => [43, 'interface.in_octets', 'eth0', 'snmp'];
        yield 'protocol' => [self::DEVICE, 'interface.in_octets', 'eth0', 'gnmi'];
    }

    #[DataProvider('otherSeries')]
    public function testAResolutionForADifferentSeriesThanTheSampleIsRejected(int $device, string $metric, string $identity, string $protocol): void
    {
        $this->catalog->answer('interface.in_octets', 'eth0', new BindingResolution(
            ResolutionStatus::Resolved,
            $device,
            new MetricId($metric),
            new ResourceId('interface', $identity),
            $protocol,
            'scripted',
            'binding-x',
            'openconfig-interfaces',
            '1.0.0',
            WriterOwnership::ModelDriven,
            'traffic_in',
            RrdDataSourceType::Counter,
            new StorageReference(self::TRAFFIC),
        ));

        $report = $this->sink()->accept([$this->in(1, self::T + 300)]);

        self::assertSame([RejectionReason::NoBinding], $this->reasons($report));
        self::assertStringContainsString('different series', $report->outcomes()[0]->detail);
        self::assertSame([], $this->files->log);
    }

    public function testOneSinkServesSeveralDevicesAndEachSampleResolvesForItsOwnDevice(): void
    {
        $this->files->add('local-data:9', ['traffic_in' => 'COUNTER']);
        $this->catalog->bind(43, 'interface.in_octets', 'eth0', 'traffic_in', RrdDataSourceType::Counter, 'local-data:9');

        $report = $this->sink()->accept([
            GraphingSamples::sample(CounterValue::fromInt(1), self::T + 300, device: 43),
            $this->in(2, self::T + 300),
            GraphingSamples::sample(CounterValue::fromInt(3), self::T + 300, device: 44),
            $this->errors(4, self::T + 300),
        ]);

        self::assertSame([null, null, RejectionReason::NoBinding, null], $this->reasons($report));
        self::assertStringContainsString('device 44', $report->outcomes()[2]->detail);
        self::assertSame([43, 42, 44, 42], array_column($this->catalog->calls, 0));
        self::assertSame([['--template', 'traffic_in', '1000000300:1']], $this->files->updates['/srv/rrd-test/local-data_9.rrd']);
        self::assertSame([['--template', 'traffic_in', '1000000300:2']], $this->files->updates['/srv/rrd-test/local-data_7.rrd']);
        self::assertSame(self::T + 300, $this->files->lastUpdate(self::ERRORS));
    }

    public function testASampleCollectedOverAnotherProtocolThanItsBindingHasNoBinding(): void
    {
        $report = $this->sink()->accept([GraphingSamples::sample(CounterValue::fromInt(1), self::T + 300, protocol: CollectionProtocol::Gnmi, source: 'snmp')]);

        self::assertSame([RejectionReason::NoBinding], $this->reasons($report));
        self::assertSame([[self::DEVICE, 'interface.in_octets', 'interface/eth0', 'gnmi']], $this->catalog->calls);
        self::assertSame([], $this->files->log);
    }

    public function testTheSourceTextIsProvenanceOnlyAndNeverSelectsTheBinding(): void
    {
        $report = $this->sink()->accept([GraphingSamples::sample(CounterValue::fromInt(1), self::T + 300, source: 'bogus-collector-name')]);

        self::assertTrue($report->allAccepted());
        self::assertSame([[self::DEVICE, 'interface.in_octets', 'interface/eth0', 'snmp']], $this->catalog->calls);
    }

    public function testALocatorRefusalRejectsThatFilesSamplesAndOtherFilesAreStillWritten(): void
    {
        $this->files->refuseLocation(self::TRAFFIC, new StorageRejected(RejectionReason::StorageFailure, 'The storage reference resolves outside the configured RRD root.'));

        $report = $this->sink()->accept([$this->in(1, self::T + 300), $this->errors(5, self::T + 300)]);

        self::assertSame([RejectionReason::StorageFailure, null], $this->reasons($report));
        self::assertSame('The storage reference resolves outside the configured RRD root.', $report->outcomes()[0]->detail);
        self::assertSame(self::T + 300, $this->files->lastUpdate(self::ERRORS));
    }

    public function testAMissingFileIsTargetMissing(): void
    {
        $this->catalog->bind(self::DEVICE, 'interface.in_octets', 'eth0', 'traffic_in', RrdDataSourceType::Counter, 'local-data:99');

        self::assertSame([RejectionReason::TargetMissing], $this->reasons($this->sink()->accept([$this->in(1, self::T + 300)])));
    }

    public function testAMissingDataSourceOrADifferentTypeInTheFileIsRefusedAndTheRestOfTheFileIsWritten(): void
    {
        $this->catalog->bind(self::DEVICE, 'interface.in_octets', 'eth0', 'traffic_gone', RrdDataSourceType::Counter, self::TRAFFIC);
        $this->catalog->bind(self::DEVICE, 'interface.in_errors', 'eth0', 'traffic_out', RrdDataSourceType::Gauge, self::TRAFFIC);
        $this->catalog->bind(self::DEVICE, 'interface.in_unicast', 'eth0', 'traffic_out', RrdDataSourceType::Counter, self::TRAFFIC);

        $report = $this->sink()->accept([
            $this->in(1, self::T + 300),
            $this->sample(GaugeValue::fromString('1'), self::T + 300, 'interface.in_errors'),
            $this->sample(CounterValue::fromInt(9), self::T + 300, 'interface.in_unicast'),
        ]);

        self::assertSame([RejectionReason::TargetMissing, RejectionReason::TypeMismatch, null], $this->reasons($report));
        self::assertStringContainsString('traffic_gone', $report->outcomes()[0]->detail);
        self::assertStringContainsString('COUNTER', $report->outcomes()[1]->detail);
        self::assertSame([['--template', 'traffic_out', '1000000300:9']], array_merge(...array_values($this->files->updates)));
    }

    public function testFormatterRejectionsAreReportedInInputOrderBesideAcceptedSamples(): void
    {
        $report = $this->sink()->accept([
            $this->in(1, self::T + 600),
            $this->in(2, self::T + 600),
            $this->errors(3, self::T + 300),
            $this->in(4, 0),
        ]);

        self::assertSame([null, RejectionReason::DuplicateSample, null, RejectionReason::InvalidTimestamp], $this->reasons($report));
    }

    public function testResubmittingABatchWritesNothingTheSecondTime(): void
    {
        $batch = [$this->in(1, self::T + 300), $this->in(2, self::T + 600), $this->errors(3, self::T + 600)];
        self::assertTrue($this->sink()->accept($batch)->allAccepted());
        $written = $this->files->updates;

        $report = $this->sink()->accept($batch);

        self::assertSame([RejectionReason::OutOfOrder, RejectionReason::DuplicateSample, RejectionReason::DuplicateSample], $this->reasons($report));
        self::assertSame($written, $this->files->updates);
    }

    public function testRowsRrdtoolAppliedBeforeAFailingRowAreReportedStoredAndTheRestFailed(): void
    {
        $this->files->failUpdate(self::TRAFFIC, 1, new StorageRejected(RejectionReason::StorageFailure, 'RRDtool update exited with status 1.'));
        // The out_octets row has another template, so it is a second call that must not run after the first fails.

        $report = $this->sink()->accept([
            $this->in(1, self::T + 300),
            $this->in(2, self::T + 600),
            $this->in(3, self::T + 900),
            $this->sample(CounterValue::fromInt(4), self::T + 1200, 'interface.out_octets'),
            $this->errors(5, self::T + 300),
        ]);

        self::assertSame([null, RejectionReason::StorageFailure, RejectionReason::StorageFailure, RejectionReason::StorageFailure, null], $this->reasons($report));
        self::assertSame('RRDtool update exited with status 1.', $report->outcomes()[1]->detail);
        self::assertStringContainsString('earlier call', $report->outcomes()[3]->detail);
        self::assertCount(1, $this->files->updates['/srv/rrd-test/local-data_7.rrd']);
        self::assertSame(self::T + 300, $this->files->lastUpdate(self::TRAFFIC));
        self::assertSame(self::T + 300, $this->files->lastUpdate(self::ERRORS));
    }

    public function testAnOutOfOrderRefusalFromRrdtoolKeepsItsReason(): void
    {
        $this->files->failUpdate(self::TRAFFIC, 0, new StorageRejected(RejectionReason::OutOfOrder, 'RRDtool refused second 1000000300; the file already holds second 1000000300.'));

        $report = $this->sink()->accept([$this->in(1, self::T + 300)]);

        self::assertSame([RejectionReason::OutOfOrder], $this->reasons($report));
    }

    public function testAnAdapterDetailCarryingAPathIsReplacedRatherThanLeaked(): void
    {
        $this->files->refuseLocation(self::TRAFFIC, new StorageRejected(RejectionReason::StorageFailure, 'opening /var/lib/rra/secret.rrd failed'));

        $outcome = $this->sink()->accept([$this->in(1, self::T + 300)])->outcomes()[0];

        self::assertSame(RejectionReason::StorageFailure, $outcome->reason);
        self::assertStringNotContainsString('/', $outcome->detail);
    }

    public function testAnEmptyBatchYieldsAnEmptyReport(): void
    {
        self::assertCount(0, $this->sink()->accept([]));
        self::assertSame([], $this->files->log);
    }

    private function sink(?WriteGate $gate = null): RecordSamples
    {
        return new RecordSamples(
            $this->catalog,
            $gate ?? WriteGate::enabled(),
            $this->files,
            $this->files,
            new RrdUpdateFormatter(new FixedClock(GraphingSamples::at(self::T + 3600))),
            $this->files,
        );
    }

    private function in(int $value, int $seconds): Sample
    {
        return $this->sample(CounterValue::fromInt($value), $seconds, 'interface.in_octets');
    }

    private function errors(int $value, int $seconds): Sample
    {
        return $this->sample(CounterValue::fromInt($value), $seconds, 'interface.in_errors');
    }

    private function sample(SampleValue $value, int $seconds, string $metric): Sample
    {
        return GraphingSamples::sample($value, $seconds, $metric);
    }

    /** @return list<RejectionReason|null> */
    private function reasons(SinkReport $report): array
    {
        return array_map(static fn(SampleOutcome $o): ?RejectionReason => $o->reason, $report->outcomes());
    }
}
