<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\ModelRegistry;

use Kadupul\ModelRegistry\Application\Command\RecordDeviceObservation;
use Kadupul\ModelRegistry\Domain\AssociationState;
use Kadupul\ModelRegistry\Domain\UnsupportedModelRevision;
use Kadupul\ModelRegistry\Domain\VerificationRecord;
use Kadupul\ModelRegistry\Infrastructure\Persistence\InMemoryDeviceAssociationRepository;
use Kadupul\ModelRegistry\Infrastructure\Persistence\InMemoryModelRepository;
use PHPUnit\Framework\TestCase;

final class RecordDeviceObservationTest extends TestCase
{
    private InMemoryModelRepository $models;
    private InMemoryDeviceAssociationRepository $associations;
    private RecordDeviceObservation $record;

    protected function setUp(): void
    {
        $this->models = new InMemoryModelRepository();
        $this->associations = new InMemoryDeviceAssociationRepository();
        $this->record = new RecordDeviceObservation($this->models, $this->associations);
    }

    private function stored(): ?AssociationState
    {
        return $this->associations->find(7, ModelFixtures::identity('ietf-interfaces'), ModelFixtures::revision('2018-02-20'))?->state();
    }

    public function testAdvertisementDoesNotRequireTheModelToBeRegistered(): void
    {
        $this->record->advertised(7, ModelFixtures::identity('ietf-interfaces'), ModelFixtures::revision('2018-02-20'));
        self::assertSame(AssociationState::Advertised, $this->stored());
    }

    public function testVerificationRequiresTheRegisteredRevision(): void
    {
        $this->expectException(UnsupportedModelRevision::class);
        $this->record->verified(7, ModelFixtures::identity('ietf-interfaces'), ModelFixtures::revision('2018-02-20'), new VerificationRecord(new \DateTimeImmutable('2026-06-01'), ['/interfaces'], []));
    }

    public function testRediscoveryAfterVerificationKeepsTheVerifiedState(): void
    {
        $this->models->save(ModelFixtures::model('ietf-interfaces', '2018-02-20'));
        $identity = ModelFixtures::identity('ietf-interfaces');
        $revision = ModelFixtures::revision('2018-02-20');
        $this->record->advertised(7, $identity, $revision);
        $this->record->verified(7, $identity, $revision, new VerificationRecord(new \DateTimeImmutable('2026-06-01'), ['/interfaces'], []));
        $this->record->advertised(7, $identity, $revision);
        self::assertSame(AssociationState::Verified, $this->stored());
    }

    public function testExplicitUnsupportedFindingOverridesVerified(): void
    {
        $this->models->save(ModelFixtures::model('ietf-interfaces', '2018-02-20'));
        $identity = ModelFixtures::identity('ietf-interfaces');
        $revision = ModelFixtures::revision('2018-02-20');
        $this->record->verified(7, $identity, $revision, new VerificationRecord(new \DateTimeImmutable('2026-06-01'), ['/interfaces'], []));
        $this->record->unsupported(7, $identity, $revision);
        self::assertSame(AssociationState::Unsupported, $this->stored());
    }
}
