<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Application\Port\ConfigurationSettings;

/**
 * The statements are the ones lib/functions.php has always sent, text and
 * parameter order included, so query logs and the recorded function goldens
 * read the same whichever path ran them.
 */
final readonly class DbalConfigurationSettings implements ConfigurationSettings
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function value(string $name): ?string
    {
        $value = $this->connection->fetchOne('SELECT value FROM settings WHERE name = ?', [$name]);

        return $value === false ? null : $value;
    }

    #[\Override]
    public function exists(string $name): bool
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settings WHERE name = ?', [$name]) > 0;
    }

    #[\Override]
    public function values(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative('SELECT name, value FROM settings WHERE name IN (' . implode(', ', array_fill(0, count($names), '?')) . ')', $names);
        $values = [];
        foreach ($rows as $row) {
            $values[$row['name']] = $row['value'];
        }

        return $values;
    }

    #[\Override]
    public function write(string $name, string|int $value): int
    {
        return (int) $this->connection->executeStatement('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
    }
}
