<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Report native base-table conversion needs on the existing selected connection. */
final readonly class InstallerTableCatalog
{
    /** Bind the native catalog/read boundary without constructing a new database connection. */
    public function __construct(private NativeInstallerFunctions $native) {}

    /** @return array<string, array{Name: string, Collation: mixed, Engine: mixed, Rows: mixed, Row_format: mixed}>|false */
    public function tables(): array|false
    {
        $tables = $this->native->call('get_cacti_base_tables');
        if ($tables === false) {
            return false;
        }
        if (!is_array($tables)) {
            throw new \RuntimeException('The installation table catalog is unavailable.');
        }
        $result = [];
        foreach ($tables as $table) {
            if (!is_string($table)) {
                throw new \RuntimeException('Invalid installation table identity.');
            }
            // SHOW ... LIKE treats underscores as wildcards. Equality keeps the exact base-table identity.
            $row = $this->native->call('db_fetch_row_prepared', 'SHOW TABLE STATUS WHERE Name = ?', [$table]);
            if (!is_array($row) || $row === []) {
                throw new \RuntimeException('Installation table metadata could not be read.');
            }
            $collation = $row['Collation'] ?? '';
            $engine = $row['Engine'] ?? '';
            $format = $row['Row_format'] ?? '';
            if ($collation !== 'utf8mb4_unicode_ci' || $engine === 'MyISAM' || ($format === 'Compact' && $engine === 'InnoDB')) {
                $result[$table] = ['Name' => $table, 'Collation' => $row['Collation'] ?? null,
                    'Engine' => $row['Engine'] ?? null, 'Rows' => $row['Rows'] ?? 0, 'Row_format' => $row['Row_format'] ?? null];
            }
        }
        return $result;
    }
}
