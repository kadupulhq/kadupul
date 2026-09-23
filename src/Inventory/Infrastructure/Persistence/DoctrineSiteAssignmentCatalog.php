<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Inventory\Application\Port\SiteAssignmentCatalog;

final readonly class DoctrineSiteAssignmentCatalog implements SiteAssignmentCatalog
{
    public function __construct(private Connection $database) {}

    public function sites(): array
    {
        /** @var array<int, string> $sites */
        $sites = $this->database->fetchAllKeyValue(
            'SELECT id, name FROM sites WHERE id > 0 ORDER BY name, id',
        );

        return $sites;
    }
}
