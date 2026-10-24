<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Application\Command;

use Kadupul\ModelRegistry\Application\Port\ModelRepository;
use Kadupul\ModelRegistry\Application\Query\ResolveDependencies;
use Kadupul\ModelRegistry\Domain\CircularDependency;
use Kadupul\ModelRegistry\Domain\MissingDependency;
use Kadupul\ModelRegistry\Domain\Model;
use Kadupul\ModelRegistry\Domain\ModelChecksumConflict;
use Kadupul\ModelRegistry\Domain\UnsupportedModelRevision;

final class RegisterModel
{
    public function __construct(private readonly ModelRepository $models) {}

    /**
     * @throws ModelChecksumConflict when the identity and revision exist with other content
     * @throws MissingDependency
     * @throws UnsupportedModelRevision
     * @throws CircularDependency
     */
    public function __invoke(Model $model): Registration
    {
        $existing = $this->models->find($model->identity(), $model->revision());
        if ($existing !== null) {
            if (!$existing->checksum()->equals($model->checksum())) {
                throw new ModelChecksumConflict($model->identity(), $model->revision(), $existing->checksum(), $model->checksum());
            }

            return Registration::AlreadyRegistered;
        }
        (new ResolveDependencies($this->models))->forModel($model);
        $this->models->save($model);

        return Registration::Created;
    }
}
