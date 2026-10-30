<?php

declare(strict_types=1);

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

final class BindingApprovalPermissionTest extends TestCase
{
    public function testOnlyActorsGrantedRealm15DirectlyOrThroughAnEnabledGroupMayApprove(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE user_auth_realm (realm_id INTEGER, user_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
        $pdo->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        // 3: direct realm 15. 4: console only. 5: realm 15 through an enabled group. 6: through a disabled one.
        $pdo->exec('INSERT INTO user_auth_realm VALUES (15, 3), (8, 4), (8, 5), (8, 6)');
        $pdo->exec("INSERT INTO user_auth_group VALUES (1, 'on'), (2, '')");
        $pdo->exec('INSERT INTO user_auth_group_members VALUES (1, 5), (2, 6)');
        $pdo->exec('INSERT INTO user_auth_group_realm VALUES (1, 15), (2, 15)');
        $database = new class ($pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
        $session = new LegacyAuthenticatedSession(
            new SharedSession(new RequestStack(), self::createStub(LegacyConfiguration::class), new ReadOnlyDatabaseSessionHandler($database), $database),
            $database,
        );

        self::assertTrue($session->mayApproveBindings(new Actor(3, 'direct')));
        self::assertFalse($session->mayApproveBindings(new Actor(4, 'console-only')));
        self::assertTrue($session->mayApproveBindings(new Actor(5, 'group')));
        self::assertFalse($session->mayApproveBindings(new Actor(6, 'disabled-group')));
        self::assertFalse($session->mayApproveBindings(new Actor(0, 'nobody')));
        self::assertFalse($session->mayApproveBindings(new Actor(99, 'unknown')));
    }
}
