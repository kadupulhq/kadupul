<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;

/**
 * Command that edits one site.
 */
final readonly class EditSite
{
    /**
     * Binds the command to console access and the site editor.
     */
    public function __construct(private ConsoleAccess $access, private SiteEditor $sites) {}

    /**
     * Applies the edit to the site after checking the revision.
     *
     * @param int $id Site id.
     * @param string $revision Token from the site when the form was loaded.
     * @param array|null $fields Address and map fields to replace; null keeps the current ones.
     * @throws SiteNotFound When the site does not exist.
     * @throws \Kadupul\Inventory\Domain\SiteEditConflict When the site changed since $revision was issued.
     * @throws \InvalidArgumentException When a value is invalid.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
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
