<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Kadupul\Platform\Application\Port\ColumnCatalog;
use Kadupul\Platform\Application\Port\ColumnWidening;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Domain\Schema\ColumnDefinition;
use Kadupul\Platform\Domain\Schema\ColumnExtra;
use Kadupul\Platform\Domain\Schema\ColumnSpec;
use Kadupul\Platform\Domain\Schema\ColumnType;

final readonly class DbalColumnWidening implements ColumnWidening
{
    // Binary order is SHOW TABLES order, which the original walked; the
    // catalog's own collation would sort "_" after letters.
    private const string COLUMNS = "SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT, c.EXTRA
        FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'";
    private const string ORDER = ' ORDER BY CAST(c.TABLE_NAME AS BINARY), c.ORDINAL_POSITION';
    private const string WIDE = 'int(10) unsigned';

    public function __construct(private MaintenanceConnections $connections) {}

    #[\Override]
    public function catalog(DatabaseTarget $target): ColumnCatalog
    {
        // DATABASE() is the target connection's own schema; the original read
        // SHOW TABLES under the local database's name even after switching to main.
        return self::catalogOf($this->connections->for($target)->fetchAllAssociative(self::COLUMNS . self::ORDER));
    }

    #[\Override]
    public function statement(DatabaseTarget $target, string $table, array $columns): string
    {
        $db = $this->connections->for($target);
        $type = ColumnType::parse(self::WIDE) ?? throw new \LogicException('Not a column type: ' . self::WIDE);
        $clauses = array_map(static function (ColumnDefinition $column) use ($db, $type): string {
            $change = $column->change();
            // An AUTO_INCREMENT column is always NOT NULL and keeps no default.
            $spec = $change->autoIncrement
                ? new ColumnSpec($change->name, $type, true, null, false, ColumnExtra::AutoIncrement)
                : new ColumnSpec($change->name, $type, !$change->nullable, $change->default, false, ColumnExtra::None);

            return ColumnDdl::modify($db, $spec, true);
        }, $columns);

        return 'ALTER TABLE ' . $db->quoteSingleIdentifier($table) . ' ' . implode(', ', $clauses);
    }

    #[\Override]
    public function widen(DatabaseTarget $target, string $table, array $columns): bool
    {
        // Defence in depth: the use case took these columns from catalog(), but
        // this is the last point before the server, so it reads them again and
        // sends nothing unless each is still exactly as decided on. The bound
        // TABLE_NAME comparison may ignore case; has() and sameAs() do not.
        $listed = self::catalogOf($this->connections->for($target)->fetchAllAssociative(self::COLUMNS . ' AND c.TABLE_NAME = ?' . self::ORDER, [$table]));
        $current = $listed->columns($table);
        $unchanged = static fn(ColumnDefinition $column): bool => array_any($current, static fn(ColumnDefinition $now): bool => $now->sameAs($column));
        if (!$listed->has($table) || !array_all($columns, $unchanged)) {
            return false;
        }

        return $this->connections->execute($target, $this->statement($target, $table, $columns));
    }

    /** @param list<array<string, mixed>> $rows */
    private static function catalogOf(array $rows): ColumnCatalog
    {
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['TABLE_NAME']][] = new ColumnDefinition(
                (string) $row['COLUMN_NAME'],
                (string) $row['COLUMN_TYPE'],
                $row['IS_NULLABLE'] === 'YES',
                // Only integer columns are widened, so a string default
                // that reads "NULL" cannot be mistaken for SQL NULL here.
                ColumnDdl::defaultValue($row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT']),
                (string) $row['EXTRA'],
            );
        }

        return new ColumnCatalog($columns);
    }
}
