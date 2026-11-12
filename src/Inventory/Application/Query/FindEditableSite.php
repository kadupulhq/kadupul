<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Domain\Site;

/**
 * Query that loads one site for editing.
 */
final readonly class FindEditableSite
{
    /**
     * Binds the query to console access and the site editor.
     */
    public function __construct(private ConsoleAccess $access, private SiteEditor $sites) {}

    /**
     * Returns the site.
     *
     * @return ?\Kadupul\Inventory\Domain\Site Null when the site does not exist.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(int $id): ?Site
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->sites->find($id);
    }
}
