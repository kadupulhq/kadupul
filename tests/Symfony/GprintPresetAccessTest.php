<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class GprintPresetAccessTest extends TestCase
{
    public function testRealmFiveAllowsDirectAndEnabledGroupGrantsButNotOtherRealms(): void
    {
        $db = $this->database();
        $console = new class implements ConsoleAccess {
            public ?Actor $actor = null;
            public function consoleActor(): ?Actor
            {
                return $this->actor;
            }
            public function canManageDevices(Actor $actor): bool
            {
                return false;
            }
        };
        $console->actor = new Actor(9, 'gprint-operator');
        $connection = new class ($db) implements DatabaseConnection {
            public function __construct(private readonly \PDO $db) {}
            public function get(): \PDO
            {
                return $this->db;
            }
        };
        $access = new LegacyGprintPresetAccess($console, $connection);
        try {
            $access->authorize();
            self::fail('An actor without realm 5 was authorized.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        }

        $db->exec('INSERT INTO user_auth_realm VALUES (9,5)');
        self::assertSame(9, $access->authorize()->id);
        $db->exec('DELETE FROM user_auth_realm WHERE user_id=9');
        $db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        $db->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $db->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)');
        $db->exec("INSERT INTO user_auth_group VALUES (3,'on'); INSERT INTO user_auth_group_realm VALUES (3,5); INSERT INTO user_auth_group_members VALUES (3,9)");
        self::assertSame(9, $access->authorize()->id);
        $db->exec("UPDATE user_auth_group SET enabled=''");
        try {
            $access->authorize();
            self::fail('A disabled group grant was accepted.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        }
    }

    public function testWriteAuthorizationRequiresTheSameCurrentActor(): void
    {
        $db = $this->database();
        $db->exec('INSERT INTO user_auth_realm VALUES (9,8),(9,5)');
        $console = new class implements ConsoleAccess {
            public ?Actor $actor = null;
            public function consoleActor(): ?Actor
            {
                return $this->actor;
            }
            public function canManageDevices(Actor $actor): bool
            {
                return false;
            }
        };
        $console->actor = new Actor(9, 'gprint-operator');
        $connection = new class ($db) implements DatabaseConnection {
            public function __construct(private readonly \PDO $db) {}
            public function get(): \PDO
            {
                return $this->db;
            }
        };
        $access = new LegacyGprintPresetAccess($console, $connection);
        try {
            $access->assertCurrent(9);
            self::fail('Write authorization was allowed without a transaction.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        $db->beginTransaction();
        $access->assertCurrent(9);
        $db->commit();

        $db->exec('DELETE FROM user_auth_realm WHERE user_id=9 AND realm_id=8');
        $db->beginTransaction();
        try {
            $access->assertCurrent(9);
            self::fail('A still-present session actor passed after its console realm grant was revoked.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        } finally {
            $db->rollBack();
        }

        $db->exec('INSERT INTO user_auth_realm VALUES (9,8)');
        $db->exec("UPDATE user_auth SET enabled='' WHERE id=9");
        $db->beginTransaction();
        try {
            $access->assertCurrent(9);
            self::fail('A still-present session actor passed after its account was disabled.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        } finally {
            $db->rollBack();
        }
        $db->exec("UPDATE user_auth SET enabled='on' WHERE id=9");
        $db->exec("UPDATE user_auth SET must_change_password='on' WHERE id=9");
        try {
            $access->authorize();
            self::fail('A stale actor read presets after a forced password change.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        }
        $db->beginTransaction();
        try {
            $access->assertCurrent(9);
            self::fail('A stale actor passed after a forced password change.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        } finally {
            $db->rollBack();
        }
        $db->exec("UPDATE user_auth SET must_change_password='' WHERE id=9");
        $db->exec('DELETE FROM user_auth_realm WHERE user_id=9 AND realm_id=5');
        $db->beginTransaction();
        try {
            $access->assertCurrent(9);
            self::fail('A still-present session actor passed after its GPRINT realm grant was revoked.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        } finally {
            $db->rollBack();
        }
        $db->exec('INSERT INTO user_auth_realm VALUES (9,5)');
        $db->beginTransaction();
        try {
            $access->assertCurrent(10);
            self::fail('A different acting user passed write reauthorization.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        } finally {
            $db->rollBack();
        }
    }

    private function database(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
        $db->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)');
        $db->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $db->exec("INSERT INTO user_auth VALUES (9,'gprint-operator','on','','')");
        $db->exec("INSERT INTO settings VALUES ('auth_method','1'),('guest_user','0')");
        return $db;
    }
}
