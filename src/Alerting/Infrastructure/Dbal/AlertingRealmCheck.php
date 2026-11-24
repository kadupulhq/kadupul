<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

/**
 * The account and realm checks DbalRuleAccess and DbalIncidentAccess share,
 * as the other DBAL feature adapters make them: the console actor, an
 * enabled, unlocked account that need not change its password and is not
 * the guest, a supported auth_method, and the realm granted directly or
 * through an enabled group. They run on the alerting stores' connection, so
 * the recheck inside a write transaction reads the same session the write
 * commits on.
 */
final readonly class AlertingRealmCheck
{
    public const int CONSOLE_REALM = 8;

    public function __construct(private ConsoleAccess $console, private Connection $database) {}

    public function authorize(int $realm): Actor
    {
        $actor = $this->console->consoleActor();
        if ($actor === null) {
            throw new AlertingAccessDenied(true);
        }
        if (!$this->accountAllows($actor->id, '') || !$this->hasRealm($actor->id, $realm, '')) {
            throw new AlertingAccessDenied(false);
        }

        return $actor;
    }

    public function assertCurrent(int $actorId, int $realm): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Write authorization requires an active transaction.');
        }
        // Shared locks hold the policy rows until commit, so a revocation
        // waits for the write instead of racing it.
        $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' LOCK IN SHARE MODE' : '';
        $actor = $this->console->consoleActor();
        if ($actor === null || $actor->id !== $actorId || !$this->accountAllows($actorId, $lock)
            || !$this->hasRealm($actorId, self::CONSOLE_REALM, $lock) || !$this->hasRealm($actorId, $realm, $lock)) {
            throw new AlertingAccessDenied($actor === null);
        }
    }

    private function accountAllows(int $actorId, string $lock): bool
    {
        $user = $this->database->fetchAssociative('SELECT username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $lock, [$actorId]);
        if ($user === false || $user['enabled'] !== 'on' || $user['locked'] === 'on' || $user['must_change_password'] === 'on') {
            return false;
        }
        $method = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'auth_method'" . $lock);
        // read_config_option() casts the stored text the same way.
        if ($method !== false && !in_array(is_scalar($method) ? (int) $method : 0, [1, 2, 3, 4], true)) {
            return false;
        }
        $guest = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'guest_user'" . $lock);

        return $actorId !== (is_scalar($guest) ? (int) $guest : 0) && $user['username'] !== $guest;
    }

    private function hasRealm(int $actorId, int $realmId, string $lock): bool
    {
        if ($this->database->fetchOne('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $lock, [$actorId, $realmId]) !== false) {
            return true;
        }

        return $this->database->fetchOne("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1" . $lock, [$actorId, $realmId]) !== false;
    }
}
