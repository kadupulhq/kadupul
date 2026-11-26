<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Contract;

/**
 * Read-only collector catalog exposed to other bounded contexts.
 */
interface CollectorCatalog
{
    /** @return list<CollectorSummary> */
    public function listAll(): array;

    /** @return list<CollectorSummary> */
    public function findEnabled(): array;

    public function findById(int $id): ?CollectorSummary;
}
