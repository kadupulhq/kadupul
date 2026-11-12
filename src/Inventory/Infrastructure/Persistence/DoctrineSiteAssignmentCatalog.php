<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Inventory\Application\Port\SiteAssignmentCatalog;

/**
 * Lists every site as a target for device assignment.
 */
final readonly class DoctrineSiteAssignmentCatalog implements SiteAssignmentCatalog
{
    /**
     * Binds the catalog to the main connection.
     */
    public function __construct(private Connection $database) {}

    /**
     * Returns the site names keyed by id, ordered by name.
     */
    public function sites(): array
    {
        /** @var array<int, string> $sites */
        $sites = $this->database->fetchAllKeyValue(
            'SELECT id, name FROM sites WHERE id > 0 ORDER BY name, id',
        );

        return $sites;
    }
}
