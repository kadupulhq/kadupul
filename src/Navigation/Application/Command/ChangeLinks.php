<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Command;

use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;

/** Applies one bulk operation to external links on behalf of an authorized actor. */
final readonly class ChangeLinks
{
    /** Wires the access check and the link store. */
    public function __construct(private LinkAccess $access, private LinkStore $store) {}
    /**
     * Runs delete, enable, disable, up or down on the given links.
     *
     * @param string $revision the revision of the link list the caller last read; a stale one is refused
     * @throws \Kadupul\Navigation\Application\Query\LinkAccessDenied when the actor may not manage links
     * @throws \InvalidArgumentException when the operation or selection is invalid
     * @throws \Kadupul\Navigation\Domain\LinkConflict when the links changed since $revision
     */
    public function __invoke(array $ids, string $operation, string $revision): void
    {
        $actor = $this->access->authorize();
        $this->store->mutate($actor->id, $ids, $operation, $revision);
    }
}
