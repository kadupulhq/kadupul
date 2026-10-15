<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Application\Port\UserSettings;

/**
 * Same statements, text and parameter order as lib/functions.php has always
 * sent; see DbalConfigurationSettings.
 */
final readonly class DbalUserSettings implements UserSettings
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function value(int|string $userId, string $name): ?string
    {
        $value = $this->connection->fetchOne('SELECT value FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]);

        return $value === false ? null : $value;
    }

    #[\Override]
    public function exists(int|string $userId, string $name): bool
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]) > 0;
    }

    #[\Override]
    public function write(int|string $userId, string $name, string|int $value): int
    {
        return (int) $this->connection->executeStatement('INSERT INTO settings_user (user_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$userId, $name, $value]);
    }

    #[\Override]
    public function remove(int|string $userId, string $name): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]);
    }
}
