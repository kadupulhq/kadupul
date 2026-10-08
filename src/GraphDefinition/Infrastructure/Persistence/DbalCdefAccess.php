<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(CdefAccess::class)]
final readonly class DbalCdefAccess implements CdefAccess
{
    private const int CONSOLE_REALM = 8;
    private const int CDEF_REALM = 14;

    public function __construct(
        private ConsoleAccess $console,
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function authorize(): Actor
    {
        $actor = $this->console->consoleActor();
        if ($actor === null) {
            throw new CdefAccessDenied(true);
        }
        if (!$this->accountAllows($actor->id, '') || !$this->hasRealm($actor->id, self::CDEF_REALM, '')) {
            throw new CdefAccessDenied(false);
        }
        return $actor;
    }

    public function assertCurrent(int $actorId): void
    {
        // Deletion runs inside the reference contract's own PDO transaction,
        // which DBAL does not track, so ask the driver as well.
        $native = $this->database->getNativeConnection();
        if (!$this->database->isTransactionActive() && !($native instanceof \PDO && $native->inTransaction())) {
            throw new \LogicException('Write authorization requires an active transaction.');
        }
        // Shared locks hold the policy rows until commit, so a revocation
        // waits for the write instead of racing it.
        $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' LOCK IN SHARE MODE' : '';
        $actor = $this->console->consoleActor();
        if ($actor === null || $actor->id !== $actorId || !$this->accountAllows($actorId, $lock)
            || !$this->hasRealm($actorId, self::CONSOLE_REALM, $lock) || !$this->hasRealm($actorId, self::CDEF_REALM, $lock)) {
            throw new CdefAccessDenied($actor === null);
        }
    }

    private function accountAllows(int $actorId, string $lock): bool
    {
        $user = $this->database->fetchAssociative('SELECT username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $lock, [$actorId]);
        if ($user === false || $user['enabled'] !== 'on' || $user['locked'] === 'on' || $user['must_change_password'] === 'on') {
            return false;
        }
        $method = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'auth_method'" . $lock);
        if ($method !== false && !in_array((int) $method, [1, 2, 3, 4], true)) {
            return false;
        }
        $guest = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'guest_user'" . $lock);
        return $actorId !== (int) $guest && $user['username'] !== $guest;
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
