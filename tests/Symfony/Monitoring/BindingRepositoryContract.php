<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Domain\BindingConflict;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingOrigin;
use Kadupul\Monitoring\Domain\BindingProvenance;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\BindingTransitionRejected;
use Kadupul\Monitoring\Domain\MetricBinding;
use Kadupul\Monitoring\Domain\RrdDataSourceType;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * Behaviour every BindingRepository shares, so the in-memory store used by
 * unit tests cannot drift from the database store used in production.
 */
trait BindingRepositoryContract
{
    abstract protected function repository(): BindingRepository;

    public function testASavedBindingIsFoundByKeyFieldAndDevice(): void
    {
        $bindings = $this->repository();
        $binding = BindingFixture::binding();
        $bindings->save($binding);

        self::assertBindingsEqual([$binding], $bindings->matching(BindingFixture::key()));
        self::assertBindingsEqual([$binding], $bindings->writingTo(BindingFixture::field()));
        self::assertBindingsEqual([$binding], $bindings->forDevice(42));
        self::assertSame([], $bindings->matching(BindingFixture::key(identity: 'eth1')));
        self::assertSame([], $bindings->writingTo(BindingFixture::field(8)));
        self::assertSame([], $bindings->forDevice(43));
    }

    public function testEveryFieldSurvivesTheRoundTrip(): void
    {
        $bindings = $this->repository();
        $binding = new MetricBinding(
            new BindingKey(7, new MetricId('system.cpu.load'), new ResourceId('cpu', "Ünit 0 / slot \"a\""), 'gnmi'),
            'openconfig-system',
            '2.10.3-rc.1',
            BindingFixture::field(12, 'cpu-Load_1'),
            RrdDataSourceType::Gauge,
            BindingStatus::Verified,
            new BindingProvenance(BindingOrigin::Operator, "admin: bound by hand\nsecond line", new \DateTimeImmutable('2026-10-23T14:05:06.123456+02:00')),
            new \DateTimeImmutable('2026-10-24T01:02:03.000009Z'),
        );
        $bindings->save($binding);

        self::assertBindingsEqual([$binding], $bindings->matching($binding->key));
    }

    public function testALifecycleStepReplacesTheBindingInPlace(): void
    {
        $bindings = $this->repository();
        $bindings->save(BindingFixture::binding());
        $approved = BindingFixture::binding()->withStatus(BindingStatus::Approved);
        $bindings->save($approved);

        self::assertBindingsEqual([$approved], $bindings->matching(BindingFixture::key()));
        self::assertCount(1, $bindings->forDevice(42));
    }

    public function testAStaleCopyCannotUndoARejection(): void
    {
        $bindings = $this->repository();
        $bindings->save(BindingFixture::binding());
        $rejected = BindingFixture::binding()->withStatus(BindingStatus::Rejected);
        $bindings->save($rejected);

        try {
            $bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved));
            self::fail('A stale approval replaced a rejected binding.');
        } catch (BindingTransitionRejected $e) {
            self::assertStringContainsString('stored as rejected', $e->getMessage());
        }
        self::assertBindingsEqual([$rejected], $bindings->matching(BindingFixture::key()));
    }

    public function testADifferentDefinitionReplacesOnlyARejectedBinding(): void
    {
        $bindings = $this->repository();
        $bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved));
        $moved = BindingFixture::binding(field: BindingFixture::field(8));

        try {
            $bindings->save($moved);
            self::fail('A new definition replaced an approved binding.');
        } catch (BindingConflict) {
            self::assertSame([], $bindings->writingTo(BindingFixture::field(8)));
        }
        $bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved)->withStatus(BindingStatus::Rejected));
        $bindings->save($moved);

        self::assertBindingsEqual([$moved], $bindings->matching(BindingFixture::key()));
        self::assertSame([], $bindings->writingTo(BindingFixture::field()));
    }

    public function testKeysAndFieldsCompareExactly(): void
    {
        $bindings = $this->repository();
        $variants = ['eth0', 'ETH0', 'eth0 ', 'éth0', 'eth0'];
        foreach (array_unique($variants) as $i => $identity) {
            $bindings->save(BindingFixture::binding(key: BindingFixture::key(identity: $identity), field: BindingFixture::field(20 + $i)));
        }
        $bindings->save(BindingFixture::binding(key: BindingFixture::key(identity: 'eth9'), field: BindingFixture::field(30, 'traffic_in')));
        $bindings->save(BindingFixture::binding(key: BindingFixture::key(identity: 'eth10'), field: BindingFixture::field(30, 'Traffic_in')));

        foreach (array_unique($variants) as $i => $identity) {
            $found = $bindings->matching(BindingFixture::key(identity: $identity));
            self::assertCount(1, $found, json_encode($identity, JSON_THROW_ON_ERROR));
            self::assertSame(20 + $i, $found[0]->field->localDataId);
        }
        $found = $bindings->writingTo(BindingFixture::field(30, 'Traffic_in'));
        self::assertCount(1, $found);
        self::assertSame('eth10', $found[0]->key->resource->identity);
        self::assertCount(6, $bindings->forDevice(42));
    }

    /**
     * @param list<MetricBinding> $expected
     * @param list<MetricBinding> $actual
     */
    private static function assertBindingsEqual(array $expected, array $actual): void
    {
        $shape = static fn(MetricBinding $b): array => [
            $b->key->canonical(), $b->packName, $b->packVersion, $b->field->toString(), $b->expectedType, $b->status,
            $b->provenance->origin, $b->provenance->sourceDetail, $b->provenance->recordedAt->format('Y-m-d\TH:i:s.uP'),
            $b->lastVerifiedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
        ];
        self::assertSame(array_map($shape, $expected), array_map($shape, $actual));
    }
}
