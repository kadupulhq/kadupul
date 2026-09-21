<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteCatalog;
use Kadupul\Inventory\Application\ReadModel\SitePage;
use Kadupul\Inventory\Domain\SiteListCriteria;

final readonly class ListSites
{
    public function __construct(private ConsoleAccess $access, private SiteCatalog $sites) {}

    public function __invoke(SiteListCriteria $criteria): SitePage
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new InventoryAccessDenied(true);
        }
        if (!$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied(false);
        }
        return $this->sites->listFor($actor->id, $criteria);
    }
}
