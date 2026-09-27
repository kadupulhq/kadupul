<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Inventory\Infrastructure\Persistence\DoctrineSiteAssignmentCatalog;
use PHPUnit\Framework\TestCase;

final class DoctrineSiteAssignmentCatalogTest extends TestCase
{
    public function testItReturnsAssignableSitesInDisplayOrder(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement('CREATE TABLE sites (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL)');
        $database->executeStatement("INSERT INTO sites (id, name) VALUES (2, 'Zulu'), (0, 'Console'), (3, 'Alpha'), (1, 'Alpha')");

        self::assertSame([1 => 'Alpha', 3 => 'Alpha', 2 => 'Zulu'], (new DoctrineSiteAssignmentCatalog($database))->sites());
    }
}
