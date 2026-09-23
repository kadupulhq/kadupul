<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Inventory\Application\ReadModel\DeviceDetails;
use Kadupul\Inventory\Application\ReadModel\DeviceSite;
use Kadupul\Inventory\Application\ReadModel\DeviceSummary;
use Kadupul\Inventory\Domain\SiteListCriteria;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Inventory\Infrastructure\Persistence\DoctrineDeviceDetailsReader;
use Kadupul\Inventory\Infrastructure\Persistence\DoctrineDeviceSites;
use Kadupul\Inventory\Infrastructure\Persistence\DoctrineDeviceVisibility;
use Kadupul\Inventory\Infrastructure\Persistence\DoctrineSiteCatalog;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DoctrineDeviceVisibilityTest extends TestCase
{
    private const SCHEMA = [
        'CREATE TABLE settings (name TEXT, value TEXT)',
        'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, policy_graphs INTEGER, policy_hosts INTEGER, policy_graph_templates INTEGER)',
        'CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT, policy_graphs INTEGER, policy_hosts INTEGER, policy_graph_templates INTEGER)',
        'CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)',
        'CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER)',
        'CREATE TABLE user_auth_group_perms (group_id INTEGER, item_id INTEGER, type INTEGER)',
        'CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT, city TEXT, state TEXT, country TEXT)',
        'CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT, hostname TEXT, disabled TEXT, status INTEGER, location TEXT, external_id TEXT, notes TEXT, site_id INTEGER, deleted TEXT)',
        'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER)',
    ];

    // User 1 sees everything, 2 relies on exceptions, 3 must see nothing, 5 is
    // group-only and 99 has no policy. Group 21 is disabled and would grant
    // every device if its policy leaked into the predicate.
    private const DEVICES = [
        "INSERT INTO sites VALUES (0, 'Console', '', '', ''), (1, 'Alpha', 'Oslo', 'Oslo', 'Norway'), (2, 'Bravo', NULL, NULL, NULL), (3, 'Charlie', 'Lima', '', 'Peru'), (4, 'Delta', '', '', ''), (5, 'alpha', 'Bergen', '', 'Norway')",
        "INSERT INTO host VALUES (1, 'host-allowed', '10.0.0.1', '', 3, 'rack 1', 'ext-1', 'note 1', 1, ''),"
            . " (2, 'host-denied', '10.0.0.2', 'on', 1, '', '', 'secret note', 2, ''),"
            . " (3, 'graph-only', '10.0.0.3', '', 2, '', '', '', 3, ''),"
            . " (4, 'deleted', '10.0.0.4', '', 3, '', '', '', 1, 'on'),"
            . " (5, 'disabled-group-only', '10.0.0.5', '', 3, '', '', 'hidden', 2, ''),"
            . " (6, 'template-only', '10.0.0.6', '', 4, '', '', '', 3, ''),"
            . " (7, 'no-site', '10.0.0.7', '', 0, '', '', '', 9, ''),"
            . " (8, 'no-graphs', '10.0.0.8', '', 3, '', '', '', 5, ''),"
            . " (0, 'zero', '0', '', 3, '', '', '', 1, '')",
        'INSERT INTO graph_local VALUES (10, 1, 100), (11, 2, 101), (12, 3, 102), (13, 5, 103), (14, 6, 104), (15, 6, 104), (16, 6, 105), (17, 4, 100), (18, 7, 100)',
        'INSERT INTO user_auth VALUES (1, 1, 1, 1), (2, 2, 2, 2), (3, 2, 2, 2)',
        'INSERT INTO user_auth_perms VALUES (2, 1, 3), (2, 12, 1), (2, 104, 4), (2, 7, 3)',
        "INSERT INTO user_auth_group VALUES (20, 'on', 2, 2, 2), (21, '', 1, 1, 1), (22, 'on', 2, 2, 2)",
        'INSERT INTO user_auth_group_members VALUES (20, 2), (21, 2), (21, 3), (22, 5), (21, 5)',
        'INSERT INTO user_auth_group_perms VALUES (20, 8, 3), (21, 5, 3), (22, 3, 3), (22, 16, 1)',
    ];

    public static function policyShapes(): iterable
    {
        foreach ([1, 2, 3, 4] as $mode) {
            foreach (['none', 'user', 'group', 'user and group'] as $shape) {
                yield "mode $mode, $shape" => [$mode, $shape];
            }
        }
    }

    #[DataProvider('policyShapes')]
    public function testDoctrinePredicateMatchesThePdoReadPredicate(int $mode, string $shape): void
    {
        $defaults = [];
        foreach ([1, 2] as $graphs) {
            foreach ([1, 2] as $hosts) {
                foreach ([1, 2] as $templates) {
                    $defaults[] = [$graphs, $hosts, $templates];
                }
            }
        }
        $userDefaults = str_contains($shape, 'user') ? $defaults : [null];
        $groupDefaults = str_contains($shape, 'group') ? $defaults : [null];
        foreach ($userDefaults as $user) {
            foreach ($groupDefaults as $group) {
                $fixture = ["INSERT INTO settings VALUES ('graph_auth_method', '$mode')"];
                if ($user !== null) {
                    $fixture[] = 'INSERT INTO user_auth VALUES (7, ' . implode(', ', $user) . ')';
                }
                if ($group !== null) {
                    $fixture[] = "INSERT INTO user_auth_group VALUES (30, 'on', " . implode(', ', $group) . ')';
                    $fixture[] = 'INSERT INTO user_auth_group_members VALUES (30, 7)';
                }
                $fixture[] = "INSERT INTO user_auth_group VALUES (31, '', 1, 1, 1)";
                $fixture[] = 'INSERT INTO user_auth_group_members VALUES (31, 7)';
                $fixture[] = 'INSERT INTO user_auth_perms VALUES (7, 11, 1), (7, 12, 3), (7, 13, 4)';
                $fixture[] = 'INSERT INTO user_auth_group_perms VALUES (30, 21, 1), (30, 23, 4), (31, 99, 3)';
                [$pdo, $dbal] = $this->databases($fixture);

                $expected = (new LegacyDeviceVisibility($this->connection($pdo)))->predicate(7);
                $message = json_encode(['user' => $user, 'group' => $group]);
                self::assertSame($expected, (new DoctrineDeviceVisibility($dbal))->predicate(7), $message);
                self::assertStringNotContainsString('group_id = 31', $expected, $message);
                self::assertSame(($user !== null) + ($group !== null), substr_count($expected, 'type = 1'), $message);
                if ($shape === 'none') {
                    self::assertSame('1 = 0', $expected);
                }
            }
        }
    }

    public function testMissingModeFallsBackToTheFirstLegacyMode(): void
    {
        [$pdo, $dbal] = $this->databases(['INSERT INTO user_auth VALUES (7, 2, 2, 1)']);

        $expected = (new LegacyDeviceVisibility($this->connection($pdo)))->predicate(7);
        self::assertStringStartsWith('(gl.id IN (', $expected);
        self::assertStringContainsString(' OR gl.graph_template_id NOT IN (', $expected);
        self::assertSame($expected, (new DoctrineDeviceVisibility($dbal))->predicate(7));
    }

    public static function unsupportedPolicies(): iterable
    {
        yield 'mode 0' => [["INSERT INTO settings VALUES ('graph_auth_method', '0')", 'INSERT INTO user_auth VALUES (7, 1, 1, 1)']];
        yield 'mode 5' => [["INSERT INTO settings VALUES ('graph_auth_method', '5')"]];
        yield 'user graph default' => [['INSERT INTO user_auth VALUES (7, 0, 1, 1)']];
        yield 'user device default' => [['INSERT INTO user_auth VALUES (7, 1, 3, 1)']];
        yield 'group template default' => [["INSERT INTO user_auth_group VALUES (30, 'on', 2, 2, 5)", 'INSERT INTO user_auth_group_members VALUES (30, 7)']];
    }

    #[DataProvider('unsupportedPolicies')]
    public function testUnsupportedPoliciesStillFailClosed(array $fixture): void
    {
        [$pdo, $dbal] = $this->databases($fixture);
        foreach ([fn() => (new LegacyDeviceVisibility($this->connection($pdo)))->predicate(7), fn() => (new DoctrineDeviceVisibility($dbal))->predicate(7)] as $predicate) {
            try {
                $predicate();
                self::fail('An unsupported visibility policy produced a predicate.');
            } catch (\RuntimeException $error) {
                self::assertSame('Unsupported visibility policy.', $error->getMessage());
            }
        }
    }

    public function testLockedPredicateReadsAndMaterializesCurrentRowsInOrder(): void
    {
        $pdo = new class ('sqlite::memory:') extends \PDO {
            public array $statements = [];

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->statements[] = $query;

                return parent::query(str_replace(' LOCK IN SHARE MODE', '', $query));
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->statements[] = $query;

                return parent::prepare(str_replace(' LOCK IN SHARE MODE', '', $query), $options);
            }
        };
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        foreach (array_merge(self::SCHEMA, [
            "INSERT INTO settings VALUES ('graph_auth_method', '2')",
            'INSERT INTO user_auth VALUES (7, 2, 2, 2)',
            "INSERT INTO user_auth_group VALUES (30, 'on', 1, 2, 1)",
            'INSERT INTO user_auth_group_members VALUES (30, 7)',
            'INSERT INTO user_auth_perms VALUES (7, 11, 1), (7, 13, 4), (7, 12, 4)',
            'INSERT INTO user_auth_group_perms VALUES (30, 21, 1)',
        ]) as $statement) {
            $pdo->exec($statement);
        }
        $visibility = new LegacyDeviceVisibility($this->connection($pdo));
        try {
            $visibility->predicate(7, true);
            self::fail('A locked predicate ran outside a transaction.');
        } catch (\LogicException $error) {
            self::assertSame('Visibility locks require a transaction.', $error->getMessage());
        }
        self::assertSame([], $pdo->statements);

        $pdo->beginTransaction();
        $predicate = $visibility->predicate(7, true);
        $pdo->rollBack();

        self::assertSame('(gl.id IN (11) OR (1 = 0 AND gl.graph_template_id IN (13,12))) OR (gl.id NOT IN (21) OR (1 = 0 AND 1 = 1))', $predicate);
        self::assertSame([
            "SELECT value FROM settings WHERE name = 'graph_auth_method' LOCK IN SHARE MODE",
            "SELECT id, 'user' AS kind, policy_graphs, policy_hosts, policy_graph_templates FROM user_auth WHERE id = ? LOCK IN SHARE MODE",
            "SELECT g.id, 'group' AS kind, g.policy_graphs, g.policy_hosts, g.policy_graph_templates\n            FROM user_auth_group g JOIN user_auth_group_members m ON m.group_id = g.id WHERE m.user_id = ? AND g.enabled = 'on' LOCK IN SHARE MODE",
            'SELECT item_id FROM user_auth_perms WHERE user_id = 7 AND type = 1 LOCK IN SHARE MODE',
            'SELECT item_id FROM user_auth_perms WHERE user_id = 7 AND type = 3 LOCK IN SHARE MODE',
            'SELECT item_id FROM user_auth_perms WHERE user_id = 7 AND type = 4 LOCK IN SHARE MODE',
            'SELECT item_id FROM user_auth_group_perms WHERE group_id = 30 AND type = 1 LOCK IN SHARE MODE',
            'SELECT item_id FROM user_auth_group_perms WHERE group_id = 30 AND type = 3 LOCK IN SHARE MODE',
            'SELECT item_id FROM user_auth_group_perms WHERE group_id = 30 AND type = 4 LOCK IN SHARE MODE',
        ], $pdo->statements);
    }

    public static function visibleDevices(): iterable
    {
        yield 'mode 1, exceptions' => [1, 2, [1 => 'Alpha', 3 => 'Charlie', 5 => 'alpha'], [1, 3, 6, 7, 8], [1 => 1, 2 => 0, 3 => 2, 4 => 0, 5 => 1]];
        yield 'mode 2, exceptions' => [2, 2, [3 => 'Charlie'], [3], [1 => 0, 2 => 0, 3 => 1, 4 => 0, 5 => 0]];
        yield 'mode 3, exceptions' => [3, 2, [1 => 'Alpha', 3 => 'Charlie', 5 => 'alpha'], [1, 3, 7, 8], [1 => 1, 2 => 0, 3 => 1, 4 => 0, 5 => 1]];
        yield 'mode 4, exceptions' => [4, 2, [3 => 'Charlie'], [3, 6], [1 => 0, 2 => 0, 3 => 2, 4 => 0, 5 => 0]];
        yield 'mode 1, group only' => [1, 5, [3 => 'Charlie'], [3, 6], [1 => 0, 2 => 0, 3 => 2, 4 => 0, 5 => 0]];
        yield 'mode 2, group only' => [2, 5, [3 => 'Charlie'], [6], [1 => 0, 2 => 0, 3 => 1, 4 => 0, 5 => 0]];
        yield 'mode 1, unrestricted' => [1, 1, [1 => 'Alpha', 2 => 'Bravo', 3 => 'Charlie', 5 => 'alpha'], [1, 2, 3, 5, 6, 7, 8], [1 => 1, 2 => 2, 3 => 2, 4 => 0, 5 => 1]];
        foreach ([1, 2, 3, 4] as $mode) {
            yield "mode $mode, denied" => [$mode, 3, [], [], [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]];
            yield "mode $mode, no policy" => [$mode, 99, [], [], [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]];
        }
    }

    #[DataProvider('visibleDevices')]
    public function testAdaptersReturnOnlyVisibleDevices(int $mode, int $user, array $sites, array $devices, array $counts): void
    {
        [, $database] = $this->databases(array_merge(["INSERT INTO settings VALUES ('graph_auth_method', '$mode')"], self::DEVICES));
        $visibility = new DoctrineDeviceVisibility($database);

        $visibleSites = [];
        foreach ((new DoctrineDeviceSites($database, $visibility))->visibleTo($user) as $site) {
            self::assertInstanceOf(DeviceSite::class, $site);
            $visibleSites[$site->id] = $site->name;
        }
        self::assertSame($sites, $visibleSites);

        $reader = new DoctrineDeviceDetailsReader($database, $visibility);
        $found = [];
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 42] as $id) {
            $details = $reader->findVisible($user, $id);
            if ($details !== null) {
                self::assertSame($id, $details->device->id);
                $found[] = $id;
            }
        }
        self::assertSame($devices, $found);

        $visibleCounts = [];
        foreach ((new DoctrineSiteCatalog($database, $visibility))->listFor($user, new SiteListCriteria())->sites as $site) {
            $visibleCounts[$site->id] = $site->visibleDevices;
        }
        ksort($visibleCounts);
        self::assertSame($counts, $visibleCounts);
    }

    public function testDetailsAndCatalogKeepTheirProjections(): void
    {
        [, $database] = $this->databases(array_merge(["INSERT INTO settings VALUES ('graph_auth_method', '1')"], self::DEVICES));
        $visibility = new DoctrineDeviceVisibility($database);
        $reader = new DoctrineDeviceDetailsReader($database, $visibility);

        self::assertEquals(new DeviceDetails(new DeviceSummary(1, 'host-allowed', '10.0.0.1', false, 'Up', 'rack 1', 'ext-1'), 'note 1', 1, 'Alpha'), $reader->findVisible(2, 1));
        self::assertEquals(new DeviceDetails(new DeviceSummary(7, 'no-site', '10.0.0.7', false, 'Unknown', '', ''), '', 9, null), $reader->findVisible(2, 7));
        self::assertNull($reader->findVisible(2, 2));
        self::assertNull($reader->findVisible(2, 5));

        $catalog = new DoctrineSiteCatalog($database, $visibility);
        $page = $catalog->listFor(1, new SiteListCriteria('al', 1, 25, 'desc', 'devices'));
        self::assertSame([[5, 'alpha', 'Bergen', '', 'Norway', 1], [1, 'Alpha', 'Oslo', 'Oslo', 'Norway', 1]], array_map(static fn($site): array => [$site->id, $site->name, $site->city, $site->state, $site->country, $site->visibleDevices], $page->sites));
        self::assertFalse($page->hasNext);
        self::assertSame([[1, 1], [2, 2], [3, 2], [5, 1]], array_map(static fn($site): array => [$site->id, $site->visibleDevices], $catalog->listFor(1, new SiteListCriteria('r'))->sites));
        $bravo = $catalog->listFor(1, new SiteListCriteria('Bravo'))->sites[0];
        self::assertSame(['', '', ''], [$bravo->city, $bravo->state, $bravo->country]);
        self::assertSame([], $catalog->listFor(1, new SiteListCriteria('a', 2))->sites);
    }

    private function databases(array $fixture): array
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (array_merge(self::SCHEMA, $fixture) as $statement) {
            $pdo->exec($statement);
            $dbal->executeStatement($statement);
        }

        return [$pdo, $dbal];
    }

    private function connection(\PDO $pdo): DatabaseConnection
    {
        return new readonly class ($pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
    }
}
