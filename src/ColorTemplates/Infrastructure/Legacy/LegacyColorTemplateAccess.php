<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Legacy;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyColorTemplateAccess implements ColorTemplateAccess
{
    private const int REALM_ID = 5;

    public function __construct(private ConsoleAccess $console, private DatabaseConnection $database) {}

    public function authorize(): Actor
    {
        $actor = $this->console->consoleActor();
        if ($actor === null) {
            throw new ColorTemplateAccessDenied(true);
        }
        if (!$this->accountAllows($actor->id) || !$this->hasRealm($actor->id, self::REALM_ID, false)) {
            throw new ColorTemplateAccessDenied(false);
        }
        return $actor;
    }

    public function assertCurrent(int $actorId): void
    {
        $db = $this->database->get();
        if (!$db->inTransaction()) {
            throw new \LogicException('Color template write authorization requires an active transaction.');
        }
        $actor = $this->console->consoleActor();
        if ($actor === null || $actor->id !== $actorId || !$this->accountAllows($actorId)
            || !$this->hasRealm($actorId, 8, true) || !$this->hasRealm($actorId, self::REALM_ID, true)) {
            throw new ColorTemplateAccessDenied($actor === null);
        }
    }

    private function accountAllows(int $actorId): bool
    {
        $db = $this->database->get();
        $suffix = $db->inTransaction() && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = $db->prepare('SELECT id, username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $suffix);
        $this->execute($query, [$actorId]);
        $user = $this->fetchOne($query);
        if (!$user || $user['enabled'] !== 'on' || $user['locked'] === 'on' || $user['must_change_password'] === 'on') {
            return false;
        }
        $auth = $this->fetchScalar($db->query("SELECT value FROM settings WHERE name = 'auth_method'" . $suffix));
        if ($auth !== false && !in_array((int) $auth, [1, 2, 3, 4], true)) {
            return false;
        }
        $guest = $this->fetchScalar($db->query("SELECT value FROM settings WHERE name = 'guest_user'" . $suffix));
        return $actorId !== (int) $guest && $user['username'] !== $guest;
    }

    private function hasRealm(int $actorId, int $realmId, bool $lock): bool
    {
        if ($actorId < 1) {
            return false;
        }
        $db = $this->database->get();
        $suffix = $lock && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = $db->prepare('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $suffix);
        $this->execute($query, [$actorId, $realmId]);
        if ($this->fetchScalar($query) !== false) {
            return true;
        }
        if (!$this->groupTablesExist($db)) {
            return false;
        }
        $query = $db->prepare("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1" . $suffix);
        $this->execute($query, [$actorId, $realmId]);
        return $this->fetchScalar($query) !== false;
    }

    private function execute(\PDOStatement|false $statement, array $parameters): void
    {
        if ($statement === false || !$statement->execute($parameters)) {
            throw new \RuntimeException('Color template authorization could not be verified.');
        }
    }

    private function fetchOne(\PDOStatement|false $statement): array|false
    {
        if ($statement === false) {
            throw new \RuntimeException('Color template authorization could not be verified.');
        }
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $this->assertReadConfirmed($statement);
        return $row;
    }

    private function fetchScalar(\PDOStatement|false $statement): mixed
    {
        if ($statement === false) {
            throw new \RuntimeException('Color template authorization could not be verified.');
        }
        $value = $statement->fetchColumn();
        $this->assertReadConfirmed($statement);
        return $value;
    }

    private function assertReadConfirmed(\PDOStatement $statement): void
    {
        if ($statement->errorCode() !== '00000') {
            throw new \RuntimeException('Color template authorization could not be verified.');
        }
    }

    private function groupTablesExist(\PDO $db): bool
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $query = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
            foreach (['user_auth_group_realm', 'user_auth_group_members', 'user_auth_group'] as $table) {
                $this->execute($query, [$table]);
                if ($this->fetchScalar($query) === false) {
                    return false;
                }
            }
            return true;
        }
        try {
            foreach (['user_auth_group_realm', 'user_auth_group_members', 'user_auth_group'] as $table) {
                $query = $db->query("SHOW TABLES LIKE '" . $table . "'");
                if ($this->fetchScalar($query) === false) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
