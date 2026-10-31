<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphTemplateAssignmentCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalGraphTemplateAssignmentCatalogTest extends TestCase
{
    private Connection $db;
    private DbalGraphTemplateAssignmentCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE host (id INTEGER PRIMARY KEY, hostname TEXT, description TEXT, host_template_id INTEGER)',
            'CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE host_graph (host_id INTEGER, graph_template_id INTEGER)',
            "INSERT INTO host VALUES (1, 'sw1', 'Access switch', 1), (2, 'rt1', 'Core router', 2)",
            "INSERT INTO graph_templates VALUES (3, 'Traffic'), (4, 'Errors')",
            'INSERT INTO host_graph VALUES (2, 4)',
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalGraphTemplateAssignmentCatalog($this->db, $this->db);
    }

    public function testADeviceAndATemplateAreFoundById(): void
    {
        $device = $this->catalog->findDevice(OperatorDatabase::Local, '2');
        $template = $this->catalog->findGraphTemplate(OperatorDatabase::Local, '4');

        self::assertSame([2, 'rt1', 2, 'Core router'], [$device?->id, $device?->hostname, $device?->hostTemplateId, $device?->description]);
        self::assertSame([4, 'Errors'], [$template?->id, $template?->name]);
        self::assertNull($this->catalog->findDevice(OperatorDatabase::Local, '9'));
        self::assertNull($this->catalog->findGraphTemplate(OperatorDatabase::Local, '9'));
    }

    public function testAnAssociationIsPerDeviceAndTemplate(): void
    {
        self::assertTrue($this->catalog->isAssociated(OperatorDatabase::Local, 2, 4));
        self::assertFalse($this->catalog->isAssociated(OperatorDatabase::Local, 1, 4));
        self::assertFalse($this->catalog->isAssociated(OperatorDatabase::Local, 2, 3));
    }

    public function testTheListsAreInIdOrder(): void
    {
        self::assertSame([1, 2], array_map(static fn($device): int => $device->id, $this->catalog->devices(OperatorDatabase::Local)));
        self::assertSame([[3, 'Traffic'], [4, 'Errors']], array_map(static fn($template): array => [$template->id, $template->name], $this->catalog->graphTemplates(OperatorDatabase::Local)));
    }

    public function testTheMainConnectionIsUsedForMain(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $other->executeStatement('CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT)');
        $other->executeStatement("INSERT INTO graph_templates VALUES (7, 'Main only')");
        $catalog = new DbalGraphTemplateAssignmentCatalog($this->db, $other);

        self::assertSame(7, $catalog->findGraphTemplate(OperatorDatabase::Main, '7')?->id);
        self::assertNull($catalog->findGraphTemplate(OperatorDatabase::Local, '7'));
    }
}
