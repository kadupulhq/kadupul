<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Infrastructure\Persistence;

use Kadupul\ModelRegistry\Application\Port\ModelRepository;
use Kadupul\ModelRegistry\Domain\Model;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;

/** Interim store until the registry has a schema; contents last for one process. */
final class InMemoryModelRepository implements ModelRepository
{
    /** @var array<string, array<string, Model>> identity key => revision value => model */
    private array $models = [];

    public function find(ModelIdentity $identity, ModelRevision $revision): ?Model
    {
        return $this->models[$identity->key()][$revision->value()] ?? null;
    }

    public function revisionsOf(ModelIdentity $identity): array
    {
        $revisions = array_values($this->models[$identity->key()] ?? []);
        usort($revisions, static fn(Model $a, Model $b): int => $a->revision()->compareTo($b->revision()));

        return $revisions;
    }

    public function save(Model $model): void
    {
        $this->models[$model->identity()->key()][$model->revision()->value()] = $model;
    }
}
