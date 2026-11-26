<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Collection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Collection\Application\UseCase\ListCollectors;
use Kadupul\Collection\Domain\CollectorId;
use Kadupul\Collection\Domain\CollectorStatus;
use Kadupul\Collection\Infrastructure\Persistence\DbalCollectorCatalog;
use PHPUnit\Framework\TestCase;

final class DbalCollectorCatalogTest extends TestCase
{
    private Connection $database;
    private DbalCollectorCatalog $catalog;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement(
            "CREATE TABLE poller (
                id INTEGER PRIMARY KEY,
                disabled TEXT DEFAULT '',
                name TEXT,
                status INTEGER NOT NULL DEFAULT 0,
                hostname TEXT NOT NULL DEFAULT '',
                processes INTEGER DEFAULT 1,
                threads INTEGER DEFAULT 1,
                sync_interval INTEGER DEFAULT 7200,
                last_update TEXT NOT NULL DEFAULT '0000-00-00 00:00:00'
            )",
        );
        $this->database->executeStatement(
            "INSERT INTO poller (id, disabled, name, status, hostname, processes, threads, sync_interval, last_update) VALUES
                (1, '', 'Main Poller', 2, 'poller01.example', 2, 8, 7200, '2026-11-26 10:00:00'),
                (2, 'on', 'Standby Poller', 4, 'poller02.example', 1, 4, 3600, '2026-11-25 10:00:00')",
        );

        $this->catalog = new DbalCollectorCatalog($this->database);
    }

    public function testReadsCollectorsOrderedById(): void
    {
        $collectors = $this->catalog->findAll();
        $this->assertCount(2, $collectors);
        $this->assertTrue($collectors[0]->id->isPrimary());
        $this->assertSame('Main Poller', $collectors[0]->name);
        $this->assertSame(CollectorStatus::Idle, $collectors[0]->status);
        $this->assertFalse($collectors[0]->disabled);

        $this->assertSame(2, $collectors[1]->id->toInt());
        $this->assertTrue($collectors[1]->disabled);
        $this->assertSame(CollectorStatus::Disabled, $collectors[1]->status);
    }

    public function testFindByIdAndListUseCase(): void
    {
        $found = $this->catalog->findById(CollectorId::fromInt(2));
        $this->assertNotNull($found);
        $this->assertSame('Standby Poller', $found->name);
        $this->assertNull($this->catalog->findById(CollectorId::fromInt(99)));

        $useCase = new ListCollectors($this->catalog);
        $this->assertCount(1, $useCase->findEnabled());
        $this->assertSame('idle', $useCase->findById(1)?->statusLabel);
    }
}
