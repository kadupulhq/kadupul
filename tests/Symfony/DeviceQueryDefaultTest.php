<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Kadupul\Inventory\Application\Port\DeviceAssociationStore;
use Kadupul\Inventory\Application\Query\PrepareDeviceAssociations;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceAssociations;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationRecords;
use Kadupul\Platform\Contract\DatabaseConnection;

final class DeviceQueryDefaultTest extends TestCase
{
    public function testDisabledSnmpOverridesConfiguredDefault(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $store = $this->createMock(DeviceAssociationStore::class);
        $store->method('findVisible')->willReturn(new DeviceAssociations(7, 'Router', 0, 1, 0, [], [], 'query', 0));
        $store->expects(self::never())->method('defaultReindexMethod');
        self::assertSame(0, (new PrepareDeviceAssociations($access, $store))(7, 'query')['default_reindex']);
    }
    public function testConfigurationMatchesLegacyDefaultAndSavedOverrides(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $db = $this->createMock(DatabaseConnection::class);
        $db->method('get')->willReturn($pdo);
        $store = new LegacyDeviceAssociations($db, new LegacyDeviceVisibility($db), new DeviceAssociationRecords(), dirname(__DIR__, 2));
        self::assertSame(1, $store->defaultReindexMethod());
        foreach ([0,1,2,3] as $value) {
            $pdo->exec('DELETE FROM settings');
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['reindex_method', (string) $value]);
            self::assertSame($value, $store->defaultReindexMethod());
        }
    }
}
