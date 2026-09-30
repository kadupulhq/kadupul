<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\GraphDefinition\Application\Port\VdefRealmAccess;

final readonly class DoctrineVdefRealmAccess implements VdefRealmAccess
{
    public function __construct(private Connection $database) {}

    public function canManageDefinitions(int $actorId): bool
    {
        if ($this->database->fetchOne('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = 14', [$actorId]) !== false) {
            return true;
        }

        return $this->database->fetchOne("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE m.user_id = ? AND r.realm_id = 14 AND g.enabled = 'on' LIMIT 1", [$actorId]) !== false;
    }
}
