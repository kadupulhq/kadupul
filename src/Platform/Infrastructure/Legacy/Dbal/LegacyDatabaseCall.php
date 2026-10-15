<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;

/**
 * The point where a legacy wrapper turns DBAL's exceptions back into the
 * return values its callers test for. Each method mirrors one db_* helper:
 *
 *   cell()    db_fetch_cell_prepared()   value, or false on failure or no row
 *   row()     db_fetch_row_prepared()    row, [] for no row, false on failure
 *   assoc()   db_fetch_assoc_prepared()  rows, [] for none, false on failure
 *   execute() db_execute_prepared()      true, false on failure
 *
 * On failure it sets db_error() and writes the helper's cacti_log lines,
 * with these deliberate differences:
 *
 * - It logs inside a transaction too. The helper returns false there without
 *   a word, which hides the cause of the rollback that usually follows.
 * - With $log = false it still writes the first line at DEBUG verbosity, so
 *   no failure goes unrecorded; the helper writes nothing.
 * - It never retries. The helper repeats one statement after a deadlock or
 *   lock timeout; the operation here may hold several statements, and
 *   repeating the ones that already committed would apply them twice.
 * - It never reconnects after "server has gone away". The helper does that by
 *   loading the installation's database credentials again, which this path
 *   must never touch.
 *
 * With KADUPUL_THROW_DATABASE_ERRORS set it throws the helper's Error, after
 * logging. Exceptions that do not come from DBAL pass through untouched.
 */
final readonly class LegacyDatabaseCall
{
    /** POLLER_VERBOSITY_DEBUG, which the helpers pass for the SQL line. */
    private const int DEBUG = 5;

    /**
     * @param (\Closure(string, bool, string, int|string): mixed)|null $log
     *     test seam with cacti_log()'s signature; null uses cacti_log() itself
     */
    public function __construct(private ?\Closure $log = null) {}

    /** @param \Closure(Connection): mixed $operation */
    public function cell(\Closure $operation, bool $log = true, \PDO|false|null $db_conn = false): mixed
    {
        return $this->run('Cell', $operation, $log, $db_conn);
    }

    /**
     * @param \Closure(Connection): (array<string, mixed>|false) $operation
     *
     * @return array<string, mixed>|false
     */
    public function row(\Closure $operation, bool $log = true, \PDO|false|null $db_conn = false): array|false
    {
        $row = $this->run('Row', $operation, $log, $db_conn, $failed);

        return $failed ? false : ($row === false ? [] : $row);
    }

    /**
     * @param \Closure(Connection): list<array<string, mixed>> $operation
     *
     * @return list<array<string, mixed>>|false
     */
    public function assoc(\Closure $operation, bool $log = true, \PDO|false|null $db_conn = false): array|false
    {
        return $this->run('Row', $operation, $log, $db_conn);
    }

    /**
     * The operation returns its affected-row count, which db_affected_rows()
     * then reports for this connection, as it does after the helper.
     *
     * @param \Closure(Connection): (int|string) $operation
     */
    public function execute(\Closure $operation, bool $log = true, \PDO|false|null $db_conn = false): bool
    {
        global $affected_rows;
        $pdo = LegacyDatabaseConnection::select($db_conn);
        if ($pdo !== null) {
            $affected_rows[spl_object_hash($pdo)] = 0;
        }
        $count = $this->run('Exec', $operation, $log, $pdo ?? $db_conn, $failed);
        if ($failed) {
            return false;
        }
        $affected_rows[spl_object_hash($pdo)] = (int) $count;

        return true;
    }

    private function run(string $name, \Closure $operation, bool $log, \PDO|false|null $db_conn, ?bool &$failed = null): mixed
    {
        global $database_last_error;
        $failed = true;
        $pdo = LegacyDatabaseConnection::select($db_conn);
        if ($pdo === null) {
            $database_last_error = 'DB ' . $name . ' -- No connection found';
            $this->write('ERROR: A DB ' . $name . ' Failed!, Error: No connection found', self::DEBUG);
            self::throwIfStrict();

            return false;
        }
        try {
            $value = $operation(LegacyDatabaseConnection::open($pdo));
        } catch (DbalException $exception) {
            [$number, $message] = self::describe($exception);
            $database_last_error = 'DB ' . $name . ' Failed!, Error ' . $number . ': ' . $message;
            $sql = $exception instanceof DriverException ? (string) $exception->getQuery()?->getSQL() : '';
            $sql = (string) preg_replace('/\s*[\r\n]+\s*/', ' ', $sql);
            if ($number === 1153) {
                // As the helper does, so a huge statement cannot flood the log.
                $sql = strlen($sql) > 1024 ? substr($sql, 0, 1024) . '...' : $sql;
                $this->write('ERROR: A DB ' . $name . ' Too Large!, Error: ' . $number . ', SQL: \'' . $sql . '\'', self::DEBUG);
                if ($log) {
                    $this->write('ERROR: A DB ' . $name . ' Too Large!, Error: ' . $message, self::DEBUG, true);
                }
            } else {
                $this->write('ERROR: A DB ' . $name . ' Failed!, Error: ' . $number . ', SQL: \'' . $sql . '\'', self::DEBUG);
                if ($log) {
                    $this->write('ERROR: A DB ' . $name . ' Failed!, Error: ' . $message, '', true);
                }
            }
            self::throwIfStrict();

            return false;
        }
        $failed = false;

        return $value;
    }

    /**
     * The server's error number and text, which is what the helper logs; the
     * DBAL message adds a prefix and the SQLSTATE.
     *
     * @return array{int|string, string}
     */
    private static function describe(DbalException $exception): array
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && is_array($cause->errorInfo) && isset($cause->errorInfo[1])) {
                return [$cause->errorInfo[1], (string) ($cause->errorInfo[2] ?? '<no error>')];
            }
        }

        return [$exception->getCode(), $exception->getMessage()];
    }

    /**
     * The helper writes a DEBUG-level line with the SQL, then a line with the
     * server's text (unlevelled, or DEBUG for an oversized statement), then a
     * backtrace.
     */
    private function write(string $line, int|string $level, bool $backtrace = false): void
    {
        if ($this->log !== null) {
            ($this->log)($line, false, 'DBCALL', $level);
        } elseif (function_exists('cacti_log')) {
            cacti_log($line, false, 'DBCALL', $level);
        } else {
            // Outside the legacy bootstrap there is no cacti_log(); the PHP
            // error log is still somewhere an operator looks.
            error_log('DBCALL ' . $line);
        }
        if ($backtrace && $this->log === null && function_exists('cacti_debug_backtrace')) {
            cacti_debug_backtrace('SQL', false, true, 0, 1);
        }
    }

    private static function throwIfStrict(): void
    {
        if (defined('KADUPUL_THROW_DATABASE_ERRORS') && KADUPUL_THROW_DATABASE_ERRORS) {
            throw new \Error('Database operation failed.');
        }
    }
}
