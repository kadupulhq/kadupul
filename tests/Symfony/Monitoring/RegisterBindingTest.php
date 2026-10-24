<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\RegisterBinding;
use Kadupul\Monitoring\Domain\BindingConflict;
use Kadupul\Monitoring\Domain\BindingMatch;
use Kadupul\Monitoring\Domain\BindingOrigin;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\InvalidBinding;
use Kadupul\Monitoring\Domain\MatchConfidence;
use Kadupul\Monitoring\Domain\RrdDataSourceType;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryBindingRepository;
use PHPUnit\Framework\TestCase;

final class RegisterBindingTest extends TestCase
{
    private InMemoryBindingRepository $bindings;
    private RegisterBinding $register;

    protected function setUp(): void
    {
        $this->bindings = new InMemoryBindingRepository();
        $this->register = new RegisterBinding($this->bindings);
    }

    public function testRegisteringTheSameBindingTwiceIsANoOp(): void
    {
        $first = $this->register->register(BindingFixture::binding());
        $again = $this->register->register(BindingFixture::binding(provenance: BindingFixture::binding()->provenance));

        self::assertSame($first, $again);
        self::assertCount(1, $this->bindings->matching(BindingFixture::key()));
    }

    public function testRepeatingAnAlreadyApprovedBindingKeepsItsApproval(): void
    {
        $this->bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved));

        self::assertSame(BindingStatus::Approved, $this->register->register(BindingFixture::binding())->status);
    }

    public function testRedefiningAKeyIsRejectedAndLeavesTheOriginal(): void
    {
        $this->register->register(BindingFixture::binding());

        foreach ([
            'another field' => BindingFixture::binding(field: BindingFixture::field(9, 'traffic_in')),
            'another type' => BindingFixture::binding(type: RrdDataSourceType::Derive),
        ] as $name => $conflicting) {
            try {
                $this->register->register($conflicting);
                self::fail($name . ' was accepted');
            } catch (BindingConflict $e) {
                self::assertStringContainsString(BindingFixture::key()->bindingId(), $e->getMessage(), $name);
            }
        }
        self::assertTrue($this->bindings->matching(BindingFixture::key())[0]->definitionEquals(BindingFixture::binding()));
    }

    public function testARejectedBindingMakesWayForANewDefinition(): void
    {
        $this->bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Rejected));

        $replacement = $this->register->register(BindingFixture::binding(field: BindingFixture::field(9, 'traffic_in')));
        $reused = $this->register->register(BindingFixture::binding(key: BindingFixture::key(identity: 'eth1')));

        self::assertSame($replacement, $this->bindings->matching(BindingFixture::key())[0]);
        self::assertSame(BindingStatus::Proposed, $reused->status);
    }

    public function testTwoKeysCannotShareOneStorageField(): void
    {
        $this->register->register(BindingFixture::binding());

        $this->expectException(BindingConflict::class);
        $this->expectExceptionMessage('7:traffic_in');
        $this->register->register(BindingFixture::binding(key: BindingFixture::key(identity: 'eth1')));
    }

    public function testSeveralFieldsOfOneRrdFileBindToDifferentKeys(): void
    {
        $this->register->register(BindingFixture::binding());
        $this->register->register(BindingFixture::binding(key: BindingFixture::key(metric: 'interface.out_octets'), field: BindingFixture::field(7, 'traffic_out')));

        self::assertCount(2, $this->bindings->forDevice(42));
    }

    public function testANewBindingStartsAsProposed(): void
    {
        $this->expectException(InvalidBinding::class);
        $this->expectExceptionMessage('proposed');

        $this->register->register(BindingFixture::binding()->withStatus(BindingStatus::Approved));
    }

    public function testAConfirmedLegacyMatchBecomesAProposedLegacyDiscoveredBinding(): void
    {
        $match = new BindingMatch(MatchConfidence::Confirmed, ['ifName eth0 matches', 'traffic_in carries interface.in_octets'], BindingFixture::field(), RrdDataSourceType::Counter);

        $binding = $this->register->discovered(BindingFixture::key(), 'openconfig-interfaces', '1.0.0', $match, new \DateTimeImmutable('2026-10-23T14:05:00Z'));

        self::assertSame(BindingStatus::Proposed, $binding->status);
        self::assertSame(BindingOrigin::LegacyDiscovered, $binding->provenance->origin);
        self::assertSame('ifName eth0 matches; traffic_in carries interface.in_octets', $binding->provenance->sourceDetail);
        self::assertSame($binding, $this->bindings->matching(BindingFixture::key())[0]);
    }

    public function testAnAmbiguousOrHeuristicMatchIsNeverBound(): void
    {
        foreach ([MatchConfidence::Ambiguous, MatchConfidence::Heuristic, MatchConfidence::None] as $confidence) {
            try {
                $this->register->discovered(BindingFixture::key(), 'openconfig-interfaces', '1.0.0', new BindingMatch($confidence, ['two candidates'], BindingFixture::field(), RrdDataSourceType::Counter), new \DateTimeImmutable());
                self::fail($confidence->value . ' was bound');
            } catch (InvalidBinding $e) {
                self::assertStringContainsString($confidence->value, $e->getMessage());
            }
        }
        self::assertSame([], $this->bindings->forDevice(42));
    }
}
