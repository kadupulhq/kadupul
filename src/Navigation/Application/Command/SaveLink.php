<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Command;

use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;

/** Creates or updates one external link on behalf of an authorized actor. */
final readonly class SaveLink
{
    /** Wires the access check and the link store. */
    public function __construct(private LinkAccess $access, private LinkStore $store) {}
    /**
     * Saves the link and returns its id.
     *
     * @param int|null $id the link to update, or null to create one
     * @param string $revision the revision of the link list the caller last read; a stale one is refused
     * @throws \Kadupul\Navigation\Application\Query\LinkAccessDenied when the actor may not manage links
     * @throws \InvalidArgumentException when the fields are invalid
     * @throws \Kadupul\Navigation\Domain\LinkConflict when the links changed since $revision or the link no longer exists
     */
    public function __invoke(?int $id, array $fields, string $revision): int
    {
        $actor = $this->access->authorize();
        return $this->store->save($actor->id, $id, $fields, $revision);
    }
}
