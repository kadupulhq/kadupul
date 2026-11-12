<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A batch of 1 to 100 sites, each paired with the revision the user saw.
 */
final readonly class SiteSelection
{
    public array $revisions;

    /**
     * Validates the ids and revisions.
     *
     * @param array $revisions SHA-256 revision tokens keyed by site id.
     * @throws \InvalidArgumentException When the ids or revisions are invalid.
     */
    public function __construct(array $revisions)
    {
        self::validateIds(array_keys($revisions));
        $this->revisions = SelectionIds::normalizeRevisions($revisions, 'Invalid site selection.');
    }

    /**
     * Normalizes site ids to a sorted list of unique integers.
     *
     * @param array $ids Map from int to int|string.
     * @return array List of int.
     * @throws \InvalidArgumentException When there are not 1 to 100 ids, or an id is malformed or repeated.
     */
    public static function validateIds(array $ids): array
    {
        return SelectionIds::normalize($ids, 10, 4294967295, 'Select between 1 and 100 sites.', 'Invalid site selection.');
    }
}
