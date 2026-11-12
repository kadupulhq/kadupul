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

/**
 * Query that returns one page of the site list.
 */
final readonly class ListSites
{
    /**
     * Binds the query to console access and the site catalog.
     */
    public function __construct(private ConsoleAccess $access, private SiteCatalog $sites) {}

    /**
     * Returns the sites that match the criteria.
     *
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
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
