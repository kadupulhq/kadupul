<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataQueryAssociationCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalDataQueryAssociationCatalogTest extends TestCase
{
    private Connection $db;
    private DbalDataQueryAssociationCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE host (id INTEGER PRIMARY KEY, hostname TEXT, description TEXT, host_template_id INTEGER)',
            'CREATE TABLE snmp_query (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE host_snmp_query (host_id INTEGER, snmp_query_id INTEGER, reindex_method INTEGER)',
            "INSERT INTO host VALUES (1, 'sw1', 'Access switch', 1), (2, 'rt1', 'Core router', 2)",
            "INSERT INTO snmp_query VALUES (3, 'Interfaces'), (4, 'Disks')",
            'INSERT INTO host_snmp_query VALUES (2, 4, 1)',
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalDataQueryAssociationCatalog($this->db, $this->db);
    }

    private static function method(string $typed): ReindexMethod
    {
        return ReindexMethod::parse($typed) ?? throw new \LogicException('The method parses.');
    }

    public function testADeviceAndAQueryAreFoundById(): void
    {
        $device = $this->catalog->findDevice(OperatorDatabase::Local, '2');
        $query = $this->catalog->findDataQuery(OperatorDatabase::Local, '4');

        self::assertSame([2, 'rt1', 2, 'Core router'], [$device?->id, $device?->hostname, $device?->hostTemplateId, $device?->description]);
        self::assertSame([4, 'Disks'], [$query?->id, $query?->name]);
        self::assertNull($this->catalog->findDevice(OperatorDatabase::Local, '9'));
        self::assertNull($this->catalog->findDataQuery(OperatorDatabase::Local, '9'));
    }

    public function testAnAssociationIsPerDeviceQueryAndMethod(): void
    {
        self::assertTrue($this->catalog->isAssociated(OperatorDatabase::Local, 2, 4, self::method('uptime')));
        self::assertFalse($this->catalog->isAssociated(OperatorDatabase::Local, 2, 4, self::method('2')), 'another method is not the same association');
        self::assertFalse($this->catalog->isAssociated(OperatorDatabase::Local, 1, 4, self::method('1')));
        self::assertFalse($this->catalog->isAssociated(OperatorDatabase::Local, 2, 3, self::method('1')));
    }

    public function testTheListsAreInIdOrder(): void
    {
        self::assertSame([1, 2], array_map(static fn($device): int => $device->id, $this->catalog->devices(OperatorDatabase::Local)));
        self::assertSame([[3, 'Interfaces'], [4, 'Disks']], array_map(static fn($query): array => [$query->id, $query->name], $this->catalog->dataQueries(OperatorDatabase::Local)));
    }

    public function testTheMainConnectionIsUsedForMain(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $other->executeStatement('CREATE TABLE snmp_query (id INTEGER PRIMARY KEY, name TEXT)');
        $other->executeStatement("INSERT INTO snmp_query VALUES (7, 'Main only')");
        $catalog = new DbalDataQueryAssociationCatalog($this->db, $other);

        self::assertSame(7, $catalog->findDataQuery(OperatorDatabase::Main, '7')?->id);
        self::assertNull($catalog->findDataQuery(OperatorDatabase::Local, '7'));
    }
}
