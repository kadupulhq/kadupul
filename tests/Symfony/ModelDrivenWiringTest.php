<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\RecordSamples;
use Kadupul\Graphing\Application\Port\Clock as GraphingClock;
use Kadupul\Graphing\Application\Port\RrdTargetLocator;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Infrastructure\Clock\SymfonyClock as GraphingSymfonyClock;
use Kadupul\Kernel;
use Kadupul\ModelRegistry\Contract\ModelCatalog;
use Kadupul\Monitoring\Application\ApproveBinding;
use Kadupul\Monitoring\Domain\BindingApprovalDenied;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Monitoring\Infrastructure\Persistence\DbalBindingRepository;
use Kadupul\Monitoring\Infrastructure\Persistence\DbalWriterClaimStore;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterOwnershipRegistry;
use Kadupul\Telemetry\Application\Contract\Clock as TelemetryClock;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Telemetry\Contract\SampleSink;
use Kadupul\Telemetry\Infrastructure\Clock\SymfonyClock as TelemetrySymfonyClock;
use Kadupul\Tests\Graphing\GraphingSamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Boots the real container because a service that compiles can still be wired
 * to the wrong implementation, and the sink must never write by default.
 */
final class ModelDrivenWiringTest extends TestCase
{
    private Kernel $kernel;
    private ContainerInterface $services;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->services = $this->kernel->getContainer()->get('test.service_container');
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public static function wiredServices(): iterable
    {
        foreach ([
            'sample_sink',
            'binding_catalog',
            'model_catalog',
            'telemetry_clock',
            'graphing_clock',
            'rrd_locator',
            'register_model',
            'record_device_observation',
            'lookup_model',
            'resolve_dependencies',
            'register_binding',
            'approve_binding',
            'activate_collection_plan',
            'discover_device_capabilities',
            'monitoring_planner',
            'counter_normalizer',
            'plan_serializer',
            'pack_reader',
            'binding_repository',
            'writer_ownership',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('wiredServices')]
    public function testEveryWiredServiceResolves(string $name): void
    {
        self::assertIsObject($this->services->get('test.model_driven.' . $name));
    }

    public function testRrdWritesAreDisabledByDefault(): void
    {
        self::assertFalse($this->kernel->getContainer()->getParameter('kadupul.monitoring.rrd_writes_enabled'));
    }

    public function testNoEnvironmentVariableCanOpenTheWriteGate(): void
    {
        $definition = file_get_contents(dirname(__DIR__, 2) . '/config/services.yaml');
        self::assertIsString($definition);
        self::assertMatchesRegularExpression('/^\s+kadupul\.monitoring\.rrd_writes_enabled: false\s*$/m', $definition);
        self::assertDoesNotMatchRegularExpression('/%env\([^)]*\)%[^\n]*rrd_writes|rrd_writes[^\n]*%env\(/', $definition);
    }

    public function testTheSampleSinkIsTheGuardedRrdSink(): void
    {
        self::assertInstanceOf(RecordSamples::class, $this->services->get('test.model_driven.sample_sink'));
        self::assertInstanceOf(SampleSink::class, $this->services->get('test.model_driven.sample_sink'));
    }

    public function testTheDefaultSinkRejectsEverySampleBecauseWritesAreDisabled(): void
    {
        $sink = $this->services->get('test.model_driven.sample_sink');
        $report = $sink->accept([
            GraphingSamples::sample(CounterValue::fromInt(5), GraphingSamples::EPOCH),
            GraphingSamples::sample(CounterValue::fromInt(6), GraphingSamples::EPOCH + 60),
        ]);

        self::assertSame(2, $report->count());
        foreach ($report->outcomes() as $outcome) {
            self::assertSame(RejectionReason::WritesDisabled, $outcome->reason);
        }
    }

    public function testTheClockPortsAreTheSymfonyAdapters(): void
    {
        self::assertInstanceOf(TelemetrySymfonyClock::class, $this->services->get('test.model_driven.telemetry_clock'));
        self::assertInstanceOf(TelemetryClock::class, $this->services->get('test.model_driven.telemetry_clock'));
        self::assertInstanceOf(GraphingSymfonyClock::class, $this->services->get('test.model_driven.graphing_clock'));
        self::assertInstanceOf(GraphingClock::class, $this->services->get('test.model_driven.graphing_clock'));
    }

    public function testUnconfiguredRrdStorageRefusesInsteadOfGuessing(): void
    {
        $locator = $this->services->get('test.model_driven.rrd_locator');
        self::assertInstanceOf(RrdTargetLocator::class, $locator);

        $this->expectException(StorageRejected::class);
        $this->expectExceptionMessage('not configured');
        $locator->locate(new StorageReference('local-data:7'));
    }

    public function testBindingsAndClaimsAreTheDatabaseStoresOnTheMainConnection(): void
    {
        self::assertInstanceOf(DbalBindingRepository::class, $this->services->get('test.model_driven.binding_repository'));
        $ownership = $this->services->get('test.model_driven.writer_ownership');
        self::assertInstanceOf(WriterOwnershipRegistry::class, $ownership);
        $claims = (new \ReflectionProperty(WriterOwnershipRegistry::class, 'claims'))->getValue($ownership);
        self::assertInstanceOf(DbalWriterClaimStore::class, $claims);

        $main = $this->services->get('test.model_driven.main_connection');
        self::assertInstanceOf(Connection::class, $main);
        foreach ([$this->services->get('test.model_driven.binding_repository'), $claims] as $store) {
            self::assertSame($main, (new \ReflectionProperty($store, 'database'))->getValue($store));
        }
        // Resolving the stores must not open a connection; the test kernel has no database.
        self::assertFalse($main->isConnected());
    }

    public function testBindingApprovalIsDeniedBecauseNoAuthorizerIsWired(): void
    {
        $approve = $this->services->get('test.model_driven.approve_binding');
        self::assertInstanceOf(ApproveBinding::class, $approve);

        $this->expectException(BindingApprovalDenied::class);
        $approve->approve(new BindingKey(1, new MetricId('interface.in_octets'), new ResourceId('interface', 'eth0'), 'snmp'), 'admin');
    }

    public function testTheModelCatalogIsTheRegistryContract(): void
    {
        self::assertInstanceOf(ModelCatalog::class, $this->services->get('test.model_driven.model_catalog'));
    }
}
