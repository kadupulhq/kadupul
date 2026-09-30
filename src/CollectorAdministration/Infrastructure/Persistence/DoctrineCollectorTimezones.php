<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\CollectorAdministration\Application\Port\CollectorTimezones;

final readonly class DoctrineCollectorTimezones implements CollectorTimezones
{
    public function __construct(private Connection $database) {}

    public function search(string $term): array
    {
        $limit = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'autocomplete_rows'");
        $limit = max(1, min(100, (int) $limit));
        $rows = $this->database->fetchAllAssociative(
            'SELECT Name AS label, Name AS `value` FROM mysql.time_zone_name WHERE Name LIKE ? ORDER BY Name LIMIT ' . $limit,
            ['%' . $term . '%'],
        );
        return array_map(static fn(array $row): array => ['label' => (string) $row['label'], 'value' => (string) $row['value']], $rows);
    }
}
