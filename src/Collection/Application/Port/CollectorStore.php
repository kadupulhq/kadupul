<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Port;

use Kadupul\Collection\Domain\Collector;
use Kadupul\Collection\Domain\CollectorId;

interface CollectorStore
{
    /** @return list<Collector> */
    public function findAll(): array;

    public function findById(CollectorId $id): ?Collector;
}
