<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract;

use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogScreen;

/** Supplies the records of the management lists it supports. */
interface ManagementCatalog
{
    /** True when this catalog can serve the screen. */
    public function supports(CatalogScreen $screen): bool;
    /**
     * One page of records for the screen, scoped to the parent record when the screen has one.
     *
     * @throws Management\CatalogParentNotFound when the parent record does not exist
     */
    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage;
}
