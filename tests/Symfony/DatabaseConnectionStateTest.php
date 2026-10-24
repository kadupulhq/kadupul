<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What lib/database.php does with the connection globals when code assigns
 * them directly, as test fixtures, the bin/legacy-*.php handoffs and plugins
 * do. Recorded against the helpers before their connection state moved behind
 * LegacyConnectionRegistry; any change here is a contract change.
 * tests/integration/DatabaseConnectionStateDatabaseTest covers the same
 * helpers on MariaDB and MySQL through the real bootstrap.
 */
final class DatabaseConnectionStateTest extends TestCase
{
    private const string OFFSET_ON_NULL = 'Trying to access array offset on null';

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function scenarios(): iterable
    {
        yield 'no connection assigned' => ['unconfigured', [
            'insert_id' => 'false',
            'affected' => 'false',
            'qstr' => "'it\\'s'",
            'begin' => 'false',
            'execute' => 'false',
            'error' => 'DB Exec -- No connection found',
            'cell' => 'false',
            'close' => 'false',
            'initialized' => 'false',
            'default_database' => 'NULL',
            'switch_remote' => 'false',
            // One per helper that indexes the session array without isset():
            // insert_id, affected, qstr, begin, close, initialized.
            'warnings' => array_fill(0, 6, [E_WARNING, self::OFFSET_ON_NULL]),
            'logged' => [['WARNING: db_qstr() called without a valid database connection. Escaping may be unsafe.', 'DBCALL']],
            // Declaring a global creates it as null; callers that test
            // isset() or array_key_exists() on these names see that.
            'globals' => ['database_sessions' => 'null', 'database_details' => 'null', 'database_hostname' => 'null', 'database_port' => 'null', 'database_default' => 'null', 'local_db_cnn_id' => 'null', 'remote_db_cnn_id' => 'null'],
        ]];
        yield 'globals assigned after the library loads' => ['assigned', [
            'create' => 'true',
            'insert' => 'true',
            'affected' => '2',
            'insert_id' => "'2'",
            'cell' => "'two'",
            // SQLite reports no row count for a SELECT.
            'row' => "array (\n)",
            'assoc' => "array (\n  0 => \n  array (\n    'name' => 'one',\n  ),\n  1 => \n  array (\n    'name' => 'two',\n  ),\n)",
            'qstr' => "'it''s'",
            'qstr_null' => 'NULL',
            'begin' => 'true',
            'in_transaction' => true,
            'rollback' => 'true',
            'commit_without_transaction' => 'false',
            'total_queries' => 5,
            'after_replace' => "'replaced'",
            'after_rekey' => "'one'",
            'warnings' => [],
            'logged' => [],
            'globals' => ['database_sessions' => 'array(probe:0:state,probe:0:moved)', 'database_details' => 'null', 'database_hostname' => 'string', 'database_port' => 'int', 'database_default' => 'string'],
        ]];
        yield 'db_close on the default and on a passed handle' => ['close', [
            'close' => 'true',
            'key_kept' => true,
            'session' => 'null',
            'error_logged' => [],
            'close_again' => 'false',
            'after_close' => 'false',
            'error' => 'DB Cell -- No connection found',
            'close_explicit' => 'true',
            'explicit_after' => 'null',
            'warnings' => [],
            'globals' => ['database_sessions' => 'array(probe:0:state)', 'database_details' => 'null', 'database_hostname' => 'string', 'database_port' => 'string', 'database_default' => 'string'],
        ]];
        yield 'switching between the collector and main databases' => ['switch', [
            'main_poller_remote' => 'false',
            'main_poller_force' => 'false',
            'main_poller_local' => 'false',
            'main_poller_side' => 'local',
            'remote' => 'true',
            'remote_side' => 'main',
            'remote_same' => true,
            'local' => 'true',
            'local_side' => 'local',
            'force' => 'true',
            'force_side' => 'main',
            'remote_gone' => 'true',
            'remote_gone_session' => 'bool',
            'warnings' => [],
            'globals' => ['database_sessions' => 'array(collector:3306:cacti)', 'database_details' => 'null', 'database_hostname' => 'string', 'database_port' => 'string', 'database_default' => 'string', 'local_db_cnn_id' => 'PDO', 'remote_db_cnn_id' => 'bool'],
        ]];
        yield 'db_connect_real returns a registered session' => ['cached', [
            'same' => true,
            'total_queries' => 0,
            'details' => false,
            'warnings' => [],
            'globals' => ['database_sessions' => 'array(db.invalid:1:cached)', 'database_details' => 'null', 'database_persist' => 'null'],
        ]];
        yield 'db_connect_real against a refused port' => ['refused', [
            'result' => 'false',
            'attempts' => 2,
            'codes' => [2002, 2002],
            'leaks' => false,
            'under_two_seconds' => true,
            'total_queries' => 0,
            'warnings' => [],
            'globals' => ['database_sessions' => 'null', 'database_details' => 'null', 'database_persist' => 'null'],
        ]];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('scenarios')]
    public function testTheHelpersKeepTheirConnectionContract(string $scenario, array $expected): void
    {
        if ($scenario === 'refused' && !\extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is required to attempt a MySQL connection');
        }
        self::assertSame($expected, $this->runFixture($scenario));
    }

    /** @return array<string, mixed> */
    private function runFixture(string $scenario): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1', \dirname(__DIR__) . '/Fixtures/database-connection-state-sqlite.php', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Connection state fixture did not start');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);

        return json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
    }
}
