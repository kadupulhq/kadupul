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
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataSourceTitleCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalDataSourceTitleCatalogTest extends TestCase
{
    private Connection $db;
    private DbalDataSourceTitleCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE data_template (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE data_input (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE data_local (id INTEGER PRIMARY KEY, host_id INTEGER, data_template_id INTEGER)',
            'CREATE TABLE data_template_data (id INTEGER PRIMARY KEY, local_data_id INTEGER, data_input_id INTEGER, name_cache TEXT)',
            "INSERT INTO data_template VALUES (1, 'Interface Traffic'), (2, 'Load Average')",
            "INSERT INTO data_input VALUES (1, 'Get SNMP Data'), (2, 'Script Query')",
            // Data source 5 has no template and no input method; 6 sits on device 0. Row 99 is a template row (local_data_id 0).
            'INSERT INTO data_local VALUES (3, 10, 1), (4, 10, 2), (5, 11, 0), (6, 0, 2), (7, 12, 1)',
            "INSERT INTO data_template_data VALUES (1, 7, 1, 'Edge - Traffic'), (2, 3, 1, 'Core - Traffic'), (3, 4, 2, 'Core - Load'), (4, 5, NULL, 'Lone source'), (5, 6, 2, 'Unhosted'), (99, 0, 1, 'Template row')",
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalDataSourceTitleCatalog($this->db, $this->db);
    }

    /** @return list<int> */
    private function ids(string $hosts, string $filter = ''): array
    {
        $selection = GraphSelection::parse($hosts, $filter) ?? throw new \LogicException('selector');

        return array_map(static fn($source): int => $source->localDataId, $this->catalog->select(OperatorDatabase::Local, $selection));
    }

    public function testAllReturnsEveryDataSourceInIdOrderWithoutTheTemplateRows(): void
    {
        self::assertSame([3, 4, 5, 6, 7], $this->ids('all'));
    }

    public function testDevicesAreSelectedByListAndZeroMeansNoDevice(): void
    {
        self::assertSame([3, 4], $this->ids('10'));
        self::assertSame([3, 4, 5], $this->ids('10,11'));
        self::assertSame([6], $this->ids('0'));
    }

    public function testTheFilterMatchesATitleADataIdATemplateOrAnInputMethod(): void
    {
        self::assertSame([3, 7], $this->ids('all', 'Traffic'));
        self::assertSame([4, 6], $this->ids('all', 'Load Av'));
        self::assertSame([4, 6], $this->ids('all', 'Script Query'), 'a data input method name');
        self::assertSame([3, 7], $this->ids('all', 'Get SNMP'), 'another data input method name');
        self::assertSame([6], $this->ids('all', '6'), 'a data id');
        self::assertSame([3], $this->ids('10', 'Traffic'));
    }

    public function testAQuoteInTheFilterIsTextNotSql(): void
    {
        self::assertSame([], $this->ids('all', "x') OR 1=1 -- "));
        self::assertSame(5, (int) $this->db->fetchOne('SELECT COUNT(*) FROM data_local'));
    }

    public function testTheTitleIsTheStoredNameCache(): void
    {
        $selection = GraphSelection::parse('11') ?? throw new \LogicException('selector');

        self::assertSame('Lone source', $this->catalog->select(OperatorDatabase::Local, $selection)[0]->title);
    }
}
