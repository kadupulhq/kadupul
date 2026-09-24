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
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;

final readonly class MaintenanceConnections
{
    /** POLLER_VERBOSITY_DEBUG: db_execute_prepared() logs its SQL line at this level (lib/database.php:638). */
    private const int SQL_LINE_LEVEL = 5;

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
     * prefix its exception message adds.
     */
    private function failed(DatabaseTarget $target, string $statement, DriverException $error): void
    {
        $pdo = $error->getPrevious()?->getPrevious();
        $message = $pdo instanceof \PDOException && is_string($pdo->errorInfo[2] ?? null) ? $pdo->errorInfo[2] : ($error->getPrevious() ?? $error)->getMessage();
        $this->log($target, 'DBCALL', 'ERROR: A DB Exec Failed!, Error: ' . $error->getCode() . ", SQL: '" . $statement . "'", self::SQL_LINE_LEVEL);
        $this->log($target, 'DBCALL', 'ERROR: A DB Exec Failed!, Error: ' . $message);
    }
}
