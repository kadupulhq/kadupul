<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteCreator;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewSite;

/**
 * Command that creates a site.
 */
final readonly class CreateSite
{
    /**
     * Binds the command to console access and the site creator.
     */
    public function __construct(private ConsoleAccess $access, private SiteCreator $sites) {}

    /**
     * Validates the fields and creates the site.
     *
     * @param array $fields Submitted values; see NewSite::DEFAULTS for the keys.
     * @return int Id of the new site.
     * @throws \InvalidArgumentException When a field is invalid.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(array $fields): int
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }

        return $this->sites->create($actor->id, new NewSite($fields));
    }
}
