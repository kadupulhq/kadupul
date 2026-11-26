<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Platform\Contract\SettingsWriter;

/**
 * DBAL implementation of SettingsWriter targeting Platform's settings table.
 */
final readonly class DbalSettingsWriter implements SettingsWriter
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function write(string $name, string|int $value): void
    {
        if ($name === '' || strlen($name) > 255) {
            throw new \InvalidArgumentException('Setting name must be between 1 and 255 bytes.');
        }

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->connection->executeStatement(
                'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                [$name, (string) $value],
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value',
            [$name, (string) $value],
        );
    }

    #[\Override]
    public function delete(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Setting name must not be empty.');
        }

        $this->connection->executeStatement('DELETE FROM settings WHERE name = ?', [$name]);
    }
}
