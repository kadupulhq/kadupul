<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ColorTemplateAccess::class)]
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
        $db = $this->database->get();
        if (!self::accountAllows($db, $actor->id) || !self::hasRealm($db, $actor->id, self::REALM_ID, false)) {
            throw new ColorTemplateAccessDenied(false);
        }
        return $actor;
    }

    public function assertCurrent(int $actorId): void
    {
        $actor = $this->console->consoleActor();
        if ($actor === null || $actor->id !== $actorId) {
            throw new ColorTemplateAccessDenied($actor === null);
        }
        self::assertGranted($this->database->get(), $actorId);
    }

    /**
     * The write-time check without a session, for the synchronization worker,
     * which runs after the Symfony request has matched the session actor.
     */
    public static function assertGranted(\PDO $db, int $actorId): void
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('Write authorization requires an active transaction.');
        }
        if (!self::accountAllows($db, $actorId) || !self::hasRealm($db, $actorId, 8, true) || !self::hasRealm($db, $actorId, self::REALM_ID, true)) {
            throw new ColorTemplateAccessDenied(false);
        }
    }

    private static function accountAllows(\PDO $db, int $actorId): bool
    {
        $suffix = $db->inTransaction() && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = ColorTemplateSql::execute($db, 'SELECT id, username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $suffix, [$actorId]);
        $user = ColorTemplateSql::one($query);
        if (!$user || $user['enabled'] !== 'on' || $user['locked'] === 'on' || ($user['must_change_password'] ?? '') === 'on') {
            return false;
        }
        $auth = ColorTemplateSql::column(ColorTemplateSql::execute($db, "SELECT value FROM settings WHERE name = 'auth_method'" . $suffix));
        if ($auth !== false && !in_array((int) $auth, [1, 2, 3, 4], true)) {
            return false;
        }
        $guest = ColorTemplateSql::column(ColorTemplateSql::execute($db, "SELECT value FROM settings WHERE name = 'guest_user'" . $suffix));
        return $actorId !== (int) $guest && $user['username'] !== $guest;
    }

    private static function hasRealm(\PDO $db, int $actorId, int $realmId, bool $lock): bool
    {
        if ($actorId < 1) {
            return false;
        }
        $suffix = $lock && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = ColorTemplateSql::execute($db, 'SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $suffix, [$actorId, $realmId]);
        if (ColorTemplateSql::column($query) !== false) {
            return true;
        }
        if (!self::groupTablesExist($db)) {
            return false;
        }
        $query = ColorTemplateSql::execute($db, "SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1" . $suffix, [$actorId, $realmId]);
        return ColorTemplateSql::column($query) !== false;
    }

    private static function groupTablesExist(\PDO $db): bool
    {
        foreach (['user_auth_group_realm', 'user_auth_group_members', 'user_auth_group'] as $table) {
            $query = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ColorTemplateSql::execute($db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])
                : ColorTemplateSql::execute($db, 'SHOW TABLES LIKE ' . $db->quote($table));
            if (ColorTemplateSql::column($query) === false) {
                return false;
            }
        }
        return true;
    }
}
