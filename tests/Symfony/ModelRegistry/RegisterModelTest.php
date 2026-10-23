<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\ModelRegistry;

use Kadupul\ModelRegistry\Application\Command\RegisterModel;
use Kadupul\ModelRegistry\Application\Command\Registration;
use Kadupul\ModelRegistry\Domain\CircularDependency;
use Kadupul\ModelRegistry\Domain\MissingDependency;
use Kadupul\ModelRegistry\Domain\ModelChecksumConflict;
use Kadupul\ModelRegistry\Domain\UnsupportedModelRevision;
use Kadupul\ModelRegistry\Infrastructure\Persistence\InMemoryModelRepository;
use PHPUnit\Framework\TestCase;

final class RegisterModelTest extends TestCase
{
    private InMemoryModelRepository $models;
    private RegisterModel $register;

    protected function setUp(): void
    {
        $this->models = new InMemoryModelRepository();
        $this->register = new RegisterModel($this->models);
    }

    public function testRegistersAModelWithoutDependencies(): void
    {
        $model = ModelFixtures::model('ietf-interfaces');
        self::assertSame(Registration::Created, ($this->register)($model));
        self::assertEquals($model, $this->models->find($model->identity(), $model->revision()));
    }

    public function testRegisteringTheSameChecksumAgainIsIdempotentAndKeepsTheOriginalProvenance(): void
    {
        $first = ModelFixtures::model('ietf-interfaces');
        ($this->register)($first);
        $again = new \Kadupul\ModelRegistry\Domain\Model(
            $first->identity(),
            $first->revision(),
            [],
            ['interfaces'],
            ModelFixtures::provenance('mirror'),
            $first->checksum(),
        );
        self::assertSame(Registration::AlreadyRegistered, ($this->register)($again));
        self::assertSame('vendor-repo', $this->models->find($first->identity(), $first->revision())?->provenance()->source());
    }

    public function testSameIdentityAndRevisionWithADifferentChecksumIsRejectedAndNotStored(): void
    {
        $original = ModelFixtures::model('ietf-interfaces');
        ($this->register)($original);
        $tampered = ModelFixtures::model('ietf-interfaces', seed: 'tampered');
        try {
            ($this->register)($tampered);
            self::fail('Expected a checksum conflict');
        } catch (ModelChecksumConflict $e) {
            self::assertStringContainsString('yang|urn:example:ietf-interfaces|ietf-interfaces@2024-01-15', $e->getMessage());
        }
        self::assertEquals($original, $this->models->find($original->identity(), $original->revision()));
    }

    public function testMissingDependencyIsReportedByExactIdentityAndNothingIsStored(): void
    {
        $model = ModelFixtures::model('ietf-ip', dependencies: [ModelFixtures::dependsOn('ietf-interfaces')]);
        try {
            ($this->register)($model);
            self::fail('Expected a missing dependency');
        } catch (MissingDependency $e) {
            self::assertEquals(ModelFixtures::identity('ietf-interfaces'), $e->identity());
            self::assertStringContainsString('yang|urn:example:ietf-interfaces|ietf-interfaces', $e->getMessage());
        }
        self::assertNull($this->models->find($model->identity(), $model->revision()));
    }

    public function testDependencyOlderThanTheMinimumRevisionIsUnsupported(): void
    {
        ($this->register)(ModelFixtures::model('ietf-interfaces', '2014-05-08'));
        $model = ModelFixtures::model('ietf-ip', dependencies: [ModelFixtures::dependsOn('ietf-interfaces', '2018-02-20')]);
        try {
            ($this->register)($model);
            self::fail('Expected an unsupported revision');
        } catch (UnsupportedModelRevision $e) {
            self::assertStringContainsString('2018-02-20', $e->getMessage());
            self::assertStringContainsString('2014-05-08', $e->getMessage());
        }
    }

    public function testDependencySatisfiedByANewerRegisteredRevisionIsAccepted(): void
    {
        ($this->register)(ModelFixtures::model('ietf-interfaces', '2014-05-08'));
        ($this->register)(ModelFixtures::model('ietf-interfaces', '2018-02-20'));
        $model = ModelFixtures::model('ietf-ip', dependencies: [ModelFixtures::dependsOn('ietf-interfaces', '2018-02-20')]);
        self::assertSame(Registration::Created, ($this->register)($model));
    }

    public function testANewRevisionThatClosesACycleIsRejected(): void
    {
        ($this->register)(ModelFixtures::model('a'));
        ($this->register)(ModelFixtures::model('b', dependencies: [ModelFixtures::dependsOn('a')]));
        try {
            ($this->register)(ModelFixtures::model('a', '2025-01-01', dependencies: [ModelFixtures::dependsOn('b')]));
            self::fail('Expected a cycle');
        } catch (CircularDependency $e) {
            self::assertStringContainsString('a -> b -> a', $e->getMessage());
        }
        self::assertCount(1, $this->models->revisionsOf(ModelFixtures::identity('a')));
    }
}
