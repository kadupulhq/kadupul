<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\TableCatalog;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;

final readonly class MaintenanceConnections
{
    /** POLLER_VERBOSITY_DEBUG: db_execute_prepared() logs its SQL line at this level (lib/database.php:638). */
    private const int SQL_LINE_LEVEL = 5;
    // Binary order is SHOW TABLES order, which the originals walked; the
    // catalog's own collation would sort "_" after letters.
    private const string TABLES = "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT, TABLE_ROWS FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY CAST(TABLE_NAME AS BINARY)";

    public function __construct(
        private Connection $localConnection,
        private Connection $mainConnection,
        private LegacyOperatorLog $operatorLog,
    ) {}

    public function for(DatabaseTarget $target): Connection
    {
        return match ($target) {
            DatabaseTarget::Main => $this->mainConnection,
            DatabaseTarget::Local => $this->localConnection,
        };
    }

    /**
     * The target's own base tables. DATABASE() is the target connection's
     * schema; the originals used the local database's name even after
     * switching to main.
     */
    public function tableCatalog(DatabaseTarget $target): TableCatalog
    {
        $statuses = [];
        foreach ($this->for($target)->fetchAllAssociative(self::TABLES) as $row) {
            $statuses[(string) $row['TABLE_NAME']] = new TableStatus(
                $row['ENGINE'] === null ? null : (string) $row['ENGINE'],
                $row['TABLE_COLLATION'] === null ? null : (string) $row['TABLE_COLLATION'],
                $row['ROW_FORMAT'] === null ? null : (string) $row['ROW_FORMAT'],
                $row['TABLE_ROWS'] === null ? null : (int) $row['TABLE_ROWS'],
            );
        }

        return new TableCatalog($statuses);
    }

    /**
     * One DDL statement. MySQL commits DDL implicitly, so there is no
     * transaction to roll back: a refusal is logged the way db_execute()
     * logged it, minus the backtrace, and reported as false.
     */
    public function execute(DatabaseTarget $target, string $statement): bool
    {
        try {
            $this->for($target)->executeStatement($statement);

            return true;
        } catch (DriverException $error) {
            $this->failed($target, $statement, $error);

            return false;
        }
    }

    /**
     * The audit baseline's row inserts, in order, in one transaction, so a
     * refusal rolls back every row and leaves no partial baseline. Callers
     * pass DML only; DDL would commit the transaction early.
     *
     * @param non-empty-list<array{0: string, 1: list<int|string|null>}> $statements
     * @return ?int the affected rows, or null when the inserts rolled back
     */
    public function write(DatabaseTarget $target, array $statements): ?int
    {
        // Null outside the statements: a failed BEGIN or COMMIT has no SQL
        // line to log, and must not blame the last statement that ran.
        $current = null;
        try {
            return $this->for($target)->transactional(static function (Connection $db) use ($statements, &$current): int {
                $affected = 0;
                foreach ($statements as [$sql, $params]) {
                    $current = $sql;
                    $affected += (int) $db->executeStatement($sql, $params);
                }
                $current = null;

                return $affected;
            });
        } catch (DriverException $error) {
            $this->failed($target, $current, $error);

            return null;
        }
    }

    public function log(DatabaseTarget $target, string $environ, string $message, ?int $level = null): void
    {
        try {
            $this->operatorLog->record($this->for($target), $environ, $message, $level);
        } catch (Exception) {
            // Best effort, as cacti_log() is: a connection that just failed a
            // statement must not also fail the report of that statement.
        }
    }

    /**
     * db_execute_prepared()'s two lines (lib/database.php:638-639): the
     * server's error number and the statement at debug verbosity, then the
     * server's own message, which PDO's errorInfo holds without the SQLSTATE
     * prefix its exception message adds. A failure outside any statement,
     * such as BEGIN, writes only the message.
     */
    private function failed(DatabaseTarget $target, ?string $statement, DriverException $error): void
    {
        $pdo = $error->getPrevious()?->getPrevious();
        $message = $pdo instanceof \PDOException && is_string($pdo->errorInfo[2] ?? null) ? $pdo->errorInfo[2] : ($error->getPrevious() ?? $error)->getMessage();
        if ($statement !== null) {
            $this->log($target, 'DBCALL', 'ERROR: A DB Exec Failed!, Error: ' . $error->getCode() . ", SQL: '" . $statement . "'", self::SQL_LINE_LEVEL);
        }
        $this->log($target, 'DBCALL', 'ERROR: A DB Exec Failed!, Error: ' . $message);
    }
}
