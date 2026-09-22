<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\SiteAssignmentCatalog;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacySiteAssignmentCatalog implements SiteAssignmentCatalog
{
    public function __construct(private DatabaseConnection $database) {}
    public function sites(): array
    {
        return $this->database->get()->query('SELECT id, name FROM sites WHERE id > 0 ORDER BY name, id')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
