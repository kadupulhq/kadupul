<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Application\Command;

use Kadupul\ModelRegistry\Application\Port\DeviceAssociationRepository;
use Kadupul\ModelRegistry\Application\Port\ModelRepository;
use Kadupul\ModelRegistry\Application\Query\LookupModel;
use Kadupul\ModelRegistry\Domain\DeviceModelAssociation;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;
use Kadupul\ModelRegistry\Domain\UnsupportedModelRevision;
use Kadupul\ModelRegistry\Domain\VerificationRecord;

final class RecordDeviceObservation
{
    public function __construct(private readonly ModelRepository $models, private readonly DeviceAssociationRepository $associations) {}

    /**
     * Records what a device claims to support. The model need not be registered:
     * devices routinely advertise revisions the registry has never seen.
     */
    public function advertised(int $deviceId, ModelIdentity $identity, ModelRevision $revision): void
    {
        $current = $this->current($deviceId, $identity, $revision);
        $this->associations->save($current->rediscover());
    }

    /**
     * @throws UnsupportedModelRevision when the revision being verified is not registered
     */
    public function verified(int $deviceId, ModelIdentity $identity, ModelRevision $revision, VerificationRecord $record): void
    {
        (new LookupModel($this->models))($identity, $revision);
        $this->associations->save($this->current($deviceId, $identity, $revision)->verify($record));
    }

    public function unsupported(int $deviceId, ModelIdentity $identity, ModelRevision $revision): void
    {
        $this->associations->save($this->current($deviceId, $identity, $revision)->markUnsupported());
    }

    private function current(int $deviceId, ModelIdentity $identity, ModelRevision $revision): DeviceModelAssociation
    {
        return $this->associations->find($deviceId, $identity, $revision) ?? DeviceModelAssociation::unknown($deviceId, $identity, $revision);
    }
}
