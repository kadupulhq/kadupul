<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\ItemPermissions;
use Kadupul\IdentityAccess\Application\Port\PermissionCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Domain\ItemPermissionRequest;
use Kadupul\IdentityAccess\Domain\ItemType;
use Kadupul\IdentityAccess\Domain\ListTarget;
use Kadupul\IdentityAccess\Domain\TreeSortType;

/**
 * Lists the users, trees, devices, graphs or graph templates a permission can name, as the --list-* options of add_perms.php did.
 */
final readonly class ListPermissionTargets
{
    private const string ACTION = 'permission.list';

    public function __construct(private UserCommandAccess $access, private ItemPermissions $permissions, private PermissionCatalog $catalog) {}

    /**
     * @param ?string $hostId the device whose graphs --list-graphs shows, as given
     * @param ?string $operator account to act as; null means the admin_user setting
     * @throws UserManagementDenied
     */
    public function __invoke(ListTarget $target, ?string $hostId, bool $local, ?string $operator): PermissionListing
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, true);
        $database = $scope->database;

        return match ($target) {
            ListTarget::Groups => new PermissionListing($target, $database, ListOutcome::NotImplemented),
            ListTarget::Users => new PermissionListing($target, $database, ListOutcome::Listed, array_map(
                static fn(array $user): array => [$user['id'], $user['username'], $user['full_name']],
                $this->catalog->users($database),
            )),
            ListTarget::Trees => new PermissionListing($target, $database, ListOutcome::Listed, array_map(
                static fn(array $tree): array => [$tree['id'], TreeSortType::label($tree['sort_type']), $tree['name']],
                $this->catalog->trees($database),
            )),
            ListTarget::Hosts => new PermissionListing($target, $database, ListOutcome::Listed, array_map(
                static fn(array $host): array => [$host['id'], $host['hostname'], $host['host_template_id'], $host['description']],
                $this->catalog->hosts($database),
            )),
            ListTarget::GraphTemplates => new PermissionListing($target, $database, ListOutcome::Listed, array_map(
                static fn(array $template): array => [$template['id'], $template['name']],
                $this->catalog->graphTemplates($database),
            )),
            ListTarget::Graphs => $this->graphs($target, $database, $hostId),
        };
    }

    /**
     * @param ?string $hostId the device id as given; anything that is not a whole id of an existing device yields HostInvalid
     */
    private function graphs(ListTarget $target, OperatorDatabase $database, ?string $hostId): PermissionListing
    {
        $id = $hostId === null ? null : ItemPermissionRequest::wholeId($hostId);
        if ($id === null || !$this->permissions->itemExists($database, ItemType::Host, $id)) {
            return new PermissionListing($target, $database, ListOutcome::HostInvalid);
        }

        return new PermissionListing($target, $database, ListOutcome::Listed, array_map(
            static fn(array $graph): array => [$graph['id'], $graph['name'], $graph['template_name']],
            $this->catalog->hostGraphs($database, $id),
        ));
    }
}
