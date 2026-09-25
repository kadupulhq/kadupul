<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Application\Port\AuditCatalog;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\SchemaAudit;
use Kadupul\Platform\Application\Port\TableCatalog;
use Kadupul\Platform\Domain\Schema\AddColumn;
use Kadupul\Platform\Domain\Schema\AlterClause;
use Kadupul\Platform\Domain\Schema\DropIndex;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\ModifyColumn;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;
use Kadupul\Platform\Domain\Schema\RebuildIndex;
use Kadupul\Platform\Domain\Schema\TableAlter;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;

final readonly class DbalSchemaAudit implements SchemaAudit
{
    private const string VERSION = 'SELECT cacti FROM version';
    private const string PLUGIN_TABLE = 'plugin_db_changes';
    private const string PLUGIN_CHANGES = 'SELECT `table`, `column`, method FROM plugin_db_changes WHERE method IN (?, ?)';
    /** The SHOW COLUMNS and SHOW INDEXES fields the audit compares, prints or imports. */
    private const array COLUMN_FIELDS = ['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'];
    private const array INDEX_FIELDS = ['Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Collation', 'Cardinality', 'Sub_part', 'Packed', 'Null', 'Index_type', 'Comment'];

    public function __construct(private MaintenanceConnections $connections, private InstallationVersion $version) {}

    #[\Override]
    public function codeVersion(): string
    {
        return $this->version->file();
    }

    #[\Override]
    public function databaseVersion(DatabaseTarget $target): string
    {
        $version = $this->connections->for($target)->fetchOne(self::VERSION);

        return $version === false || $version === null ? '' : (string) $version;
    }

    #[\Override]
    public function catalog(DatabaseTarget $target): AuditCatalog
    {
        $db = $this->connections->for($target);
        $catalog = $this->connections->tableCatalog($target);
        $tables = [];
        foreach ($catalog->names() as $name) {
            $status = $catalog->status($name);
            if ($status !== null) {
                $tables[] = self::table($db, $name, $status);
            }
        }

        return new AuditCatalog($tables, self::plugins($db, $catalog));
    }

    /** Renders only; it sends nothing, because --alters prints it for tables it must not touch. */
    #[\Override]
    public function statement(DatabaseTarget $target, TableAlter $alter): string
    {
        if (!$alter->buildable() || $alter->charset === null) {
            throw new \LogicException('An unbuildable alter has no statement.');
        }
        $db = $this->connections->for($target);
        $clauses = array_map(static fn(AlterClause $clause): string => self::clause($db, $clause), $alter->clauses);
        $options = ($alter->innodb ? 'ENGINE=InnoDB ' : '') . 'ROW_FORMAT=Dynamic CHARSET=' . $alter->charset->value;

        return 'ALTER TABLE ' . $db->quoteSingleIdentifier($alter->table) . ' ' . implode(', ', $clauses) . ', ' . $options;
    }

    #[\Override]
    public function alter(DatabaseTarget $target, TableAlter $alter, LiveTable $read): bool
    {
        $statement = $this->statement($target, $alter);
        // Defence in depth: the use case decided from $read, but this is the
        // last point before the server. The table is read again with the
        // catalog's own queries, and nothing is sent unless it is listed by
        // exact name, letter case included, reads exactly as it did, and still
        // has every column and index the statement names. The catalog is keyed
        // by the name the server returned, so its lookup is case-sensitive,
        // where a bound TABLE_NAME comparison would not be.
        if ($alter->table !== $read->name) {
            return false;
        }
        $status = $this->connections->tableCatalog($target)->status($alter->table);
        if ($status === null) {
            return false;
        }
        $now = self::table($this->connections->for($target), $alter->table, $status);
        if (!$now->sameShape($read) || !self::namesOnlyWhatIsListed($alter, $now)) {
            return false;
        }

        return $this->connections->execute($target, $statement);
    }

    private static function table(Connection $db, string $name, TableStatus $status): LiveTable
    {
        $quoted = $db->quoteSingleIdentifier($name);

        return new LiveTable(
            $name,
            $status,
            array_map(static fn(array $row): array => self::fields($row, self::COLUMN_FIELDS), $db->fetchAllAssociative('SHOW COLUMNS FROM ' . $quoted)),
            array_map(static fn(array $row): array => self::fields($row, self::INDEX_FIELDS), $db->fetchAllAssociative('SHOW INDEXES FROM ' . $quoted)),
        );
    }

    /**
     * The fields as strings, NULL kept. PDO returns some as integers; the
     * script compared them loosely and printed them, where both read alike.
     *
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return array<string, ?string>
     */
    private static function fields(array $row, array $fields): array
    {
        $kept = [];
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            $kept[$field] = $value === null ? null : (string) $value;
        }

        return $kept;
    }

    private static function plugins(Connection $db, TableCatalog $catalog): PluginSchemaChanges
    {
        // The script's lookups failed and matched nothing when the table was
        // missing. Any other fault is a real one and is not swallowed.
        if (!$catalog->has(self::PLUGIN_TABLE)) {
            return PluginSchemaChanges::none();
        }
        $tables = [];
        $columns = [];
        foreach ($db->fetchAllAssociative(self::PLUGIN_CHANGES, ['create', 'addcolumn']) as $row) {
            if ($row['method'] === 'create') {
                $tables[] = (string) $row['table'];
            } else {
                $columns[] = [(string) $row['table'], (string) $row['column']];
            }
        }

        return new PluginSchemaChanges($tables, $columns);
    }

    /**
     * A MODIFY or DROP naming something the server does not list, by exact
     * name, would act on a different column or index than the one the audit
     * compared, since MariaDB matches these names without letter case.
     * DropIndex names come from the live Key_name, which BaselineName never
     * checked, so this is also what keeps a stray name out of the statement.
     */
    private static function namesOnlyWhatIsListed(TableAlter $alter, LiveTable $now): bool
    {
        $fields = array_column($now->columns, 'Field');
        $keys = array_column($now->indexes, 'Key_name');

        return array_all($alter->clauses, static fn(AlterClause $clause): bool => match (true) {
            $clause instanceof ModifyColumn => in_array($clause->spec->name, $fields, true),
            $clause instanceof DropIndex => in_array($clause->name, $keys, true),
            $clause instanceof RebuildIndex => array_all($clause->drops, static fn(?string $drop): bool => $drop === null || in_array($drop, $keys, true)),
            default => true,
        });
    }

    private static function clause(Connection $db, AlterClause $clause): string
    {
        return match (true) {
            $clause instanceof ModifyColumn => ColumnDdl::modify($db, $clause->spec),
            $clause instanceof AddColumn => ColumnDdl::add($db, $clause->spec, $clause->after),
            $clause instanceof DropIndex => 'DROP INDEX ' . $db->quoteSingleIdentifier($clause->name),
            $clause instanceof RebuildIndex => self::rebuild($db, $clause),
            default => throw new \LogicException('An unbuildable clause has no statement.'),
        };
    }

    private static function rebuild(Connection $db, RebuildIndex $index): string
    {
        $drops = array_map(static fn(?string $drop): string => $drop === null ? 'DROP PRIMARY KEY' : 'DROP INDEX ' . $db->quoteSingleIdentifier($drop), $index->drops);
        $add = match (true) {
            $index->primary => 'ADD PRIMARY KEY',
            $index->unique => 'ADD UNIQUE INDEX ' . $db->quoteSingleIdentifier($index->name),
            default => 'ADD INDEX ' . $db->quoteSingleIdentifier($index->name),
        };
        $columns = implode(', ', array_map($db->quoteSingleIdentifier(...), $index->columns));

        return implode(', ', [...$drops, $add . ' (' . $columns . ') USING ' . $index->using->value]);
    }
}
