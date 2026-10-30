<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\ReapplyGraphTitles;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphTitles;
use PHPUnit\Framework\TestCase;

final class ReapplyGraphTitlesTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];

    private function useCase(InMemoryGraphTitles $graphs, bool $manage = true, bool $collector = false): ReapplyGraphTitles
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(7, 'admin'));
        $operator->method('canManageGraphs')->willReturn($manage);
        $topology = $this->createStub(CollectorTopology::class);
        $topology->method('isRemoteCollector')->willReturn($collector);
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new ReapplyGraphTitles(new GraphCommandAccess($operator, $topology, $trail), $graphs, $graphs);
    }

    /** @return list<GraphTitle> */
    private static function graphs(int $count): array
    {
        return array_map(static fn(int $id): GraphTitle => new GraphTitle($id, 'Graph ' . $id), range(1, $count));
    }

    private static function all(): GraphSelection
    {
        return GraphSelection::parse('all') ?? throw new \LogicException('all parses');
    }

    public function testItRenamesEverySelectedGraphAndAuditsTheRun(): void
    {
        $graphs = new InMemoryGraphTitles(self::graphs(3));
        $report = ($this->useCase($graphs))(self::all(), false, null, true);

        self::assertSame([[1, 2, 3]], $graphs->batches);
        self::assertSame([3, true, false], [$report->reapplied, $report->succeeded(), $report->dryRun]);
        self::assertCount(1, $this->events);
        self::assertSame(['graph.title.reapply', 'local:3', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testADryRunListsTheGraphsAndChangesNone(): void
    {
        $graphs = new InMemoryGraphTitles(self::graphs(2));
        $report = ($this->useCase($graphs))(self::all(), false, null, false);

        self::assertSame([], $graphs->batches);
        self::assertSame([2, 0, true, true], [count($report->graphs), $report->reapplied, $report->dryRun, $report->succeeded()]);
        self::assertSame([], $this->events);
    }

    public function testNoGraphsIsASuccessWithoutAWorker(): void
    {
        $graphs = new InMemoryGraphTitles([]);
        $report = ($this->useCase($graphs))(self::all(), false, null, true);

        self::assertSame([], $graphs->batches);
        self::assertTrue($report->succeeded());
        self::assertSame([], $this->events);
    }

    public function testALargeSelectionRunsInBatches(): void
    {
        $graphs = new InMemoryGraphTitles(self::graphs(1201));
        $report = ($this->useCase($graphs))(self::all(), false, null, true);

        self::assertSame([500, 500, 201], array_map('count', $graphs->batches));
        self::assertSame(1201, $report->reapplied);
    }

    public function testAShortWorkerIsReportedAsFailedAndAudited(): void
    {
        $graphs = new InMemoryGraphTitles(self::graphs(4), 3);
        $report = ($this->useCase($graphs))(self::all(), false, null, true);

        self::assertSame([3, false], [$report->reapplied, $report->succeeded()]);
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    public function testAnUnknownOutcomeIsAuditedAsFailedAndRethrown(): void
    {
        $graphs = new InMemoryGraphTitles(self::graphs(1), null, true);

        try {
            ($this->useCase($graphs))(self::all(), false, null, true);
            self::fail('the worker failure must reach the caller');
        } catch (\RuntimeException) {
            self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
        }
    }

    public function testAnOperatorWithoutRealm5IsRefusedBeforeAnyRead(): void
    {
        $graphs = new InMemoryGraphTitles(self::graphs(1));

        try {
            ($this->useCase($graphs, manage: false))(self::all(), false, null, true);
            self::fail('realm 5 is required');
        } catch (GraphManagementDenied) {
            self::assertSame([], $graphs->selections);
            self::assertSame([[AuditEvent::DENIED, 'graph.title.reapply']], array_map(static fn(AuditEvent $e): array => [$e->decision, $e->action], $this->events));
        }
    }

    public function testACollectorWorksOnTheMainDatabaseUnlessLocal(): void
    {
        $graphs = new InMemoryGraphTitles(self::graphs(1));
        self::assertSame(OperatorDatabase::Main, ($this->useCase($graphs, collector: true))(self::all(), false, null, false)->database);
        self::assertSame(OperatorDatabase::Local, ($this->useCase($graphs, collector: true))(self::all(), true, null, false)->database);
    }
}
