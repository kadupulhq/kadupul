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
            $this->log($target, 'DBCALL', 'ERROR: A DB Exec Failed!, Error: ' . $error->getCode() . ", SQL: '" . $statement . "'");
            $this->log($target, 'DBCALL', 'ERROR: A DB Exec Failed!, Error: ' . ($error->getPrevious() ?? $error)->getMessage());

            return false;
        }
    }

    public function log(DatabaseTarget $target, string $environ, string $message): void
    {
        try {
            $this->operatorLog->record($this->for($target), $environ, $message);
        } catch (Exception) {
            // Best effort, as cacti_log() is: a connection that just failed a
            // statement must not also fail the report of that statement.
        }
    }
}
