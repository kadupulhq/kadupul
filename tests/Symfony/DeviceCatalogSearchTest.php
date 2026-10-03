<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceListCriteria;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceCatalog;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeviceCatalogSearchTest extends TestCase
{
    public function testNumericSearchIncludesDeviceIdsWithoutBypassingVisibility(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec("CREATE TABLE settings (name TEXT,value TEXT);
            CREATE TABLE user_auth (id INTEGER,policy_graphs INTEGER,policy_hosts INTEGER,policy_graph_templates INTEGER);
            CREATE TABLE user_auth_group (id INTEGER,enabled TEXT,policy_graphs INTEGER,policy_hosts INTEGER,policy_graph_templates INTEGER);
            CREATE TABLE user_auth_group_members (user_id INTEGER,group_id INTEGER);
            CREATE TABLE user_auth_perms (user_id INTEGER,item_id INTEGER,type INTEGER);
            CREATE TABLE graph_local (id INTEGER,host_id INTEGER,graph_template_id INTEGER);
            CREATE TABLE host (id INTEGER,description TEXT,hostname TEXT,disabled TEXT,status INTEGER,location TEXT,external_id TEXT,deleted TEXT);
            INSERT INTO user_auth VALUES (1,2,2,2);
            INSERT INTO user_auth_perms VALUES (1,7,3),(1,8,3);
            INSERT INTO host VALUES (7,'Alpha','alpha.invalid','',3,'','',''),(8,'Room 7','beta.invalid','',3,'','',''),(9,'Hidden 7','hidden.invalid','',3,'','','');");
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $catalog = new LegacyDeviceCatalog($database, new LegacyDeviceVisibility($database));
        $ids = static fn($page) => array_map(static fn($device) => $device->id, $page->devices);
        self::assertSame([7, 8], $ids($catalog->visibleTo(1, new DeviceListCriteria(search: '7'))));
        self::assertSame([], $ids($catalog->visibleTo(1, new DeviceListCriteria(search: '9'))));
        self::assertSame([], $ids($catalog->visibleTo(1, new DeviceListCriteria(search: '7 OR 1=1'))));
        self::assertSame([7], $ids($catalog->visibleTo(1, new DeviceListCriteria(search: 'alpha'))));
    }
}
