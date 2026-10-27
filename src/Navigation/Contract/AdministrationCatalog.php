<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract;

use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;

interface AdministrationCatalog
{
    public function supports(ListScreen $screen): bool;

    /** @return list<array<string, int|float|string|null>> At most rows + 1 public-data records, never credentials. */
    public function read(ListScreen $screen, ListFilters $filters): array;
}
