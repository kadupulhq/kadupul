<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Tests\Fixtures\RecoveryQueueFaultPdo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/RecoveryQueueFaultPdo.php';
require_once dirname(__DIR__, 2) . '/lib/database.php';

final class NativeCollectorRecoveryProbeTest extends TestCase
{
    private ?\PDO $database = null;
    private ?string $schema = null;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') $this->markTestSkipped('A disposable database is required.');
        $user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER');
        if ($user === false) $user = getenv('KADUPUL_TEST_MYSQL_USER');
        $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD');
        if ($password === false) $password = getenv('KADUPUL_TEST_MYSQL_PASSWORD');
        $this->database = new \PDO($dsn, $user === false ? 'root' : $user, $password === false ? '' : $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = 'native_recovery_' . bin2hex(random_bytes(8));
        $this->database->exec('CREATE DATABASE `' . $schema . '`');
        $this->schema = $schema;
        $this->database->exec('USE `' . $schema . '`');
        $this->database->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
        $ddl = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($ddl);
        foreach (['poller_output_boost', 'poller_output'] as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \([^;]*;/s', $ddl, $match));
            $this->database->exec($match[0]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->database !== null && $this->schema !== null) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            $this->database->exec('DROP DATABASE `' . $this->schema . '`');
        }
    }

    /** @return iterable<string,array{string,bool}> */
    public static function queueStates(): iterable
    {
        yield 'both empty admit primary' => ['empty', false];
        yield 'Boost pending' => ['boost', true];
        yield 'ordinary pending' => ['output', true];
        yield 'Boost unreadable' => ['missing-boost', true];
        yield 'ordinary unreadable' => ['missing-output', true];
    }

    #[DataProvider('queueStates')]
    public function testActualQueueReadsPreserveRecoveryAndCallerTransaction(string $mode, bool $expected): void
    {
        self::assertNotNull($this->database);
        $table = str_contains($mode, 'boost') ? 'poller_output_boost' : 'poller_output';
        if (str_starts_with($mode, 'missing-')) $this->database->exec('DROP TABLE `' . $table . '`');
        elseif ($mode !== 'empty') $this->database->exec('INSERT INTO `' . $table . '`(local_data_id,rrd_name,time,output) VALUES(1,\'fixture\',\'2026-01-01 00:00:00\',\'1\')');
        $this->database->exec('CREATE TABLE recovery_marker(id INT PRIMARY KEY) ENGINE=InnoDB');
        self::assertTrue($this->database->beginTransaction());
        $this->database->exec('INSERT INTO recovery_marker VALUES(1)');
        self::assertSame($expected, \db_collector_recovery_required($this->database));
        self::assertTrue($this->database->inTransaction());
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM recovery_marker')->fetchColumn());
        self::assertTrue($this->database->rollBack());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM recovery_marker')->fetchColumn());
    }

    /** @return iterable<string,array{string,bool,list<string>}> */
    public static function driverStates(): iterable
    {
        $complete = ['query','state-initial','fetchColumn','state-fetched','closeCursor','state-closed'];
        yield 'admitted actual data' => ['none', false, [...$complete, ...$complete]];
        yield 'query false' => ['query-false', true, ['query']];
        yield 'query exception' => ['query-throw', true, ['query']];
        yield 'initial SQLSTATE failure' => ['pre-error', true, ['query','state-initial','closeCursor']];
        yield 'initial SQLSTATE unknown' => ['pre-null', true, ['query','state-initial','closeCursor']];
        yield 'state exception' => ['state-throw', true, ['query','state-initial']];
        yield 'late fetch false' => ['fetch-false', true, $complete];
        yield 'late fetch exception' => ['fetch-throw', true, ['query','state-initial','fetchColumn']];
        yield 'late SQLSTATE failure' => ['post-error', true, ['query','state-initial','fetchColumn','state-fetched','closeCursor']];
        yield 'close false' => ['close-false', true, ['query','state-initial','fetchColumn','state-fetched','closeCursor']];
        yield 'close exception' => ['close-throw', true, ['query','state-initial','fetchColumn','state-fetched','closeCursor']];
        yield 'closed SQLSTATE failure' => ['close-error', true, $complete];
    }

    /** @param list<string> $events */
    #[DataProvider('driverStates')]
    public function testReadFailuresCannotBecomeEmptyQueueAdmission(string $stage, bool $expected, array $events): void
    {
        self::assertNotNull($this->database);
        $probe = new RecoveryQueueFaultPdo($this->database, $stage);
        self::assertSame($expected, \db_collector_recovery_required($probe));
        self::assertSame($events, $probe->events);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testQueueAvailabilityDoesNotRelaxWorkerWriteFailureOrCommitCallerWork(): void
    {
        self::assertNotNull($this->database);
        require_once dirname(__DIR__, 2) . '/lib/functions.php';
        define('KADUPUL_THROW_DATABASE_ERRORS', true);
        $GLOBALS['database_total_queries'] = 0;
        $GLOBALS['database_log'] = false;
        $GLOBALS['affected_rows'] = [];
        $GLOBALS['config'] = [];
        $this->database->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
        $this->database->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        $this->database->exec('CREATE TABLE recovery_marker(id INT PRIMARY KEY,label VARCHAR(8)) ENGINE=InnoDB');
        $this->database->exec('DROP TABLE poller_output_boost');
        self::assertTrue($this->database->beginTransaction());
        $this->database->exec("INSERT INTO recovery_marker VALUES(1,'original')");
        self::assertTrue(\db_collector_recovery_required($this->database));
        try {
            \db_execute_prepared("UPDATE recovery_marker SET label=REPEAT('x',32) WHERE id=1", [], false, $this->database);
            self::fail('The worker write-error policy was relaxed.');
        } catch (\Error $error) {
            self::assertSame('Database operation failed.', $error->getMessage());
        }
        self::assertTrue($this->database->inTransaction());
        self::assertSame('original', $this->database->query('SELECT label FROM recovery_marker WHERE id=1')->fetchColumn());
        self::assertTrue($this->database->rollBack());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM recovery_marker')->fetchColumn());
    }

    public function testProgrammingFailuresAreNotSwallowedAsDatabaseAvailability(): void
    {
        self::assertNotNull($this->database);
        $this->expectException(\LogicException::class);
        \db_collector_recovery_required(new RecoveryQueueFaultPdo($this->database, 'program-error'));
    }
}
