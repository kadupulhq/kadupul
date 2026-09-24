<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Cli;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/**
 * The command line has no session. Shell access with a readable config.php
 * already reaches the database, so this adds attribution and the same account
 * and realm checks as the web path rather than a new boundary.
 *
 * Cacti replicates user_auth* and settings to collectors (lib/poller.php), so
 * the local copy is as valid a source as main. An unreachable main database is
 * never swapped for the local one; the caller picks the database.
 */
final class CliConsoleAccess implements ConsoleAccess, ConsoleOperator
{
    private ?string $username = null;
    private ?Connection $database = null;

    public function __construct(private readonly Connection $localConnection, private readonly Connection $mainConnection) {}

    #[\Override]
    public function select(?string $username, OperatorDatabase $database): void
    {
        $this->username = $username === null || $username === '' ? null : $username;
        $this->database = match ($database) {
            OperatorDatabase::Local => $this->localConnection,
            OperatorDatabase::Main => $this->mainConnection,
        };
    }

    #[\Override]
    public function consoleActor(): ?Actor
    {
        $db = $this->database();
        $authMethod = $db->fetchOne("SELECT value FROM settings WHERE name = 'auth_method'");
        if ($authMethod !== false && !in_array((int) $authMethod, [1, 2, 3, 4], true)) {
            return null;
        }
        $columns = 'SELECT id, username, enabled, locked, must_change_password FROM user_auth';
        if ($this->username === null) {
            // read_config_option() falls back to the declared default, '1', when
            // the row is absent. A stored value that is not a plain positive id,
            // such as '1abc', must not be truncated into one.
            $setting = $db->fetchOne("SELECT value FROM settings WHERE name = 'admin_user'");
            $setting = $setting === false ? '1' : (string) $setting;
            if (!ctype_digit($setting) || (int) $setting <= 0) {
                return null;
            }
            $users = $db->fetchAllAssociative($columns . ' WHERE id = ?', [(int) $setting]);
        } else {
            // Usernames repeat across auth realms and MySQL compares them without
            // case, so any match that is not unique names no one.
            $users = $db->fetchAllAssociative($columns . ' WHERE username = ?', [$this->username]);
        }
        if (count($users) !== 1) {
            return null;
        }
        $user = $users[0];
        if ($user['enabled'] !== 'on' || $user['locked'] === 'on' || $user['must_change_password'] === 'on') {
            return null;
        }
        $id = (int) $user['id'];
        $guest = $db->fetchOne("SELECT value FROM settings WHERE name = 'guest_user'");
        if ($id === (int) $guest || $user['username'] === $guest || !$this->hasRealm($id, 8)) {
            return null;
        }

        return new Actor($id, (string) $user['username']);
    }

    #[\Override]
    public function canManageDevices(Actor $actor): bool
    {
        return $this->hasRealm($actor->id, 3);
    }

    #[\Override]
    public function canAdministerInstallation(Actor $actor): bool
    {
        return $this->hasRealm($actor->id, 15);
    }

    private function hasRealm(int $id, int $realm): bool
    {
        if ($id <= 0) {
            return false;
        }
        $db = $this->database();
        if ($db->fetchOne('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?', [$id, $realm]) !== false) {
            return true;
        }

        return $db->fetchOne("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1", [$id, $realm]) !== false;
    }

    private function database(): Connection
    {
        return $this->database ?? throw new \LogicException('Select the command-line operator before resolving it.');
    }
}
