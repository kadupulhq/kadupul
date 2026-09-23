<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationRecords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceAssociationReadFailureTest extends TestCase
{
    #[DataProvider('failures')]
    public function testFailedReadsNeverProduceAnEmptySnapshotOrCatalog(string $kind, bool $catalog, string $failure): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn($failure !== 'execute');
        if ($failure === 'fetch') {
            $statement->method('fetchAll')->willThrowException(new \PDOException('Fixture fetch failed'));
        } else {
            $statement->expects(self::never())->method('fetchAll');
        }
        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturn($failure === 'prepare' ? false : $statement);
        $this->expectException(\RuntimeException::class);
        $records = new DeviceAssociationRecords();
        if ($catalog) {
            $records->available($db, $kind);
        } else {
            $records->snapshot($db, ['id' => 7], $kind);
        }
    }

    public static function failures(): iterable
    {
        foreach (['graph', 'query'] as $kind) {
            foreach ([false, true] as $catalog) {
                foreach (['prepare', 'execute', 'fetch'] as $failure) {
                    yield [$kind, $catalog, $failure];
                }
            }
        }
    }
}
