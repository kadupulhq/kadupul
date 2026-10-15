<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

/**
 * DBAL on the legacy PDO must be the same server session as the db_* helpers,
 * leave the handle as the helpers expect it, and report failure the way the
 * helpers it replaces do. The fixture runs the real lib/database.php.
 */
final class LegacyDbalConnectionDatabaseTest extends TestCase
{
    private const string SILENT = '0';

    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
    }

    public function testDbalSharesTheLegacySessionAndItsTransactions(): void
    {
        $state = $this->runNative('contract');
        self::assertSame($state['connection_id']['legacy'], $state['connection_id']['bridge']);
        self::assertNotSame($state['connection_id']['legacy'], $state['connection_id']['observer']);

        self::assertSame(['bridge_before_commit' => 1, 'observer_before_commit' => 0, 'rollback' => true, 'bridge_after_rollback' => 0, 'observer_after_rollback' => 0], $state['legacy_write']);
        self::assertSame(['legacy_before_rollback' => 1, 'observer_before_rollback' => 0, 'rollback' => true, 'legacy_after_rollback' => 0, 'observer_before_commit' => 0, 'commit' => true, 'observer_after_commit' => 1], $state['dbal_write']);
        foreach (['rollback' => 0, 'commit' => 1] as $end => $kept) {
            self::assertSame(['legacy_sees_transaction' => true, 'write' => true, 'bridge_before_end' => 1, 'observer_before_end' => 0, 'legacy_transaction_after_end' => false, 'legacy_after_end' => $kept, 'observer_after_end' => $kept], $state['reverse'][$end], $end);
        }
        // DBAL refuses to nest inside the legacy transaction instead of
        // committing or discarding the caller's work.
        self::assertSame(['refused' => 'Doctrine\DBAL\Exception\DriverException', 'legacy_transaction' => true, 'legacy_sees_write' => 1], $state['nested']);
    }

    public function testTheLegacyHandleKeepsItsErrorMode(): void
    {
        $modes = $this->runNative('contract')['errmode'];
        foreach (['before', 'after_success', 'after_failure_query', 'after_failure_statement', 'after_failure_exec', 'after_failure_commit', 'after_legacy_failure'] as $point) {
            self::assertSame((int) self::SILENT, $modes[$point], $point);
        }
        self::assertSame('Doctrine\DBAL\Exception\TableNotFoundException', $modes['failure_query']);
        self::assertSame('Doctrine\DBAL\Exception\TableNotFoundException', $modes['failure_statement']);
        self::assertSame('Doctrine\DBAL\Exception\TableNotFoundException', $modes['failure_exec']);
        self::assertSame('Doctrine\DBAL\Exception\NoActiveTransaction', $modes['failure_commit']);
        self::assertSame('false', $modes['legacy_failure_returns']);
        self::assertTrue($modes['legacy_failure_logged']);
        self::assertTrue($modes['warning_restored']);
        self::assertSame(['mode' => (int) self::SILENT, 'legacy_failure' => 'false'], $modes['inside_operation']);
    }

    public function testFailuresTranslateToTheHelperReturnValuesAndLogLines(): void
    {
        $state = $this->runNative('contract');
        $sql = ['cell' => 'SELECT name FROM dbalbr_missing WHERE id = ?', 'row' => 'SELECT name FROM dbalbr_missing WHERE id = ?', 'assoc' => 'SELECT name FROM dbalbr_missing WHERE id = ?', 'execute' => 'UPDATE dbalbr_missing SET name = ? WHERE id = ?'];
        parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), strlen('mysql:'))), $dsn);
        $missing = "Table '" . $dsn['dbname'] . ".dbalbr_missing' doesn't exist";
        $names = ['cell' => 'Cell', 'row' => 'Row', 'assoc' => 'Row', 'execute' => 'Exec'];
        foreach ($state['failure'] as $shape => $modes) {
            $name = $names[$shape];
            $first = ['ERROR: A DB ' . $name . ' Failed!, Error: 1146, SQL: \'' . $sql[$shape] . '\'', false, 'DBCALL', 5];
            $logged = [
                $first,
                ['ERROR: A DB ' . $name . ' Failed!, Error: ' . $missing, false, 'DBCALL', ''],
                ['backtrace', 'SQL', false, true, 0, 1],
            ];
            $error = 'DB ' . $name . ' Failed!, Error 1146: ' . $missing;
            // What the helpers do, measured; any change here is a contract change.
            self::assertSame(['returns' => 'false', 'db_error' => $error, 'logs' => $logged, 'errmode' => 0], $modes['log']['legacy'], $shape);
            self::assertSame(['returns' => 'false', 'db_error' => $error, 'logs' => [], 'errmode' => 0], $modes['quiet']['legacy'], $shape);
            self::assertSame(['returns' => 'false', 'db_error' => $error, 'logs' => [], 'errmode' => 0], $modes['transaction']['legacy'], $shape);
            // The bridge matches them, but never fails without a log line.
            self::assertSame($modes['log']['legacy'], $modes['log']['bridge'], $shape);
            self::assertSame(['returns' => 'false', 'db_error' => $error, 'logs' => [$first], 'errmode' => 0], $modes['quiet']['bridge'], $shape);
            self::assertSame(['returns' => 'false', 'db_error' => $error, 'logs' => $logged, 'errmode' => 0], $modes['transaction']['bridge'], $shape);
        }
        foreach ($state['success'] as $case => $shapes) {
            foreach ($shapes as $shape => [$legacy, $bridge]) {
                self::assertSame($legacy, $bridge, $case . ' ' . $shape);
            }
        }
        self::assertSame(['cell' => 'false', 'row' => "array (\n)", 'assoc' => "array (\n)"], array_map(static fn(array $pair): string => $pair[0], $state['success']['empty']));
        self::assertSame(['legacy_returns' => true, 'legacy' => 2, 'bridge_returns' => true, 'bridge' => 2], $state['affected']);
        self::assertTrue($state['no_connection']['select']);
        self::assertSame(['returns' => 'false', 'db_error' => 'DB Cell -- No connection found', 'logs' => [], 'errmode' => 0], $state['no_connection']['legacy']);
        self::assertSame(['returns' => 'false', 'db_error' => 'DB Cell -- No connection found', 'logs' => [['ERROR: A DB Cell Failed!, Error: No connection found', false, 'DBCALL', 5]], 'errmode' => 0], $state['no_connection']['bridge']);
    }

    public function testStrictWorkersStillStopOnFailure(): void
    {
        $state = $this->runNative('strict');
        self::assertSame(['thrown' => 'Error', 'message' => 'Database operation failed.', 'previous' => true, 'logs' => 0, 'errmode' => 0], $state['legacy']);
        self::assertSame(['thrown' => 'Error', 'message' => 'Database operation failed.', 'previous' => true, 'logs' => 2, 'errmode' => 0], $state['bridge']);
    }

    /**
     * The legacy session is utf8 (utf8mb3), so DBAL on it sees what the
     * helpers see: characters outside the BMP read back as '?' and are
     * stored as '?' without an error under the legacy sql_mode. The
     * utf8mb4 Doctrine connections round-trip them.
     */
    public function testTheBridgeInheritsTheLegacyCharset(): void
    {
        $charset = $this->runNative('contract')['charset'];
        foreach (['two_byte', 'three_byte'] as $case) {
            $read = $charset['read'][$case];
            self::assertSame([$read['stored'], $read['stored'], $read['stored']], [$read['observer'], $read['bridge'], $read['legacy']], $case);
        }
        $four = $charset['read']['four_byte'];
        self::assertSame('f09f9880', $four['observer']);
        self::assertSame('3f', $four['bridge']);
        self::assertSame($four['legacy'], $four['bridge']);
        self::assertSame(['bridge_returns' => true, 'observer' => bin2hex('bridge-????'), 'logs' => 0], $charset['write']);
        self::assertContains($charset['session']['bridge']['connection'], ['utf8', 'utf8mb3']);
        self::assertSame(['client' => 'utf8mb4', 'connection' => 'utf8mb4', 'results' => 'utf8mb4'], $charset['session']['observer']);
    }

    public function testTheBridgeOpensNoSessionAndReadsNoCredentials(): void
    {
        $state = $this->runNative('contract');
        self::assertTrue($state['native_is_legacy']);
        self::assertSame($state['sessions_before'], $state['sessions_held']);
        self::assertSame($state['sessions_before'], $state['sessions_after']);
        self::assertContains($state['legacy_session'], $state['sessions_before']);
        self::assertFalse($state['config_read']);
        self::assertSame(0, $state['final_errmode']);
    }

    /** @return array<string, mixed> */
    private function runNative(string $scenario): array
    {
        static $runs = [];
        if (isset($runs[$scenario])) {
            return $runs[$scenario];
        }
        // Arguments stay out of traces: db_connect_real() takes the password.
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=' . error_reporting(), '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1', dirname(__DIR__) . '/Fixtures/legacy-dbal-connection-native.php', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Legacy DBAL fixture did not start');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);

        return $runs[$scenario] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
