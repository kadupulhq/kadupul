<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingMatch;
use Kadupul\Monitoring\Domain\BindingOrigin;
use Kadupul\Monitoring\Domain\BindingProvenance;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\BindingTransitionRejected;
use Kadupul\Monitoring\Domain\InvalidBinding;
use Kadupul\Monitoring\Domain\MatchConfidence;
use Kadupul\Monitoring\Domain\MetricBinding;
use Kadupul\Monitoring\Domain\RrdDataSourceName;
use Kadupul\Monitoring\Domain\RrdDataSourceType;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;
use PHPUnit\Framework\TestCase;

final class MetricBindingTest extends TestCase
{
    public function testDataSourceNamesFollowRrdtoolRules(): void
    {
        foreach (['a', 'traffic_in', 'Traffic-In_64', str_repeat('x', 19)] as $valid) {
            self::assertSame($valid, (new RrdDataSourceName($valid))->value);
        }
        foreach (['', str_repeat('x', 20), 'traffic in', 'traffic.in', "traffic_in\n", 'trafic/in', 'é'] as $invalid) {
            try {
                new RrdDataSourceName($invalid);
                self::fail('Accepted data-source name ' . json_encode($invalid));
            } catch (InvalidBinding $e) {
                self::assertStringContainsString('1 to 19', $e->getMessage());
            }
        }
    }

    public function testAStorageFieldIsTheLocalDataIdAndTheDataSourceNameTogether(): void
    {
        $in = BindingFixture::field(7, 'traffic_in');
        $out = BindingFixture::field(7, 'traffic_out');

        self::assertFalse($in->equals($out), 'two data sources in one RRD file are different fields');
        self::assertTrue($in->equals(BindingFixture::field(7, 'traffic_in')));
        self::assertFalse($in->equals(BindingFixture::field(8, 'traffic_in')));
        self::assertSame($in->localDataId, $out->localDataId);

        $this->expectException(InvalidBinding::class);
        new StorageField(0, new RrdDataSourceName('traffic_in'));
    }

    public function testTheIdentityKeyIsDeviceMetricResourceAndProtocol(): void
    {
        $key = BindingFixture::key();

        self::assertTrue($key->equals(BindingFixture::key()));
        self::assertFalse($key->equals(BindingFixture::key(deviceId: 43)));
        self::assertFalse($key->equals(BindingFixture::key(metric: 'interface.out_octets')));
        self::assertFalse($key->equals(BindingFixture::key(identity: 'eth1')));
        self::assertFalse($key->equals(BindingFixture::key(protocol: 'gnmi')));
        self::assertSame($key->bindingId(), BindingFixture::key()->bindingId());
        self::assertMatchesRegularExpression('/^bnd-[0-9a-f]{32}$/D', $key->bindingId());
        self::assertNotSame($key->bindingId(), BindingFixture::key(identity: 'eth1')->bindingId());
        // A resource identity may contain the separator; the id must not collide.
        self::assertNotSame(
            (new BindingKey(42, new MetricId('interface.in_octets'), new ResourceId('interface', 'a/b'), 'snmp'))->bindingId(),
            (new BindingKey(42, new MetricId('interface.in_octets'), new ResourceId('interface', 'a'), 'snmp'))->bindingId(),
        );
    }

    public function testAKeyRefusesAnUnknownProtocolOrDevice(): void
    {
        foreach ([[0, 'snmp'], [42, 'telnet'], [42, '']] as [$device, $protocol]) {
            try {
                new BindingKey($device, new MetricId('interface.in_octets'), new ResourceId('interface', 'eth0'), $protocol);
                self::fail('Accepted ' . $device . ' ' . $protocol);
            } catch (InvalidBinding) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDefinitionEqualityIgnoresLifecycleButNotStorageOrType(): void
    {
        $binding = BindingFixture::binding();

        self::assertTrue($binding->definitionEquals($binding->withStatus(BindingStatus::Approved)));
        self::assertTrue($binding->definitionEquals(BindingFixture::binding(provenance: new BindingProvenance(BindingOrigin::Operator, 'admin', new \DateTimeImmutable('2026-10-24T00:00:00Z')))));
        self::assertFalse($binding->definitionEquals(BindingFixture::binding(field: BindingFixture::field(7, 'traffic_out'))));
        self::assertFalse($binding->definitionEquals(BindingFixture::binding(type: RrdDataSourceType::Derive)));
        self::assertFalse($binding->definitionEquals(BindingFixture::binding(packVersion: '1.1.0')));
    }

    public function testStatusMovesOnlyAlongTheLifecycle(): void
    {
        $approved = BindingFixture::binding()->withStatus(BindingStatus::Approved);
        self::assertSame(BindingStatus::Approved, $approved->status);
        self::assertSame(BindingStatus::Verified, $approved->withStatus(BindingStatus::Verified)->status);

        $this->expectException(BindingTransitionRejected::class);
        $this->expectExceptionMessage('proposed to verified');
        BindingFixture::binding()->withStatus(BindingStatus::Verified);
    }

    public function testABindingNeedsAValidPackVersionAndProvenanceDetail(): void
    {
        foreach ([static fn() => BindingFixture::binding(packVersion: 'one'), static fn() => new BindingProvenance(BindingOrigin::LegacyDiscovered, ' ', new \DateTimeImmutable())] as $make) {
            try {
                $make();
                self::fail('Accepted an invalid binding');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $provenance = new BindingProvenance(BindingOrigin::ModelDriven, 'planner', new \DateTimeImmutable('2026-10-23T16:05:00+02:00'));
        self::assertSame('2026-10-23T14:05:00+00:00', $provenance->recordedAt->format(DATE_ATOM));
    }

    public function testOnlyAConfirmedMatchCanBind(): void
    {
        $field = BindingFixture::field(7, 'traffic_in');
        $confirmed = new BindingMatch(MatchConfidence::Confirmed, ['ifName eth0 matches'], $field, RrdDataSourceType::Counter);

        self::assertTrue($confirmed->canBind());
        foreach ([MatchConfidence::Ambiguous, MatchConfidence::Heuristic, MatchConfidence::None] as $confidence) {
            self::assertFalse((new BindingMatch($confidence, ['why'], null, null))->canBind(), $confidence->value);
        }

        $this->expectException(InvalidBinding::class);
        new BindingMatch(MatchConfidence::Confirmed, ['no field'], null, null);
    }

    public function testAMatchAlwaysExplainsItself(): void
    {
        $this->expectException(InvalidBinding::class);
        new BindingMatch(MatchConfidence::None, [], null, null);
    }

    public function testTheBindingCarriesTheExpectedDataSourceType(): void
    {
        $binding = BindingFixture::binding(type: RrdDataSourceType::Gauge);

        self::assertSame(RrdDataSourceType::Gauge, $binding->expectedType);
        self::assertSame('GAUGE', $binding->expectedType->value);
        self::assertNull($binding->lastVerifiedAt);
        self::assertSame('openconfig-interfaces', $binding->packName);
    }
}
