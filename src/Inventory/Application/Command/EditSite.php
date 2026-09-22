<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;

final readonly class EditSite
{
    public function __construct(private ConsoleAccess $access, private SiteEditor $sites) {}

    public function __invoke(int $id, string $name, string $notes, string $revision, ?array $fields = null): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $site = $this->sites->find($id);
        if ($site === null) {
            throw new SiteNotFound();
        }
        $site->revise($name, $notes, $revision, $fields);
        $this->sites->save($actor->id, $site, $revision);
    }
}
