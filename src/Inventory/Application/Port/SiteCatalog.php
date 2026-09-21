<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Application\ReadModel\SitePage;
use Kadupul\Inventory\Domain\SiteListCriteria;

interface SiteCatalog
{
    // Site administrators can list all sites; device counts are actor-scoped.
    public function listFor(int $userId, SiteListCriteria $criteria): SitePage;
}
