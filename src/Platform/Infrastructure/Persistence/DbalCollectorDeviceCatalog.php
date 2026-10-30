<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Contract\ManagementVisibility;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalCollectorDeviceCatalog implements ManagementCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private ManagementVisibility $visibility,
    ) {}

    public function supports(CatalogScreen $screen): bool
    {
        return $screen === CatalogScreen::CollectorDevices;
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        if (!$this->supports($screen) || $actorId < 1 || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported collector device catalog.');
        }
        $name = $this->database->fetchOne('SELECT name FROM poller WHERE id = ?', [$parentId]);
        if ($name === false) {
            throw new CatalogParentNotFound();
        }
        $scope = $this->visibility->forActor($actorId);
        $records = $this->database->fetchAllAssociative(
            'SELECT id, description AS name, hostname, disabled, status
            FROM host WHERE poller_id = ? AND id IN (' . $scope['devices'] . ')
            AND description LIKE ? ORDER BY `' . $filters->sortColumn . '` ' . $filters->sortDirection
            . ', id ASC LIMIT ' . $filters->fetchLimit() . ' OFFSET ' . $filters->offset(),
            [$parentId, '%' . $filters->filter . '%']
        );
        return CatalogPage::fromRows($records, $name);
    }
}
