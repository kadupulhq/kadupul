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

interface RelationshipCatalog
{
    public function supports(RelationshipScreen $screen): bool;
    public function read(RelationshipScreen $screen, ListFilters $filters, int $parentId): RelationshipPage;
}
