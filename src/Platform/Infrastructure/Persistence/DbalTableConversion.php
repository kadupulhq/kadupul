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
use Kadupul\Platform\Domain\Schema\TableStatus;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class DbalTableConversion implements TableConversion
{
    public function __construct(private string $projectDir, private Filesystem $filesystem, private MaintenanceConnections $connections) {}

    #[\Override]
    public function baseTables(): array
    {
        try {
            $schema = $this->filesystem->readFile($this->projectDir . '/cacti.sql');
        } catch (IOException) {
            return [];
        }
        $tables = [];
        foreach (explode("\n", $schema) as $line) {
            if (str_contains($line, 'CREATE TABLE')) {
                $tables[] = trim(str_replace(['CREATE TABLE', '`', '(', ' '], '', $line));
            }
        }

        return $tables;
    }

    #[\Override]
    public function tableStatuses(DatabaseTarget $target): TableCatalog
    {
        // DATABASE() is the target connection's own schema. The original used
        // the local database's name even after switching to main.
        $rows = $this->connections->for($target)->fetchAllAssociative(
            "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT, TABLE_ROWS FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
        );
        $statuses = [];
        foreach ($rows as $row) {
            $statuses[(string) $row['TABLE_NAME']] = new TableStatus(
                $row['ENGINE'] === null ? null : (string) $row['ENGINE'],
                $row['TABLE_COLLATION'] === null ? null : (string) $row['TABLE_COLLATION'],
                $row['ROW_FORMAT'] === null ? null : (string) $row['ROW_FORMAT'],
                $row['TABLE_ROWS'] === null ? null : (int) $row['TABLE_ROWS'],
            );
        }

        return new TableCatalog($statuses);
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
