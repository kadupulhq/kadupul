<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalNetworkDeviceCatalog implements ManagementCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function supports(CatalogScreen $screen): bool
    {
        return $screen === CatalogScreen::NetworkDevices;
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        if (!$this->supports($screen) || $actorId < 1 || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported network device catalog.');
        }
        $name = $this->database->fetchOne('SELECT name FROM automation_networks WHERE id = ?', [$parentId]);
        if ($name === false) {
            throw new CatalogParentNotFound();
        }
        $records = $this->database->fetchAllAssociative(
            'SELECT id, REPLACE(sysName, \':\', \' \') AS name, hostname, ip, REPLACE(os, \':\', \' \') AS os, known, up
            FROM automation_devices WHERE network_id = ? AND sysName LIKE ? ORDER BY `' . $filters->sortColumn . '` '
            . $filters->sortDirection . ', id ASC LIMIT ' . $filters->fetchLimit() . ' OFFSET ' . $filters->offset(),
            [$parentId, '%' . $filters->filter . '%']
        );
        return CatalogPage::fromRows($records, $name);
    }
}
