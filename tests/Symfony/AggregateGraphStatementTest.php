<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AggregateGraphStatementTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/api_aggregate.php';
        foreach (['database_sessions', 'database_hostname', 'database_port', 'database_default'] as $name) {
            $this->previous[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
        }
        $GLOBALS['database_hostname'] = 'aggregate-unit';
        $GLOBALS['database_port'] = 0;
        $GLOBALS['database_default'] = 'aggregate-unit';
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => [$present, $value]) {
            if ($present) {
                $GLOBALS[$name] = $value;
            } else {
                unset($GLOBALS[$name]);
            }
        }
    }

    private function select(\PDO $database): void
    {
        $GLOBALS['database_sessions'] = ['aggregate-unit:0:aggregate-unit' => $database];
    }

    public static function nativeIdentifierModes(): iterable
    {
        yield 'case-sensitive native identifiers' => [0, false];
        yield 'lowercase native identifiers' => [1, true];
        yield 'case-preserving native identifiers' => ['2', true];
        yield 'unknown native policy' => [null, false];
        yield 'unsupported native policy' => [3, false];
    }

    #[DataProvider('nativeIdentifierModes')]
    public function testConfiguredIdentifierCasingRequiresConfirmedNativePolicy(mixed $mode, bool $expected): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('errorCode')->willReturn('00000');
        $statement->method('fetchColumn')->willReturn($mode);
        $statement->method('closeCursor')->willReturn(true);
        $database = $this->createMock(\PDO::class);
        $database->expects(self::once())->method('prepare')->with('SELECT @@lower_case_table_names')->willReturn($statement);
        self::assertSame($expected, \aggregate_graph_selected_schema_matches($database, 'AGGREGATE-UNIT'));
    }

    public function testRealSilentPrepareAndExecuteFailuresNeverBecomeSuccessfulWrites(): void
    {
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $database->exec('CREATE TABLE sentinel (id INTEGER PRIMARY KEY)');
        $database->exec("CREATE TRIGGER refuse_insert BEFORE INSERT ON sentinel BEGIN SELECT RAISE(ABORT,'actual SQL refusal'); END");
        $this->select($database);
        foreach (['INSERT INTO missing_table VALUES (1)', 'INSERT INTO sentinel VALUES (1)'] as $sql) {
            try {
                \aggregate_graph_execute($sql);
                self::fail('A real failed write was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('could not be confirmed', $error->getMessage());
            }
        }
        self::assertSame(0, (int) $database->query('SELECT COUNT(*) FROM sentinel')->fetchColumn());
    }

    public function testActualEmptyNullAndScalarReadsKeepTheirDistinctResults(): void
    {
        $database = new \PDO('sqlite::memory:');
        $this->select($database);
        self::assertSame([], \aggregate_graph_fetch_rows('SELECT 1 WHERE 0'));
        self::assertSame([], \aggregate_graph_fetch_row('SELECT 1 WHERE 0'));
        self::assertFalse(\aggregate_graph_fetch_value('SELECT 1 WHERE 0'));
        self::assertNull(\aggregate_graph_fetch_value('SELECT NULL'));
        self::assertSame(7, \aggregate_graph_fetch_value('SELECT 7'));
    }

    public static function unconfirmedStates(): iterable
    {
        yield 'missing state' => [null];
        yield 'late error' => ['HY000'];
    }

    #[DataProvider('unconfirmedStates')]
    public function testPositiveExecuteRequiresConfirmedStatementState(?string $state): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([7])->willReturn(true);
        $statement->method('errorCode')->willReturn($state);
        $statement->expects(self::never())->method('fetchAll');
        $database = $this->createMock(\PDO::class);
        $database->expects(self::once())->method('prepare')->with('SELECT ?')->willReturn($statement);
        $this->select($database);
        $this->expectException(\RuntimeException::class);
        \aggregate_graph_fetch_rows('SELECT ?', [7]);
    }

    public function testFetchFailureSurvivesAnIndependentThrownCloseFailure(): void
    {
        $original = new \RuntimeException('original fetch failure');
        $cleanup = new \RuntimeException('independent close failure');
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('errorCode')->willReturn('00000');
        $statement->expects(self::once())->method('fetchAll')->willThrowException($original);
        $statement->expects(self::once())->method('closeCursor')->willThrowException($cleanup);
        $database = $this->createMock(\PDO::class);
        $database->method('prepare')->willReturn($statement);
        $this->select($database);
        try {
            \aggregate_graph_fetch_rows('SELECT 1');
            self::fail('Unconfirmed fetch/cleanup was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('read close could not be confirmed', $error->getMessage());
            self::assertSame($original, $error->getPrevious());
        }
    }
    public function testRealSilentFailedReadsCannotBecomeAnEmptyMemberList(): void
    {
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $this->select($database);
        $this->expectException(\RuntimeException::class);
        \aggregate_graph_fetch_rows('SELECT * FROM missing_members');
    }

    public function testUnsupportedDriverNeverRunsTheMutationCallback(): void
    {
        $database = new \PDO('sqlite::memory:');
        $this->select($database);
        $called = false;
        self::assertFalse(\aggregate_graph_mutation(static function () use (&$called): bool {
            $called = true;
            return true;
        }));
        self::assertFalse($called);
    }

    public function testPositiveReadWithLateSqlErrorNeverReturnsItsRows(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('errorCode')->willReturnOnConsecutiveCalls('00000', 'HY000', '00000');
        $statement->method('fetchAll')->willReturn([['id' => 7]]);
        $statement->method('closeCursor')->willReturn(true);
        $database = $this->createMock(\PDO::class);
        $database->method('prepare')->willReturn($statement);
        $this->select($database);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read could not be confirmed');
        \aggregate_graph_fetch_rows('SELECT id FROM fixture');
    }

}
