<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceStatisticsReset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceStatisticsResetTest extends TestCase
{
    #[DataProvider('writeResults')]
    public function testWriteMustConfirmExactlyOneMatchingDevice(bool $executed, int $matched, bool $accepted): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([7, 3])->willReturn($executed);
        $statement->expects($executed ? self::once() : self::never())->method('rowCount')->willReturn($matched);
        $db = $this->createMock(\PDO::class);
        $db->expects(self::once())->method('prepare')->with(self::callback(static function (string $sql): bool {
            return str_contains($sql, "WHERE id = ? AND poller_id = ? AND deleted = ''")
                && str_contains($sql, "min_time = '9.99999'")
                && str_contains($sql, 'total_polls = 0, failed_polls = 0, availability = 100')
                && !str_contains($sql, 'disabled =');
        }))->willReturn($statement);
        if (!$accepted) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Device statistics reset could not be confirmed.');
        }
        (new DeviceStatisticsReset())->apply($db, new DeviceState(7, 'fixture', 'fixture.invalid', true, 0, 3, 0));
    }

    public static function writeResults(): iterable
    {
        yield 'successful matched row, including already reset counters' => [true, 1, true];
        yield 'missing, reassigned or deleted device' => [true, 0, false];
        yield 'unexpected multiple matches' => [true, 2, false];
        yield 'failed write cannot use a stale row count' => [false, 1, false];
    }
}
