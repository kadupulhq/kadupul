<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\RelationshipCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Relationships\RelationshipPage;
use Kadupul\Navigation\Contract\Relationships\RelationshipParentNotFound;
use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.relationship_catalog')]
final readonly class DbalReceiverRelationships implements RelationshipCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(RelationshipScreen $screen): bool
    {
        return in_array($screen, [RelationshipScreen::ReceiverSubscriptions, RelationshipScreen::ReceiverHistory], true);
    }

    public function read(RelationshipScreen $screen, ListFilters $filters, int $parentId): RelationshipPage
    {
        if (!$this->supports($screen) || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported receiver relationships.');
        }
        $parent = $this->database->fetchOne("SELECT COALESCE(NULLIF(description, ''), hostname) FROM snmpagent_managers WHERE id = ?", [$parentId]);
        if ($parent === false) {
            throw new RelationshipParentNotFound();
        }
        // A subscription is identified by receiver + MIB + notification. Do not
        // join cached descriptions by name and multiply unrelated MIB records.
        $source = $screen === RelationshipScreen::ReceiverSubscriptions
            ? 'SELECT notification AS id, notification AS name, mib FROM snmpagent_managers_notifications WHERE manager_id = ?'
            : 'SELECT id, notification AS name, time AS event_time, severity, mib, varbinds FROM snmpagent_notifications_log WHERE manager_id = ?';
        $search = $screen === RelationshipScreen::ReceiverHistory ? ['name', 'mib', 'varbinds'] : ['name', 'mib'];
        $where = implode(' OR ', array_map(static fn(string $column): string => 'listing.' . $column . ' LIKE ?', $search));
        $order = $screen === RelationshipScreen::ReceiverSubscriptions ? ', listing.name ASC, listing.mib ASC' : ', listing.id DESC';
        $records = $this->database->fetchAllAssociative('SELECT listing.* FROM (' . $source . ') listing WHERE (' . $where . ') ORDER BY listing.`'
            . $filters->sortColumn . '` ' . $filters->sortDirection . $order . ' LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows), [$parentId, ...array_fill(0, count($search), '%' . $filters->filter . '%')]);
        return new RelationshipPage($parent, $records);
    }
}
