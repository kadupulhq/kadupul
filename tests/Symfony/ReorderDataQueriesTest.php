<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\MainCollectorOnly;
use Kadupul\Graphing\Application\Command\ReorderDataQueries;
use Kadupul\Graphing\Application\Port\DataQueryIndexItem;
use Kadupul\Graphing\Domain\DataQuerySelection;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\Tests\Fixtures\InMemoryDataQueryIndexes;
use PHPUnit\Framework\TestCase;

final class ReorderDataQueriesTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];

    private function useCase(InMemoryDataQueryIndexes $indexes, bool $manage = true, bool $collector = false): ReorderDataQueries
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(7, 'admin'));
        $operator->method('canManageDevices')->willReturn($manage);
        $topology = $this->createStub(CollectorTopology::class);
        $topology->method('isRemoteCollector')->willReturn($collector);
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new ReorderDataQueries(new GraphCommandAccess($operator, $topology, $trail), $indexes, $indexes);
    }

    /** @return list<DataQueryIndexItem> */
    private static function items(int $count): array
    {
        return array_map(static fn(int $id): DataQueryIndexItem => new DataQueryIndexItem(7, 11, (string) $id, $id, '4'), range(1, $count));
    }

    private static function all(): DataQuerySelection
    {
        return DataQuerySelection::parse('all', 'all');
    }

    public function testItReordersEveryItemAndAuditsTheRun(): void
    {
        $indexes = new InMemoryDataQueryIndexes(self::items(3));
        $report = ($this->useCase($indexes))(self::all(), null, true);

        self::assertSame([3], array_map('count', $indexes->runs));
        self::assertSame([3, true, false, null], [count($report->indexOn), $report->succeeded(), $report->dryRun, $report->stoppedAt()]);
        self::assertCount(1, $this->events);
        self::assertSame(['data-query.reorder', 'local:3', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testADryRunListsTheItemsAndChangesNone(): void
    {
        $indexes = new InMemoryDataQueryIndexes(self::items(2));
        $report = ($this->useCase($indexes))(self::all(), null, false);

        self::assertSame([], $indexes->runs);
        self::assertSame([2, 0, true, true], [count($report->items), count($report->indexOn), $report->dryRun, $report->succeeded()]);
        self::assertSame([], $this->events);
    }

    public function testNothingSelectedIsASuccessWithoutAWorker(): void
    {
        $indexes = new InMemoryDataQueryIndexes([]);
        $report = ($this->useCase($indexes))(self::all(), null, true);

        self::assertSame([], $indexes->runs);
        self::assertTrue($report->succeeded());
        self::assertSame([], $this->events);
    }

    public function testASortFieldThatCannotBeDeterminedStopsTheRunAndNamesTheItem(): void
    {
        $indexes = new InMemoryDataQueryIndexes(self::items(4), 2);
        $report = ($this->useCase($indexes))(self::all(), null, true);

        self::assertSame([2, false], [count($report->indexOn), $report->succeeded()]);
        self::assertSame(3, $report->stoppedAt()?->dataTemplateDataId);
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    public function testAnUnknownOutcomeIsAuditedAsFailedAndRethrown(): void
    {
        $indexes = new InMemoryDataQueryIndexes(self::items(1), null, true);

        try {
            ($this->useCase($indexes))(self::all(), null, true);
            self::fail('the worker failure must reach the caller');
        } catch (\RuntimeException) {
            self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
        }
    }

    public function testAnOperatorWithoutRealm3IsRefusedBeforeAnyRead(): void
    {
        $indexes = new InMemoryDataQueryIndexes(self::items(1));

        try {
            ($this->useCase($indexes, manage: false))(self::all(), null, true);
            self::fail('realm 3 is required');
        } catch (GraphManagementDenied) {
            self::assertSame([], $indexes->selections);
            self::assertSame([[AuditEvent::DENIED, 'data-query.reorder']], array_map(static fn(AuditEvent $e): array => [$e->decision, $e->action], $this->events));
        }
    }

    public function testARemoteCollectorIsRefusedBeforeAnyLookup(): void
    {
        $indexes = new InMemoryDataQueryIndexes(self::items(1));
        $useCase = $this->useCase($indexes, collector: true);

        self::assertTrue($useCase->refusesThisCollector());
        try {
            $useCase(self::all(), null, true);
            self::fail('the script ran on the main collector only');
        } catch (MainCollectorOnly) {
            self::assertSame([[], []], [$indexes->selections, $indexes->runs]);
        }
    }
}
