<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Kadupul\Navigation\Contract\RelationshipCatalog;
use Kadupul\Navigation\Contract\Relationships\RelationshipPage;
use Kadupul\Navigation\Contract\Relationships\RelationshipParentNotFound;
use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.relationship_catalog')]
final readonly class DbalIdentityRelationships implements RelationshipCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(RelationshipScreen $screen): bool
    {
        return $screen->identityView();
    }

    public function read(RelationshipScreen $screen, ListFilters $filters, int $parentId): RelationshipPage
    {
        if (!$this->supports($screen) || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported identity relationships.');
        }
        $user = $screen->parentScreen() === ListScreen::Users;
        $ownerTable = $user ? 'user_auth' : 'user_auth_group';
        $ownerName = $user ? 'username' : 'name';
        $ownerId = $user ? 'user_id' : 'group_id';
        $permissionTable = $user ? 'user_auth_perms' : 'user_auth_group_perms';
        $parent = $this->database->fetchOne("SELECT $ownerName FROM $ownerTable WHERE id = ?", [$parentId]);
        if ($parent === false) {
            throw new RelationshipParentNotFound();
        }
        $source = match ($screen) {
            RelationshipScreen::UserGroups => 'SELECT m.group_id AS id, g.name, g.enabled, g.description
                FROM user_auth_group_members m LEFT JOIN user_auth_group g ON g.id = m.group_id WHERE m.user_id = ?',
            RelationshipScreen::GroupMembers => 'SELECT m.user_id AS id, u.username AS name, u.realm, u.enabled
                FROM user_auth_group_members m LEFT JOIN user_auth u ON u.id = m.user_id WHERE m.group_id = ?',
            default => $this->permissionSource($screen, $ownerTable, $ownerId, $permissionTable),
        };
        // Sorting identifiers are enum-owned. Values remain bound parameters.
        $records = $this->database->fetchAllAssociative('SELECT listing.* FROM (' . $source . ') listing
            WHERE COALESCE(listing.name, \'\') LIKE ? ORDER BY listing.`' . $filters->sortColumn . '` ' . $filters->sortDirection
            . ', listing.id ASC LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows), [$parentId, '%' . $filters->filter . '%']);
        return new RelationshipPage($parent, $records);
    }

    private function permissionSource(RelationshipScreen $screen, string $ownerTable, string $ownerId, string $permissionTable): string
    {
        // These are the stored legacy resource types, not authentication realms.
        [$type, $policy, $resource, $name] = match ($screen) {
            RelationshipScreen::UserGraphs, RelationshipScreen::GroupGraphs => [1, 'policy_graphs', 'graph_local', "(SELECT MAX(COALESCE(NULLIF(title_cache, ''), title)) FROM graph_templates_graph WHERE local_graph_id = resource.id)"],
            RelationshipScreen::UserTrees, RelationshipScreen::GroupTrees => [2, 'policy_trees', 'graph_tree', 'resource.name'],
            RelationshipScreen::UserDevices, RelationshipScreen::GroupDevices => [3, 'policy_hosts', 'host', 'resource.description'],
            RelationshipScreen::UserTemplates, RelationshipScreen::GroupTemplates => [4, 'policy_graph_templates', 'graph_templates', 'resource.name'],
            default => throw new \InvalidArgumentException('Unsupported permission resource.'),
        };
        // Preserve orphaned stored exceptions. Metadata is optional, and cannot
        // turn an exception into a different resource or multiply its rows.
        return "SELECT p.item_id AS id, $name AS name, CASE WHEN resource.id IS NULL THEN 0 ELSE 1 END AS resolved,
            owner.$policy AS policy FROM $permissionTable p INNER JOIN $ownerTable owner ON owner.id = p.$ownerId
            LEFT JOIN $resource resource ON resource.id = p.item_id WHERE p.$ownerId = ? AND p.type = $type";
    }
}
