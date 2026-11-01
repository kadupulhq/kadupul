<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataSourceCreationCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class DbalDataSourceCreationCatalogTest extends TestCase
{
    private Connection $db;
    private DbalDataSourceCreationCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE host (id INTEGER PRIMARY KEY, hostname TEXT)',
            'CREATE TABLE data_template (id INTEGER PRIMARY KEY, name TEXT)',
            "INSERT INTO host VALUES (1, 'sw1'), (2, 'rt1')",
            "INSERT INTO data_template VALUES (3, 'Traffic'), (4, 'Load')",
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->catalog = new DbalDataSourceCreationCatalog($this->db, $this->db);
    }

    public function testADeviceAndATemplateAreFoundById(): void
    {
        $device = $this->catalog->findDevice(OperatorDatabase::Local, '2');
        $template = $this->catalog->findDataTemplate(OperatorDatabase::Local, '4');

        self::assertSame([2, 'rt1'], [$device?->id, $device?->name]);
        self::assertSame([4, 'Load'], [$template?->id, $template?->name]);
        self::assertNull($this->catalog->findDevice(OperatorDatabase::Local, '9'));
        self::assertNull($this->catalog->findDataTemplate(OperatorDatabase::Local, '9'));
    }

    public function testADeviceIsNotATemplate(): void
    {
        self::assertNull($this->catalog->findDevice(OperatorDatabase::Local, '3'));
        self::assertNull($this->catalog->findDataTemplate(OperatorDatabase::Local, '1'));
    }

    public function testTheMainConnectionIsUsedForMain(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $other->executeStatement('CREATE TABLE data_template (id INTEGER PRIMARY KEY, name TEXT)');
        $other->executeStatement("INSERT INTO data_template VALUES (7, 'Main only')");
        $catalog = new DbalDataSourceCreationCatalog($this->db, $other);

        self::assertSame(7, $catalog->findDataTemplate(OperatorDatabase::Main, '7')?->id);
        self::assertNull($catalog->findDataTemplate(OperatorDatabase::Local, '7'));
    }

    public function testAnIdIsBoundNotConcatenated(): void
    {
        self::assertNull($this->catalog->findDevice(OperatorDatabase::Local, "1 OR 1=1"));
        self::assertNull($this->catalog->findDataTemplate(OperatorDatabase::Local, "3' OR '1'='1"));
    }
}
