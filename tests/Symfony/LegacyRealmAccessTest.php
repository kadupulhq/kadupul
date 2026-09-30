<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuthenticatedSession;
use Kadupul\IdentityAccess\Infrastructure\Legacy\ReadOnlyDatabaseSessionHandler;
use Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class LegacyRealmAccessTest extends TestCase
{
    public function testProductionSessionAutoloadsAndChecksDirectAndEnabledGroupGrants(): void
    {
        self::assertTrue(class_exists(LegacyAuthenticatedSession::class));

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
        $pdo->exec('INSERT INTO user_auth_realm VALUES (10, 23)');
        $pdo->exec("INSERT INTO user_auth_group VALUES (1, 'on'), (2, '')");
        $pdo->exec('INSERT INTO user_auth_group_realm VALUES (1, 23), (2, 23)');
        $pdo->exec('INSERT INTO user_auth_group_members VALUES (1, 20), (2, 30)');
        $database = new class ($pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}
            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
        $session = new SharedSession(
            new RequestStack(),
            $this->createMock(LegacyConfiguration::class),
            new ReadOnlyDatabaseSessionHandler($database),
            $database
        );
        $access = new LegacyAuthenticatedSession($session, $database);

        self::assertTrue($access->canManageAutomation(new Actor(10, 'direct')));
        self::assertTrue($access->canManageAutomation(new Actor(20, 'group')));
        self::assertFalse($access->canManageAutomation(new Actor(30, 'disabled-group')));
        self::assertFalse($access->canManageAutomation(new Actor(0, 'invalid')));
    }
}
