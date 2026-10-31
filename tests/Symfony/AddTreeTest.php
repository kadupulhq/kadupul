<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\AddTree;
use Kadupul\Graphing\Application\Command\AddTreeNode;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\TreeCreation;
use Kadupul\Graphing\Application\Command\TreeNodeCreation;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryTrees;
use PHPUnit\Framework\TestCase;

final class AddTreeTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 4);
    }

    private function access(): GraphCommandAccess
    {
        return new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
    }

    private function node(InMemoryTrees $trees, string $type, string $tree = '5', string $parent = '0', string $name = '', string $graph = '0', string $device = '0', string $site = '0', string $style = '1', string $sort = 'alpha'): TreeNodeCreation
    {
        return (new AddTreeNode($this->access(), $trees, $trees))(false, null, $type, $sort, $tree, $parent, $name, $graph, $device, $site, $style);
    }

    public function testATreeIsCreatedWithTheSortTypeOfItsMethod(): void
    {
        $trees = new InMemoryTrees();
        $result = (new AddTree($this->access(), $trees, $trees))(false, null, 'New', 'natural');

        self::assertSame([TreeCreation::CREATED, 6], [$result->status, $result->treeId]);
        self::assertSame(['New:4'], $trees->trees);
    }

    public function testATreeWithAnExistingNameIsRefusedAndNothingIsWritten(): void
    {
        $trees = new InMemoryTrees();
        $result = (new AddTree($this->access(), $trees, $trees))(false, null, 'Main', 'alpha');

        self::assertSame([TreeCreation::EXISTS, 5], [$result->status, $result->treeId]);
        self::assertSame([], $trees->trees);
    }

    public function testAFailedWorkerIsReportedNotThrown(): void
    {
        $trees = new InMemoryTrees(fails: true);

        self::assertSame(TreeCreation::FAILED, (new AddTree($this->access(), $trees, $trees))(false, null, 'New', 'alpha')->status);
        self::assertSame(TreeNodeCreation::FAILED, $this->node($trees, 'header', name: 'Edge')->status);
    }

    public function testEachKindOfNodeKeepsOnlyItsOwnFields(): void
    {
        $trees = new InMemoryTrees();
        foreach ([
            $this->node($trees, 'header', parent: '10', name: 'Edge', graph: '3', device: '7', site: '2', style: '2'),
            $this->node($trees, 'graph', name: 'ignored', graph: '3', device: '7', site: '2', style: '2'),
            $this->node($trees, 'host', name: 'ignored', graph: '3', device: '7', site: '2', style: '2'),
            $this->node($trees, 'site', name: 'ignored', graph: '3', device: '7', site: '2', style: '2'),
        ] as $result) {
            self::assertSame([TreeNodeCreation::CREATED, 20], [$result->status, $result->nodeId]);
        }
        self::assertSame([
            [1, 10, 'Edge', 0, 0, 0, 1],
            [2, 0, '', 3, 0, 0, 1],
            [3, 0, '', 0, 7, 0, 2],
            [4, 0, '', 0, 0, 2, 2],
        ], array_map(static fn($draft): array => [$draft->itemType, $draft->parentId, $draft->title, $draft->graphId, $draft->deviceId, $draft->siteId, $draft->groupingType], $trees->nodes));
        self::assertSame(2, $trees->nodes[0]->sortType);
    }

    public function testASortMethodThatIsNotOneOfTheFourSavesInheritedSorting(): void
    {
        $trees = new InMemoryTrees();
        $this->node($trees, 'header', name: 'Edge', sort: 'random');

        self::assertSame(0, $trees->nodes[0]->sortType);
    }

    public function testEveryCheckRefusesBeforeAnythingIsWrittenAndInTheScriptsOrder(): void
    {
        $trees = new InMemoryTrees();
        $cases = [
            [TreeNodeCreation::BAD_TREE, $this->node($trees, 'header', tree: '0', parent: 'x', name: 'a')],
            [TreeNodeCreation::BAD_TREE, $this->node($trees, 'header', tree: '5.0', name: 'a')],
            [TreeNodeCreation::UNKNOWN_TREE, $this->node($trees, 'header', tree: '6', parent: 'x', name: 'a')],
            [TreeNodeCreation::BAD_PARENT, $this->node($trees, 'header', parent: '-1', name: 'a')],
            [TreeNodeCreation::UNKNOWN_PARENT, $this->node($trees, 'header', parent: '99', name: 'a')],
            [TreeNodeCreation::PARENT_NOT_HEADER, $this->node($trees, 'header', parent: '11', name: 'a')],
            [TreeNodeCreation::PARENT_NOT_HEADER, $this->node($trees, 'header', parent: '12', name: 'a')],
            [TreeNodeCreation::NAME_REQUIRED, $this->node($trees, 'header')],
            [TreeNodeCreation::NAME_REQUIRED, $this->node($trees, 'header', name: '0')],
            [TreeNodeCreation::UNKNOWN_GRAPH, $this->node($trees, 'graph', graph: '9')],
            [TreeNodeCreation::UNKNOWN_GRAPH, $this->node($trees, 'graph', graph: 'x')],
            [TreeNodeCreation::UNKNOWN_SITE, $this->node($trees, 'site', site: '3')],
            [TreeNodeCreation::UNKNOWN_DEVICE, $this->node($trees, 'host', device: '07')],
            [TreeNodeCreation::BAD_GROUPING, $this->node($trees, 'host', device: '7', style: '3')],
            [TreeNodeCreation::BAD_GROUPING, $this->node($trees, 'host', device: '7', style: 'x')],
        ];
        foreach ($cases as $index => [$status, $result]) {
            self::assertSame($status, $result->status, 'case ' . $index);
        }
        self::assertSame([], $trees->nodes);
    }

    public function testAnUnknownTreeIsReportedAsTheIntegerTheScriptCastIt(): void
    {
        $result = $this->node(new InMemoryTrees(), 'header', tree: '0012', name: 'a');

        self::assertSame([TreeNodeCreation::UNKNOWN_TREE, 12], [$result->status, $result->treeId]);
    }

    public function testAGroupingWrittenAsAFloatIsAcceptedForADevice(): void
    {
        $trees = new InMemoryTrees();

        self::assertTrue($this->node($trees, 'host', device: '7', style: '2.0')->succeeded());
        self::assertSame(2, $trees->nodes[0]->groupingType);
    }

    public function testTheTreesRealmIsNeededAndTheNeighbouringOnesAreNotEnough(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 4');
        $this->db->executeStatement('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (1, 5), (1, 3)');
        $trees = new InMemoryTrees();

        foreach ([
            fn() => $this->node($trees, 'header', name: 'a'),
            fn() => (new AddTree($this->access(), $trees, $trees))(false, null, 'New', 'alpha'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('The operator without realm 4 was admitted.');
            } catch (GraphManagementDenied) {
                // expected
            }
        }
        self::assertSame([[], []], [$trees->trees, $trees->nodes]);
    }
}
