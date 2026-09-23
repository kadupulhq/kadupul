<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceRemovalOwnershipTest extends TestCase
{
    #[DataProvider('owners')]
    public function testRemainingParentsMustMatchCapturedOwner(array $graphs, array $data, bool $accepted): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturn(true);
        $db->expects(self::exactly($graphs !== [] && $graphs[0] !== 7 ? 1 : 2))->method('prepare')->willReturnCallback(function (string $sql) use ($graphs, $data): \PDOStatement {
            self::assertStringEndsWith(' FOR UPDATE', $sql);
            $graph = str_contains($sql, 'graph_local');
            $statement = $this->createMock(\PDOStatement::class);
            $statement->expects(self::once())->method('execute')->with($graph ? [11] : [12])->willReturn(true);
            $statement->method('fetchAll')->willReturn($graph ? $graphs : $data);
            $statement->method('errorCode')->willReturn('00000');
            return $statement;
        });
        self::assertSame($accepted, DeviceRemovalDependencies::ownsRemaining($db, [7 => ['graphs' => [11], 'data_sources' => [12]]]));
    }

    public static function owners(): iterable
    {
        yield 'unchanged parents' => [[7], [7], true];
        yield 'already deleted parents' => [[], [], true];
        yield 'graph reassigned by hook' => [[99], [7], false];
        yield 'data reassigned by hook' => [[7], [99], false];
    }

    public function testRequiresTransaction(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturn(false);
        $this->expectException(\LogicException::class);
        DeviceRemovalDependencies::ownsRemaining($db, []);
    }
}
