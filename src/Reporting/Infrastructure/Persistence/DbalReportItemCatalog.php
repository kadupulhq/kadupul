<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Reporting\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalReportItemCatalog implements ManagementCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(CatalogScreen $screen): bool
    {
        return in_array($screen, [CatalogScreen::ReportItems, CatalogScreen::MyReportItems], true);
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        if (!$this->supports($screen) || $actorId < 1 || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported report contents.');
        }
        // Ownership is part of the lookup; another account's report is missing.
        $name = $this->database->fetchOne('SELECT name FROM reports WHERE id = ?'
            . ($screen->personalReport() ? ' AND user_id = ?' : ''), $screen->personalReport() ? [$parentId, $actorId] : [$parentId]);
        if ($name === false) {
            throw new CatalogParentNotFound();
        }
        // Stored references are shown without resolving protected graph/device names.
        // Text remains literal: it is neither interpreted as HTML nor rendered as a report.
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, item_text AS name, sequence, item_type AS type,
            local_graph_id AS graph_id, host_id AS device_id, tree_id, timespan, align AS alignment
            FROM reports_items WHERE report_id = ? AND item_text LIKE ? ORDER BY `' . $filters->sortColumn . '` '
            . $filters->sortDirection . ', id ASC LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows),
            [$parentId, '%' . $filters->filter . '%']
        );
        return CatalogPage::fromRows($rows, $name);
    }
}
