<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Alerting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentAccess;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The read-path gate of the alert pages, DbalRuleAccess::authorize() and
 * DbalIncidentAccess::authorize(), on real user, group and realm rows. The
 * console actor is always present, so every denial below comes from the
 * account or realm check (AlertingAccessDenied with unauthenticated false),
 * and each denied account differs from an admitted one in a single row.
 * tests/integration/AlertPagesDatabaseTest.php runs the same cases on the
 * cacti.sql tables in MariaDB.
 */
final class AlertingRealmCheckTest extends TestCase
{
    private const int GUEST = 109;

    private Connection $database;
    private int $actor = 0;

    /** @return iterable<string, array{int, int}> */
    public static function realms(): iterable
    {
        yield 'Alert Rules' => [29, 30];
        yield 'Alert Incidents' => [30, 29];
    }

    #[DataProvider('realms')]
    public function testOnlyAnEligibleAccountWithTheRealmIsAdmitted(int $realm, int $otherRealm): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            "CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT NOT NULL, enabled TEXT NOT NULL DEFAULT 'on', locked TEXT NOT NULL DEFAULT '', must_change_password TEXT NULL)",
            'CREATE TABLE user_auth_realm (realm_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (realm_id, user_id))',
            "CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, name TEXT NOT NULL, enabled TEXT NOT NULL DEFAULT 'on')",
            'CREATE TABLE user_auth_group_members (group_id INT NOT NULL, user_id INT NOT NULL)',
            'CREATE TABLE user_auth_group_realm (group_id INT NOT NULL, realm_id INT NOT NULL)',
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
        ] as $ddl) {
            $this->database->executeStatement($ddl);
        }
        $expected = self::seed($this->database, $realm, $otherRealm);

        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): Actor => new Actor($this->actor, 'user' . $this->actor));
        $access = $realm === 29 ? new DbalRuleAccess($console, $this->database) : new DbalIncidentAccess($console, $this->database);

        self::assertSame($expected, self::decisions($expected, function (int $user) use ($access): void {
            $this->actor = $user;
            self::assertSame($user, $access->authorize()->id);
        }));
    }

    /**
     * One account per case, from an admitted account changed in one row.
     * Shared with the MariaDB test in its own copy, as the two runners load
     * different autoloaders.
     *
     * @return array<string, array{int, string}> case => [user id, admitted|denied]
     */
    public static function seed(Connection $db, int $realm, int $otherRealm): array
    {
        $db->insert('settings', ['name' => 'auth_method', 'value' => '1']);
        $db->insert('settings', ['name' => 'guest_user', 'value' => (string) self::GUEST]);
        $cases = [
            'user grant' => [101, 'admitted', ['realm' => true]],
            'group grant' => [102, 'admitted', ['group' => 'on']],
            'console realm only' => [103, 'denied', []],
            'other alerting realm only' => [104, 'denied', ['other' => true]],
            'disabled group grant' => [105, 'denied', ['group' => '']],
            'locked account' => [106, 'denied', ['realm' => true, 'locked' => 'on']],
            'disabled account' => [107, 'denied', ['realm' => true, 'enabled' => '']],
            'must change password' => [108, 'denied', ['realm' => true, 'must_change_password' => 'on']],
            'guest account' => [self::GUEST, 'denied', ['realm' => true]],
        ];
        $expected = [];
        foreach ($cases as $name => [$id, $decision, $change]) {
            $db->insert('user_auth', [
                'id' => $id,
                'username' => 'user' . $id,
                'enabled' => $change['enabled'] ?? 'on',
                'locked' => $change['locked'] ?? '',
                'must_change_password' => $change['must_change_password'] ?? null,
            ]);
            $db->insert('user_auth_realm', ['realm_id' => 8, 'user_id' => $id]);
            if (isset($change['realm'])) {
                $db->insert('user_auth_realm', ['realm_id' => $realm, 'user_id' => $id]);
            }
            if (isset($change['other'])) {
                $db->insert('user_auth_realm', ['realm_id' => $otherRealm, 'user_id' => $id]);
            }
            if (isset($change['group'])) {
                $db->insert('user_auth_group', ['id' => $id, 'name' => 'group' . $id, 'enabled' => $change['group']]);
                $db->insert('user_auth_group_members', ['group_id' => $id, 'user_id' => $id]);
                $db->insert('user_auth_group_realm', ['group_id' => $id, 'realm_id' => $realm]);
            }
            $expected[$name] = [$id, $decision];
        }

        return $expected;
    }

    /**
     * @param array<string, array{int, string}> $cases
     * @param callable(int): void $authorize
     * @return array<string, array{int, string}>
     */
    public static function decisions(array $cases, callable $authorize): array
    {
        $seen = [];
        foreach ($cases as $name => [$id]) {
            try {
                $authorize($id);
                $seen[$name] = [$id, 'admitted'];
            } catch (AlertingAccessDenied $denied) {
                // Reached the account or realm check, not the missing-actor branch.
                self::assertFalse($denied->unauthenticated, $name);
                $seen[$name] = [$id, 'denied'];
            }
        }

        return $seen;
    }
}
