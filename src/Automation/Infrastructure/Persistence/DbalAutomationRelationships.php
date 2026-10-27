<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\RelationshipCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Relationships\RelationshipPage;
use Kadupul\Navigation\Contract\Relationships\RelationshipParentNotFound;
use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.relationship_catalog')]
final readonly class DbalAutomationRelationships implements RelationshipCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(RelationshipScreen $screen): bool
    {
        return in_array($screen, [RelationshipScreen::SnmpEntries, RelationshipScreen::GraphDeviceCriteria, RelationshipScreen::GraphQueryCriteria, RelationshipScreen::TreeDeviceCriteria, RelationshipScreen::TreeSteps], true);
    }

    public function read(RelationshipScreen $screen, ListFilters $filters, int $parentId): RelationshipPage
    {
        $table = match ($screen) {
            RelationshipScreen::SnmpEntries => 'automation_snmp',
            RelationshipScreen::GraphDeviceCriteria, RelationshipScreen::GraphQueryCriteria => 'automation_graph_rules',
            RelationshipScreen::TreeDeviceCriteria, RelationshipScreen::TreeSteps => 'automation_tree_rules',
            default => throw new \InvalidArgumentException('Unsupported automation relationships.'),
        };
        $parent = $this->database->fetchOne("SELECT name FROM $table WHERE id = ?", [$parentId]);
        if ($parent === false) {
            throw new RelationshipParentNotFound();
        }
        $source = match ($screen) {
            // Credential fields and credential identifiers never enter the view model.
            RelationshipScreen::SnmpEntries => 'SELECT id, sequence AS name, snmp_version AS version, snmp_port AS port,
                snmp_timeout AS timeout, snmp_retries AS retries, max_oids, bulk_walk_size AS bulk_walk
                FROM automation_snmp_items WHERE snmp_id = ?',
            RelationshipScreen::GraphDeviceCriteria, RelationshipScreen::TreeDeviceCriteria => 'SELECT id, field AS name, sequence, operation, operator, pattern
                FROM automation_match_rule_items WHERE rule_id = ? AND rule_type = ' . ($screen === RelationshipScreen::GraphDeviceCriteria ? 1 : 3),
            RelationshipScreen::GraphQueryCriteria => 'SELECT id, field AS name, sequence, operation, operator, pattern
                FROM automation_graph_rule_items WHERE rule_id = ?',
            RelationshipScreen::TreeSteps => 'SELECT id, field AS name, sequence, sort_type, propagate_changes AS propagate, search_pattern, replace_pattern
                FROM automation_tree_rule_items WHERE rule_id = ?',
            default => throw new \LogicException('Missing automation relationship query.'),
        };
        if (!array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Invalid relationship sort.');
        }
        $search = in_array($screen, [RelationshipScreen::GraphDeviceCriteria, RelationshipScreen::GraphQueryCriteria, RelationshipScreen::TreeDeviceCriteria], true)
            ? ['name', 'pattern'] : ($screen === RelationshipScreen::TreeSteps ? ['name', 'search_pattern', 'replace_pattern'] : ['name']);
        $where = implode(' OR ', array_map(static fn(string $column): string => 'listing.`' . $column . '` LIKE ?', $search));
        $parameters = [$parentId, ...array_fill(0, count($search), '%' . $filters->filter . '%')];
        $records = $this->database->fetchAllAssociative('SELECT listing.* FROM (' . $source . ') listing WHERE (' . $where . ') ORDER BY listing.`'
            . $filters->sortColumn . '` ' . $filters->sortDirection . ', listing.id ASC LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows), $parameters);
        return new RelationshipPage($parent, $records);
    }
}
