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
use Kadupul\Monitoring\Application\ResolveBinding;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\WriterOwnership;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryBindingRepository;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterOwnershipRegistry;
use Kadupul\Telemetry\Contract\CollectionProtocol;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\CounterWidth;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;
use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\SampleOutcome;
use Kadupul\Telemetry\Contract\SampleSink;
use Kadupul\Telemetry\Contract\Unit;
use Kadupul\Tests\Graphing\Fakes\FixedClock;
use Kadupul\Tests\Monitoring\BindingFixture;
use Kadupul\Tests\Monitoring\Fakes\FixedWriterOwnershipProbe;
use PHPUnit\Framework\TestCase;

/**
 * The write half of the model-driven workflow, end to end with fakes only
 * where no production code exists: a simulated gNMI notification becomes a
 * normalized Telemetry sample, goes into the SampleSink, is resolved by
 * Monitoring's real binding resolution and ownership registry, is formatted,
 * and lands in a real RRD file in a temporary directory. No protocol client
 * exists; the notification-to-sample translation lives only in this test.
 */
final class ModelDrivenRrdWriteWorkflowTest extends TestCase
{
    private const DEVICE = 42;
    private const T = 1000000200;

    private string $rrdtool;
    private TemporaryRrdRoot $root;

    protected function setUp(): void
    {
        $this->rrdtool = TemporaryRrdRoot::rrdtoolOrSkip($this);
        $this->root = TemporaryRrdRoot::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->root->remove();
        }
    }

    public function testGivenAnApprovedModelDrivenBindingWhenAGnmiCounterIsSunkThenTheRrdFileHoldsTheExactValue(): void
    {
        // Given an RRD file whose traffic_in field model-driven collection has
        // claimed, and whose traffic_out field the legacy poller still writes.
        $file = $this->root->createRrd($this->rrdtool, 'device_42/traffic_7.rrd', ['DS:traffic_in:COUNTER:600:0:U', 'DS:traffic_out:COUNTER:600:0:U'], self::T - 300);
        $bindings = new InMemoryBindingRepository();
        $legacy = new FixedWriterOwnershipProbe();
        $owners = new WriterOwnershipRegistry($legacy);
        $in = BindingFixture::binding(BindingFixture::key(self::DEVICE, 'interface.in_octets', 'eth0', 'gnmi'), BindingFixture::field(7, 'traffic_in'));
        $out = BindingFixture::binding(BindingFixture::key(self::DEVICE, 'interface.out_octets', 'eth0', 'gnmi'), BindingFixture::field(7, 'traffic_out'));
        $bindings->save($in->withStatus(BindingStatus::Approved));
        $bindings->save($out->withStatus(BindingStatus::Approved));
        $legacy->owners[$out->field->toString()] = WriterOwnership::LegacyPoller;
        $owners->claim($in->field);
        $sink = $this->sink(new ResolveBinding($bindings, $owners));

        // When two gNMI notifications are translated to samples and sunk.
        $report = $sink->accept([
            $this->fromNotification(['path' => 'interfaces/interface[name=eth0]/state/counters/in-octets', 'timestamp' => (self::T) . '000000000', 'uint_val' => '18446744073709551000']),
            $this->fromNotification(['path' => 'interfaces/interface[name=eth0]/state/counters/out-octets', 'timestamp' => (self::T) . '000000000', 'uint_val' => '5']),
        ]);

        // Then the claimed field holds the exact 64-bit reading and the legacy field is untouched.
        self::assertSame([null, RejectionReason::NotOwnedByModelDriven], array_map(static fn(SampleOutcome $o): ?RejectionReason => $o->reason, $report->outcomes()));
        self::assertSame([self::T, ['traffic_in' => '18446744073709551000', 'traffic_out' => 'U']], $this->root->lastUpdate($this->rrdtool, $file));
    }

    public function testGivenTwoDevicesInOneBatchWhenSunkThenEachResolvesItsOwnBindingAndAWrongProtocolResolvesNothing(): void
    {
        // Given gNMI bindings for eth0 on two devices, each into its own file.
        $first = $this->root->createRrd($this->rrdtool, 'device_42/traffic_7.rrd', ['DS:traffic_in:COUNTER:600:0:U'], self::T - 300);
        $second = $this->root->createRrd($this->rrdtool, 'device_43/traffic_8.rrd', ['DS:traffic_in:COUNTER:600:0:U'], self::T - 300);
        $bindings = new InMemoryBindingRepository();
        $owners = new WriterOwnershipRegistry(new FixedWriterOwnershipProbe());
        foreach ([[self::DEVICE, 7], [43, 8]] as [$device, $localDataId]) {
            $binding = BindingFixture::binding(BindingFixture::key($device, 'interface.in_octets', 'eth0', 'gnmi'), BindingFixture::field($localDataId, 'traffic_in'));
            $bindings->save($binding->withStatus(BindingStatus::Approved));
            $owners->claim($binding->field);
        }
        $sink = $this->sink(new ResolveBinding($bindings, $owners));
        $notification = static fn(string $value): array => ['path' => 'interfaces/interface[name=eth0]/state/counters/in-octets', 'timestamp' => (self::T) . '000000000', 'uint_val' => $value];

        // When one batch carries a reading for each device and an SNMP reading
        // whose provenance text claims gNMI.
        $report = $sink->accept([
            $this->fromNotification($notification('43000'), 43),
            $this->fromNotification($notification('42000')),
            $this->fromNotification($notification('99'), protocol: CollectionProtocol::Snmp),
        ]);

        // Then outcomes keep input order, each file holds its own device's value, and the SNMP reading is refused.
        self::assertSame([null, null, RejectionReason::NoBinding], array_map(static fn(SampleOutcome $o): ?RejectionReason => $o->reason, $report->outcomes()));
        self::assertSame([self::T, ['traffic_in' => '42000']], $this->root->lastUpdate($this->rrdtool, $first));
        self::assertSame([self::T, ['traffic_in' => '43000']], $this->root->lastUpdate($this->rrdtool, $second));
    }

    private function sink(ResolveBinding $catalog): SampleSink
    {
        $process = new RrdtoolProcess($this->rrdtool);

        return new RecordSamples(
            $catalog,
            WriteGate::enabled(),
            new ConfiguredRootTargetLocator($this->root->path, [7 => 'device_42/traffic_7.rrd', 8 => 'device_43/traffic_8.rrd']),
            new RrdtoolInspector($process),
            new RrdUpdateFormatter(new FixedClock(GraphingSamples::at(self::T + 60))),
            new RrdtoolUpdater($process),
        );
    }

    /** @param array{path: string, timestamp: string, uint_val: string} $notification */
    private function fromNotification(array $notification, int $device = self::DEVICE, CollectionProtocol $protocol = CollectionProtocol::Gnmi): Sample
    {
        preg_match('~\Ainterfaces/interface\[name=([^\]]+)\]/state/counters/(in|out)-octets\z~', $notification['path'], $m);
        $nanoseconds = $notification['timestamp'];
        $instant = \DateTimeImmutable::createFromFormat('U.u', substr($nanoseconds, 0, -9) . '.' . substr($nanoseconds, -9, 6), new \DateTimeZone('UTC'));
        self::assertNotFalse($instant);

        return new Sample(
            $device,
            $protocol,
            new MetricId('interface.' . $m[2] . '_octets'),
            new ResourceId('interface', $m[1]),
            MetricType::Counter,
            Unit::Octets,
            $instant,
            CounterValue::fromString($notification['uint_val']),
            source: 'gnmi',
            counterWidth: CounterWidth::Bits64,
        );
    }
}
