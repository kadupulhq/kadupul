<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Platform\Contract\UserPreferenceStore;

/**
 * DBAL implementation of UserPreferenceStore targeting Platform's settings_user table.
 */
final readonly class DbalUserPreferenceStore implements UserPreferenceStore
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function get(int $userId, string $name): ?string
    {
        self::assertUserId($userId);
        self::assertName($name);

        $value = $this->connection->fetchOne(
            'SELECT value FROM settings_user WHERE user_id = ? AND name = ?',
            [$userId, $name],
        );

        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : null);
    }

    #[\Override]
    public function set(int $userId, string $name, string|int $value): void
    {
        self::assertUserId($userId);
        self::assertName($name);

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->connection->executeStatement(
                'INSERT INTO settings_user (user_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                [$userId, $name, (string) $value],
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO settings_user (user_id, name, value) VALUES (?, ?, ?) ON CONFLICT(user_id, name) DO UPDATE SET value = excluded.value',
            [$userId, $name, (string) $value],
        );
    }

    #[\Override]
    public function remove(int $userId, string $name): void
    {
        self::assertUserId($userId);
        self::assertName($name);

        $this->connection->executeStatement(
            'DELETE FROM settings_user WHERE user_id = ? AND name = ?',
            [$userId, $name],
        );
    }

    private static function assertUserId(int $userId): void
    {
        if ($userId < 0 || $userId > 16777215) {
            throw new \InvalidArgumentException('User id is out of range for settings_user.');
        }
    }

    private static function assertName(string $name): void
    {
        if ($name === '' || strlen($name) > 255) {
            throw new \InvalidArgumentException('User preference name must be between 1 and 255 bytes.');
        }
    }
}
