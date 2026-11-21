<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * The account and realm checks the graphing DBAL access adapters share: an
 * enabled, unlocked local account that is not the guest, holding the realm
 * directly or through an enabled group. Inside a write transaction the
 * checks hold shared locks, so a revocation waits for the write instead
 * of racing it.
 */
final readonly class DbalRealmPolicy
{
    private const int CONSOLE_REALM = 8;

    public function __construct(private Connection $database) {}

    /** A read needs the account and the feature realm. */
    public function allowsRead(int $actorId, int $realmId): bool
    {
        return $this->accountAllows($actorId, '') && $this->hasRealm($actorId, $realmId, '');
    }

    /**
     * A write rechecks the account, the console realm and the feature realm
     * inside the caller's transaction, holding shared locks on the policy rows.
     */
    public function allowsWrite(int $actorId, int $realmId): bool
    {
        $this->assertTransaction();
        $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' LOCK IN SHARE MODE' : '';
        return $this->accountAllows($actorId, $lock) && $this->hasRealm($actorId, self::CONSOLE_REALM, $lock) && $this->hasRealm($actorId, $realmId, $lock);
    }

    /** A write check outside the caller's transaction is a programming error, not a denial. */
    public function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Write authorization requires an active transaction.');
        }
    }

    private function accountAllows(int $actorId, string $lock): bool
    {
        $user = $this->database->fetchAssociative('SELECT username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $lock, [$actorId]);
        if ($user === false || $user['enabled'] !== 'on' || $user['locked'] === 'on' || $user['must_change_password'] === 'on') {
            return false;
        }
        $method = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'auth_method'" . $lock);
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
