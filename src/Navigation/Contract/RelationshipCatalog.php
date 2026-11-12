<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract;

use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Relationships\RelationshipPage;
use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;

/** Supplies the records of the relationship lists it supports. */
interface RelationshipCatalog
{
    /** True when this catalog can serve the screen. */
    public function supports(RelationshipScreen $screen): bool;
    /**
     * One page of records related to the parent record.
     *
     * @throws Relationships\RelationshipParentNotFound when the parent record does not exist
     */
    public function read(RelationshipScreen $screen, ListFilters $filters, int $parentId): RelationshipPage;
}
