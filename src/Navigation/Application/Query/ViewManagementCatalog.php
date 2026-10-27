<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Application\Query;

use Kadupul\Navigation\Application\Port\ManagementCatalogAccess;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogScreen;

final readonly class ViewManagementCatalog
{
    /** @param iterable<ManagementCatalog> $catalogs */
    public function __construct(private iterable $catalogs, private ManagementCatalogAccess $access) {}

    public function __invoke(CatalogScreen $screen, array $query, string $id): array
    {
        $actor = $this->access->authorize($screen);
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $id) || (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id > $screen->maximumParentId())
            || ($screen->scoped() ? $id === '0' : $id !== '0')) {
            throw new \InvalidArgumentException('Invalid parent identity.');
        }
        $filters = CatalogFilters::fromQuery($screen, $query);
        foreach ($this->catalogs as $catalog) {
            if ($catalog->supports($screen)) {
                $page = $catalog->read($screen, $filters, $actor->id, (int) $id);
                return ['filters' => $filters->query(), 'records' => array_slice($page->records, 0, $filters->rows),
                    'hasNext' => count($page->records) > $filters->rows && $filters->page < 99999, 'parentName' => $page->parentName, 'operationParameters' => $page->operationParameters];
            }
        }
        throw new \LogicException('Management catalog is unavailable.');
    }
}
