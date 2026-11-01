<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Domain\DataQuerySelection;
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataQueryIndexCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalDataQueryIndexCatalogTest extends TestCase
{
    private Connection $db;
    private DbalDataQueryIndexCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE data_local (id INTEGER PRIMARY KEY, host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT)',
            'CREATE TABLE data_template_data (id INTEGER PRIMARY KEY, local_data_id INTEGER, data_input_id INTEGER)',
            'CREATE TABLE data_input_fields (id INTEGER PRIMARY KEY, data_input_id INTEGER, type_code TEXT)',
            'CREATE TABLE data_input_data (data_input_field_id INTEGER, data_template_data_id INTEGER, value TEXT)',
            // Input method 1 has an output_type field (10) and an index_type field (11); method 2 has no output_type.
            "INSERT INTO data_input_fields VALUES (10, 1, 'output_type'), (11, 1, 'index_type'), (12, 2, 'index_type')",
            "INSERT INTO data_local VALUES (3, 10, 5, 'a'), (4, 10, 6, 'b'), (5, 11, 5, 'c'), (6, 12, 0, ''), (7, 12, 5, 'd'), (8, 13, 5, 'e')",
            // Data source 6 has no data query; 7 has no stored output type; 8 has no data_template_data row.
            'INSERT INTO data_template_data VALUES (1, 3, 1), (2, 4, 1), (3, 5, 1), (4, 6, 1), (5, 7, 1)',
            "INSERT INTO data_input_data VALUES (10, 1, '21'), (11, 1, 'ifIndex'), (10, 2, '22'), (10, 3, '23'), (10, 4, '24')",
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalDataQueryIndexCatalog($this->db, $this->db);
    }

    /** @return list<array{int, int, string, ?int, ?string}> */
    private function rows(string $host, string $query): array
    {
        return array_map(
            static fn($item): array => [$item->hostId, $item->snmpQueryId, $item->snmpIndex, $item->dataTemplateDataId, $item->outputType],
            $this->catalog->select(OperatorDatabase::Local, DataQuerySelection::parse($host, $query)),
        );
    }

    public function testAllReturnsEveryDataSourceWithAnOutputTypeFieldInIdOrder(): void
    {
        self::assertSame([
            [10, 5, 'a', 1, '21'],
            [10, 6, 'b', 2, '22'],
            [11, 5, 'c', 3, '23'],
            [12, 0, '', 4, '24'],
            [12, 5, 'd', null, null],
        ], $this->rows('all', 'all'));
    }

    public function testADataSourceWithoutAStoredOutputTypeKeepsItsEmptyFields(): void
    {
        self::assertSame([[12, 5, 'd', null, null]], $this->rows('12', '5'));
    }

    public function testDeviceAndQueryNarrowTheSelection(): void
    {
        self::assertSame([[10, 5, 'a', 1, '21']], $this->rows('10', '5'));
        self::assertSame([[10, 5, 'a', 1, '21'], [11, 5, 'c', 3, '23'], [12, 5, 'd', null, null]], $this->rows('all', '5'));
        self::assertSame([[10, 5, 'a', 1, '21'], [10, 6, 'b', 2, '22']], $this->rows('10', 'all'));
        self::assertSame([], $this->rows('13', 'all'), 'a data source with no data_template_data row has no input fields');
    }
}
