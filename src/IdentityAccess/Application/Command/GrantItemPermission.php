<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\ItemPermissions;
use Kadupul\IdentityAccess\Domain\ItemPermissionRequest;

/**
 * Gives one user access to one graph, tree, device or graph template, as add_perms.php did.
 * A dry run runs every check and grants nothing.
 */
final readonly class GrantItemPermission
{
    private const string ACTION = 'permission.grant';

    public function __construct(private UserCommandAccess $access, private ItemPermissions $permissions) {}

    /**
     * Checks run in the order add_perms.php ran them: the user, then the item
     * type, the item id and the item itself, so a request with two faults
     * reports the same one.
     *
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $apply false runs every check and grants nothing
     * @throws UserManagementDenied
     */
    public function __invoke(ItemPermissionRequest $request, bool $local, ?string $operator, bool $apply): GrantReport
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, $apply);
        $report = static fn(GrantOutcome $outcome): GrantReport => new GrantReport($outcome, $scope->database, !$apply);
        if ($request->invalidUser !== null || ($request->userId !== null && !$this->permissions->userExists($scope->database, $request->userId))) {
            return $report(GrantOutcome::UserInvalid);
        }
        if ($request->type === null) {
            return $report(GrantOutcome::ItemTypeMissing);
        }
        if ($request->itemMissing()) {
            return $report(GrantOutcome::ItemIdMissing);
        }
        if ($request->itemId === null || !$this->permissions->itemExists($scope->database, $request->type, $request->itemId)) {
            return $report(GrantOutcome::ItemInvalid);
        }
        if ($request->userId === null) {
            return $report(GrantOutcome::NoUser);
        }
        if (!$apply) {
            return $report(GrantOutcome::Planned);
        }
        $granted = $this->permissions->grant($scope->database, $request->userId, $request->type, $request->itemId);
        $this->access->change($this->access->correlation(), $scope, self::ACTION, 'user-permission', $request->userId . ':' . $request->type->value . ':' . $request->itemId, $granted);

        return new GrantReport($granted ? GrantOutcome::Granted : GrantOutcome::NotGranted, $scope->database, false);
    }
}
