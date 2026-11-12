<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Port;

/** Storage for external links. Reads return a revision that writes must present back, so a change made from a stale view is refused. */
interface LinkStore
{
    /** Every link in display order, with the revision of that list. */
    public function snapshot(): array;
    /**
     * One filtered, sorted page of links; $filters are the parsed list parameters, including the row limit.
     * The result holds the page's links and the total number of matches.
     */
    public function list(array $filters): array;
    /** Names of the installed content files a link can point at. */
    public function files(): array;
    /** The configured list page size. */
    public function defaultRows(): int;
    /**
     * Creates or updates a link and returns its id.
     *
     * @param int|null $id the link to update, or null to create one
     * @throws \InvalidArgumentException when the fields are invalid
     * @throws \Kadupul\Navigation\Domain\LinkConflict when the revision is stale or the link no longer exists
     */
    public function save(int $actorId, ?int $id, array $fields, string $revision): int;
    /**
     * Applies delete, enable, disable, up or down to the links.
     *
     * @throws \InvalidArgumentException when the operation or selection is invalid
     * @throws \Kadupul\Navigation\Domain\LinkConflict when the revision is stale or a link no longer exists
     */
    public function mutate(int $actorId, array $ids, string $operation, string $revision): void;
}
