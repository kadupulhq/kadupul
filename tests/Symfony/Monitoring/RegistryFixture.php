<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\ModelRegistry\Application\Query\LookupModel;
use Kadupul\ModelRegistry\Domain\Checksum;
use Kadupul\ModelRegistry\Domain\Model;
use Kadupul\ModelRegistry\Domain\ModelDependency;
use Kadupul\ModelRegistry\Domain\ModelFamily;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;
use Kadupul\ModelRegistry\Domain\Provenance;
use Kadupul\ModelRegistry\Infrastructure\Catalog\RepositoryModelCatalog;
use Kadupul\ModelRegistry\Infrastructure\Persistence\InMemoryDeviceAssociationRepository;
use Kadupul\ModelRegistry\Infrastructure\Persistence\InMemoryModelRepository;

/**
 * A real ModelRegistry behind its public catalog, holding the models the
 * Monitoring tests advertise. Monitoring itself only sees the Contract.
 */
final class RegistryFixture
{
    public const OC_INTERFACES_NS = 'http://openconfig.net/yang/interfaces';
    public const OC_INTERFACES_REV = '2021-04-06';
    public const OC_TYPES_NS = 'http://openconfig.net/yang/openconfig-types';
    public const OC_TYPES_REV = '2019-04-16';
    public const IF_MIB_NS = '1.3.6.1.2.1.31';
    public const IF_MIB_REV = '2000-06-14';

    public static function catalog(): RepositoryModelCatalog
    {
        $models = new InMemoryModelRepository();
        $types = self::identity(ModelFamily::OpenConfig, self::OC_TYPES_NS, 'openconfig-types');
        $models->save(self::model($types, self::OC_TYPES_REV, [], []));
        $models->save(self::model(
            self::identity(ModelFamily::OpenConfig, self::OC_INTERFACES_NS, 'openconfig-interfaces'),
            self::OC_INTERFACES_REV,
            [new ModelDependency($types, ModelRevision::parse(ModelFamily::OpenConfig, self::OC_TYPES_REV))],
            ['openconfig-interfaces'],
        ));
        $models->save(self::model(self::identity(ModelFamily::SnmpMib, self::IF_MIB_NS, 'IF-MIB'), self::IF_MIB_REV, [], ['snmp:if-mib']));

        return new RepositoryModelCatalog(new LookupModel($models), new InMemoryDeviceAssociationRepository());
    }

    private static function identity(ModelFamily $family, string $namespace, string $name): ModelIdentity
    {
        return new ModelIdentity($family, $namespace, $name);
    }

    /**
     * @param list<ModelDependency> $dependencies
     * @param list<string> $capabilities
     */
    private static function model(ModelIdentity $identity, string $revision, array $dependencies, array $capabilities): Model
    {
        return new Model(
            $identity,
            ModelRevision::parse($identity->family, $revision),
            $dependencies,
            $capabilities,
            new Provenance('test-fixture', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'), null),
            Checksum::fromHex(hash('sha256', $identity->key() . '@' . $revision)),
        );
    }
}
