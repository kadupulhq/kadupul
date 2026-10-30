<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphTitleCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalGraphTitleCatalogTest extends TestCase
{
    private Connection $db;
    private DbalGraphTitleCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER)',
            'CREATE TABLE graph_templates_graph (id INTEGER PRIMARY KEY, local_graph_id INTEGER, title_cache TEXT)',
            "INSERT INTO graph_templates VALUES (1, 'Interface Traffic'), (2, 'Load Average')",
            // Graph 5 has no template; graph 6 sits on device 0.
            'INSERT INTO graph_local VALUES (3, 10, 1), (4, 10, 2), (5, 11, 0), (6, 0, 2), (7, 12, 1)',
            "INSERT INTO graph_templates_graph VALUES (1, 7, 'Edge - Traffic'), (2, 3, 'Core - Traffic'), (3, 4, 'Core - Load'), (4, 5, 'Lone graph'), (5, 6, 'Unhosted')",
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalGraphTitleCatalog($this->db, $this->db);
    }

    /** @return list<int> */
    private function ids(string $hosts, string $filter = ''): array
    {
        $selection = GraphSelection::parse($hosts, $filter) ?? throw new \LogicException('selector');

        return array_map(static fn($graph): int => $graph->localGraphId, $this->catalog->select(OperatorDatabase::Local, $selection));
    }

    public function testAllReturnsEveryGraphInIdOrderIncludingOnesWithoutATemplate(): void
    {
        self::assertSame([3, 4, 5, 6, 7], $this->ids('all'));
    }

    public function testDevicesAreSelectedByListAndZeroMeansNoDevice(): void
    {
        self::assertSame([3, 4], $this->ids('10'));
        self::assertSame([3, 4, 5], $this->ids('10,11'));
        self::assertSame([6], $this->ids('0'));
    }

    public function testTheFilterMatchesATitleOrATemplateName(): void
    {
        self::assertSame([3, 7], $this->ids('all', 'Traffic'));
        self::assertSame([4, 6], $this->ids('all', 'Load Av'));
        self::assertSame([3], $this->ids('10', 'Traffic'));
    }

    public function testAQuoteInTheFilterIsTextNotSql(): void
    {
        self::assertSame([], $this->ids('all', "x') OR 1=1 -- "));
        self::assertSame(5, (int) $this->db->fetchOne('SELECT COUNT(*) FROM graph_local'));
    }

    public function testTheTitleIsTheStoredTitleCache(): void
    {
        $selection = GraphSelection::parse('11') ?? throw new \LogicException('selector');

        self::assertSame('Lone graph', $this->catalog->select(OperatorDatabase::Local, $selection)[0]->title);
    }
}
