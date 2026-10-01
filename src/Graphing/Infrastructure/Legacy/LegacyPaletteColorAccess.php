<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Query\PaletteColorAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyPaletteColorAccess implements PaletteColorAccess
{
    private const int REALM_ID = 5;

    public function __construct(private ConsoleAccess $console, private DatabaseConnection $database) {}

    public function authorize(): Actor
    {
        $actor = $this->console->consoleActor();
        if ($actor === null) {
            throw new PaletteColorAccessDenied(true);
        }
        if (!$this->accountAllows($actor->id) || !$this->hasRealm($actor->id, self::REALM_ID, false)) {
            throw new PaletteColorAccessDenied(false);
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
            throw new PaletteColorAccessDenied($actor === null);
        }
    }

    private function accountAllows(int $actorId): bool
    {
        $db = $this->database->get();
        $suffix = $db->inTransaction() && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = PaletteSql::execute($db, 'SELECT id, username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $suffix, [$actorId]);
        $user = PaletteSql::one($query);
        if (!$user || $user['enabled'] !== 'on' || $user['locked'] === 'on' || ($user['must_change_password'] ?? '') === 'on') {
            return false;
        }
        $auth = PaletteSql::column(PaletteSql::execute($db, "SELECT value FROM settings WHERE name = 'auth_method'" . $suffix));
        if ($auth !== false && !in_array((int) $auth, [1, 2, 3, 4], true)) {
            return false;
        }
        $guest = PaletteSql::column(PaletteSql::execute($db, "SELECT value FROM settings WHERE name = 'guest_user'" . $suffix));
        return $actorId !== (int) $guest && $user['username'] !== $guest;
    }

    private function hasRealm(int $actorId, int $realmId, bool $lock): bool
    {
        if ($actorId < 1) {
            return false;
        }
        $db = $this->database->get();
        $suffix = $lock && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = PaletteSql::execute($db, 'SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $suffix, [$actorId, $realmId]);
        if (PaletteSql::column($query) !== false) {
            return true;
        }
        if (!$this->groupTablesExist($db)) {
            return false;
        }
        $query = PaletteSql::execute($db, "SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1" . $suffix, [$actorId, $realmId]);
        return PaletteSql::column($query) !== false;
    }

    private function groupTablesExist(\PDO $db): bool
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach (['user_auth_group_realm', 'user_auth_group_members', 'user_auth_group'] as $table) {
                $query = PaletteSql::execute($db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
                if (PaletteSql::column($query) === false) {
                    return false;
                }
            }
            return true;
        }
        try {
            $query = PaletteSql::execute($db, "SHOW TABLES LIKE 'user_auth_group_realm'");
            if (PaletteSql::column($query) === false) {
                return false;
            }
            $query = PaletteSql::execute($db, "SHOW TABLES LIKE 'user_auth_group_members'");
            if (PaletteSql::column($query) === false) {
                return false;
            }
            $query = PaletteSql::execute($db, "SHOW TABLES LIKE 'user_auth_group'");
            return PaletteSql::column($query) !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
