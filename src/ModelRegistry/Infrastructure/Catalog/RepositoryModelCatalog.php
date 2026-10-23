<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Infrastructure\Catalog;

use Kadupul\ModelRegistry\Application\Port\DeviceAssociationRepository;
use Kadupul\ModelRegistry\Application\Query\LookupModel;
use Kadupul\ModelRegistry\Contract\DeviceModelLink;
use Kadupul\ModelRegistry\Contract\ModelCatalog;
use Kadupul\ModelRegistry\Contract\ModelSummary;
use Kadupul\ModelRegistry\Domain\DeviceModelAssociation;
use Kadupul\ModelRegistry\Domain\InvalidModelDefinition;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;
use Kadupul\ModelRegistry\Domain\UnsupportedModelRevision;

final class RepositoryModelCatalog implements ModelCatalog
{
    public function __construct(private readonly LookupModel $lookup, private readonly DeviceAssociationRepository $associations) {}

    public function find(string $family, string $namespace, string $name, string $revision): ?ModelSummary
    {
        $key = $this->parse($family, $namespace, $name, $revision);
        if ($key === null) {
            return null;
        }
        try {
            $model = ($this->lookup)(...$key);
        } catch (UnsupportedModelRevision) {
            return null;
        }

        return new ModelSummary(
            $model->identity()->family->value,
            $model->identity()->namespace,
            $model->identity()->name,
            $model->revision()->value(),
            $model->capabilities(),
            array_map(
                static fn($d): string => $d->identity->key() . ($d->minimumRevision === null ? '' : '@' . $d->minimumRevision->value()),
                $model->dependencies(),
            ),
            $model->checksum()->value(),
        );
    }

    public function capabilities(string $family, string $namespace, string $name, string $revision): array
    {
        return $this->find($family, $namespace, $name, $revision)?->capabilities ?? [];
    }

    public function devicesFor(string $family, string $namespace, string $name, string $revision): array
    {
        $key = $this->parse($family, $namespace, $name, $revision);

        return $key === null ? [] : array_map($this->link(...), $this->associations->forModel(...$key));
    }

    public function modelsForDevice(int $deviceId): array
    {
        return array_map($this->link(...), $this->associations->forDevice($deviceId));
    }

    /** @return array{ModelIdentity, ModelRevision}|null null for input that cannot name a model */
    private function parse(string $family, string $namespace, string $name, string $revision): ?array
    {
        try {
            $identity = ModelIdentity::parse($family, $namespace, $name);

            return [$identity, ModelRevision::parse($identity->family, $revision)];
        } catch (InvalidModelDefinition) {
            return null;
        }
    }

    private function link(DeviceModelAssociation $a): DeviceModelLink
    {
        return new DeviceModelLink(
            $a->deviceId(),
            $a->identity()->family->value,
            $a->identity()->namespace,
            $a->identity()->name,
            $a->revision()->value(),
            $a->state()->value,
            $a->supportedPaths(),
        );
    }
}
