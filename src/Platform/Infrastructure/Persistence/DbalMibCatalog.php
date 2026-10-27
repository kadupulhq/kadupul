<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalMibCatalog implements ManagementCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(CatalogScreen $screen): bool
    {
        return in_array($screen, [CatalogScreen::Mibs, CatalogScreen::MibObjects, CatalogScreen::MibConventions, CatalogScreen::MibAttributes], true);
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        if (!$this->supports($screen) || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported MIB catalog.');
        }
        $parent = null;
        $parameters = [];
        if ($screen !== CatalogScreen::Mibs) {
            $parent = $this->database->fetchOne('SELECT name FROM snmpagent_mibs WHERE id = ?', [$parentId]);
            if ($parent === false) {
                throw new CatalogParentNotFound();
            }
            $parameters[] = $parent;
        }
        // Registered names and cached module names share the MibCache producer.
        // Do not load files or join descriptions by a non-unique object name.
        $source = match ($screen) {
            CatalogScreen::Mibs => 'SELECT id, name FROM snmpagent_mibs',
            CatalogScreen::MibObjects => 'SELECT oid AS id, name, type, otype, kind, `max-access` AS access, description FROM snmpagent_cache WHERE mib = ?',
            CatalogScreen::MibConventions => 'SELECT name AS id, name, type, description FROM snmpagent_cache_textual_conventions WHERE mib = ?',
            CatalogScreen::MibAttributes => 'SELECT name AS id, name, attribute, sequence_id AS sequence FROM snmpagent_cache_notifications WHERE mib = ?',
            default => throw new \LogicException('Missing MIB query.'),
        };
        $tie = match ($screen) {
            CatalogScreen::MibConventions => ', listing.name ASC, listing.type ASC',
            CatalogScreen::MibAttributes => ', listing.name ASC, listing.attribute ASC, listing.sequence ASC',
            default => ', listing.id ASC',
        };
        $parameters[] = '%' . $filters->filter . '%';
        $records = $this->database->fetchAllAssociative('SELECT listing.* FROM (' . $source . ') listing WHERE listing.name LIKE ? ORDER BY listing.`'
            . $filters->sortColumn . '` ' . $filters->sortDirection . $tie . ' LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows), $parameters);
        return new CatalogPage($records, $parent, ['action' => 'view_snmpagent_cache']);
    }
}
