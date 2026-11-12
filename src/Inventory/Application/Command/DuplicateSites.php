<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteLifecycle;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\SiteSelection;

/**
 * Command that copies a batch of sites.
 */
final readonly class DuplicateSites
{
    /**
     * Binds the command to console access and the site lifecycle port.
     */
    public function __construct(private ConsoleAccess $access, private SiteLifecycle $sites) {}

    /**
     * Creates a copy of every site in the selection.
     *
     * @param SiteSelection $selection Sites to copy.
     * @param string $pattern Name for each copy, where "<site>" stands for the original name.
     * @return array Ids of the new sites.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(SiteSelection $selection, string $pattern): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->sites->duplicate($actor->id, $selection, $pattern);
    }
}
