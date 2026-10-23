<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Infrastructure\Persistence;

use Kadupul\ModelRegistry\Application\Port\DeviceAssociationRepository;
use Kadupul\ModelRegistry\Domain\DeviceModelAssociation;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;

final class InMemoryDeviceAssociationRepository implements DeviceAssociationRepository
{
    /** @var array<string, DeviceModelAssociation> */
    private array $associations = [];

    public function find(int $deviceId, ModelIdentity $identity, ModelRevision $revision): ?DeviceModelAssociation
    {
        return $this->associations[self::key($deviceId, $identity, $revision)] ?? null;
    }

    public function save(DeviceModelAssociation $association): void
    {
        $this->associations[self::key($association->deviceId(), $association->identity(), $association->revision())] = $association;
    }

    public function forModel(ModelIdentity $identity, ModelRevision $revision): array
    {
        $matches = array_values(array_filter(
            $this->associations,
            static fn(DeviceModelAssociation $a): bool => $a->identity()->equals($identity) && $a->revision()->equals($revision),
        ));
        usort($matches, static fn(DeviceModelAssociation $a, DeviceModelAssociation $b): int => $a->deviceId() <=> $b->deviceId());

        return $matches;
    }

    public function forDevice(int $deviceId): array
    {
        $matches = array_values(array_filter($this->associations, static fn(DeviceModelAssociation $a): bool => $a->deviceId() === $deviceId));
        usort($matches, static fn(DeviceModelAssociation $a, DeviceModelAssociation $b): int => [$a->identity()->key(), $a->revision()->value()] <=> [$b->identity()->key(), $b->revision()->value()]);

        return $matches;
    }

    private static function key(int $deviceId, ModelIdentity $identity, ModelRevision $revision): string
    {
        return $deviceId . '|' . $identity->key() . '|' . $revision->value();
    }
}
