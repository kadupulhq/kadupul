<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\MonitoringPlanner;
use Kadupul\Monitoring\Domain\CapabilityStatus;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\MonitoredSeries;
use Kadupul\Monitoring\Domain\PackMatch;
use Kadupul\Monitoring\Domain\PackState;
use Kadupul\Monitoring\Domain\PlannedMetric;
use Kadupul\Monitoring\Domain\PlanningPolicy;
use Kadupul\Monitoring\Domain\PlanStatus;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryCollectionPlanStore;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Tests\Monitoring\Fakes\RecordingExistingMonitoring;
use PHPUnit\Framework\TestCase;

final class MonitoringPlannerTest extends TestCase
{
    public function testAVerifiedOpenConfigDeviceGetsAProposedGnmiPlan(): void
    {
        $existing = new RecordingExistingMonitoring();
        $proposal = (new MonitoringPlanner($existing))->plan(PlanningFixture::request([PlanningFixture::openConfig(), PlanningFixture::ifMib()]));

        $match = $this->match($proposal->matches, 'openconfig-interfaces');
        self::assertTrue($match->matched);
        self::assertSame(['interface.in_octets' => 'gnmi', 'interface.out_octets' => 'gnmi', 'interface.in_errors' => 'gnmi', 'interface.oper_status' => 'gnmi'], $match->transports);
        self::assertSame([], $match->excludedMetrics);
        self::assertSame(['interface/eth0', 'interface/eth1'], array_map(static fn(ResourceId $r): string => $r->toString(), $match->resources));
        self::assertSame(['Interface traffic', 'Interface errors'], array_map(static fn($g): string => $g->name, $match->graphs));

        self::assertCount(1, $proposal->plans);
        $plan = $proposal->plans[0];
        self::assertSame(PlanStatus::Proposed, $plan->status);
        self::assertSame(42, $plan->deviceId);
        self::assertSame(['openconfig-interfaces', '1.0.0'], [$plan->packName, $plan->packVersion]);
        self::assertCount(1, $plan->targets);
        $target = $plan->targets[0];
        self::assertSame('gnmi', $target->protocol);
        self::assertSame('sample', $target->mode);
        self::assertSame(60, $target->intervalSeconds);
        self::assertSame('gnmi/core-sw-01', $target->endpoint->credentialRef);
        self::assertSame('/interfaces/interface[name=*]/state/counters/in-octets', $target->subscriptions[0]);
        self::assertSame(['UP' => 1, 'DOWN' => 2, 'TESTING' => 3, 'UNKNOWN' => 4, 'DORMANT' => 5, 'NOT_PRESENT' => 6, 'LOWER_LAYER_DOWN' => 7], $target->metrics[3]->valueMap);
        self::assertSame(['monitoredSeries(42)'], $existing->calls);
    }

    public function testAdvertisedCapabilitiesCountOnlyWhenThePolicyAcceptsThem(): void
    {
        $capabilities = [PlanningFixture::openConfig(CapabilityStatus::Advertised)];
        $planner = new MonitoringPlanner(new RecordingExistingMonitoring());

        $strict = $planner->plan(PlanningFixture::request($capabilities));
        $match = $this->match($strict->matches, 'openconfig-interfaces');
        self::assertFalse($match->matched);
        self::assertSame([], $strict->plans);
        self::assertStringContainsString('requires one of openconfig-interfaces, snmp:if-mib', implode(' ', $match->reasons));
        self::assertStringContainsString('openconfig-interfaces over gnmi is advertised', implode(' ', $match->reasons));

        $lenient = $planner->plan(PlanningFixture::request($capabilities, policy: PlanningPolicy::advertisedOrVerified()));
        self::assertTrue($this->match($lenient->matches, 'openconfig-interfaces')->matched);
        self::assertSame('gnmi', $lenient->plans[0]->targets[0]->protocol);
    }

    public function testAnSnmpOnlyDeviceIsPlannedOverSnmpWithOids(): void
    {
        $proposal = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan(PlanningFixture::request([PlanningFixture::ifMib()]));

        $target = $proposal->plans[0]->targets[0];
        self::assertSame('snmp', $target->protocol);
        self::assertSame('poll', $target->mode);
        self::assertSame(['1.3.6.1.2.1.31.1.1.1.6', '1.3.6.1.2.1.31.1.1.1.10', '1.3.6.1.2.1.2.2.1.14', '1.3.6.1.2.1.2.2.1.8'], $target->subscriptions);
        self::assertSame(32, $target->metrics[2]->counterBits);
    }

    public function testThePacksProtocolPreferenceDecidesBetweenAvailableTransports(): void
    {
        $document = PackDocuments::example();
        $document->collection->protocol_preference = ['snmp', 'gnmi'];

        $proposal = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan(PlanningFixture::request([PlanningFixture::openConfig(), PlanningFixture::ifMib()], [PlanningFixture::pack($document)]));

        self::assertSame(['snmp'], array_map(static fn($t): string => $t->protocol, $proposal->plans[0]->targets));
    }

    public function testAPartialCapabilityReducesTheMetricSetWithAReason(): void
    {
        $document = PackDocuments::example();
        unset($document->metrics[2]->mappings->snmp);
        $capabilities = [PlanningFixture::openConfig(CapabilityStatus::Partial, 'Depends on openconfig|http://openconfig.net/yang/openconfig-types|openconfig-types, which the device does not advertise.'), PlanningFixture::ifMib()];

        $proposal = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan(PlanningFixture::request($capabilities, [PlanningFixture::pack($document)]));

        $match = $this->match($proposal->matches, 'openconfig-interfaces');
        self::assertTrue($match->matched);
        self::assertSame(['interface.in_errors'], array_keys($match->excludedMetrics));
        self::assertStringContainsString('no usable protocol maps it', $match->excludedMetrics['interface.in_errors']);
        self::assertStringContainsString('openconfig-interfaces over gnmi is partial: Depends on', implode(' ', $match->reasons));
        self::assertNotContains('interface.in_errors', $proposal->plans[0]->metricIds());
        self::assertSame(['Interface traffic'], array_map(static fn($g): string => $g->name, $match->graphs));
    }

    public function testAProtocolWithoutACredentialReferenceIsSkippedAndSaidSo(): void
    {
        $proposal = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan(PlanningFixture::request(
            [PlanningFixture::openConfig(), PlanningFixture::ifMib()],
            endpoints: ['snmp' => PlanningFixture::endpoints()['snmp']],
        ));

        self::assertSame('snmp', $proposal->plans[0]->targets[0]->protocol);
        self::assertStringContainsString('gnmi is supported but no credential reference is configured for it', implode(' ', $proposal->matches[0]->reasons));
    }

    public function testAMetricAlreadyMonitoredForEveryResourceIsReportedAndNotPlannedAgain(): void
    {
        $existing = new RecordingExistingMonitoring([42 => [
            $this->legacy('eth0', 'interface.in_octets'),
            $this->legacy('eth1', 'interface.in_octets'),
        ]]);

        $proposal = (new MonitoringPlanner($existing))->plan(PlanningFixture::request([PlanningFixture::openConfig()]));

        self::assertSame(['interface.out_octets', 'interface.in_errors', 'interface.oper_status'], $proposal->plans[0]->metricIds());
        self::assertCount(2, $proposal->overlaps);
        $overlap = $proposal->overlaps[0];
        self::assertSame(['interface/eth0', 'interface.in_octets', 'legacy:data-query/SNMP - Interface Statistics', 'snmp', 'gnmi', false], [$overlap->resource->toString(), $overlap->metric->toString(), $overlap->existingOrigin, $overlap->existingProtocol, $overlap->offeredProtocol, $overlap->planned]);
        self::assertStringContainsString('already monitored for every interface', $proposal->matches[0]->excludedMetrics['interface.in_octets']);
    }

    public function testAPathBasedSourceIsNarrowedToTheResourcesNotYetMonitored(): void
    {
        $existing = new RecordingExistingMonitoring([42 => [$this->legacy('eth0', 'interface.in_octets')]]);

        $proposal = (new MonitoringPlanner($existing))->plan(PlanningFixture::request([PlanningFixture::openConfig()]));

        $inOctets = array_values(array_filter($proposal->plans[0]->targets[0]->metrics, static fn(PlannedMetric $m): bool => $m->id->toString() === 'interface.in_octets'));
        self::assertSame(['/interfaces/interface[name=eth1]/state/counters/in-octets'], array_map(static fn(PlannedMetric $m): string => $m->source, $inOctets));
        self::assertContains('/interfaces/interface[name=eth1]/state/counters/in-octets', $proposal->plans[0]->targets[0]->subscriptions);
        self::assertNotContains('/interfaces/interface[name=*]/state/counters/in-octets', $proposal->plans[0]->targets[0]->subscriptions);
        self::assertCount(1, $proposal->overlaps);
    }

    public function testAnOidCannotBeNarrowedSoAPartlyMonitoredMetricIsWithheld(): void
    {
        $existing = new RecordingExistingMonitoring([42 => [$this->legacy('eth0', 'interface.in_octets')]]);

        $proposal = (new MonitoringPlanner($existing))->plan(PlanningFixture::request([PlanningFixture::ifMib()]));

        self::assertNotContains('interface.in_octets', $proposal->plans[0]->metricIds());
        self::assertStringContainsString('cannot be limited to the remaining', $proposal->matches[0]->excludedMetrics['interface.in_octets']);
    }

    public function testPreferNewPlansTheMetricAnywayAndStillReportsTheOverlap(): void
    {
        $existing = new RecordingExistingMonitoring([42 => [$this->legacy('eth0', 'interface.in_octets')]]);

        $proposal = (new MonitoringPlanner($existing))->plan(PlanningFixture::request([PlanningFixture::openConfig()], policy: PlanningPolicy::verifiedOnly()->preferringNew()));

        self::assertContains('/interfaces/interface[name=*]/state/counters/in-octets', $proposal->plans[0]->targets[0]->subscriptions);
        self::assertTrue($proposal->overlaps[0]->planned);
    }

    public function testSeriesFromThePacksOwnPlansAreNotAnOverlap(): void
    {
        $existing = new RecordingExistingMonitoring([42 => [new MonitoredSeries(new ResourceId('interface', 'eth0'), new MetricId('interface.in_octets'), 'pack:openconfig-interfaces', 'gnmi')]]);

        $proposal = (new MonitoringPlanner($existing))->plan(PlanningFixture::request([PlanningFixture::openConfig()]));

        self::assertSame([], $proposal->overlaps);
        self::assertContains('interface.in_octets', $proposal->plans[0]->metricIds());
    }

    public function testNoResourcesOfThePacksKindMeansNoPlan(): void
    {
        $proposal = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan(PlanningFixture::request([PlanningFixture::openConfig()], resources: [new ResourceId('power_supply', 'psu1')]));

        self::assertTrue($proposal->matches[0]->matched);
        self::assertSame([], $proposal->plans);
        self::assertStringContainsString('no interface resources were enumerated', implode(' ', $proposal->matches[0]->reasons));
    }

    public function testADeprecatedPackIsOfferedOnlyToDevicesAlreadyUsingIt(): void
    {
        $deprecated = PlanningFixture::pack(state: PackState::Deprecated);
        $planner = new MonitoringPlanner(new RecordingExistingMonitoring());

        $fresh = $planner->plan(PlanningFixture::request([PlanningFixture::openConfig()], [$deprecated]));
        self::assertSame([], $fresh->plans);
        self::assertStringContainsString('deprecated', implode(' ', $fresh->matches[0]->reasons));

        $active = $planner->plan(PlanningFixture::request([PlanningFixture::openConfig()]))->plans[0]->withStatus(PlanStatus::Approved)->withStatus(PlanStatus::Active);
        $using = $planner->plan(PlanningFixture::request([PlanningFixture::openConfig()], [$deprecated], currentPlans: [$active]));
        self::assertCount(1, $using->plans);
    }

    public function testEqualInputsGiveEqualPlansAndTheDedupKeyIgnoresWhenTheyWereMade(): void
    {
        $planner = new MonitoringPlanner(new RecordingExistingMonitoring());
        $first = $planner->plan(PlanningFixture::request([PlanningFixture::openConfig()]))->plans[0];
        $again = $planner->plan(PlanningFixture::request([PlanningFixture::openConfig()]))->plans[0];
        $later = $planner->plan(PlanningFixture::request([PlanningFixture::openConfig()], generatedAt: '2026-10-24T09:00:00Z'))->plans[0];

        self::assertEquals($first, $again);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $first->dedupKey);
        self::assertSame($first->dedupKey, $later->dedupKey);
        self::assertNotSame($first->planId, $later->planId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $first->planId);
    }

    public function testReplanningReportsAddedChangedUnchangedAndRemovedWithoutTouchingActivePlans(): void
    {
        $planner = new MonitoringPlanner(new RecordingExistingMonitoring());
        $store = new InMemoryCollectionPlanStore();
        $active = $this->activate($planner->plan(PlanningFixture::request([PlanningFixture::openConfig(), PlanningFixture::ifMib()]))->plans[0]);
        $store->save($active);
        $before = $store->forDevice(42);

        $unchanged = $planner->reconcile(PlanningFixture::request([PlanningFixture::openConfig(), PlanningFixture::ifMib()], currentPlans: $store->forDevice(42), generatedAt: '2026-10-24T09:00:00Z'));
        self::assertSame([$active->planId], array_map(static fn(CollectionPlan $p): string => $p->planId, $unchanged->unchanged));
        self::assertSame([[], [], []], [$unchanged->added, $unchanged->removed, $unchanged->changed]);

        // The device stops answering gNMI: the same pack is now proposed over SNMP.
        $changed = $planner->reconcile(PlanningFixture::request([PlanningFixture::ifMib()], currentPlans: $store->forDevice(42)));
        self::assertCount(1, $changed->changed);
        $change = $changed->changed[0];
        self::assertSame($active, $change->current);
        self::assertSame(PlanStatus::Proposed, $change->proposed->status);
        self::assertContains('snmp:interface.in_octets', $change->addedMetrics);
        self::assertContains('gnmi:interface.in_octets', $change->removedMetrics);

        $removed = $planner->reconcile(PlanningFixture::request([], currentPlans: $store->forDevice(42)));
        self::assertSame([$active], $removed->removed);

        $added = $planner->reconcile(PlanningFixture::request([PlanningFixture::ifMib()], deviceId: 57));
        self::assertCount(1, $added->added);

        self::assertEquals($before, $store->forDevice(42));
        self::assertSame(PlanStatus::Active, $store->find($active->planId)?->status);
    }

    public function testAPackUpgradeShowsUpAsAChangedPlanListingTheNewMetric(): void
    {
        $planner = new MonitoringPlanner(new RecordingExistingMonitoring());
        $active = $this->activate($planner->plan(PlanningFixture::request([PlanningFixture::openConfig()]))->plans[0]);
        $upgraded = PlanningFixture::pack(PackDocuments::exampleAt('1.1.0', PackDocuments::addingOutDiscards()), PackState::Active);

        $report = $planner->reconcile(PlanningFixture::request([PlanningFixture::openConfig()], [$upgraded], currentPlans: [$active]));

        self::assertCount(1, $report->changed);
        self::assertSame(['gnmi:interface.out_discards'], $report->changed[0]->addedMetrics);
        self::assertSame([], $report->changed[0]->removedMetrics);
        self::assertSame('1.1.0', $report->changed[0]->proposed->packVersion);
        self::assertSame(PlanStatus::Active, $active->status);
    }

    /** @param list<PackMatch> $matches */
    private function match(array $matches, string $pack): PackMatch
    {
        foreach ($matches as $match) {
            if ($match->packName === $pack) {
                return $match;
            }
        }
        self::fail('No match for ' . $pack);
    }

    private function legacy(string $interface, string $metric): MonitoredSeries
    {
        return new MonitoredSeries(new ResourceId('interface', $interface), new MetricId($metric), 'legacy:data-query/SNMP - Interface Statistics', 'snmp');
    }

    private function activate(CollectionPlan $plan): CollectionPlan
    {
        return $plan->withStatus(PlanStatus::Approved)->withStatus(PlanStatus::Active);
    }
}
