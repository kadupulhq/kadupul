<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Collection;

use Kadupul\Collection\Application\Port\CollectorStore;
use Kadupul\Collection\Application\Port\PollerCacheStore;
use Kadupul\Collection\Application\UseCase\InvalidatePollerCache;
use Kadupul\Collection\Application\UseCase\ListCollectors;
use Kadupul\Collection\Domain\Collector;
use Kadupul\Collection\Domain\CollectorId;
use Kadupul\Collection\Domain\CollectorStatus;
use PHPUnit\Framework\TestCase;

final class CollectionUseCasesTest extends TestCase
{
    public function testListCollectorsReturnsAllAndEnabledOnly(): void
    {
        $primary = new Collector(CollectorId::primary(), 'Main Poller', 'localhost', CollectorStatus::Idle, false, 1, 4, 7200, '2026-11-26 00:00:00');
        $remote = new Collector(CollectorId::fromInt(2), 'Edge Poller', 'edge.internal', CollectorStatus::Disabled, true, 2, 8, 3600, '2026-11-26 00:00:00');

        $store = new readonly class ([$primary, $remote]) implements CollectorStore {
            /** @param list<Collector> $collectors */
            public function __construct(private array $collectors) {}

            #[\Override]
            public function findAll(): array
            {
                return $this->collectors;
            }

            #[\Override]
            public function findById(CollectorId $id): ?Collector
            {
                return array_find($this->collectors, static fn(Collector $c): bool => $c->id->equals($id));
            }
        };

        $useCase = new ListCollectors($store);
        $this->assertCount(2, $useCase->listAll());
        $this->assertCount(1, $useCase->findEnabled());
        $this->assertSame('Main Poller', $useCase->findById(1)?->name);
        $this->assertNull($useCase->findById(0));
        $this->assertNull($useCase->findById(99));
    }

    public function testInvalidatePollerCacheMarksRemoteCollectorForSync(): void
    {
        $recorded = new class () implements PollerCacheStore {
            /** @var list<string> */
            public array $calls = [];

            #[\Override]
            public function removeDeviceItems(int $deviceId, ?CollectorId $collectorId = null): void
            {
                $this->calls[] = sprintf('device:%d:%s', $deviceId, $collectorId?->toInt() ?? 'all');
            }

            #[\Override]
            public function removeDataSourceItems(int $localDataId, ?CollectorId $collectorId = null): void
            {
                $this->calls[] = sprintf('ds:%d:%s', $localDataId, $collectorId?->toInt() ?? 'all');
            }

            #[\Override]
            public function markCollectorRequiresSync(CollectorId $collectorId): void
            {
                $this->calls[] = sprintf('sync:%d', $collectorId->toInt());
            }
        };

        $useCase = new InvalidatePollerCache($recorded);
        $useCase->invalidateDevice(10, 1);
        $useCase->invalidateDevice(11, 2);
        $useCase->invalidateDataSource(50, 3);
        $useCase->invalidateCollector(4);

        $this->assertSame([
            'device:10:1',
            'device:11:2',
            'sync:2',
            'ds:50:3',
            'sync:3',
            'sync:4',
        ], $recorded->calls);
    }

    public function testInvalidateDeviceRejectsNonPositiveId(): void
    {
        $useCase = new InvalidatePollerCache($this->createMock(PollerCacheStore::class));
        $this->expectException(\InvalidArgumentException::class);
        $useCase->invalidateDevice(0);
    }
}
