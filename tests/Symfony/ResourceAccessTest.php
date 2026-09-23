<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyResourceAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class ResourceAccessTest extends TestCase
{
    public function testOwnershipRealmAndGroupAdministratorSemantics(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE user_auth_realm (user_id INT, realm_id INT); CREATE TABLE user_auth_group_realm (group_id INT, realm_id INT); CREATE TABLE user_auth_group_members (user_id INT,group_id INT); CREATE TABLE user_auth_group (id INT, enabled TEXT)');
        $db = $this->createMock(DatabaseConnection::class);
        $db->method('get')->willReturn($pdo);
        $access = new LegacyResourceAccess($db);
        self::assertFalse($access->canManageTree(42, 42));
        $pdo->exec('INSERT INTO user_auth_realm VALUES (42,4),(42,22)');
        self::assertTrue($access->canManageTree(42, 42));
        self::assertTrue($access->canManageReport(42, 42));
        self::assertFalse($access->canManageTree(42, 99));
        self::assertFalse($access->canManageReport(42, 99));
        $pdo->exec("INSERT INTO user_auth_group VALUES (7,'on'); INSERT INTO user_auth_group_members VALUES (42,7); INSERT INTO user_auth_group_realm VALUES (7,1)");
        self::assertFalse($access->canManageTree(42, 99), 'Group system-admin realm must not bypass resource ownership');
        $pdo->exec('INSERT INTO user_auth_group_realm VALUES (7,21)');
        self::assertTrue($access->canManageReport(42, 99));
        $pdo->exec("UPDATE user_auth_group SET enabled=''");
        self::assertFalse($access->canManageReport(42, 99));
        $pdo->exec('INSERT INTO user_auth_realm VALUES (42,1)');
        self::assertTrue($access->canManageTree(42, 99));
        self::assertTrue($access->canManageReport(42, 99));
    }
}
