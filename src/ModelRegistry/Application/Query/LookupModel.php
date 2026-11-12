<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Application\Query;

use Kadupul\ModelRegistry\Application\Port\ModelRepository;
use Kadupul\ModelRegistry\Domain\Model;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;
use Kadupul\ModelRegistry\Domain\UnsupportedModelRevision;

/**
 * Finds a registered model revision or fails with the revisions that do exist.
 */
final class LookupModel
{
    /**
     * Binds the query to the model store.
     */
    public function __construct(private readonly ModelRepository $models) {}

    /**
     * @throws UnsupportedModelRevision when the revision is not registered
     */
    public function __invoke(ModelIdentity $identity, ModelRevision $revision): Model
    {
        return $this->models->find($identity, $revision)
            ?? throw UnsupportedModelRevision::notRegistered($identity, $revision, array_map(static fn(Model $m): ModelRevision => $m->revision(), $this->models->revisionsOf($identity)));
    }
}
