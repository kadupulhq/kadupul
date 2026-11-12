<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceLocations;
use Kadupul\Platform\Contract\DatabaseConnection;

/**
 * Looks up device locations in the legacy tables, limited to devices the actor may see.
 */
final readonly class LegacyDeviceLocations implements DeviceLocations
{
    /**
     * Binds the adapter to the database and the visibility rules.
     */
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility) {}
    /**
     * Returns the distinct, non-empty locations that contain the term.
     *
     * @return array List of string.
     */
    public function matching(int $actorId, string $term): array
    {
        $where = $this->visibility->predicate($actorId);
        $query = $this->database->get()->prepare("SELECT DISTINCT h.location FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.deleted = '' AND h.location <> '' AND h.location LIKE ? ESCAPE '!' AND ($where) ORDER BY h.location LIMIT 100");
        $query->execute(['%' . strtr($term, ['!' => '!!','%' => '!%','_' => '!_']) . '%']);
        return $query->fetchAll(\PDO::FETCH_COLUMN);
    }
}
