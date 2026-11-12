<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteAssignmentCatalog;

/**
 * Query that lists the sites a device can be assigned to.
 */
final readonly class ListAssignableSites
{
    /**
     * Binds the query to console access and the site assignment catalog.
     */
    public function __construct(private ConsoleAccess $access, private SiteAssignmentCatalog $catalog) {}
    /**
     * Returns the sites.
     *
     * @return array Site names keyed by id.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->catalog->sites();
    }
}
