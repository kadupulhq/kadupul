<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\RemoveBrokenGraphs;
use Kadupul\Graphing\Application\Port\BrokenGraphGroup;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\Tests\Fixtures\InMemoryBrokenGraphs;
use PHPUnit\Framework\TestCase;

final class RemoveBrokenGraphsTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];

    private function useCase(InMemoryBrokenGraphs $graphs, bool $manage = true): RemoveBrokenGraphs
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(7, 'admin'));
        $operator->method('canManageGraphs')->willReturn($manage);
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new RemoveBrokenGraphs(new GraphCommandAccess($operator, $this->createStub(CollectorTopology::class), $trail), $graphs, $graphs);
    }

    /** @return list<BrokenGraphGroup> */
    private static function broken(int $second = 2): array
    {
        return [
            new BrokenGraphGroup(1, 'Traffic', [new GraphTitle(10, 'a'), new GraphTitle(11, 'b')]),
            new BrokenGraphGroup(2, 'Load', array_map(static fn(int $id): GraphTitle => new GraphTitle(100 + $id, 'g' . $id), range(1, $second))),
        ];
    }

    public function testItOnlyReportsByDefaultAndWritesNoAudit(): void
    {
        $graphs = new InMemoryBrokenGraphs(self::broken());
        $report = ($this->useCase($graphs))(false, null, false);

        self::assertSame([], $graphs->batches);
        self::assertSame([4, false, 0, true], [$report->graphCount(), $report->removing, $report->removed, $report->succeeded()]);
        self::assertSame([], $this->events);
    }

    public function testRemovingRunsOneBatchPerTemplateAndAudits(): void
    {
        $graphs = new InMemoryBrokenGraphs(self::broken());
        $report = ($this->useCase($graphs))(false, null, true);

        self::assertSame([[10, 11], [101, 102]], $graphs->batches);
        self::assertSame([4, true], [$report->removed, $report->succeeded()]);
        self::assertSame([['graph.broken.remove', 'local:4', AuditEvent::SUCCEEDED]], array_map(static fn(AuditEvent $e): array => [$e->action, $e->targetId, $e->outcome], $this->events));
    }

    public function testALargeTemplateIsRemovedInBatches(): void
    {
        $graphs = new InMemoryBrokenGraphs(self::broken(1201));
        ($this->useCase($graphs))(false, null, true);

        self::assertSame([2, 500, 500, 201], array_map('count', $graphs->batches));
    }

    public function testNothingBrokenNeedsNoWorkerOrAudit(): void
    {
        $graphs = new InMemoryBrokenGraphs([]);
        $report = ($this->useCase($graphs))(false, null, true);

        self::assertSame([[], 0, true], [$graphs->batches, $report->graphCount(), $report->succeeded()]);
        self::assertSame([], $this->events);
    }

    public function testAShortWorkerIsAFailureAndAudited(): void
    {
        $graphs = new InMemoryBrokenGraphs(self::broken(), 1);
        $report = ($this->useCase($graphs))(false, null, true);

        self::assertFalse($report->succeeded());
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    public function testAnUnknownOutcomeIsAuditedAsFailedAndRethrown(): void
    {
        $graphs = new InMemoryBrokenGraphs(self::broken(), null, true);

        try {
            ($this->useCase($graphs))(false, null, true);
            self::fail('the worker failure must reach the caller');
        } catch (\RuntimeException) {
            self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
        }
    }

    public function testAnOperatorWithoutRealm5IsRefusedBeforeAnyRead(): void
    {
        $graphs = new InMemoryBrokenGraphs(self::broken());

        try {
            ($this->useCase($graphs, manage: false))(false, null, false);
            self::fail('realm 5 is required');
        } catch (GraphManagementDenied) {
            self::assertSame(0, $graphs->reads);
        }
    }
}
