<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\TableCatalog;
use Kadupul\Platform\Application\Port\TableConversion;
use Kadupul\Platform\Domain\Schema\TableChange;

final readonly class DbalTableConversion implements TableConversion
{
    public function __construct(private CactiSchemaFile $schema, private MaintenanceConnections $connections) {}

    #[\Override]
    public function baseTables(): array
    {
        return $this->schema->baseTables();
    }

    #[\Override]
    public function tableStatuses(DatabaseTarget $target): TableCatalog
    {
        return $this->connections->tableCatalog($target);
    }

    #[\Override]
    public function innodbEnabled(DatabaseTarget $target): bool
    {
        return array_any(
            $this->connections->for($target)->fetchAllAssociative('SHOW ENGINES'),
            static fn(array $engine): bool => strcasecmp((string) $engine['Engine'], 'InnoDB') === 0
                && in_array(strtoupper((string) $engine['Support']), ['YES', 'DEFAULT'], true),
        );
    }

    #[\Override]
    public function filePerTable(DatabaseTarget $target): bool
    {
        $row = $this->connections->for($target)->fetchAssociative("SHOW GLOBAL VARIABLES LIKE 'innodb_file_per_table'");

        return is_array($row) && strtolower((string) $row['Value']) === 'on';
    }

    #[\Override]
    public function statement(DatabaseTarget $target, string $table, TableChange $change): string
    {
        $clauses = $change->clauses();

        return 'ALTER TABLE ' . $this->connections->for($target)->quoteSingleIdentifier($table) . ($clauses === [] ? '' : ' ' . implode(', ', $clauses));
    }

    #[\Override]
    public function convert(DatabaseTarget $target, string $table, TableChange $change): bool
    {
        // Defence in depth: the step already checked the name, but this is
        // the last point before the server, so it checks the catalog again.
        if (!$this->tableStatuses($target)->has($table)) {
            return false;
        }

        return $this->connections->execute($target, $this->statement($target, $table, $change));
    }

    #[\Override]
    public function recordFailure(DatabaseTarget $target, string $message): void
    {
        $this->connections->log($target, 'CONVERT', $message);
    }
}
