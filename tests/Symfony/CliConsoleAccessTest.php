<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CliConsoleAccessTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // NOCASE stands in for MySQL's case-insensitive username collation.
        foreach ([
            "CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)",
            "CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT COLLATE NOCASE, realm INTEGER,
                    enabled TEXT, locked TEXT, must_change_password TEXT)",
            "CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)",
            "CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)",
            "CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)",
            "CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)",
            "INSERT INTO user_auth VALUES (1, 'admin', 0, 'on', '', ''), (2, 'ops', 0, 'on', '', ''),
                    (3, 'locked', 0, 'on', 'on', ''), (4, 'off', 0, '', '', ''), (5, 'guest', 0, 'on', '', ''),
                    (6, 'pending', 0, 'on', '', 'on'), (7, 'noconsole', 0, 'on', '', ''),
                    (8, 'dup', 0, 'on', '', ''), (9, 'dup', 3, 'on', '', ''),
                    (10, 'twin', 0, 'on', '', ''), (11, 'TWIN', 3, 'on', '', '')",
            "INSERT INTO user_auth_realm VALUES (1, 15), (1, 8), (3, 15), (3, 8), (4, 15), (4, 8),
                    (5, 8), (6, 8), (6, 15), (7, 15), (8, 8), (9, 8), (10, 8), (11, 8)",
            "INSERT INTO user_auth_group VALUES (9, 'on')",
            "INSERT INTO user_auth_group_members VALUES (9, 2)",
            "INSERT INTO user_auth_group_realm VALUES (9, 15), (9, 8)",
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
    }

    private function access(?string $as): CliConsoleAccess
    {
        $access = new CliConsoleAccess($this->db, $this->db);
        $access->select($as, OperatorDatabase::Local);

        return $access;
    }

    public function testAdminUserSettingIsTheDefaultActor(): void
    {
        $this->db->executeStatement("INSERT INTO settings VALUES ('admin_user', '1')");
        $actor = $this->access(null)->actor();
        self::assertSame([1, 'admin'], [$actor?->id, $actor?->username]);
        self::assertTrue($this->access(null)->canAdministerInstallation($actor));
    }

    public function testNamedOperatorGetsRealmThroughGroup(): void
    {
        $access = $this->access('ops');
        $actor = $access->actor();
        self::assertSame(2, $actor?->id);
        self::assertTrue($access->canAdministerInstallation($actor));
        // Membership alone grants nothing; only the group's own realm 15 row does.
        $this->db->executeStatement('DELETE FROM user_auth_group_realm WHERE group_id = 9 AND realm_id = 15');
        self::assertSame(2, $access->actor()?->id);
        self::assertFalse($access->canAdministerInstallation($actor));
    }

    public function testAbsentAdminUserRowFallsBackToTheDeclaredDefault(): void
    {
        self::assertSame(1, $this->access(null)->actor()?->id);
    }

    #[DataProvider('malformedAdminUsers')]
    public function testMalformedAdminUserResolvesNoActor(string $value): void
    {
        $this->db->executeStatement("INSERT INTO settings VALUES ('admin_user', ?)", [$value]);
        self::assertNull($this->access(null)->actor());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAdminUsers(): iterable
    {
        // Each of these would cast to a real id, or to 0, under (int).
        foreach (['1abc', '1.9', ' 1', '-1', '0', '', 'admin'] as $value) {
            yield var_export($value, true) => [$value];
        }
    }

    public function testRealmFromADisabledGroupIsNotGranted(): void
    {
        $this->db->executeStatement("INSERT INTO user_auth VALUES (12, 'grouped', 0, 'on', '', '')");
        $this->db->executeStatement("INSERT INTO user_auth_group VALUES (10, '')");
        $this->db->executeStatement('INSERT INTO user_auth_group_members VALUES (10, 12)');
        $this->db->executeStatement('INSERT INTO user_auth_group_realm VALUES (10, 8), (10, 15)');
        self::assertNull($this->access('grouped')->actor());
        $this->db->executeStatement("UPDATE user_auth_group SET enabled = 'on' WHERE id = 10");
        self::assertSame(12, $this->access('grouped')->actor()?->id);
    }

    public function testLockedAccountResolvesNoActor(): void
    {
        self::assertNull($this->access('locked')->actor());
        self::assertNull($this->access('off')->actor());
    }

    public function testUnknownOperatorAndMissingRealmLookTheSame(): void
    {
        self::assertNull($this->access('nobody')->actor());
        $this->db->executeStatement("INSERT INTO user_auth VALUES (13, 'viewer', 0, 'on', '', '')");
        $this->db->executeStatement('INSERT INTO user_auth_realm VALUES (13, 8)');
        $viewer = $this->access('viewer');
        $actor = $viewer->actor();
        self::assertSame(13, $actor?->id);
        self::assertFalse($viewer->canAdministerInstallation($actor));
    }

    public function testUnsupportedAuthMethodResolvesNoActor(): void
    {
        $this->db->executeStatement("INSERT INTO settings VALUES ('auth_method', '1')");
        self::assertNotNull($this->access('ops')->actor());
        $this->db->executeStatement("UPDATE settings SET value = '0' WHERE name = 'auth_method'");
        self::assertNull($this->access('ops')->actor());
    }

    public function testGuestAccountResolvesNoActor(): void
    {
        self::assertNotNull($this->access('guest')->actor());
        $this->db->executeStatement("INSERT INTO settings VALUES ('guest_user', '5')");
        self::assertNull($this->access('guest')->actor());
        $this->db->executeStatement("UPDATE settings SET value = 'guest' WHERE name = 'guest_user'");
        self::assertNull($this->access('guest')->actor());
    }

    public function testAccountWithoutConsoleRealmResolvesNoActor(): void
    {
        self::assertNull($this->access('noconsole')->actor());
    }

    public function testPendingPasswordChangeResolvesNoActor(): void
    {
        self::assertNull($this->access('pending')->actor());
    }

    public function testAmbiguousUsernameResolvesNoActor(): void
    {
        self::assertNull($this->access('dup')->actor());
        self::assertNull($this->access('twin')->actor());
    }

    public function testOperatorIsReadFromTheSelectedDatabase(): void
    {
        $main = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['settings (name TEXT, value TEXT)', 'user_auth (id INTEGER, username TEXT, realm INTEGER, enabled TEXT, locked TEXT, must_change_password TEXT)'] as $table) {
            $main->executeStatement('CREATE TABLE ' . $table);
        }
        $access = new CliConsoleAccess($this->db, $main);
        $access->select('ops', OperatorDatabase::Main);
        self::assertNull($access->actor());
        $access->select('ops', OperatorDatabase::Local);
        self::assertSame(2, $access->actor()?->id);
    }

    public function testResolvingBeforeSelectingIsAnError(): void
    {
        $this->expectException(\LogicException::class);
        (new CliConsoleAccess($this->db, $this->db))->actor();
    }
}
