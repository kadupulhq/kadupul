<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\IdentityAccess;

use Kadupul\IdentityAccess\Infrastructure\Persistence\InstallationRealmGrantManager;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class InstallationRealmGrantManagerTest extends TestCase
{
    private \PDO $pdo;
    private InstallationRealmGrantManager $manager;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, reset_perms INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE user_auth_realm (user_id INTEGER NOT NULL, realm_id INTEGER NOT NULL, PRIMARY KEY (user_id, realm_id))');
        $this->pdo->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER NOT NULL, realm_id INTEGER NOT NULL)');
        $this->pdo->exec('INSERT INTO user_auth (id, reset_perms) VALUES (1, 0), (2, 4294967295)');

        $connection = new readonly class ($this->pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            #[\Override]
            public function get(): \PDO
            {
                return $this->pdo;
            }
        };

        $this->manager = new InstallationRealmGrantManager($connection);
    }

    public function testGrantsRealmAndIncrementsResetPermsWithUint32Wrap(): void
    {
        $this->manager->lockActorForGrant(1);
        $this->manager->grantRealmAndInvalidateCache(1, 10005);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = 1 AND realm_id = 10005')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT reset_perms FROM user_auth WHERE id = 1')->fetchColumn());

        $this->manager->grantRealmAndInvalidateCache(2, 10006);
        $this->assertSame(1, (int) $this->pdo->query('SELECT reset_perms FROM user_auth WHERE id = 2')->fetchColumn());
    }

    public function testRevokesDirectAndGroupGrants(): void
    {
        $this->pdo->exec('INSERT INTO user_auth_realm VALUES (1, 10009), (1, 10010)');
        $this->pdo->exec('INSERT INTO user_auth_group_realm VALUES (3, 10009), (3, 10010)');

        $this->manager->revokeRealm(10009);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_auth_realm WHERE realm_id = 10009')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_auth_group_realm WHERE realm_id = 10009')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM user_auth_realm WHERE realm_id = 10010')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM user_auth_group_realm WHERE realm_id = 10010')->fetchColumn());
    }
}
