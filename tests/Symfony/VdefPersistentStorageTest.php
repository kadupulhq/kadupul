<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyVdefEditor;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VdefPersistentStorageTest extends TestCase
{
    use RealMariaDb;

    public static function participants(): iterable
    {
        yield 'persistent admitted' => [null];
        foreach (['vdef', 'vdef_items', 'graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            yield $table . ' temporary InnoDB' => [$table];
        }
    }

    #[DataProvider('participants')]
    public function testActualSchemaSaveRejectsTemporaryTablesAndPreservesPersistentRows(?string $shadow): void
    {
        $database = $this->realMariaDb();
        $schema = 'vdef_persist_' . bin2hex(random_bytes(6));
        $database->executeStatement('CREATE DATABASE `' . $schema . '`');
        $observer = null;
        try {
            $database->executeStatement('USE `' . $schema . '`');
            $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
            self::assertIsString($sql);
            foreach (['vdef', 'vdef_items', 'graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
                self::assertSame(1, preg_match('/^CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?^\) ENGINE=InnoDB[^;]*;/ms', $sql, $match), 'Use the actual schema for ' . $table);
                $database->executeStatement($match[0]);
            }
            $database->insert('settings', ['name' => 'auth_method', 'value' => '1']);
            $database->insert('settings', ['name' => 'guest_user', 'value' => 'guest']);
            $database->insert('user_auth', ['id' => 42, 'username' => 'operator', 'enabled' => 'on']);
            foreach ([8, 14] as $realm) {
                $database->insert('user_auth_realm', ['user_id' => 42, 'realm_id' => $realm]);
            }
            $database->insert('vdef', ['hash' => str_repeat('a', 32), 'name' => 'Before']);
            $observer = $this->realMariaDb();
            self::assertNotSame($database->getNativeConnection(), $observer->getNativeConnection());
            $observer->executeStatement('USE `' . $schema . '`');
            $before = $observer->fetchAllAssociative('SELECT * FROM vdef ORDER BY id');
            if ($shadow !== null) {
                $ddl = $database->fetchNumeric('SHOW CREATE TABLE `' . $shadow . '`')[1];
                self::assertStringStartsWith('CREATE TABLE ', $ddl);
                $database->executeStatement('CREATE TEMPORARY TABLE ' . substr($ddl, strlen('CREATE TABLE ')));
                foreach ($observer->fetchAllAssociative('SELECT * FROM `' . $shadow . '`') as $row) {
                    $database->insert($shadow, $row);
                }
            }
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $editor = new LegacyVdefEditor($database, $configuration);
            $error = null;
            $result = null;
            try {
                $result = $editor->save(42, 0, 'After');
            } catch (\RuntimeException $caught) {
                $error = $caught;
            }
            self::assertFalse($database->isTransactionActive());
            self::assertFalse($database->getNativeConnection()->inTransaction());
            $after = $observer->fetchAllAssociative('SELECT * FROM vdef ORDER BY id');
            if ($shadow === null) {
                self::assertNull($error);
                self::assertSame(2, $result);
                self::assertSame(['Before', 'After'], array_column($after, 'name'));
            } else {
                self::assertInstanceOf(\RuntimeException::class, $error);
                self::assertSame('VDEF writes require transactional tables.', $error->getMessage());
                self::assertNull($result);
                self::assertSame($before, $after);
            }
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $observer?->close();
            $database->executeStatement('DROP DATABASE `' . $schema . '`');
            $database->close();
        }
    }
}
