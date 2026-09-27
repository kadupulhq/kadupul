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
    public function testWriteMustConfirmExactlyOneMatchingDevice(bool $executed, int $matched, bool $verified, bool $accepted): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([7, 3])->willReturn($executed);
        $statement->expects($executed ? self::once() : self::never())->method('rowCount')->willReturn($matched);
        $written = $executed && $matched === 1;
        $verification = $this->createMock(\PDOStatement::class);
        $verification->expects($written ? self::once() : self::never())->method('execute')->with([7, 3])->willReturn(true);
        $verification->expects($written ? self::once() : self::never())->method('fetchColumn')->willReturn($verified ? 7 : false);
        $db = $this->createMock(\PDO::class);
        $db->expects(self::exactly($written ? 2 : 1))->method('prepare')->willReturnCallback(
            static function (string $sql) use ($statement, $verification): \PDOStatement {
                self::assertStringContainsString("WHERE id = ? AND poller_id = ? AND deleted = ''", $sql);
                self::assertStringContainsString("min_time = '9.99999'", $sql);
                self::assertStringContainsString('total_polls = 0', $sql);
                self::assertStringContainsString('failed_polls = 0', $sql);
                self::assertStringContainsString('availability = 100', $sql);
                self::assertStringNotContainsString('disabled =', $sql);

                return str_starts_with($sql, 'UPDATE') ? $statement : $verification;
            }
        );
        if (!$accepted) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Device statistics reset could not be confirmed.');
        }
        (new DeviceStatisticsReset())->apply($db, new DeviceState(7, 'fixture', 'fixture.invalid', true, 0, 3, 0));
    }

    public static function writeResults(): iterable
    {
        yield 'successful matched and verified row, including already reset counters' => [true, 1, true, true];
        yield 'stored values differ from the requested reset' => [true, 1, false, false];
        yield 'missing, reassigned or deleted device' => [true, 0, false, false];
        yield 'unexpected multiple matches' => [true, 2, false, false];
        yield 'failed write cannot use a stale row count' => [false, 1, false, false];
    }
}
