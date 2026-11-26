<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Collection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Collection\Application\UseCase\InvalidatePollerCache;
use Kadupul\Collection\Infrastructure\Persistence\DbalPollerCacheInvalidator;
use PHPUnit\Framework\TestCase;

final class DbalPollerCacheInvalidatorTest extends TestCase
{
    private Connection $database;
    private InvalidatePollerCache $invalidator;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement(
            "CREATE TABLE poller (id INTEGER PRIMARY KEY, requires_sync TEXT DEFAULT '')",
        );
        $this->database->executeStatement(
            'CREATE TABLE poller_item (local_data_id INTEGER NOT NULL, poller_id INTEGER NOT NULL, host_id INTEGER NOT NULL, rrd_name TEXT NOT NULL)',
        );
        $this->database->executeStatement("INSERT INTO poller VALUES (1, ''), (2, '')");
        $this->database->executeStatement(
            "INSERT INTO poller_item VALUES
                (100, 1, 10, 'traffic_in'),
                (101, 2, 10, 'traffic_out'),
                (102, 2, 20, 'cpu')",
        );

        $this->invalidator = new InvalidatePollerCache(new DbalPollerCacheInvalidator($this->database));
    }

    public function testInvalidatesScopedDeviceAndFlagsRemotePollerSync(): void
    {
        $this->invalidator->invalidateDevice(10, 2);

        $remaining = $this->database->fetchFirstColumn('SELECT local_data_id FROM poller_item ORDER BY local_data_id');
        $this->assertSame([100, 102], array_map('intval', $remaining));
        $this->assertSame('', $this->database->fetchOne('SELECT requires_sync FROM poller WHERE id = 1'));
        $this->assertSame('on', $this->database->fetchOne('SELECT requires_sync FROM poller WHERE id = 2'));
    }

    public function testInvalidatesDataSourceAcrossAllCollectors(): void
    {
        $this->invalidator->invalidateDataSource(100);

        $remaining = $this->database->fetchFirstColumn('SELECT local_data_id FROM poller_item ORDER BY local_data_id');
        $this->assertSame([101, 102], array_map('intval', $remaining));
    }

    public function testInvalidateCollectorMarksSyncFlag(): void
    {
        $this->invalidator->invalidateCollector(1);
        $this->assertSame('on', $this->database->fetchOne('SELECT requires_sync FROM poller WHERE id = 1'));
    }
}
