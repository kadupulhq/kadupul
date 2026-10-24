<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\ActivateCollectionPlan;
use Kadupul\Monitoring\Application\DiscoverDeviceCapabilities;
use Kadupul\Monitoring\Application\MonitoringPlanner;
use Kadupul\Monitoring\Application\PackLifecycle;
use Kadupul\Monitoring\Application\PlanningRequest;
use Kadupul\Monitoring\Domain\ActivationPolicy;
use Kadupul\Monitoring\Domain\Capability;
use Kadupul\Monitoring\Domain\CapabilityDiscoveryResult;
use Kadupul\Monitoring\Domain\CapabilityStatus;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\PackState;
use Kadupul\Monitoring\Domain\PackValidator;
use Kadupul\Monitoring\Domain\PlanningPolicy;
use Kadupul\Monitoring\Domain\PlanStatus;
use Kadupul\Monitoring\Domain\PlanTarget;
use Kadupul\Monitoring\Infrastructure\Pack\JsonPackReader;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryCollectionPlanStore;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryDeviceCapabilityStore;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryPackRepository;
use Kadupul\Monitoring\Infrastructure\Serialization\PlanSerializer;
use Kadupul\Telemetry\Application\CounterNormalizationPolicy;
use Kadupul\Telemetry\Application\CounterNormalizer;
use Kadupul\Telemetry\Application\IntervalKind;
use Kadupul\Telemetry\Application\NormalizationReport;
use Kadupul\Telemetry\Contract\CollectionErrorCode;
use Kadupul\Telemetry\Contract\CollectionProtocol;
use Kadupul\Telemetry\Contract\CollectionStatus;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\CounterWidth;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\Unit;
use Kadupul\Telemetry\Domain\CardinalityGuard;
use Kadupul\Tests\Monitoring\Fakes\FakeOpenConfigCapabilityProvider;
use Kadupul\Tests\Monitoring\Fakes\FakePlanActivationAuthorizer;
use Kadupul\Tests\Monitoring\Fakes\FakeSnmpCapabilityProvider;
use Kadupul\Tests\Monitoring\Fakes\RecordingExistingMonitoring;
use Kadupul\Tests\Telemetry\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 5 proof with fakes only: a simulated device goes from capability
 * discovery through the registry, the shipped example pack and the planner to
 * proposed plans, and simulated protocol responses are normalized by
 * Telemetry. No protocol client or collector exists; the translation from a
 * response to a Sample below stands in for one and lives only in this test.
 */
final class ModelDrivenMonitoringWorkflowTest extends TestCase
{
    private const GNMI_DEVICE = 42;
    private const SNMP_DEVICE = 57;
    private const EXISTING = __DIR__ . '/Fixtures/existing-monitoring.json';
    private const RESPONSES = __DIR__ . '/Fixtures/telemetry-responses.json';

    private FakeOpenConfigCapabilityProvider $gnmi;
    private FakeSnmpCapabilityProvider $snmp;
    private InMemoryDeviceCapabilityStore $capabilities;
    private DiscoverDeviceCapabilities $discover;
    private InMemoryPackRepository $packs;
    private PackLifecycle $lifecycle;

    protected function setUp(): void
    {
        $this->gnmi = new FakeOpenConfigCapabilityProvider();
        $this->snmp = new FakeSnmpCapabilityProvider();
        $this->capabilities = new InMemoryDeviceCapabilityStore();
        $this->discover = new DiscoverDeviceCapabilities([$this->gnmi, $this->snmp], $this->capabilities, RegistryFixture::catalog());
        $this->packs = new InMemoryPackRepository();
        $this->lifecycle = new PackLifecycle($this->packs, new PackValidator('1.2.35'));

        // Device 42 speaks gNMI; a read of openconfig-interfaces succeeded. Its
        // IF-MIB is only claimed. Device 57 only speaks SNMP, with IF-MIB read back.
        $this->gnmi->verify(self::GNMI_DEVICE, RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces', RegistryFixture::OC_INTERFACES_REV);
        $this->gnmi->advertise(self::GNMI_DEVICE, RegistryFixture::OC_TYPES_NS, 'openconfig-types', RegistryFixture::OC_TYPES_REV);
        $this->snmp->advertise(self::GNMI_DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
        $this->snmp->verify(self::SNMP_DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
    }

    public function testGivenSimulatedDevicesWhenTheWholeWorkflowRunsThenBothProtocolsYieldExactIntervalsUnderTheSameMetricIds(): void
    {
        foreach ((new JsonPackReader())->readDirectory(dirname(PackDocuments::EXAMPLE)) as $path => $document) {
            $this->lifecycle->install($document, $path);
        }
        $planner = new MonitoringPlanner(new RecordingExistingMonitoring());

        $gnmiPlan = $planner->plan($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::verifiedOnly()))->plans[0];
        $snmpPlan = $planner->plan($this->request(self::SNMP_DEVICE, ($this->discover)(self::SNMP_DEVICE), PlanningPolicy::verifiedOnly()))->plans[0];

        self::assertSame(['gnmi'], array_map(static fn(PlanTarget $t): string => $t->protocol, $gnmiPlan->targets));
        self::assertSame(['snmp'], array_map(static fn(PlanTarget $t): string => $t->protocol, $snmpPlan->targets));
        foreach ([$gnmiPlan, $snmpPlan] as $plan) {
            self::assertSame(PlanStatus::Proposed, $plan->status);
            self::assertSame([], PlanDocumentShape::violations(json_decode((new PlanSerializer())->toJson($plan), false, 512, JSON_THROW_ON_ERROR)));
        }

        $responses = $this->responses();
        $gnmi = $this->normalizeAll($this->gnmiSamples($gnmiPlan, $responses['gnmi']['notifications']));
        $snmp = $this->normalizeAll($this->snmpSamples($snmpPlan, $responses['snmp']));

        $counters = ['interface.in_errors', 'interface.in_octets', 'interface.out_octets'];
        self::assertSame($counters, array_keys($gnmi));
        self::assertSame($counters, array_keys($snmp));
        // Above PHP_INT_MAX, exact.
        self::assertSame('1000', $gnmi['interface.in_octets']->intervals[0]->delta?->toString());
        self::assertSame('93', $gnmi['interface.out_octets']->intervals[0]->delta?->toString());
        self::assertSame('300000', $snmp['interface.in_octets']->intervals[0]->delta?->toString());
        // 32-bit rollover on both protocols.
        self::assertTrue($gnmi['interface.in_errors']->intervals[0]->rolledOver);
        self::assertSame('10', $gnmi['interface.in_errors']->intervals[0]->delta?->toString());
        self::assertTrue($snmp['interface.in_errors']->intervals[0]->rolledOver);
        self::assertSame('1', $snmp['interface.in_errors']->intervals[0]->delta?->toString());
        foreach ([...$gnmi, ...$snmp] as $report) {
            self::assertSame(CollectionStatus::Succeeded, $report->status());
            self::assertSame(IntervalKind::Delta, $report->intervals[0]->kind);
        }
    }

    public function testGivenADeviceAdvertisingOpenConfigWhenCapabilitiesAreDiscoveredThenAdvertisedAndVerifiedModelsAreRecordedDistinctly(): void
    {
        $result = ($this->discover)(self::GNMI_DEVICE);

        $byName = $this->byName($result);
        self::assertSame(CapabilityStatus::Verified, $byName['openconfig-interfaces']->status);
        self::assertSame(['openconfig-interfaces'], $byName['openconfig-interfaces']->provides);
        self::assertSame(CapabilityStatus::Advertised, $byName['openconfig-types']->status);
        self::assertSame(CapabilityStatus::Advertised, $byName['IF-MIB']->status);
        self::assertSame('snmp', $byName['IF-MIB']->source);
        self::assertSame([], $result->errors);
    }

    public function testGivenDiscoveredCapabilitiesWhenPacksAreRecommendedThenPlansAreOnlyProposedAndNothingIsActivated(): void
    {
        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');
        $plans = new InMemoryCollectionPlanStore();

        $proposal = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::advertisedOrVerified()));

        self::assertTrue($proposal->matches[0]->matched);
        self::assertSame(['Interface traffic', 'Interface errors'], array_map(static fn($g): string => $g->name, $proposal->matches[0]->graphs));
        self::assertSame([PlanStatus::Proposed], array_map(static fn(CollectionPlan $p): PlanStatus => $p->status, $proposal->plans));
        self::assertSame([], $plans->forDevice(self::GNMI_DEVICE));
        self::assertSame(PackState::Installed, $this->packs->find('openconfig-interfaces')?->state);
        // Even an attempt to approve is refused under the default policy.
        try {
            (new ActivateCollectionPlan($plans, $this->packs, new ActivationPolicy(), new FakePlanActivationAuthorizer(['admin'])))->approve($proposal->plans[0], 'admin');
            self::fail('The default activation policy allowed an approval.');
        } catch (\Kadupul\Monitoring\Domain\PlanActivationDenied) {
        }
        self::assertSame([], $plans->forDevice(self::GNMI_DEVICE));
    }

    public function testGivenGnmiAndSnmpReadingsWhenNormalizedThenTheyShareMetricIdsAndKeepTheirOriginalTimestampsAndPrecision(): void
    {
        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');
        $planner = new MonitoringPlanner(new RecordingExistingMonitoring());
        $gnmiPlan = $planner->plan($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::verifiedOnly()))->plans[0];
        $snmpPlan = $planner->plan($this->request(self::SNMP_DEVICE, ($this->discover)(self::SNMP_DEVICE), PlanningPolicy::verifiedOnly()))->plans[0];
        $responses = $this->responses();

        $gnmi = $this->gnmiSamples($gnmiPlan, $responses['gnmi']['notifications']);
        $snmp = $this->snmpSamples($snmpPlan, $responses['snmp']);

        $first = static fn(array $series, string $metric): Sample => $series[$metric][0];
        self::assertTrue($first($gnmi, 'interface.in_octets')->metric->equals($first($snmp, 'interface.in_octets')->metric));
        self::assertTrue($first($gnmi, 'interface.in_octets')->resource->equals($first($snmp, 'interface.in_octets')->resource));
        // The readings come from two devices, so sharing metric and resource ids must not make them one series.
        self::assertNotSame($first($gnmi, 'interface.in_octets')->seriesKey(), $first($snmp, 'interface.in_octets')->seriesKey());
        self::assertSame([self::GNMI_DEVICE, CollectionProtocol::Gnmi], [$first($gnmi, 'interface.in_octets')->deviceId, $first($gnmi, 'interface.in_octets')->protocol]);
        self::assertSame([self::SNMP_DEVICE, CollectionProtocol::Snmp], [$first($snmp, 'interface.in_octets')->deviceId, $first($snmp, 'interface.in_octets')->protocol]);
        // gNMI's nanosecond timestamp keeps its microseconds; the fixture's last three digits are zero
        // because Sample holds a DateTimeImmutable, which stops at microseconds.
        self::assertSame('2026-10-23T14:05:00.123456Z', $first($gnmi, 'interface.in_octets')->timestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2026-10-23T14:05:00.250000Z', $first($snmp, 'interface.in_octets')->timestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('18446744073709550000', $first($gnmi, 'interface.in_octets')->value->toString());
        $report = $this->normalizeAll($gnmi)['interface.in_octets'];
        self::assertSame(60_530_865, $report->intervals[0]->elapsedMicros);
        self::assertEquals($first($gnmi, 'interface.in_octets')->timestamp, $report->intervals[0]->from);
    }

    public function testGivenAnInterfaceMonitoredBySnmpWhenGnmiAlsoOffersItThenAnOverlapIsReportedAndNoSecondEntryIsPlanned(): void
    {
        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');

        $proposal = (new MonitoringPlanner(RecordingExistingMonitoring::fromFixture(self::EXISTING)))
            ->plan($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::verifiedOnly(), ['eth0']));

        self::assertSame(['interface.in_octets', 'interface.out_octets'], array_map(static fn($o): string => $o->metric->toString(), $proposal->overlaps));
        self::assertSame(['snmp', 'snmp'], array_map(static fn($o): string => $o->existingProtocol, $proposal->overlaps));
        self::assertSame(['gnmi', 'gnmi'], array_map(static fn($o): string => $o->offeredProtocol, $proposal->overlaps));
        self::assertSame(['interface.in_errors', 'interface.oper_status'], $proposal->plans[0]->metricIds());
    }

    public function testGivenExistingMonitoringWhenTheFeatureRunsEndToEndThenExistingMonitoringIsOnlyReadAndItsConfigurationIsUnchanged(): void
    {
        $fixtureBefore = hash_file('sha256', self::EXISTING);
        $existing = RecordingExistingMonitoring::fromFixture(self::EXISTING);
        $seriesBefore = $existing->monitoredSeries(self::GNMI_DEVICE);
        $existing->calls = [];
        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');
        $plans = new InMemoryCollectionPlanStore();
        $planner = new MonitoringPlanner($existing);

        $proposal = $planner->plan($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::verifiedOnly()));
        $activation = new ActivateCollectionPlan($plans, $this->packs, ActivationPolicy::allowApproved(), new FakePlanActivationAuthorizer(['admin']));
        $active = $activation->activate($activation->approve($proposal->plans[0], 'admin')->planId, 'admin');
        $planner->reconcile($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::verifiedOnly(), currentPlans: $plans->forDevice(self::GNMI_DEVICE)));

        self::assertSame(PlanStatus::Active, $active->status);
        self::assertSame(['monitoredSeries(42)', 'monitoredSeries(42)'], $existing->calls);
        self::assertSame([], array_values(array_filter(get_class_methods($existing), static fn(string $m): bool => !in_array($m, ['__construct', 'fromFixture', 'monitoredSeries'], true))));
        self::assertEquals($seriesBefore, $existing->monitoredSeries(self::GNMI_DEVICE));
        self::assertSame($fixtureBefore, hash_file('sha256', self::EXISTING));
    }

    public function testGivenFailingCollectorsWhenDiscoveryAndNormalizationRunThenEveryErrorIsReportedAndNothingIsDroppedSilently(): void
    {
        $this->snmp->failWith(new \RuntimeException('no response from 192.0.2.7 after 3 retries'));

        $discovery = ($this->discover)(self::GNMI_DEVICE);

        self::assertSame(CollectionStatus::Partial, $discovery->status());
        self::assertSame(CollectionErrorCode::Unavailable, $discovery->errors[0]->code);
        self::assertStringContainsString('no response', $discovery->errors[0]->message);
        self::assertSame(CapabilityStatus::Verified, $this->byName($discovery)['openconfig-interfaces']->status);

        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');
        $plan = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan($this->request(self::GNMI_DEVICE, $discovery, PlanningPolicy::verifiedOnly()))->plans[0];
        $conflicting = $this->responses()['gnmi_conflict']['notifications'];
        $report = $this->normalizeAll($this->gnmiSamples($plan, $conflicting))['interface.out_octets'];

        self::assertSame(CollectionStatus::Partial, $report->status());
        self::assertCount(1, $report->errors);
        self::assertSame(CollectionErrorCode::Conflict, $report->errors[0]->code);
        self::assertStringContainsString('160, 170', $report->errors[0]->message);
        // Four readings in: two conflicting ones reported as an error, the other two make one interval.
        self::assertCount(1, $report->intervals);
        self::assertSame('120', $report->intervals[0]->delta?->toString());
    }

    public function testGivenAKnownDeviceWhenItIsRediscoveredThenNothingIsDuplicatedAndVerificationIsKept(): void
    {
        $first = ($this->discover)(self::GNMI_DEVICE);
        $stored = $this->capabilities->capabilitiesFor(self::GNMI_DEVICE);
        // The device now only claims the model it was verified on.
        $this->gnmi->advertise(self::GNMI_DEVICE, RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces', RegistryFixture::OC_INTERFACES_REV);

        $second = ($this->discover)(self::GNMI_DEVICE);

        self::assertEquals($first, $second);
        self::assertEquals($stored, $this->capabilities->capabilitiesFor(self::GNMI_DEVICE));
        self::assertCount(3, $this->capabilities->capabilitiesFor(self::GNMI_DEVICE));
    }

    public function testGivenAnActivePlanWhenTheDeviceCapabilitiesChangeThenReplanningReportsTheChangeAndLeavesTheActivePlanAlone(): void
    {
        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');
        [$plans, $active] = $this->activeGnmiPlan();
        // gNMI stops working on the device; IF-MIB is now read back over SNMP.
        $this->gnmi->report(self::GNMI_DEVICE, RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces', RegistryFixture::OC_INTERFACES_REV, CapabilityStatus::Unsupported, 'Subscribe returned Unimplemented');
        $this->snmp->verify(self::GNMI_DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);

        $report = (new MonitoringPlanner(new RecordingExistingMonitoring()))->reconcile($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::verifiedOnly(), currentPlans: $plans->forDevice(self::GNMI_DEVICE)));

        self::assertCount(1, $report->changed);
        self::assertContains('snmp:interface.in_octets', $report->changed[0]->addedMetrics);
        self::assertContains('gnmi:interface.in_octets', $report->changed[0]->removedMetrics);
        self::assertSame(PlanStatus::Proposed, $report->changed[0]->proposed->status);
        self::assertEquals($active, $plans->find($active->planId));
        self::assertSame(PlanStatus::Active, $plans->find($active->planId)?->status);
    }

    public function testGivenAnActivePlanWhenThePackIsUpgradedThenThePackStaysActiveAndReplanningReportsTheNewMetric(): void
    {
        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');
        [$plans, $active] = $this->activeGnmiPlan();

        $upgrade = $this->lifecycle->upgrade(PackDocuments::exampleAt('1.1.0', PackDocuments::addingOutDiscards()), 'v1.1.pack.json');
        $report = (new MonitoringPlanner(new RecordingExistingMonitoring()))->reconcile($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::verifiedOnly(), currentPlans: $plans->forDevice(self::GNMI_DEVICE)));

        self::assertSame(PackState::Active, $upgrade->pack->state);
        self::assertSame(['gnmi:interface.out_discards'], $report->changed[0]->addedMetrics);
        self::assertSame('1.0.0', $plans->find($active->planId)?->packVersion);
        self::assertSame(PlanStatus::Active, $plans->find($active->planId)?->status);
    }

    public function testGivenAnApprovedBindingWhenProductionWritesAreEnabledThenSamplesReachLegacyRrdStorage(): void
    {
        // Raw samples reach temporary RRD files through RecordSamples (see
        // tests/Symfony/Graphing/ModelDrivenRrdWriteWorkflowTest). What is left
        // is production wiring and a local_data_id to file adapter, both
        // deliberately absent while writes stay disabled by default.
        self::markTestSkipped('Gap: no production wiring or legacy path adapter; model-driven writes to existing Cacti RRD files stay disabled (docs/model-driven-monitoring.md).');
    }

    /** @return array{InMemoryCollectionPlanStore, CollectionPlan} */
    private function activeGnmiPlan(): array
    {
        $plans = new InMemoryCollectionPlanStore();
        $proposed = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan($this->request(self::GNMI_DEVICE, ($this->discover)(self::GNMI_DEVICE), PlanningPolicy::verifiedOnly()))->plans[0];
        $activation = new ActivateCollectionPlan($plans, $this->packs, ActivationPolicy::allowApproved(), new FakePlanActivationAuthorizer(['admin']));

        return [$plans, $activation->activate($activation->approve($proposed, 'admin')->planId, 'admin')];
    }

    /**
     * @param list<string> $interfaces
     * @param list<CollectionPlan> $currentPlans
     */
    private function request(int $deviceId, CapabilityDiscoveryResult $capabilities, PlanningPolicy $policy, array $interfaces = ['eth0'], array $currentPlans = []): PlanningRequest
    {
        return new PlanningRequest(
            $deviceId,
            $capabilities,
            $this->packs->all(),
            PlanningFixture::interfaces(...$interfaces),
            PlanningFixture::endpoints(),
            $policy,
            new \DateTimeImmutable(PlanningFixture::GENERATED_AT),
            $currentPlans,
        );
    }

    /** @return array<string, Capability> */
    private function byName(CapabilityDiscoveryResult $result): array
    {
        $byName = [];
        foreach ($result->capabilities as $capability) {
            $byName[$capability->model->name] = $capability;
        }

        return $byName;
    }

    /** @return array<string, mixed> */
    private function responses(): array
    {
        return json_decode((string) file_get_contents(self::RESPONSES), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /**
     * Stands in for a gNMI collector: matches each notification path to the
     * plan metric whose source selects it and builds a Telemetry sample.
     *
     * @param list<array{timestamp_ns: string, path: string, uint_val: string}> $notifications
     * @return array<string, list<Sample>> by metric id
     */
    private function gnmiSamples(CollectionPlan $plan, array $notifications): array
    {
        $samples = [];
        foreach ($notifications as $notification) {
            $seconds = substr($notification['timestamp_ns'], 0, -9);
            $micros = substr($notification['timestamp_ns'], -9, 6);
            $at = \DateTimeImmutable::createFromFormat('U.u', $seconds . '.' . $micros, new \DateTimeZone('UTC'));
            self::assertInstanceOf(\DateTimeImmutable::class, $at);
            foreach ($plan->targets[0]->metrics as $metric) {
                [$prefix, $suffix] = explode('[name=*]', $metric->source, 2) + [1 => ''];
                $path = $notification['path'];
                if ($metric->type !== MetricType::Counter || !str_starts_with($path, $prefix . '[name=') || !str_ends_with($path, ']' . $suffix)) {
                    continue;
                }
                $name = substr($path, strlen($prefix) + 6, -strlen(']' . $suffix));
                $samples[$metric->id->toString()][] = $this->sample($plan, $metric->id, $name, $metric->unit, $at, $notification['uint_val'], $metric->counterBits);
            }
        }
        ksort($samples);

        return $samples;
    }

    /**
     * Stands in for an SNMP poller: an instance OID is the plan's column OID
     * plus ifIndex, and ifName gives the interface identity.
     *
     * @param array{if_name: array<string, string>, polls: list<array{polled_at: string, varbinds: list<array{oid: string, value: string}>}>} $response
     * @return array<string, list<Sample>> by metric id
     */
    private function snmpSamples(CollectionPlan $plan, array $response): array
    {
        $samples = [];
        foreach ($response['polls'] as $poll) {
            $at = new \DateTimeImmutable($poll['polled_at']);
            foreach ($poll['varbinds'] as $varbind) {
                foreach ($plan->targets[0]->metrics as $metric) {
                    if ($metric->type !== MetricType::Counter || !str_starts_with($varbind['oid'], $metric->source . '.')) {
                        continue;
                    }
                    $index = substr($varbind['oid'], strlen($metric->source) + 1);
                    $samples[$metric->id->toString()][] = $this->sample($plan, $metric->id, $response['if_name'][$index], $metric->unit, $at, $varbind['value'], $metric->counterBits);
                }
            }
        }
        ksort($samples);

        return $samples;
    }

    /** Device and protocol come from the plan, as a collector executing it would take them. */
    private function sample(CollectionPlan $plan, MetricId $metric, string $interface, string $unit, \DateTimeImmutable $at, string $value, ?int $bits): Sample
    {
        $protocol = $plan->targets[0]->protocol;

        return new Sample(
            $plan->deviceId,
            CollectionProtocol::fromName($protocol),
            $metric,
            new ResourceId('interface', $interface),
            MetricType::Counter,
            Unit::from($unit),
            $at,
            CounterValue::fromString($value),
            ['name' => $interface],
            source: $protocol,
            counterWidth: $bits === null ? null : CounterWidth::from($bits),
        );
    }

    /**
     * @param array<string, list<Sample>> $samples
     * @return array<string, NormalizationReport> by metric id
     */
    private function normalizeAll(array $samples): array
    {
        $normalizer = new CounterNormalizer(new FixedClock(new \DateTimeImmutable('2026-10-23T14:15:00Z')), new CardinalityGuard(100));
        $policy = new CounterNormalizationPolicy(600, 3600);
        $reports = [];
        foreach ($samples as $metric => $series) {
            $reports[$metric] = $normalizer->normalize($series, $policy);
        }

        return $reports;
    }
}
