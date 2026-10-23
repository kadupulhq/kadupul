<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Application\Port;

use Kadupul\ModelRegistry\Domain\DeviceModelAssociation;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;

interface DeviceAssociationRepository
{
    public function find(int $deviceId, ModelIdentity $identity, ModelRevision $revision): ?DeviceModelAssociation;

    public function save(DeviceModelAssociation $association): void;

    /** @return list<DeviceModelAssociation> ordered by device id */
    public function forModel(ModelIdentity $identity, ModelRevision $revision): array;

    /** @return list<DeviceModelAssociation> ordered by model key and revision */
    public function forDevice(int $deviceId): array;
}
