<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Domain\GraphRemovalFilter;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphRemovalCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

/** SQLite has no RLIKE, so the title expressions are covered by the MariaDB parity run instead. */
final class DbalGraphRemovalCatalogTest extends TestCase
{
    private Connection $db;
    private DbalGraphRemovalCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE host_template (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE host (id INTEGER PRIMARY KEY, hostname TEXT, description TEXT, host_template_id INTEGER)',
            'CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE host_template_graph (host_template_id INTEGER, graph_template_id INTEGER)',
            'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, graph_template_id INTEGER, host_id INTEGER)',
            'CREATE TABLE graph_templates_graph (id INTEGER PRIMARY KEY, local_graph_id INTEGER, title_cache TEXT)',
            "INSERT INTO host_template VALUES (1, 'Switch'), (2, 'Router')",
            "INSERT INTO host VALUES (1, 'sw1', 'Access switch', 1), (2, 'rt1', 'Core router', 2), (3, 'rt2', 'Edge router', 2), (4, 'orphan', 'No template row', 9)",
            "INSERT INTO graph_templates VALUES (10, 'Traffic'), (11, 'Errors'), (12, 'Load')",
            'INSERT INTO host_template_graph VALUES (1, 10), (2, 10), (2, 11), (2, 12), (2, 99)',
            // Graph 205 belongs to no device (host 0) and graph 206 to a device that no longer exists.
            'INSERT INTO graph_local VALUES (201, 10, 1), (202, 10, 2), (203, 11, 2), (204, 12, 3), (205, 10, 0), (206, 10, 77)',
            "INSERT INTO graph_templates_graph VALUES (1, 201, 'Switch traffic'), (2, 202, 'Core traffic'), (3, 203, 'Core errors'), (4, 204, 'Edge load'), (5, 205, 'No device'), (6, 206, 'Gone device')",
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalGraphRemovalCatalog($this->db, $this->db);
    }

    /** @return list<int> */
    private function ids(GraphRemovalFilter $filter): array
    {
        return array_map(static fn($graph): int => $graph->localGraphId, $this->catalog->graphs(OperatorDatabase::Local, $filter));
    }

    public function testAllIgnoresTheSelectorsAndStillNeedsADeviceRow(): void
    {
        // Graphs 205 and 206 have no device row, so the original's inner join never found them.
        self::assertSame([201, 202, 203, 204], $this->ids(new GraphRemovalFilter([99], [99], [99], [], true)));
    }

    public function testSelectorsNarrowTogether(): void
    {
        self::assertSame([202, 203], $this->ids(new GraphRemovalFilter([2], [], [], [], false)));
        self::assertSame([202, 203, 204], $this->ids(new GraphRemovalFilter([], [2], [], [], false)));
        self::assertSame([201, 202], $this->ids(new GraphRemovalFilter([], [], [10], [], false)));
        self::assertSame([202], $this->ids(new GraphRemovalFilter([2, 3], [2], [10], [], false)));
        self::assertSame([], $this->ids(new GraphRemovalFilter([1], [2], [], [], false)));
    }

    public function testGraphsComeBackWithTheirTitlesInIdOrder(): void
    {
        $graphs = $this->catalog->graphs(OperatorDatabase::Local, new GraphRemovalFilter([2], [], [], [], false));

        self::assertSame([[202, 'Core traffic'], [203, 'Core errors']], array_map(static fn($graph): array => [$graph->localGraphId, $graph->title], $graphs));
    }

    public function testDevicesCanBeNarrowedByTemplateAndNeedTheTemplateRow(): void
    {
        $line = static fn($device): array => [$device->id, $device->hostname, $device->hostTemplateId, $device->description];

        self::assertSame([[2, 'rt1', 2, 'Core router'], [3, 'rt2', 2, 'Edge router']], array_map($line, $this->catalog->devices(OperatorDatabase::Local, [2])));
        // The orphan has no template row, so it is listed only when no template is asked for.
        self::assertSame([1, 2, 3, 4], array_map(static fn($device): int => $device->id, $this->catalog->devices(OperatorDatabase::Local, [])));
    }

    public function testDeviceTemplatesAreInIdOrder(): void
    {
        self::assertSame([[1, 'Switch'], [2, 'Router']], array_map(static fn($template): array => [$template->id, $template->name], $this->catalog->deviceTemplates(OperatorDatabase::Local)));
    }

    public function testGraphTemplatesAreListedOnceByNameAndOnlyForAttachedOnes(): void
    {
        $line = static fn($template): array => [$template->id, $template->name];

        // Template 10 is attached to both device templates and appears once; 99 has no row, so its name is empty and it sorts first.
        self::assertSame([[99, ''], [11, 'Errors'], [12, 'Load'], [10, 'Traffic']], array_map($line, $this->catalog->graphTemplates(OperatorDatabase::Local, [])));
        self::assertSame([[10, 'Traffic']], array_map($line, $this->catalog->graphTemplates(OperatorDatabase::Local, [1])));
    }

    public function testTheMainConnectionIsUsedForMain(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $other->executeStatement('CREATE TABLE host_template (id INTEGER PRIMARY KEY, name TEXT)');
        $other->executeStatement("INSERT INTO host_template VALUES (7, 'Main only')");
        $catalog = new DbalGraphRemovalCatalog($this->db, $other);

        self::assertSame([7], array_map(static fn($template): int => $template->id, $catalog->deviceTemplates(OperatorDatabase::Main)));
        self::assertSame([1, 2], array_map(static fn($template): int => $template->id, $catalog->deviceTemplates(OperatorDatabase::Local)));
    }
}
