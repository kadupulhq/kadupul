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

/**
 * Reads the report list for the admin and personal report management screens.
 */
#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalReportManagementCatalog implements ManagementCatalog
{
    /**
     * Binds the catalog to the web connection.
     */
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    /**
     * Tells whether this catalog serves the screen (the admin and personal report lists).
     */
    public function supports(CatalogScreen $screen): bool
    {
        return in_array($screen, [CatalogScreen::AdminReports, CatalogScreen::MyReports], true);
    }

    /**
     * Returns one page of reports matching the filter, sorted as requested.
     *
     * The personal screen lists only the actor's reports.
     *
     * @throws \InvalidArgumentException when the screen or sort column is unsupported, or the actor ID is below 1
     */
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
            . ' ORDER BY `' . $filters->sortColumn . '` ' . $filters->sortDirection . ', r.id ASC LIMIT ' . $filters->fetchLimit()
            . ' OFFSET ' . $filters->offset(), $params);
        // A top-level list has no parent heading, so the page keeps a null parent name.
        return new CatalogPage(CatalogPage::fromRows($records, null)->records);
    }
}
