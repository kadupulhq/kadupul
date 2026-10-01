<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplatePermissions;
use Kadupul\IdentityAccess\Contract\Actor;

final readonly class DoctrineAggregateTemplateAccess implements AggregateTemplatePermissions
{
    public function __construct(private Connection $database) {}

    public function canManage(Actor $actor): bool
    {
        return $this->database->fetchOne('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = 5', [$actor->id]) !== false
            || $this->database->fetchOne("SELECT r.realm_id FROM user_auth_group_realm r
                INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
                INNER JOIN user_auth_group g ON g.id = r.group_id
                WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = 5 LIMIT 1", [$actor->id]) !== false;
    }
}
