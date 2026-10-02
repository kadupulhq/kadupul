<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorStore;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaletteColorPersistentStorageTest extends TestCase
{
    use RealMariaDb;

    public static function participants(): iterable
    {
        yield 'persistent admitted' => [null];
        foreach (['colors', 'graph_templates_item', 'color_template_items', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            yield $table . ' InnoDB temporary shadow' => [$table];
        }
    }

    #[DataProvider('participants')]
    public function testActualSaveRequiresPersistentTablesAndPreservesObserverRows(?string $shadow): void
    {
        $connection = $this->realMariaDb();
        $db = $connection->getNativeConnection();
        $schema = 'palette_persist_' . bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE `' . $schema . '`');
        $db->exec('USE `' . $schema . '`');
        $observer = null;
        try {
            foreach (['colors', 'graph_templates_item', 'color_template_items', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
                $columns = $table === 'colors' ? 'id INT PRIMARY KEY AUTO_INCREMENT,name VARCHAR(40),hex VARCHAR(6),read_only VARCHAR(2)' : 'id INT PRIMARY KEY';
                $db->exec('CREATE TABLE `' . $table . '` (' . $columns . ') ENGINE=InnoDB');
            }
            $db->exec("INSERT INTO colors (name,hex,read_only) VALUES ('Before','aaaaaa','')");
            $observer = $this->realMariaDb();
            $observerDb = $observer->getNativeConnection();
            self::assertNotSame($db, $observerDb);
            $observerDb->exec('USE `' . $schema . '`');
            $before = $observerDb->query('SELECT * FROM colors ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
            if ($shadow !== null) {
                $ddl = $db->query('SHOW CREATE TABLE `' . $shadow . '`')->fetch(\PDO::FETCH_NUM)[1];
                self::assertStringStartsWith('CREATE TABLE ', $ddl);
                $db->exec('CREATE TEMPORARY TABLE ' . substr($ddl, strlen('CREATE TABLE ')));
            }
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($db);
            $access = $this->createMock(PaletteColorAccess::class);
            $authorized = [];
            $access->method('assertCurrent')->willReturnCallback(static function (int $actor) use (&$authorized): void {
                $authorized[] = $actor;
            });
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $store = new LegacyPaletteColorStore($database, $access, $this->createMock(AuditTrail::class), $configuration);
            $error = null;
            $result = null;
            try {
                $result = $store->save(9, null, 'After', 'bbbbbb', null);
            } catch (\RuntimeException $caught) {
                $error = $caught;
            }
            self::assertFalse($db->inTransaction());
            $after = $observerDb->query('SELECT * FROM colors ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
            if ($shadow === null) {
                self::assertNull($error);
                self::assertSame(2, $result);
                self::assertSame([9], $authorized);
                self::assertSame(['After', 'Before'], array_column($after, 'name'));
            } else {
                self::assertSame($before, $after, 'Independent observer must retain persistent rows.');
                self::assertInstanceOf(\RuntimeException::class, $error);
                self::assertSame('Color writes require transactional tables.', $error->getMessage());
                self::assertNull($result);
                self::assertSame([], $authorized, 'Reject temporary targets before authorization or writes.');
            }
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $observer?->close();
            $db->exec('DROP DATABASE `' . $schema . '`');
            $connection->close();
        }
    }
}
