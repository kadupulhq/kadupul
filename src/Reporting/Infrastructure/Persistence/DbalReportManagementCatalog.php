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
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalReportManagementCatalog implements ManagementCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(CatalogScreen $screen): bool
    {
        return in_array($screen, [CatalogScreen::AdminReports, CatalogScreen::MyReports], true);
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        if (!$this->supports($screen) || !array_key_exists($filters->sortColumn, $screen->columns()) || $actorId < 1) {
            throw new \InvalidArgumentException('Unsupported report catalog.');
        }
        $where = $screen === CatalogScreen::MyReports ? ' AND r.user_id = ?' : '';
        $params = ['%' . $filters->filter . '%'];
        if ($screen === CatalogScreen::MyReports) {
            $params[] = $actorId;
        }
        // Recipient/BCC lists, message bodies and format paths never leave this reader.
        // An orphan owner is presented as effectively disabled without writing on GET.
        $records = $this->database->fetchAllAssociative("SELECT r.id, r.name,
            COALESCE(NULLIF(u.full_name, ''), u.username) AS owner,
            CASE WHEN u.id IS NULL THEN '' ELSE r.enabled END AS enabled,
            r.intrvl AS `interval`, r.count AS `count`, r.offset AS `offset`, r.lastsent, r.mailtime, r.attachment_type
            FROM reports r LEFT JOIN user_auth u ON u.id = r.user_id WHERE r.name LIKE ?" . $where
            . ' ORDER BY `' . $filters->sortColumn . '` ' . $filters->sortDirection . ', r.id ASC LIMIT ' . ($filters->rows + 1)
            . ' OFFSET ' . (($filters->page - 1) * $filters->rows), $params);
        return new CatalogPage($records);
    }
}
