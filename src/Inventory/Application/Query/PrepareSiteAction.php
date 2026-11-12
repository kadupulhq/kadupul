<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteLifecycle;
use Kadupul\Inventory\Domain\SiteSelection;

/**
 * Query that loads the sites selected for a delete or duplicate action.
 */
final readonly class PrepareSiteAction
{
    /**
     * Binds the query to console access and the site lifecycle port.
     */
    public function __construct(private ConsoleAccess $access, private SiteLifecycle $sites) {}

    /**
     * Returns the selected sites for the confirmation step.
     *
     * @param array $ids Sites selected for the action.
     * @return array List of Site.
     * @throws \InvalidArgumentException When the ids are not 1 to 100 valid, unique ids.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(array $ids): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->sites->find(SiteSelection::validateIds($ids));
    }
}
