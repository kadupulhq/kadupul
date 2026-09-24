<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Infrastructure\Legacy\CollectorIdentity;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;

final readonly class DbalDatabaseMaintenance implements DatabaseMaintenance
{
    public function __construct(
        private Connection $localConnection,
        private Connection $mainConnection,
        private CollectorIdentity $collector,
        private LegacyOperatorLog $log,
    ) {}

    #[\Override]
    public function isRemoteCollector(): bool
    {
        return $this->collector->isRemoteCollector();
    }

    #[\Override]
    public function tables(DatabaseTarget $target): array
    {
        return array_map('strval', $this->connection($target)->fetchFirstColumn('SHOW TABLES'));
    }

    #[\Override]
    public function binlogEnabled(DatabaseTarget $target): bool
    {
        $row = $this->connection($target)->fetchAssociative("SHOW GLOBAL VARIABLES LIKE 'log_bin'");

        return is_array($row) && (strtolower((string) $row['Value']) === 'on' || (string) $row['Value'] === '1');
    }

    #[\Override]
    public function analyze(DatabaseTarget $target, string $table, bool $noBinlog): bool
    {
        // ANALYZE TABLE returns a result set, one row per table, so it must be
        // fetched rather than executed as a statement: an unconsumed result
        // set makes MariaDB refuse the next statement on the same connection.
        // Names come from SHOW TABLES, yet are still quoted as identifiers so
        // an unusual name cannot change the statement.
        $db = $this->connection($target);
        try {
            $rows = $db->executeQuery('ANALYZE TABLE ' . ($noBinlog ? 'NO_WRITE_TO_BINLOG ' : '') . $db->quoteSingleIdentifier($table))->fetchAllAssociative();
        } catch (Exception) {
            return false;
        }

        return !array_any($rows, static fn(array $row): bool => strcasecmp((string) ($row['Msg_type'] ?? ''), 'error') === 0);
    }

    #[\Override]
    public function recordStats(DatabaseTarget $target, string $message): void
    {
        $this->log->record($this->connection($target), 'SYSTEM', $message);
    }

    private function connection(DatabaseTarget $target): Connection
    {
        return match ($target) {
            DatabaseTarget::Main => $this->mainConnection,
            DatabaseTarget::Local => $this->localConnection,
        };
    }
}
