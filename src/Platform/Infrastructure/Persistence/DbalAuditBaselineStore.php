<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Kadupul\Platform\Application\Port\AuditBaselineStore;
use Kadupul\Platform\Application\Port\AuditCatalog;
use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\AuditSchemaDump;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/** Reads the canonical audit schema and renders current database metadata without database-side staging tables. */
final readonly class DbalAuditBaselineStore implements AuditBaselineStore
{
    public const string FILE = '/docs/audit_schema.sql';
    public const string DUMP_COLUMNS = "CREATE TABLE `table_columns` (
  `table_name` varchar(50) NOT NULL,
  `table_sequence` int(10) unsigned NOT NULL,
  `table_field` varchar(50) NOT NULL,
  `table_type` varchar(50) DEFAULT NULL,
  `table_null` varchar(10) DEFAULT NULL,
  `table_key` varchar(4) DEFAULT NULL,
  `table_default` varchar(50) DEFAULT NULL,
  `table_extra` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`table_name`,`table_sequence`,`table_field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Holds Default Cacti Table Definitions'";
    public const string DUMP_INDEXES = "CREATE TABLE `table_indexes` (
  `idx_table_name` varchar(50) NOT NULL,
  `idx_non_unique` int(10) unsigned DEFAULT NULL,
  `idx_key_name` varchar(128) NOT NULL,
  `idx_seq_in_index` int(10) unsigned NOT NULL,
  `idx_column_name` varchar(50) NOT NULL,
  `idx_collation` varchar(10) DEFAULT NULL,
  `idx_cardinality` int(10) unsigned DEFAULT NULL,
  `idx_sub_part` varchar(50) DEFAULT NULL,
  `idx_packed` varchar(128) DEFAULT NULL,
  `idx_null` varchar(10) DEFAULT NULL,
  `idx_index_type` varchar(20) DEFAULT NULL,
  `idx_comment` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`idx_table_name`,`idx_key_name`,`idx_seq_in_index`,`idx_column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Holds Default Cacti Index Definitions'";

    public function __construct(private string $projectDir, private Filesystem $filesystem) {}

    public function read(): ?AuditBaseline
    {
        try {
            return AuditSchemaDump::parse($this->filesystem->readFile($this->projectDir . self::FILE));
        } catch (IOException) {
            return null;
        }
    }

    public function export(AuditCatalog $catalog): string
    {
        $columns = [];
        $indexes = [];
        foreach ($catalog->tables() as $table) {
            foreach (array_values($table->columns) as $sequence => $column) {
                $columns[] = [$table->name, $sequence + 1, $column['Field'], $column['Type'], $column['Null'], $column['Key'], $column['Default'], $column['Extra']];
            }
            foreach ($table->indexes as $index) {
                $indexes[] = [$index['Table'], (int) $index['Non_unique'], $index['Key_name'], (int) $index['Seq_in_index'], $index['Column_name'], $index['Collation'],
                    $index['Cardinality'] === null ? null : (int) $index['Cardinality'], $index['Sub_part'], $index['Packed'], $index['Null'], $index['Index_type'], $index['Comment']];
            }
        }
        $sql = self::DUMP_COLUMNS . ";\n\n" . self::DUMP_INDEXES . ";\n";
        foreach ($columns as $row) {
            $sql .= 'INSERT INTO `table_columns` VALUES (' . self::tuple($row) . ");\n";
        }
        foreach ($indexes as $row) {
            $sql .= 'INSERT INTO `table_indexes` VALUES (' . self::tuple($row) . ");\n";
        }

        return $sql;
    }

    /** @param list<int|string|null> $values */
    private static function tuple(array $values): string
    {
        return implode(',', array_map(static fn(int|string|null $value): string => match (true) {
            $value === null => 'NULL',
            is_int($value) => (string) $value,
            default => "'" . strtr($value, ["\\" => "\\\\", "'" => "\\'", "\0" => "\\0", "\x08" => "\\b", "\t" => "\\t", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"]) . "'",
        }, $values));
    }
}
