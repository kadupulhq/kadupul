<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Application\Port;

use Kadupul\ModelRegistry\Domain\Model;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;

interface ModelRepository
{
    public function find(ModelIdentity $identity, ModelRevision $revision): ?Model;

    /**
     * @return list<Model> every registered revision of the identity, oldest first
     */
    public function revisionsOf(ModelIdentity $identity): array;

    public function save(Model $model): void;
}
