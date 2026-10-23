<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\ModelRegistry;

use Kadupul\ModelRegistry\Domain\AssociationState;
use Kadupul\ModelRegistry\Domain\DeviceModelAssociation;
use Kadupul\ModelRegistry\Domain\InvalidAssociationTransition;
use Kadupul\ModelRegistry\Domain\VerificationRecord;
use PHPUnit\Framework\TestCase;

final class DeviceModelAssociationTest extends TestCase
{
    private function association(): DeviceModelAssociation
    {
        return DeviceModelAssociation::discovered(7, ModelFixtures::identity('ietf-interfaces'), ModelFixtures::revision('2018-02-20'));
    }

    private function record(array $supported, array $unsupported = []): VerificationRecord
    {
        return new VerificationRecord(new \DateTimeImmutable('2026-06-01T00:00:00+00:00'), $supported, $unsupported);
    }

    public function testAdvertisedStartsAsAdvertisedWithNoSupportedPaths(): void
    {
        $association = $this->association();
        self::assertSame(AssociationState::Advertised, $association->state());
        self::assertSame([], $association->supportedPaths());
        self::assertNull($association->verification());
    }

    public function testUnknownIsADistinctInitialStateFromUnsupported(): void
    {
        $unknown = DeviceModelAssociation::unknown(7, ModelFixtures::identity('x'), ModelFixtures::revision('2018-02-20'));
        self::assertSame(AssociationState::Unknown, $unknown->state());
        self::assertSame(AssociationState::Unsupported, $unknown->markUnsupported()->state());
    }

    public function testAdvertisedCannotBecomeVerifiedWithoutAVerificationRecord(): void
    {
        $this->expectException(InvalidAssociationTransition::class);
        $this->association()->transitionTo(AssociationState::Verified, null);
    }

    public function testAdvertisedCannotBecomePartialWithoutAVerificationRecord(): void
    {
        $this->expectException(InvalidAssociationTransition::class);
        $this->association()->transitionTo(AssociationState::Partial, null);
    }

    public function testNothingTransitionsBackToUnknown(): void
    {
        $this->expectException(InvalidAssociationTransition::class);
        $this->association()->transitionTo(AssociationState::Unknown, null);
    }

    public function testFullVerificationRecordsSupportedPathsAndBecomesVerified(): void
    {
        $verified = $this->association()->verify($this->record(['/interfaces', '/interfaces/interface']));
        self::assertSame(AssociationState::Verified, $verified->state());
        self::assertSame(['/interfaces', '/interfaces/interface'], $verified->supportedPaths());
    }

    public function testVerificationWithUnsupportedPathsIsPartialAndKeepsBothSets(): void
    {
        $partial = $this->association()->verify($this->record(['/interfaces'], ['/interfaces/interface/statistics']));
        self::assertSame(AssociationState::Partial, $partial->state());
        self::assertSame(['/interfaces'], $partial->supportedPaths());
        self::assertSame(['/interfaces/interface/statistics'], $partial->verification()?->unsupportedPaths());
    }

    public function testVerificationRequiresAtLeastOneSupportedPath(): void
    {
        $this->expectException(InvalidAssociationTransition::class);
        $this->record([], ['/interfaces']);
    }

    public function testRediscoveryDoesNotDowngradeVerifiedOrPartial(): void
    {
        $verified = $this->association()->verify($this->record(['/interfaces']));
        self::assertSame(AssociationState::Verified, $verified->rediscover()->state());
        self::assertSame(['/interfaces'], $verified->rediscover()->supportedPaths());

        $partial = $this->association()->verify($this->record(['/a'], ['/b']));
        self::assertSame(AssociationState::Partial, $partial->rediscover()->state());
    }

    public function testRediscoveryDoesNotOverrideAnExplicitUnsupportedFinding(): void
    {
        self::assertSame(AssociationState::Unsupported, $this->association()->markUnsupported()->rediscover()->state());
    }

    public function testRediscoveryPromotesUnknownToAdvertised(): void
    {
        $unknown = DeviceModelAssociation::unknown(7, ModelFixtures::identity('x'), ModelFixtures::revision('2018-02-20'));
        self::assertSame(AssociationState::Advertised, $unknown->rediscover()->state());
    }

    public function testAnExplicitNewObservationMayDowngradeVerified(): void
    {
        $verified = $this->association()->verify($this->record(['/interfaces']));
        self::assertSame(AssociationState::Unsupported, $verified->markUnsupported()->state());
        self::assertSame([], $verified->markUnsupported()->supportedPaths());
        $partial = $verified->verify($this->record(['/interfaces'], ['/interfaces/interface']));
        self::assertSame(AssociationState::Partial, $partial->state());
    }

    public function testUnsupportedCanBeReplacedByALaterVerification(): void
    {
        $again = $this->association()->markUnsupported()->verify($this->record(['/interfaces']));
        self::assertSame(AssociationState::Verified, $again->state());
    }
}
