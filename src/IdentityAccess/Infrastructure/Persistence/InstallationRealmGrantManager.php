<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Kadupul\IdentityAccess\Contract\RealmGrantManager;
use Kadupul\Platform\Contract\DatabaseConnection;

/**
 * Manages user_auth_realm, user_auth_group_realm, and user_auth.reset_perms on
 * the installation PDO connection so mutations participate atomically in a
 * caller's transaction.
 */
final readonly class InstallationRealmGrantManager implements RealmGrantManager
{
    public function __construct(private DatabaseConnection $database) {}

    #[\Override]
    public function lockActorForGrant(int $actorId): void
    {
        $db = $this->database->get();
        $suffix = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $actor = $db->prepare('SELECT id FROM user_auth WHERE id = ?' . $suffix);
        if (!$actor->execute([$actorId])) {
            throw new \RuntimeException('Actor lock was not confirmed.');
        }
        $actor->fetchColumn();
        $actor->closeCursor();
    }

    #[\Override]
    public function grantRealmAndInvalidateCache(int $userId, int $realmId): void
    {
        if ($userId < 1 || $realmId < 1) {
            throw new \InvalidArgumentException('User id and realm id must be positive.');
        }

        $db = $this->database->get();
        $db->prepare('REPLACE INTO user_auth_realm (user_id, realm_id) VALUES (?, ?)')->execute([$userId, $realmId]);

        $current = $db->prepare('SELECT reset_perms FROM user_auth WHERE id = ?');
        $current->execute([$userId]);
        $previous = $current->fetchColumn();
        if ($previous === false || filter_var($previous, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]) === false) {
            throw new \RuntimeException('Permission generation unavailable.');
        }
        $expected = (int) $previous === 4294967295 ? 1 : (int) $previous + 1;
        $current->closeCursor();

        $epoch = $db->prepare('UPDATE user_auth SET reset_perms = (reset_perms % 4294967295) + 1 WHERE id = ?');
        if (!$epoch->execute([$userId]) || $epoch->rowCount() !== 1) {
            throw new \RuntimeException('Permission invalidation was not confirmed.');
        }

        $current->execute([$userId]);
        if ((int) $current->fetchColumn() !== $expected) {
            throw new \RuntimeException('Permission invalidation readback changed.');
        }
        $current->closeCursor();
    }

    #[\Override]
    public function revokeRealm(int $realmId): void
    {
        if ($realmId < 1) {
            throw new \InvalidArgumentException('Realm id must be positive.');
        }

        $db = $this->database->get();
        $db->prepare('DELETE FROM user_auth_realm WHERE realm_id = ?')->execute([$realmId]);
        $db->prepare('DELETE FROM user_auth_group_realm WHERE realm_id = ?')->execute([$realmId]);
    }
}
