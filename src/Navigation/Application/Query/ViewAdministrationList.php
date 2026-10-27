<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Application\Query;

use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Navigation\Application\Port\AdministrationListAccess;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;

final readonly class ViewAdministrationList
{
    /** @param iterable<AdministrationCatalog> $catalogs */
    public function __construct(private iterable $catalogs, private AdministrationListAccess $access) {}

    /**
     * @param array<string, mixed> $query
     * @return array{filters: ListFilters, records: list<array<string, int|float|string|null>>, hasNext: bool}
     */
    public function __invoke(ListScreen $screen, array $query): array
    {
        $this->access->authorize($screen);
        $filters = ListFilters::fromQuery($screen, $query);
        foreach ($this->catalogs as $catalog) {
            if ($catalog->supports($screen)) {
                $records = $catalog->read($screen, $filters);
                return ['filters' => $filters, 'records' => array_slice($records, 0, $filters->rows),
                    'hasNext' => count($records) > $filters->rows && $filters->page < 99999];
            }
        }
        throw new \LogicException('Administration list catalog is unavailable.');
    }
}
