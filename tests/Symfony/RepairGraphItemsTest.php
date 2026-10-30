<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\RepairGraphItems;
use Kadupul\Graphing\Application\Port\RepairGraph;
use Kadupul\Graphing\Application\Port\RepairRrdTarget;
use Kadupul\Graphing\Application\Port\WrongItem;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\Tests\Fixtures\InMemoryGraphItemRepairs;
use PHPUnit\Framework\TestCase;

final class RepairGraphItemsTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];

    private function useCase(InMemoryGraphItemRepairs $repairs, bool $manage = true): RepairGraphItems
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(7, 'admin'));
        $operator->method('canManageGraphs')->willReturn($manage);
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new RepairGraphItems(new GraphCommandAccess($operator, $this->createStub(CollectorTopology::class), $trail), $repairs);
    }

    private static function repairs(): InMemoryGraphItemRepairs
    {
        return new InMemoryGraphItemRepairs(
            [new RepairGraph(10, 1), new RepairGraph(11, 1), new RepairGraph(20, 2), new RepairGraph(30, 3), new RepairGraph(40, 4)],
            [1 => new RepairRrdTarget(100, 500), 2 => new RepairRrdTarget(200, null), 3 => new RepairRrdTarget(300, 600)],
            [10 => [new WrongItem(7, 90), new WrongItem(8, 91)], 30 => []],
        );
    }

    public function testItRepointsOnlyTheGraphsWithWrongItemsAndAuditsTheRun(): void
    {
        $repairs = self::repairs();
        $report = ($this->useCase($repairs))(5, 6, null, false, null, true);

        self::assertSame(['10>500:7,8'], $repairs->repoints);
        self::assertSame([5, 1, true], [$report->checked, $report->repointed(), $report->executed]);
        self::assertSame('UPDATE graph_templates_item SET task_item_id=500 WHERE task_item_id!=500 and graph_template_id=5 and local_graph_id=10 and id in (7,8)', $report->entries[0]->sql);
        self::assertSame([90, 91], $report->entries[0]->oldTaskItemIds);
        self::assertSame([['graph.item.repair', 'local:5', AuditEvent::SUCCEEDED]], array_map(static fn(AuditEvent $e): array => [$e->action, $e->targetId, $e->outcome], $this->events));
    }

    public function testADataSourceWithoutAnRrdItemIsReportedForEachOfItsGraphs(): void
    {
        $report = ($this->useCase(self::repairs()))(5, 6, 2, false, null, false);

        self::assertSame(1, count($report->entries));
        self::assertTrue($report->entries[0]->isMissingRrd());
        self::assertSame(200, $report->entries[0]->dataSourceId);
    }

    public function testADeviceWithoutADataSourceIsSkippedSilently(): void
    {
        $report = ($this->useCase(self::repairs()))(5, 6, 4, false, null, false);

        self::assertSame([1, []], [$report->checked, $report->entries]);
    }

    public function testADryRunChangesNothingAndIsNotAudited(): void
    {
        $repairs = self::repairs();
        $report = ($this->useCase($repairs))(5, 6, null, false, null, false);

        self::assertSame([], $repairs->repoints);
        self::assertSame([1, false], [$report->repointed(), $report->executed]);
        self::assertSame([], $this->events);
    }

    public function testTheDataSourceIsLookedUpOncePerDevice(): void
    {
        $repairs = self::repairs();
        ($this->useCase($repairs))(5, 6, null, false, null, false);

        self::assertSame(4, $repairs->targetLookups);
    }

    public function testAnOperatorWithoutRealm5IsRefusedBeforeAnyRead(): void
    {
        $repairs = self::repairs();

        try {
            ($this->useCase($repairs, manage: false))(5, 6, null, false, null, true);
            self::fail('realm 5 is required');
        } catch (GraphManagementDenied) {
            self::assertSame(0, $repairs->targetLookups);
            self::assertSame([AuditEvent::DENIED], array_map(static fn(AuditEvent $e): string => $e->decision, $this->events));
        }
    }
}
