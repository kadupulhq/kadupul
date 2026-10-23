<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Application\Query;

use Kadupul\ModelRegistry\Application\Port\ModelRepository;
use Kadupul\ModelRegistry\Domain\CircularDependency;
use Kadupul\ModelRegistry\Domain\MissingDependency;
use Kadupul\ModelRegistry\Domain\Model;
use Kadupul\ModelRegistry\Domain\ModelDependency;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;
use Kadupul\ModelRegistry\Domain\UnsupportedModelRevision;

/**
 * Each dependency resolves to the newest registered revision of its identity,
 * because a minimum revision states a floor, not an exact pin.
 */
final class ResolveDependencies
{
    public function __construct(private readonly ModelRepository $models) {}

    /**
     * @return list<Model> transitive dependencies, each listed after the models it depends on
     * @throws UnsupportedModelRevision when the requested model is not registered or a dependency is below its minimum
     * @throws MissingDependency
     * @throws CircularDependency
     */
    public function __invoke(ModelIdentity $identity, ModelRevision $revision): array
    {
        $root = (new LookupModel($this->models))($identity, $revision);

        return $this->forModel($root);
    }

    /**
     * Resolves a model that may not be stored yet. Registration uses this so a
     * rejected model is never persisted.
     *
     * @return list<Model>
     */
    public function forModel(Model $root): array
    {
        $ordered = [];
        $this->visit($root, $root, [], $ordered);

        return array_values($ordered);
    }

    /**
     * @param list<string> $path
     * @param array<string, Model> $ordered
     */
    private function visit(Model $root, Model $model, array $path, array &$ordered): void
    {
        $key = $model->identity()->key();
        if (in_array($key, $path, true)) {
            throw new CircularDependency(array_map($this->label(...), [...$path, $key]));
        }
        $path[] = $key;
        foreach ($model->dependencies() as $dependency) {
            $target = $this->select($root, $dependency, $model);
            if (!isset($ordered[$target->reference()])) {
                $this->visit($root, $target, $path, $ordered);
                $ordered[$target->reference()] = $target;
            }
        }
    }

    private function select(Model $root, ModelDependency $dependency, Model $requiredBy): Model
    {
        $candidates = $this->models->revisionsOf($dependency->identity);
        // The root may be new, so it takes part in selection for its own identity.
        if ($root->identity()->equals($dependency->identity)) {
            $candidates = array_values(array_filter($candidates, static fn(Model $m): bool => !$m->revision()->equals($root->revision())));
            $candidates[] = $root;
        }
        if ($candidates === []) {
            throw new MissingDependency($dependency->identity, $requiredBy->reference());
        }
        usort($candidates, static fn(Model $a, Model $b): int => $a->revision()->compareTo($b->revision()));
        $newest = $candidates[array_key_last($candidates)];
        $minimum = $dependency->minimumRevision;
        if ($minimum !== null && $newest->revision()->compareTo($minimum) < 0) {
            throw UnsupportedModelRevision::belowMinimum($dependency->identity, $minimum, array_map(static fn(Model $m): ModelRevision => $m->revision(), $candidates), $requiredBy->reference());
        }

        return $newest;
    }

    private function label(string $key): string
    {
        return explode('|', $key)[2];
    }
}
