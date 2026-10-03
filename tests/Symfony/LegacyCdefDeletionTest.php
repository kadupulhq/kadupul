<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefDeletion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyCdefDeletionTest extends TestCase
{
    public static function unconfirmedStates(): iterable
    {
        yield 'unestablished' => [null];
        yield 'late error' => ['HY000'];
    }

    #[DataProvider('unconfirmedStates')]
    public function testTrueBeginRequiresConfirmedSqlStateBeforeAnyQuery(?string $state): void
    {
        $database = $this->createMock(\PDO::class);
        $database->method('inTransaction')->willReturn(false, true);
        $database->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $database->expects(self::once())->method('beginTransaction')->willReturn(true);
        $database->expects(self::never())->method('prepare');
        $database->expects(self::once())->method('rollBack')->willReturn(true);
        $database->method('errorCode')->willReturn($state, '00000');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transaction could not be started');
        (new LegacyCdefDeletion($database, 1))->delete([1]);
    }

    #[DataProvider('unconfirmedStates')]
    public function testTrueRollbackRequiresConfirmedSqlStateBeforeReportingCleanup(?string $state): void
    {
        $database = $this->createMock(\PDO::class);
        $database->method('inTransaction')->willReturn(false, true);
        $database->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $database->expects(self::once())->method('beginTransaction')->willReturn(true);
        $database->expects(self::once())->method('prepare')->willReturn(false);
        $database->expects(self::once())->method('rollBack')->willReturn(true);
        $database->method('errorCode')->willReturn('00000', $state);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reload before retrying');
        (new LegacyCdefDeletion($database, 1))->delete([1]);
    }

    public function testRollbackFailurePreservesTheOriginalOperationFailure(): void
    {
        $original = new \RuntimeException('original SQL operation failure');
        $cleanup = new \RuntimeException('independent rollback failure');
        $database = $this->createMock(\PDO::class);
        $database->method('inTransaction')->willReturn(false, true);
        $database->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $database->expects(self::once())->method('beginTransaction')->willReturn(true);
        $database->method('errorCode')->willReturn('00000');
        $database->expects(self::once())->method('prepare')->willThrowException($original);
        $database->expects(self::once())->method('rollBack')->willThrowException($cleanup);
        try {
            (new LegacyCdefDeletion($database, 1))->delete([1]);
            self::fail('Unconfirmed rollback was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Reload before retrying', $error->getMessage());
            self::assertSame($original, $error->getPrevious());
        }
    }

    private function database(): \PDO
    {
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $database->exec('CREATE TABLE cdef (id INTEGER PRIMARY KEY, system INTEGER DEFAULT 0)');
        $database->exec('CREATE TABLE cdef_items (id INTEGER PRIMARY KEY, cdef_id INTEGER, type INTEGER, value TEXT)');
        foreach (['graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            $database->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, cdef_id INTEGER)");
        }
        $database->exec('INSERT INTO cdef (id) VALUES (1),(2),(3)');
        $database->exec("INSERT INTO cdef_items VALUES (1,1,5,'2'),(2,2,1,'1'),(3,3,1,'1')");

        return $database;
    }

    private function snapshot(\PDO $database): array
    {
        $snapshot = [];
        foreach (['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            $snapshot[$table] = $database->query("SELECT * FROM $table ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $snapshot;
    }

    public function testDeletesOnlyExactSelectedParentsAndOwnedChildrenAtomically(): void
    {
        $database = $this->database();
        (new LegacyCdefDeletion($database, 1))->delete(['2', 1]);
        self::assertSame([3], array_column($this->snapshot($database)['cdef'], 'id'));
        self::assertSame([3], array_column($this->snapshot($database)['cdef_items'], 'id'));
        self::assertFalse($database->inTransaction());
    }

    public function testIncomingOwnerTargetAndEveryCacheFamilyPreservesAllRows(): void
    {
        foreach (['cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            $database = $this->database();
            $database->exec($table === 'cdef_items' ? "INSERT INTO cdef_items VALUES (4,3,5,'1')" : "INSERT INTO $table VALUES (4,1)");
            $before = $this->snapshot($database);
            try {
                (new LegacyCdefDeletion($database, 1))->delete([1, 2]);
                self::fail('Incoming reference did not refuse deletion.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('still referenced', $error->getMessage());
            }
            self::assertSame($before, $this->snapshot($database));
            self::assertFalse($database->inTransaction());
        }
    }

    public function testRealSilentSqlFailureAfterChildMutationRollsBackEverything(): void
    {
        $database = $this->database();
        $database->exec("CREATE TRIGGER fail_parent BEFORE DELETE ON cdef BEGIN SELECT RAISE(ABORT,'native parent refusal'); END");
        $before = $this->snapshot($database);
        try {
            (new LegacyCdefDeletion($database, 1))->delete([1, 2]);
            self::fail('Native failed DELETE was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('SQL could not be confirmed', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($database));
        self::assertFalse($database->inTransaction());
    }

    public function testProtectedAndUnestablishedSystemFlagsRefuseBeforeChildMutation(): void
    {
        foreach ([1, null, 'malformed'] as $system) {
            $database = $this->database();
            $statement = $database->prepare('UPDATE cdef SET system=? WHERE id=1');
            $statement->execute([$system]);
            $before = $this->snapshot($database);
            try {
                (new LegacyCdefDeletion($database, 1))->delete([1, 2]);
                self::fail('Protected or unestablished eligibility was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('not eligible', $error->getMessage());
            }
            self::assertSame($before, $this->snapshot($database));
            self::assertFalse($database->inTransaction());
        }
    }

    public function testStaleMissingParentIsRejectedBeforeAnyMutation(): void
    {
        $database = $this->database();
        $before = $this->snapshot($database);
        try {
            (new LegacyCdefDeletion($database, 1))->delete([1, 9]);
            self::fail('Missing selected parent was ignored.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('changed', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($database));
    }

    public function testCallerTransactionAndCallerWritesArePreserved(): void
    {
        $database = $this->database();
        $database->beginTransaction();
        $database->exec('INSERT INTO cdef (id) VALUES (9)');
        $before = $this->snapshot($database);
        try {
            (new LegacyCdefDeletion($database, 1))->delete([1, 2]);
            self::fail('Caller transaction was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('caller transaction', $error->getMessage());
        }
        self::assertTrue($database->inTransaction());
        self::assertSame($before, $this->snapshot($database));
        $database->commit();
        self::assertSame($before, $this->snapshot($database));
    }

    public function testInvalidOrAmbiguousSelectionsNeverWrite(): void
    {
        foreach ([[], [1, 1], ['01'], [true], [1.0], ['1e0'], ['1junk'], [0], [16777216], [7 => 1]] as $selection) {
            $database = $this->database();
            $before = $this->snapshot($database);
            try {
                (new LegacyCdefDeletion($database, 1))->delete($selection);
                self::fail('Invalid selection was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid CDEF selection.', $error->getMessage());
            }
            self::assertSame($before, $this->snapshot($database));
            self::assertFalse($database->inTransaction());
        }
    }
}
