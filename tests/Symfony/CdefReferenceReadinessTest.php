<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CdefReferenceReadinessTest extends TestCase
{
    #[DataProvider('handoffs')]
    public function testBoundedNativeCallHandoff(array $fetches, array $rowsets, array $states, bool $close, bool $expected, bool $throws): void
    {
        $call = $this->createMock(\PDOStatement::class);
        $call->expects(self::never())->method('fetchAll');
        $call->method('fetch')->willReturnOnConsecutiveCalls(...$fetches);
        $call->method('nextRowset')->willReturnOnConsecutiveCalls(...$rowsets);
        $call->method('errorCode')->willReturnOnConsecutiveCalls(...$states);
        $call->expects(self::once())->method('closeCursor')->willReturn($close);
        $database = $this->createMock(\PDO::class);
        $database->method('getAttribute')->willReturn('mysql');
        $database->method('errorCode')->willReturn('00000');
        $database->method('query')->willReturnCallback(function (string $sql) use ($call): \PDOStatement {
            if (str_starts_with($sql, 'CALL ')) {
                return $call;
            }
            $rows = match (true) {
                str_starts_with($sql, 'SHOW CREATE') => [['Create Table' => 'CREATE TABLE fixture (id MEDIUMINT UNSIGNED) ENGINE=InnoDB']],
                str_starts_with($sql, 'SHOW COLUMNS') => [
                    ['Field' => 'id', 'Type' => 'mediumint unsigned', 'Extra' => ''],
                    ['Field' => 'cdef_id', 'Type' => 'mediumint unsigned', 'Extra' => ''],
                ],
                str_contains($sql, 'information_schema.ROUTINES') => [['ROUTINE_TYPE' => 'PROCEDURE', 'SECURITY_TYPE' => 'DEFINER', 'SQL_DATA_ACCESS' => 'READS SQL DATA', 'DEFINER' => 'installer@localhost']],
                default => [['count' => 0]],
            };
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('fetchAll')->willReturn($rows);
            $statement->method('errorCode')->willReturn('00000');

            return $statement;
        });
        if ($throws) {
            $this->expectException(\RuntimeException::class);
        }
        self::assertSame($expected, (new CdefReferenceReadiness($database, 1))->ready());
    }

    public static function handoffs(): iterable
    {
        $row = ['contract_version' => 1, 'ready' => 1];
        yield 'native terminal retains old columns' => [[$row, false, false], [true, false], array_fill(0, 5, '00000'), true, true, false];
        yield 'no first row' => [[false, false, false], [true, false], array_fill(0, 5, '00000'), true, false, false];
        yield 'extra first row' => [[$row, $row, false], [true, false], array_fill(0, 5, '00000'), true, false, false];
        yield 'nonempty extra result' => [[$row, false, $row], [true], array_fill(0, 4, '00000'), true, false, false];
        yield 'multiple empty extras' => [[$row, false, false], [true, true], array_fill(0, 5, '00000'), true, false, false];
        yield 'missing terminal' => [[$row, false], [false], array_fill(0, 3, '00000'), true, false, false];
        yield 'late first fetch error' => [[$row, false], [], ['HY000', '00000'], true, false, true];
        yield 'next rowset error' => [[$row, false], [false], ['00000', 'HY000', '00000'], true, false, true];
        yield 'terminal fetch error' => [[$row, false, false], [true], ['00000', '00000', 'HY000', '00000'], true, false, true];
        yield 'terminal next error' => [[$row, false, false], [true, false], ['00000', '00000', '00000', 'HY000', '00000'], true, false, true];
        yield 'close false' => [[$row, false, false], [true, false], array_fill(0, 4, '00000'), false, false, true];
        yield 'close late error' => [[$row, false, false], [true, false], ['00000', '00000', '00000', '00000', 'HY000'], true, false, true];
        yield 'wrong typed version' => [[['contract_version' => true, 'ready' => 1], false, false], [true, false], array_fill(0, 5, '00000'), true, false, false];
        yield 'extra field' => [[['contract_version' => 1, 'ready' => 1, 'extra' => 1], false, false], [true, false], array_fill(0, 5, '00000'), true, false, false];
    }
}
