<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Port\RealmAccessChecker;

/**
 * Doctrine DBAL lookup for direct and enabled-group realm grants.
 */
final readonly class DbalRealmAccessChecker implements RealmAccessChecker
{
    public function __construct(
        private Connection $database,
    ) {}

    #[\Override]
    public function hasRealm(int $actorId, int $realmId, bool $includeGroups = true): bool
    {
        if ($actorId < 1 || $realmId < 1) {
            return false;
        }

        if ($this->database->fetchOne(
            'SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?',
            [$actorId, $realmId],
        ) !== false) {
            return true;
        }

        if (!$includeGroups) {
            return false;
        }

        return $this->database->fetchOne(
            "SELECT r.realm_id
             FROM user_auth_group_realm AS r
             INNER JOIN user_auth_group_members AS m ON m.group_id = r.group_id
             INNER JOIN user_auth_group AS g ON g.id = r.group_id
             WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ?
             LIMIT 1",
            [$actorId, $realmId],
        ) !== false;
    }
}
