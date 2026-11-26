<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Policy;

/**
 * Evaluates graph-tree visibility and tree management ownership rules.
 * Group-level UsersGroups (realm 1) never bypasses ownership; only a direct
 * UsersGroups grant confers global tree management.
 */
final readonly class TreeVisibilityPolicy
{
    /**
     * @param list<PrincipalVisibilityProfile> $profiles User profile plus enabled group profiles
     */
    public function canViewTree(int $treeId, array $profiles): bool
    {
        if ($treeId < 1 || $profiles === []) {
            return false;
        }

        return array_any(
            $profiles,
            static fn(PrincipalVisibilityProfile $profile): bool => $profile->trees->allows($treeId),
        );
    }

    public function canManageTree(
        int $actorId,
        int $ownerId,
        bool $hasDirectUserAdminRealm,
        bool $hasTreesRealm,
    ): bool {
        if ($actorId < 1) {
            return false;
        }

        return $hasDirectUserAdminRealm || ($hasTreesRealm && $actorId === $ownerId);
    }
}
