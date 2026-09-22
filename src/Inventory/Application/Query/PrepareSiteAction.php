<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteLifecycle;
use Kadupul\Inventory\Domain\SiteSelection;

final readonly class PrepareSiteAction
{
    public function __construct(private ConsoleAccess $access, private SiteLifecycle $sites) {}

    public function __invoke(array $ids): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->sites->find(SiteSelection::validateIds($ids));
    }
}
