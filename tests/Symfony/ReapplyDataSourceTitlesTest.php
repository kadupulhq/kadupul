<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\ReapplyDataSourceTitles;
use Kadupul\Graphing\Application\Port\DataSourceTitle;
use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryDataSourceTitles;
use PHPUnit\Framework\TestCase;

final class ReapplyDataSourceTitlesTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];

    private function useCase(InMemoryDataSourceTitles $sources, bool $manage = true, bool $collector = false): ReapplyDataSourceTitles
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

        return new ReapplyDataSourceTitles(new GraphCommandAccess($operator, $topology, $trail), $sources, $sources);
    }

    /** @return list<DataSourceTitle> */
    private static function sources(int $count): array
    {
        return array_map(static fn(int $id): DataSourceTitle => new DataSourceTitle($id, 'Data source ' . $id), range(1, $count));
    }

    private static function all(): GraphSelection
    {
        return GraphSelection::parse('all') ?? throw new \LogicException('all parses');
    }

    public function testItRenamesEverySelectedDataSourceAndAuditsTheRun(): void
    {
        $sources = new InMemoryDataSourceTitles(self::sources(3));
        $report = ($this->useCase($sources))(self::all(), false, null, true);

        self::assertSame([[1, 2, 3]], $sources->batches);
        self::assertSame([3, true, false], [count($report->renamed), $report->succeeded(), $report->dryRun]);
        self::assertCount(1, $this->events);
        self::assertSame(['data-source.title.reapply', 'local:3', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testADryRunListsTheDataSourcesAndChangesNone(): void
    {
        $sources = new InMemoryDataSourceTitles(self::sources(2));
        $report = ($this->useCase($sources))(self::all(), false, null, false);

        self::assertSame([], $sources->batches);
        self::assertSame([2, 0, true, true], [count($report->dataSources), count($report->renamed), $report->dryRun, $report->succeeded()]);
        self::assertSame([], $this->events);
    }

    public function testNoDataSourcesIsASuccessWithoutAWorker(): void
    {
        $sources = new InMemoryDataSourceTitles([]);
        $report = ($this->useCase($sources))(self::all(), false, null, true);

        self::assertSame([], $sources->batches);
        self::assertTrue($report->succeeded());
        self::assertSame([], $this->events);
    }

    public function testALargeSelectionRunsInBatches(): void
    {
        $sources = new InMemoryDataSourceTitles(self::sources(1201));
        $report = ($this->useCase($sources))(self::all(), false, null, true);

        self::assertSame([500, 500, 201], array_map('count', $sources->batches));
        self::assertSame(1201, count($report->renamed));
    }

    public function testAShortWorkerIsReportedAsFailedAndAudited(): void
    {
        $sources = new InMemoryDataSourceTitles(self::sources(4), 3);
        $report = ($this->useCase($sources))(self::all(), false, null, true);

        self::assertSame([3, false], [count($report->renamed), $report->succeeded()]);
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    public function testAnUnknownOutcomeIsAuditedAsFailedAndRethrown(): void
    {
        $sources = new InMemoryDataSourceTitles(self::sources(1), null, true);

        try {
            ($this->useCase($sources))(self::all(), false, null, true);
            self::fail('the worker failure must reach the caller');
        } catch (\RuntimeException) {
            self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
        }
    }

    public function testAnOperatorWithoutRealm5IsRefusedBeforeAnyRead(): void
    {
        $sources = new InMemoryDataSourceTitles(self::sources(1));

        try {
            ($this->useCase($sources, manage: false))(self::all(), false, null, true);
            self::fail('realm 3 is required');
        } catch (GraphManagementDenied) {
            self::assertSame([], $sources->selections);
            self::assertSame([[AuditEvent::DENIED, 'data-source.title.reapply']], array_map(static fn(AuditEvent $e): array => [$e->decision, $e->action], $this->events));
        }
    }

    public function testACollectorWorksOnTheMainDatabaseUnlessLocal(): void
    {
        $sources = new InMemoryDataSourceTitles(self::sources(1));
        self::assertSame(OperatorDatabase::Main, ($this->useCase($sources, collector: true))(self::all(), false, null, false)->database);
        self::assertSame(OperatorDatabase::Local, ($this->useCase($sources, collector: true))(self::all(), true, null, false)->database);
    }
}
