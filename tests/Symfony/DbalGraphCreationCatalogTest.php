<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Application\Port\SnmpFilter;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphCreationCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalGraphCreationCatalogTest extends TestCase
{
    private Connection $db;
    private DbalGraphCreationCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // MySQL has REGEXP built in; SQLite calls a user function with the pattern first.
        $this->db->getNativeConnection()->sqliteCreateFunction('REGEXP', static fn(string $pattern, string $value): int => preg_match('/' . $pattern . '/i', $value), 2);
        foreach ([
            'CREATE TABLE host (id INTEGER PRIMARY KEY, hostname TEXT, description TEXT, host_template_id INTEGER)',
            'CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT, multiple TEXT DEFAULT "")',
            'CREATE TABLE host_template_graph (host_template_id INTEGER, graph_template_id INTEGER)',
            'CREATE TABLE snmp_query (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE snmp_query_graph (id INTEGER PRIMARY KEY, snmp_query_id INTEGER, name TEXT)',
            'CREATE TABLE host_snmp_query (host_id INTEGER, snmp_query_id INTEGER, reindex_method INTEGER)',
            'CREATE TABLE host_snmp_cache (host_id INTEGER, snmp_query_id INTEGER, field_name TEXT, field_value TEXT, snmp_index TEXT)',
            'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, graph_template_id INTEGER, host_id INTEGER, snmp_query_id INTEGER DEFAULT 0, snmp_index TEXT DEFAULT "")',
            'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, local_graph_id INTEGER, task_item_id INTEGER)',
            'CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, local_data_id INTEGER)',
            "INSERT INTO host VALUES (8, 'sw1', 'Switch', 0), (7, 'core1', 'Core router', 2)",
            "INSERT INTO graph_templates (id, name, multiple) VALUES (4, 'Load', ''), (3, 'Traffic', ''), (5, 'Several', 'on')",
            'INSERT INTO host_template_graph VALUES (2, 3), (2, 4), (1, 3), (2, 3)',
            "INSERT INTO snmp_query VALUES (1, 'Interfaces')",
            "INSERT INTO snmp_query_graph VALUES (2, 1, 'Traffic'), (3, 1, 'Errors'), (4, 2, 'Other query')",
            'INSERT INTO host_snmp_query VALUES (7, 1, 1)',
            "INSERT INTO host_snmp_cache VALUES (7, 1, 'ifName', 'eth0', '1'), (7, 1, 'ifName', 'eth1', '2'), (7, 1, 'ifName', 'lo', '3'), (7, 1, 'ifStatus', 'Up', '1'), (7, 1, 'ifStatus', 'Down', '2'),
                (7, 1, 'ifStatus', 'Up', '3'), (7, 2, 'ifName', 'other', '9'), (8, 1, 'ifName', 'eth0', '5')",
            "INSERT INTO graph_local VALUES (30, 3, 7, 0, ''), (31, 3, 7, 1, '2'), (32, 5, 7, 0, ''), (33, 4, 8, 0, '')",
            'INSERT INTO data_template_rrd VALUES (60, 600), (61, 601)',
            'INSERT INTO graph_templates_item VALUES (1, 31, 61), (2, 31, 60)',
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalGraphCreationCatalog($this->db, $this->db);
    }

    public function testDevicesAndTemplatesAreListedInIdOrder(): void
    {
        self::assertSame([[7, 'core1', 2, 'Core router'], [8, 'sw1', 0, 'Switch']], array_map(static fn($device): array => [$device->id, $device->hostname, $device->hostTemplateId, $device->description], $this->catalog->devices(OperatorDatabase::Local)));
        self::assertSame([[3, 'Traffic'], [4, 'Load'], [5, 'Several']], array_map(static fn($choice): array => [$choice->id, $choice->name], $this->catalog->graphTemplates(OperatorDatabase::Local)));
        self::assertSame([[1, 'Interfaces']], array_map(static fn($choice): array => [$choice->id, $choice->name], $this->catalog->queries(OperatorDatabase::Local)));
        self::assertSame([[2, 'Traffic'], [3, 'Errors']], array_map(static fn($choice): array => [$choice->id, $choice->name], $this->catalog->queryTypes(OperatorDatabase::Local, 1)));
    }

    public function testTheTemplatesOfADeviceTemplateComeOnceEachByNameAndOnlyForANumber(): void
    {
        $names = static fn(?array $choices): ?array => $choices === null ? null : array_map(static fn($choice): string => $choice->name, $choices);

        self::assertSame(['Load', 'Traffic'], $names($this->catalog->graphTemplatesOfDeviceTemplate(OperatorDatabase::Local, '2')));
        self::assertSame(['Load', 'Traffic'], $names($this->catalog->graphTemplatesOfDeviceTemplate(OperatorDatabase::Local, '2.0')));
        self::assertSame(['Load', 'Traffic'], $names($this->catalog->graphTemplatesOfDeviceTemplate(OperatorDatabase::Local, ' 2')));
        self::assertSame(['Traffic'], $names($this->catalog->graphTemplatesOfDeviceTemplate(OperatorDatabase::Local, '1e0')));
        self::assertSame([], $names($this->catalog->graphTemplatesOfDeviceTemplate(OperatorDatabase::Local, '9')));
        self::assertNull($this->catalog->graphTemplatesOfDeviceTemplate(OperatorDatabase::Local, '2 OR 1=1'));
        self::assertNull($this->catalog->graphTemplatesOfDeviceTemplate(OperatorDatabase::Local, 'abc'));
    }

    public function testFieldsAndValuesAreDistinctOrderedAndLimitedToTheQuery(): void
    {
        self::assertSame(['ifName', 'ifStatus'], $this->catalog->snmpFields(OperatorDatabase::Local, 7, null));
        self::assertSame(['ifName', 'ifStatus'], $this->catalog->snmpFields(OperatorDatabase::Local, 7, 1));
        self::assertSame(['ifName'], $this->catalog->snmpFields(OperatorDatabase::Local, 7, 2));
        self::assertSame(['eth0', 'eth1', 'lo', 'other'], $this->catalog->snmpValues(OperatorDatabase::Local, 7, 'ifName', null));
        self::assertSame(['eth0', 'eth1', 'lo'], $this->catalog->snmpValues(OperatorDatabase::Local, 7, 'ifName', 1));
        self::assertSame(['Down', 'Up'], $this->catalog->snmpValues(OperatorDatabase::Local, 7, 'ifStatus', 1), 'a value that repeats counts once');
        self::assertSame([], $this->catalog->snmpValues(OperatorDatabase::Local, 7, "ifName' OR '1'='1", 1), 'a field is a value, not SQL');
    }

    public function testADataQueryIsAssociatedWhenTheDeviceHasARowForIt(): void
    {
        self::assertTrue($this->catalog->queryAssociated(OperatorDatabase::Local, 7, 1));
        self::assertFalse($this->catalog->queryAssociated(OperatorDatabase::Local, 8, 1));
        self::assertFalse($this->catalog->queryAssociated(OperatorDatabase::Local, 7, 2));
    }

    public function testAnExistingGraphIsOneOfTheTemplateThatIsNotOneOfSeveral(): void
    {
        self::assertSame(30, $this->catalog->existingGraph(OperatorDatabase::Local, 3, 7, null, null));
        self::assertSame(31, $this->catalog->existingGraph(OperatorDatabase::Local, 3, 7, 1, '2'));
        self::assertNull($this->catalog->existingGraph(OperatorDatabase::Local, 3, 7, 1, '3'));
        self::assertNull($this->catalog->existingGraph(OperatorDatabase::Local, 5, 7, null, null), 'a template that allows several graphs never counts as made');
        self::assertNull($this->catalog->existingGraph(OperatorDatabase::Local, 3, 8, null, null));
    }

    public function testTheFirstDataSourceIsTheOneOfTheFirstItemRow(): void
    {
        self::assertSame(601, $this->catalog->firstDataSource(OperatorDatabase::Local, 31));
        self::assertNull($this->catalog->firstDataSource(OperatorDatabase::Local, 30));
    }

    public function testIndexesMatchEveryFilterByValueOrExpression(): void
    {
        $indexes = fn(SnmpFilter ...$filters): array => $this->catalog->snmpIndexes(OperatorDatabase::Local, 7, 1, array_values($filters));

        self::assertSame(['1'], $indexes(new SnmpFilter('ifName', 'eth0', null)));
        self::assertSame(['1', '2'], $indexes(new SnmpFilter('ifName', null, '^eth')));
        self::assertSame(['1'], $indexes(new SnmpFilter('ifName', null, '^eth'), new SnmpFilter('ifStatus', 'Up', null)));
        self::assertSame(['1', '3'], $indexes(new SnmpFilter('ifStatus', null, '^up$')));
        self::assertSame([], $indexes(new SnmpFilter('ifName', 'ETH9', null)));
        self::assertSame([], $indexes(new SnmpFilter('ifName', "eth0' OR '1'='1", null)), 'a value is a value, not SQL');
    }
}
