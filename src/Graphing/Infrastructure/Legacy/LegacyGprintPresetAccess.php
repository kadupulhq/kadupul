<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyGprintPresetAccess implements GprintPresetAccess
{
    private const int REALM_ID = 5;

    public function __construct(private ConsoleAccess $console, private DatabaseConnection $database) {}

    public function authorize(): Actor
    {
        $actor = $this->console->consoleActor();
        if ($actor === null) {
            throw new GprintPresetAccessDenied(true);
        }
        if (!$this->accountAllows($actor->id) || !$this->hasRealm($actor->id, self::REALM_ID, false)) {
            throw new GprintPresetAccessDenied(false);
        }
        return $actor;
    }

    public function assertCurrent(int $actorId): void
    {
        $db = $this->database->get();
        if (!$db->inTransaction()) {
            throw new \LogicException('Write authorization requires an active transaction.');
        }
        $actor = $this->console->consoleActor();
        if ($actor === null || $actor->id !== $actorId || !$this->accountAllows($actorId)
            || !$this->hasRealm($actorId, 8, true) || !$this->hasRealm($actorId, self::REALM_ID, true)) {
            throw new GprintPresetAccessDenied($actor === null);
        }
    }

    private function accountAllows(int $actorId): bool
    {
        $db = $this->database->get();
        $suffix = $db->inTransaction() && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = GprintPresetSql::execute($db, 'SELECT id, username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $suffix, [$actorId]);
        $user = GprintPresetSql::one($query);
        if (!$user || $user['enabled'] !== 'on' || $user['locked'] === 'on' || ($user['must_change_password'] ?? '') === 'on') {
            return false;
        }
        $auth = GprintPresetSql::column(GprintPresetSql::execute($db, "SELECT value FROM settings WHERE name = 'auth_method'" . $suffix));
        if ($auth !== false && !in_array((int) $auth, [1, 2, 3, 4], true)) {
            return false;
        }
        $guest = GprintPresetSql::column(GprintPresetSql::execute($db, "SELECT value FROM settings WHERE name = 'guest_user'" . $suffix));
        return $actorId !== (int) $guest && $user['username'] !== $guest;
    }

    private function hasRealm(int $actorId, int $realmId, bool $lock): bool
    {
        if ($actorId < 1) {
            return false;
        }
        $db = $this->database->get();
        $suffix = $lock && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = GprintPresetSql::execute($db, 'SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $suffix, [$actorId, $realmId]);
        if (GprintPresetSql::column($query) !== false) {
            return true;
        }
        if (!$this->groupTablesExist($db)) {
            return false;
        }
        $query = GprintPresetSql::execute($db, "SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1" . $suffix, [$actorId, $realmId]);
        return GprintPresetSql::column($query) !== false;
    }

    private function groupTablesExist(\PDO $db): bool
    {
        foreach (['user_auth_group_realm', 'user_auth_group_members', 'user_auth_group'] as $table) {
            $query = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? GprintPresetSql::execute($db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])
                : GprintPresetSql::execute($db, "SHOW TABLES LIKE '" . $table . "'");
            if (GprintPresetSql::column($query) === false) {
                return false;
            }
        }
        return true;
    }
}
