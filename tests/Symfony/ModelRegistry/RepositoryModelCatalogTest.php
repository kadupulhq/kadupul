<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\ModelRegistry;

use Kadupul\ModelRegistry\Application\Command\RecordDeviceObservation;
use Kadupul\ModelRegistry\Application\Query\LookupModel;
use Kadupul\ModelRegistry\Domain\VerificationRecord;
use Kadupul\ModelRegistry\Infrastructure\Catalog\RepositoryModelCatalog;
use Kadupul\ModelRegistry\Infrastructure\Persistence\InMemoryDeviceAssociationRepository;
use Kadupul\ModelRegistry\Infrastructure\Persistence\InMemoryModelRepository;
use PHPUnit\Framework\TestCase;

final class RepositoryModelCatalogTest extends TestCase
{
    private InMemoryModelRepository $models;
    private InMemoryDeviceAssociationRepository $associations;
    private RepositoryModelCatalog $catalog;

    protected function setUp(): void
    {
        $this->models = new InMemoryModelRepository();
        $this->associations = new InMemoryDeviceAssociationRepository();
        $this->catalog = new RepositoryModelCatalog(new LookupModel($this->models), $this->associations);
    }

    public function testFindReturnsPlainValuesForOtherModules(): void
    {
        $this->models->save(ModelFixtures::model('ietf-ip', dependencies: [ModelFixtures::dependsOn('ietf-interfaces', '2018-02-20')], capabilities: ['ip', 'ipv6']));
        $summary = $this->catalog->find('yang', 'urn:example:ietf-ip', 'ietf-ip', '2024-01-15');
        self::assertNotNull($summary);
        self::assertSame('2024-01-15', $summary->revision);
        self::assertSame(['ip', 'ipv6'], $summary->capabilities);
        self::assertSame(['yang|urn:example:ietf-interfaces|ietf-interfaces@2018-02-20'], $summary->dependencies);
        self::assertSame(64, strlen($summary->checksum));
    }

    public function testFindReturnsNullForAnUnregisteredRevisionAndMalformedInput(): void
    {
        self::assertNull($this->catalog->find('yang', 'urn:example:x', 'x', '2024-01-15'));
        self::assertNull($this->catalog->find('bogus', 'urn:example:x', 'x', '2024-01-15'));
    }

    public function testCapabilitiesAreEmptyWhenTheModelIsUnknown(): void
    {
        self::assertSame([], $this->catalog->capabilities('yang', 'urn:example:x', 'x', '2024-01-15'));
        $this->models->save(ModelFixtures::model('x', capabilities: ['a', 'b']));
        self::assertSame(['a', 'b'], $this->catalog->capabilities('yang', 'urn:example:x', 'x', '2024-01-15'));
    }

    public function testDevicesForAModelExposeStateAndSupportedPaths(): void
    {
        $this->models->save(ModelFixtures::model('x', '2018-02-20'));
        $record = new RecordDeviceObservation($this->models, $this->associations);
        $x = ModelFixtures::identity('x');
        $rev = ModelFixtures::revision('2018-02-20');
        $record->advertised(3, $x, $rev);
        $record->verified(9, $x, $rev, new VerificationRecord(new \DateTimeImmutable('2026-06-01'), ['/a'], ['/b']));

        $links = $this->catalog->devicesFor('yang', 'urn:example:x', 'x', '2018-02-20');
        self::assertCount(2, $links);
        self::assertSame([3, 9], array_map(static fn($l): int => $l->deviceId, $links));
        self::assertSame(['advertised', 'partial'], array_map(static fn($l): string => $l->state, $links));
        self::assertSame(['/a'], $links[1]->supportedPaths);
        self::assertSame([], $this->catalog->devicesFor('yang', 'urn:example:y', 'y', '2018-02-20'));
    }

    public function testModelsForADeviceListEveryAssociation(): void
    {
        $record = new RecordDeviceObservation($this->models, $this->associations);
        $record->advertised(3, ModelFixtures::identity('x'), ModelFixtures::revision('2018-02-20'));
        $record->advertised(3, ModelFixtures::identity('y'), ModelFixtures::revision('2018-02-20'));
        $record->advertised(4, ModelFixtures::identity('z'), ModelFixtures::revision('2018-02-20'));
        $names = array_map(static fn($l): string => $l->name, $this->catalog->modelsForDevice(3));
        self::assertSame(['x', 'y'], $names);
    }
}
