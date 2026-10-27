<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Application\Query;

use Kadupul\Navigation\Application\Port\RelationshipAccess;
use Kadupul\Navigation\Contract\RelationshipCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;

final readonly class ViewRelationships
{
    /** @param iterable<RelationshipCatalog> $catalogs */
    public function __construct(private iterable $catalogs, private RelationshipAccess $access) {}

    public function __invoke(RelationshipScreen $screen, array $query, string $id): array
    {
        $this->access->authorize($screen);
        $parentId = filter_var($id, FILTER_VALIDATE_INT);
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) || $parentId === false || $parentId < 1 || $parentId > 4294967295) {
            throw new \InvalidArgumentException('Invalid relationship parent.');
        }
        $filters = ListFilters::fromColumns($screen->columns(), $query, $screen->defaultSort(), $screen === RelationshipScreen::ReceiverHistory ? 'DESC' : 'ASC');
        foreach ($this->catalogs as $catalog) {
            if ($catalog->supports($screen)) {
                $page = $catalog->read($screen, $filters, $parentId);
                return ['filters' => $filters->query(), 'parentName' => $page->parentName, 'parentId' => $parentId,
                    'records' => array_slice($page->records, 0, $filters->rows), 'hasNext' => count($page->records) > $filters->rows && $filters->page < 99999];
            }
        }
        throw new \LogicException('Relationship catalog is unavailable.');
    }
}
