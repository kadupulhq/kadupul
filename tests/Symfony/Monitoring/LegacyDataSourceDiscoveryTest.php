<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\MonitoringPlanner;
use Kadupul\Monitoring\Application\RegisterBinding;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingOrigin;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\MatchConfidence;
use Kadupul\Monitoring\Domain\Overlap;
use Kadupul\Monitoring\Domain\OwnershipClaimRefused;
use Kadupul\Monitoring\Domain\RrdDataSourceType;
use Kadupul\Monitoring\Domain\WriterOwnership;
use Kadupul\Monitoring\Infrastructure\Legacy\IdentityConcern;
use Kadupul\Monitoring\Infrastructure\Legacy\LegacyDataSource;
use Kadupul\Monitoring\Infrastructure\Legacy\LegacyDataSourceDiscovery;
use Kadupul\Monitoring\Infrastructure\Legacy\LegacyExistingMonitoring;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryBindingRepository;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterOwnershipRegistry;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;
use PHPUnit\Framework\TestCase;

/** Runs against SQLite (LegacyMonitoringFixture); MariaDB/MySQL are not exercised. */
final class LegacyDataSourceDiscoveryTest extends TestCase
{
    private LegacyMonitoringFixture $fixture;
    private LegacyDataSourceDiscovery $discovery;
    private LegacyExistingMonitoring $existing;

    protected function setUp(): void
    {
        $this->fixture = new LegacyMonitoringFixture();
        $this->discovery = new LegacyDataSourceDiscovery($this->fixture->connection);
        $this->existing = new LegacyExistingMonitoring($this->discovery);
    }

    public function testDiscoveryReadsOneDevicesDataSourcesWithFieldsWritersAndGraphs(): void
    {
        $sources = $this->discovery->dataSources(42);

        self::assertSame([10, 11, 12, 13, 14, 15, 17], array_map(static fn(LegacyDataSource $s): int => $s->localDataId, $sources));
        $traffic = $sources[0];
        self::assertSame(42, $traffic->deviceId);
        self::assertSame(1, $traffic->dataQueryId);
        self::assertSame('1', $traffic->index);
        self::assertSame('snmp', $traffic->protocol);
        self::assertSame(['ifDescr' => 'Ethernet0', 'ifIndex' => '1', 'ifName' => 'eth0'], $traffic->resourceHints);
        self::assertSame('<path_rra>/core-sw-01_traffic_in_10.rrd', $traffic->pathPlaceholder);
        self::assertSame([100], $traffic->graphIds);
        self::assertSame(
            [['traffic_in', RrdDataSourceType::Counter, true], ['traffic_out', RrdDataSourceType::Counter, true]],
            array_map(static fn($f): array => [$f->name, $f->type, $f->legacyWriter], $traffic->fields),
        );
        self::assertFalse($sources[1]->fields[0]->legacyWriter, 'errors_in has no poller_item row');
        self::assertSame(RrdDataSourceType::Derive, $sources[1]->fields[0]->type);
        self::assertSame([], $sources[4]->resourceHints, 'a cache row that is no longer present is not a hint');
    }

    public function testAMultiOutputScriptWriterCoversEveryFieldOfItsFile(): void
    {
        $script = $this->discovery->dataSources(42)[6];

        self::assertSame(0, $script->dataQueryId);
        self::assertSame('legacy', $script->protocol);
        self::assertSame([['load_1min', true], ['load_5min', true]], array_map(static fn($f): array => [$f->name, $f->legacyWriter], $script->fields));
        self::assertSame(RrdDataSourceType::Gauge, $script->fields[0]->type);
    }

    public function testDiscoveryOnlySelectsWithBoundParametersAndChangesNothing(): void
    {
        $before = $this->fixture->snapshot();
        $this->fixture->statements = [];

        $this->discovery->dataSources(42);
        $this->discovery->identityConcerns(42);
        $this->discovery->ownership(BindingFixture::field(10, 'traffic_in'));
        $this->existing->monitoredSeries(42);
        $this->existing->match(BindingFixture::key());
        $statements = $this->fixture->statements;

        self::assertNotSame([], $statements);
        foreach ($statements as $sql) {
            self::assertMatchesRegularExpression('/\A\s*SELECT\s/i', $sql);
            self::assertDoesNotMatchRegularExpression('/\b42\b|traffic_in/', $sql, 'values are bound, not interpolated');
        }
        self::assertSame($before, $this->fixture->snapshot());
    }

    public function testUnreliableIdentitiesAreExported(): void
    {
        $concerns = array_map(static fn(IdentityConcern $c): string => $c->localDataId . ' ' . $c->kind, $this->discovery->identityConcerns(42));

        self::assertSame([
            '10 multiple_fields_per_rrd',
            '11 no_poller_item',
            '12 duplicate_resource_name',
            '13 duplicate_resource_name',
            '14 reindexed_snmp_index',
            '15 reindexed_snmp_index',
            '17 multiple_fields_per_rrd',
        ], $concerns);
        self::assertStringContainsString('dup', $this->discovery->identityConcerns(42)[2]->detail);
    }

    public function testEveryExistingLegacyFieldDefaultsToTheLegacyPoller(): void
    {
        self::assertSame(WriterOwnership::LegacyPoller, $this->discovery->ownership(BindingFixture::field(10, 'traffic_in')));
        self::assertSame(WriterOwnership::LegacyPoller, $this->discovery->ownership(BindingFixture::field(11, 'errors_in')), 'no poller_item row is still a legacy data source');
        self::assertSame(WriterOwnership::Unassigned, $this->discovery->ownership(BindingFixture::field(10, 'missing')));
        self::assertSame(WriterOwnership::Unassigned, $this->discovery->ownership(BindingFixture::field(999, 'traffic_in')));

        $this->expectException(OwnershipClaimRefused::class);
        (new WriterOwnershipRegistry($this->discovery))->claim(BindingFixture::field(11, 'errors_in'));
    }

    public function testConfirmedNeedsDeviceResourceIdentityAndFieldSemanticsToAgree(): void
    {
        foreach ([['eth0', 'ifName'], ['Ethernet0', 'ifDescr'], ['1', 'ifIndex']] as [$identity, $via]) {
            $match = $this->existing->match(BindingFixture::key(identity: $identity));
            self::assertSame(MatchConfidence::Confirmed, $match->confidence, $identity);
            self::assertTrue($match->field?->equals(BindingFixture::field(10, 'traffic_in')), $identity);
            self::assertSame(RrdDataSourceType::Counter, $match->type);
            self::assertStringContainsString($via, implode(' ', $match->reasons));
        }
        $out = $this->existing->match(BindingFixture::key(metric: 'interface.out_octets'));
        self::assertTrue($out->field?->equals(BindingFixture::field(10, 'traffic_out')));
        self::assertSame(MatchConfidence::Confirmed, $this->existing->match(BindingFixture::key(metric: 'interface.in_errors', identity: 'eth1'))->confidence);
    }

    public function testDuplicateNamesAreAmbiguousAndNameOnlyEvidenceIsHeuristic(): void
    {
        $cases = [
            'two interfaces named dup' => [BindingFixture::key(identity: 'dup'), MatchConfidence::Ambiguous],
            'cache row gone after a reindex' => [BindingFixture::key(identity: 'old0'), MatchConfidence::Heuristic],
            'index only, field name resembles the metric' => [BindingFixture::key(identity: '9'), MatchConfidence::Heuristic],
            'no field carries the metric' => [BindingFixture::key(metric: 'interface.out_errors'), MatchConfidence::None],
            'other device' => [BindingFixture::key(deviceId: 43), MatchConfidence::None],
            'unknown resource kind' => [new BindingKey(42, new MetricId('interface.in_octets'), new ResourceId('power_supply', 'psu1'), 'snmp'), MatchConfidence::None],
        ];
        foreach ($cases as $name => [$key, $expected]) {
            $match = $this->existing->match($key);
            self::assertSame($expected, $match->confidence, $name . ': ' . implode('; ', $match->reasons));
            self::assertFalse($match->canBind(), $name);
        }
        self::assertStringContainsString('12', implode(' ', $this->existing->match(BindingFixture::key(identity: 'dup'))->reasons));
        self::assertStringContainsString('13', implode(' ', $this->existing->match(BindingFixture::key(identity: 'dup'))->reasons));
    }

    public function testOnlyAConfirmedMatchIsRegisteredAsABinding(): void
    {
        $bindings = new InMemoryBindingRepository();
        $register = new RegisterBinding($bindings);
        $at = new \DateTimeImmutable('2026-10-23T14:05:00Z');

        $binding = $register->discovered(BindingFixture::key(), 'openconfig-interfaces', '1.0.0', $this->existing->match(BindingFixture::key()), $at);
        try {
            $register->discovered(BindingFixture::key(identity: 'dup'), 'openconfig-interfaces', '1.0.0', $this->existing->match(BindingFixture::key(identity: 'dup')), $at);
            self::fail('An ambiguous match was bound');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(BindingStatus::Proposed, $binding->status);
        self::assertSame(BindingOrigin::LegacyDiscovered, $binding->provenance->origin);
        self::assertCount(1, $bindings->forDevice(42));
        self::assertSame(WriterOwnership::LegacyPoller, (new WriterOwnershipRegistry($this->discovery))->ownership($binding->field));
    }

    public function testThePlannerReportsLegacyOverlapsFromTheRealAdapter(): void
    {
        $proposal = (new MonitoringPlanner($this->existing))->plan(PlanningFixture::request([PlanningFixture::ifMib()]));

        $overlaps = array_map(static fn(Overlap $o): string => $o->resource->toString() . ' ' . $o->metric->toString() . ' ' . $o->existingOrigin . ' ' . $o->existingProtocol, $proposal->overlaps);
        self::assertSame([
            'interface/eth0 interface.in_octets legacy:data-source/10:traffic_in snmp',
            'interface/eth0 interface.out_octets legacy:data-source/10:traffic_out snmp',
            'interface/eth1 interface.in_errors legacy:data-source/11:errors_in snmp',
        ], $overlaps);
        self::assertSame([], $this->existing->monitoredSeries(43), 'device 43 has no identifiable resources');
    }
}
