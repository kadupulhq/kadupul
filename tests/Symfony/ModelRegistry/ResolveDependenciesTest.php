<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\ModelRegistry;

use Kadupul\ModelRegistry\Application\Query\LookupModel;
use Kadupul\ModelRegistry\Application\Query\ResolveDependencies;
use Kadupul\ModelRegistry\Domain\CircularDependency;
use Kadupul\ModelRegistry\Domain\MissingDependency;
use Kadupul\ModelRegistry\Domain\UnsupportedModelRevision;
use Kadupul\ModelRegistry\Infrastructure\Persistence\InMemoryModelRepository;
use PHPUnit\Framework\TestCase;

final class ResolveDependenciesTest extends TestCase
{
    private InMemoryModelRepository $models;

    protected function setUp(): void
    {
        $this->models = new InMemoryModelRepository();
    }

    public function testLookupReturnsTheRegisteredModel(): void
    {
        $model = ModelFixtures::model('ietf-interfaces');
        $this->models->save($model);
        self::assertEquals($model, (new LookupModel($this->models))($model->identity(), $model->revision()));
    }

    public function testLookupOfAnUnregisteredRevisionListsWhatIsRegistered(): void
    {
        $this->models->save(ModelFixtures::model('ietf-interfaces', '2014-05-08'));
        try {
            (new LookupModel($this->models))(ModelFixtures::identity('ietf-interfaces'), ModelFixtures::revision('2018-02-20'));
            self::fail('Expected an unsupported revision');
        } catch (UnsupportedModelRevision $e) {
            self::assertStringContainsString('2018-02-20', $e->getMessage());
            self::assertStringContainsString('2014-05-08', $e->getMessage());
        }
    }

    public function testLookupOfAnUnknownIdentityReportsThatNoRevisionExists(): void
    {
        $this->expectException(UnsupportedModelRevision::class);
        $this->expectExceptionMessage('no revision is registered');
        (new LookupModel($this->models))(ModelFixtures::identity('nothing'), ModelFixtures::revision('2018-02-20'));
    }

    public function testResolutionListsDependenciesBeforeDependents(): void
    {
        $this->models->save(ModelFixtures::model('base'));
        $this->models->save(ModelFixtures::model('mid', dependencies: [ModelFixtures::dependsOn('base')]));
        $top = ModelFixtures::model('top', dependencies: [ModelFixtures::dependsOn('mid'), ModelFixtures::dependsOn('base')]);
        $this->models->save($top);

        $order = array_map(
            static fn($model): string => $model->identity()->name(),
            (new ResolveDependencies($this->models))($top->identity(), $top->revision()),
        );
        self::assertSame(['base', 'mid'], $order);
    }

    public function testResolutionPicksTheNewestRevisionThatMeetsTheMinimum(): void
    {
        $this->models->save(ModelFixtures::model('base', '2014-05-08'));
        $this->models->save(ModelFixtures::model('base', '2018-02-20'));
        $top = ModelFixtures::model('top', dependencies: [ModelFixtures::dependsOn('base', '2016-01-01')]);
        $this->models->save($top);

        $resolved = (new ResolveDependencies($this->models))($top->identity(), $top->revision());
        self::assertSame('2018-02-20', $resolved[0]->revision()->value());
    }

    public function testResolutionReportsTheExactMissingIdentity(): void
    {
        $top = ModelFixtures::model('top', dependencies: [ModelFixtures::dependsOn('ghost')]);
        $this->models->save($top);
        try {
            (new ResolveDependencies($this->models))($top->identity(), $top->revision());
            self::fail('Expected a missing dependency');
        } catch (MissingDependency $e) {
            self::assertEquals(ModelFixtures::identity('ghost'), $e->identity());
        }
    }

    public function testResolutionRejectsADependencyBelowItsMinimum(): void
    {
        $this->models->save(ModelFixtures::model('base', '2014-05-08'));
        $top = ModelFixtures::model('top', dependencies: [ModelFixtures::dependsOn('base', '2018-02-20')]);
        $this->models->save($top);
        $this->expectException(UnsupportedModelRevision::class);
        (new ResolveDependencies($this->models))($top->identity(), $top->revision());
    }

    public function testResolutionDetectsACycleAlreadyInTheStore(): void
    {
        $a = ModelFixtures::model('a', dependencies: [ModelFixtures::dependsOn('b')]);
        $b = ModelFixtures::model('b', dependencies: [ModelFixtures::dependsOn('a')]);
        $this->models->save($a);
        $this->models->save($b);
        try {
            (new ResolveDependencies($this->models))($a->identity(), $a->revision());
            self::fail('Expected a cycle');
        } catch (CircularDependency $e) {
            self::assertStringContainsString('a -> b -> a', $e->getMessage());
        }
    }

    public function testDiamondDependenciesAreNotACycle(): void
    {
        $this->models->save(ModelFixtures::model('base'));
        $this->models->save(ModelFixtures::model('left', dependencies: [ModelFixtures::dependsOn('base')]));
        $this->models->save(ModelFixtures::model('right', dependencies: [ModelFixtures::dependsOn('base')]));
        $top = ModelFixtures::model('top', dependencies: [ModelFixtures::dependsOn('left'), ModelFixtures::dependsOn('right')]);
        $this->models->save($top);

        $resolved = (new ResolveDependencies($this->models))($top->identity(), $top->revision());
        self::assertCount(3, $resolved);
    }
}
