<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\GraphRemovalMode;
use Kadupul\Graphing\Application\Command\RemoveGraphs;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Domain\GraphRemovalFilter;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphRemoval;
use PHPUnit\Framework\TestCase;

final class RemoveGraphsTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 5);
    }

    private function useCase(InMemoryGraphRemoval $graphs): RemoveGraphs
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));

        return new RemoveGraphs($access, $graphs, $graphs);
    }

    /** @return list<GraphTitle> */
    private function titles(int $count): array
    {
        return array_map(static fn(int $id): GraphTitle => new GraphTitle($id, "Graph $id"), range(1, $count));
    }

    public function testCountingReadsAndRemovesNothing(): void
    {
        $graphs = new InMemoryGraphRemoval($this->titles(3));
        $report = ($this->useCase($graphs))(false, null, new GraphRemovalFilter([7], [], [], [], false), GraphRemovalMode::Count, false);

        self::assertCount(3, $report->graphs);
        self::assertSame([], $graphs->batches);
        self::assertSame([7], $graphs->filters[0]->hostIds);
    }

    public function testNoSelectorIsRefusedForCountingAndRemovingButNotForListing(): void
    {
        $none = new GraphRemovalFilter([], [], [], [], false);
        foreach ([GraphRemovalMode::Count, GraphRemovalMode::Remove] as $mode) {
            $graphs = new InMemoryGraphRemoval($this->titles(2));
            $report = ($this->useCase($graphs))(false, null, $none, $mode, false);

            self::assertTrue($report->refused);
            self::assertFalse($report->succeeded());
            self::assertSame([], $graphs->filters, 'a refused run never reads the graphs');
            self::assertSame([], $graphs->batches);
        }
        $graphs = new InMemoryGraphRemoval($this->titles(2));
        self::assertFalse(($this->useCase($graphs))(false, null, $none, GraphRemovalMode::Listing, false)->refused);
        $all = new GraphRemovalFilter([], [], [], [], true);
        self::assertFalse(($this->useCase(new InMemoryGraphRemoval($this->titles(1))))(false, null, $all, GraphRemovalMode::Remove, false)->refused);
    }

    public function testRemovalPassesTheDataSourceChoiceAndBatchesBy500(): void
    {
        $graphs = new InMemoryGraphRemoval($this->titles(1001));
        $report = ($this->useCase($graphs))(false, null, new GraphRemovalFilter([], [], [], [], true), GraphRemovalMode::Remove, true);

        self::assertSame(1001, $report->removed);
        self::assertTrue($report->succeeded());
        self::assertSame([500, 500, 1], array_map('count', $graphs->batches));
        self::assertSame([true, true, true], $graphs->kept);
    }

    public function testNothingMatchingIsNotASuccess(): void
    {
        $report = ($this->useCase(new InMemoryGraphRemoval([])))(false, null, new GraphRemovalFilter([9], [], [], [], false), GraphRemovalMode::Remove, false);

        self::assertFalse($report->succeeded());
        self::assertSame(0, $report->removed);
    }

    public function testAFailedWorkerPropagates(): void
    {
        $this->expectException(\RuntimeException::class);
        ($this->useCase(new InMemoryGraphRemoval($this->titles(2), fails: true)))(false, null, new GraphRemovalFilter([], [], [], [], true), GraphRemovalMode::Remove, false);
    }

    public function testAnOperatorWithoutGraphAccessIsDeniedBeforeAnythingIsRead(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 5');
        $graphs = new InMemoryGraphRemoval($this->titles(2));

        try {
            ($this->useCase($graphs))(false, null, new GraphRemovalFilter([], [], [], [], true), GraphRemovalMode::Remove, false);
            self::fail('Expected a denial.');
        } catch (GraphManagementDenied) {
            self::assertSame([], $graphs->filters);
            self::assertSame([], $graphs->batches);
        }
    }
}
