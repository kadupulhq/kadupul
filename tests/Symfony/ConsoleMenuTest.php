<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\RealmGrants;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuthenticatedSession;
use Kadupul\IdentityAccess\Infrastructure\Legacy\ReadOnlyDatabaseSessionHandler;
use Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession;
use Kadupul\Navigation\Infrastructure\Legacy\LegacyConsoleMenu;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\IdentityTranslator;

final class ConsoleMenuTest extends TestCase
{
    public function testDefinitionsMatchTheLegacyMenusAndRealms(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/include/global_arrays.php');
        $start = strpos($source, "if (\$config['poller_id'] == 1 || \$config['connection'] == 'online') {");
        self::assertNotFalse($start);
        $else = strpos($source, '} else {', $start);
        $end = strpos($source, "\n}\n", $else);
        self::assertNotFalse($else);
        self::assertNotFalse($end);
        $realmStart = strpos($source, '$user_auth_realm_filenames = array(');
        self::assertNotFalse($realmStart);
        $realmBlock = substr($source, $realmStart, strpos($source, ');', $realmStart) - $realmStart);
        preg_match_all("/'([^']+)'\\s*=>\\s*(-?\\d+)/", $realmBlock, $pairs, PREG_SET_ORDER);
        $realms = [];
        foreach ($pairs as [, $file, $realm]) {
            $realms[$file] = (int) $realm;
        }

        foreach ([
            'online' => [substr($source, $start, $else - $start), LegacyConsoleMenu::SECTIONS],
            'offline' => [substr($source, $else, $end - $else), LegacyConsoleMenu::OFFLINE_SECTIONS],
        ] as $branch => [$block, $definition]) {
            $legacy = [];
            $section = null;
            foreach (preg_split('/\R/', $block) as $line) {
                if (preg_match("/^\\s*__\\('([^']+)'\\)\\s*=>\\s*array\\(/", $line, $match)) {
                    $section = $match[1];
                } elseif ($section !== null && preg_match("/^\\s*'([^']+)'\\s*=>\\s*__\\('([^']+)'\\)/", $line, $match)) {
                    $legacy[$section][$match[1]] = $match[2];
                }
            }
            $mirrored = [];
            foreach ($definition as $name => $items) {
                foreach ($items as $path => [$label, $realm]) {
                    $mirrored[$name][$path] = $label;
                    self::assertArrayHasKey($path, $realms, $path . ' has no legacy realm');
                    self::assertSame($realms[$path], $realm, $path);
                    self::assertTrue(LegacyConsoleMenu::isAppPath($path), $path);
                }
            }
            self::assertNotSame([], $legacy, $branch);
            self::assertSame($legacy, $mirrored, $branch);
        }
    }

    public function testCollectorWithoutAnOnlinePrimaryGetsTheOfflineMenu(): void
    {
        $configuration = self::createStub(LegacyConfiguration::class);
        $configuration->method('values')->willThrowException(new \RuntimeException('Collector recovery is pending.'));
        $menu = self::menu(new Actor(7, 'operator'), [3, 8, 14, 15, 10003], $configuration);

        self::assertSame([
            ['label' => 'Management', 'items' => [['label' => 'Devices', 'path' => 'app.php/inventory/devices']]],
            ['label' => 'Data Collection', 'items' => [['label' => 'Data Collectors', 'path' => 'pollers.php']]],
            ['label' => 'Configuration', 'items' => [['label' => 'Settings', 'path' => 'settings.php']]],
            ['label' => 'Utilities', 'items' => [['label' => 'System Utilities', 'path' => 'utilities.php']]],
            ['label' => 'Operator <b>tools</b>', 'items' => [['label' => 'Granted "link"', 'path' => 'link.php?id=3']]],
        ], $menu->sections());
    }

    public function testSectionsFollowTheSignedInUsersRealms(): void
    {
        $menu = self::menu(new Actor(7, 'operator'), [8, 14, 10003, 10005, 10006]);

        self::assertSame([
            ['label' => 'Main Console', 'items' => [['label' => 'Console Page', 'path' => 'index.php']]],
            ['label' => 'Presets', 'items' => [['label' => 'CDEFs', 'path' => 'cdef.php'], ['label' => 'VDEFs', 'path' => 'vdef.php']]],
            ['label' => 'Operator <b>tools</b>', 'items' => [['label' => 'Granted "link"', 'path' => 'link.php?id=3']]],
        ], $menu->sections());
    }

    public function testRealmWithdrawalHidesItsItems(): void
    {
        $paths = [];
        foreach (self::menu(new Actor(7, 'operator'), [8])->sections() as $section) {
            foreach ($section['items'] as $item) {
                $paths[] = $item['path'];
            }
        }

        self::assertSame(['index.php'], $paths);
    }

    public function testNoConsoleActorOrRealmSourceMeansNoMenu(): void
    {
        self::assertSame([], self::menu(null, [8, 14])->sections());

        $consoleOnly = new class implements ConsoleAccess {
            public function consoleActor(): ?Actor
            {
                return new Actor(7, 'operator');
            }

            public function canManageDevices(Actor $actor): bool
            {
                return true;
            }
        };
        $menu = new LegacyConsoleMenu($consoleOnly, self::online(), self::database(), new IdentityTranslator());
        self::assertSame([], $menu->sections());
    }

    public function testUnavailableGrantsHideTheMenu(): void
    {
        $session = new class implements ConsoleAccess, RealmGrants {
            public function consoleActor(): ?Actor
            {
                return new Actor(7, 'operator');
            }

            public function canManageDevices(Actor $actor): bool
            {
                return false;
            }

            public function grantedRealms(Actor $actor, array $realmIds): array
            {
                throw new \PDOException('gone');
            }
        };
        $menu = new LegacyConsoleMenu($session, self::online(), self::database(), new IdentityTranslator());

        self::assertSame([], $menu->sections());
    }

    public function testSessionAdapterReportsDirectAndEnabledGroupGrants(): void
    {
        $database = self::database();
        $pdo = $database->get();
        $pdo->exec('CREATE TABLE user_auth_realm (realm_id INTEGER, user_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
        $pdo->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        $pdo->exec("INSERT INTO user_auth_realm VALUES (8, 7), (15, 9)");
        $pdo->exec("INSERT INTO user_auth_group VALUES (1, 'on'), (2, '')");
        $pdo->exec('INSERT INTO user_auth_group_members VALUES (1, 7), (2, 7)');
        $pdo->exec('INSERT INTO user_auth_group_realm VALUES (1, 14), (2, 1)');
        $session = new LegacyAuthenticatedSession(
            new SharedSession(new RequestStack(), self::createStub(LegacyConfiguration::class), new ReadOnlyDatabaseSessionHandler($database), $database),
            $database,
        );

        self::assertSame([8, 14], $session->grantedRealms(new Actor(7, 'operator'), [8, 14, 14, 15, 1]));
        self::assertSame([], $session->grantedRealms(new Actor(0, 'nobody'), [8]));
    }

    #[DataProvider('paths')]
    public function testOnlyRelativeApplicationPathsAreAccepted(string $path, bool $accepted): void
    {
        self::assertSame($accepted, LegacyConsoleMenu::isAppPath($path));
    }

    public static function paths(): iterable
    {
        yield 'script' => ['host.php', true];
        yield 'route' => ['app.php/inventory/devices/new', true];
        yield 'link' => ['link.php?id=3', true];
        yield 'absolute URL' => ['https://example.com/host.php', false];
        yield 'protocol relative' => ['//example.com/host.php', false];
        yield 'root relative' => ['/host.php', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'javascript script name' => ['javascript:alert(1)//x.php', false];
        yield 'data' => ['data:text/html,x.php', false];
        yield 'traversal' => ['../host.php', false];
        yield 'route traversal' => ['app.php/../host.php', false];
        yield 'backslash' => ['\\\\example.com\\host.php', false];
        yield 'newline' => ["host.php\n", false];
        yield 'second parameter' => ['link.php?id=3&next=https://example.com', false];
        yield 'fragment' => ['host.php#x', false];
        yield 'empty' => ['', false];
    }

    /** @param list<int> $granted */
    private static function menu(?Actor $actor, array $granted, ?LegacyConfiguration $configuration = null): LegacyConsoleMenu
    {
        $session = new class ($actor, $granted) implements ConsoleAccess, RealmGrants {
            /** @param list<int> $granted */
            public function __construct(private ?Actor $actor, private array $granted) {}

            public function consoleActor(): ?Actor
            {
                return $this->actor;
            }

            public function canManageDevices(Actor $actor): bool
            {
                return false;
            }

            public function grantedRealms(Actor $actor, array $realmIds): array
            {
                return array_values(array_intersect($realmIds, $this->granted));
            }
        };

        return new LegacyConsoleMenu($session, $configuration ?? self::online(), self::database(), new IdentityTranslator());
    }

    private static function online(): LegacyConfiguration
    {
        return new class implements LegacyConfiguration {
            public function values(): array
            {
                return ['collector_id' => 1];
            }
        };
    }

    private static function database(): DatabaseConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE external_links (id INTEGER, sortorder INTEGER, title TEXT, contentfile TEXT, style TEXT, extendedstyle TEXT, enabled TEXT)');
        $insert = $pdo->prepare('INSERT INTO external_links VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([3, 1, 'Granted "link"', 'a.html', 'CONSOLE', 'Operator <b>tools</b>', 'on']);
        $insert->execute([4, 2, 'Not granted', 'b.html', 'CONSOLE', 'Operator <b>tools</b>', 'on']);
        $insert->execute([5, 3, 'Disabled', 'c.html', 'CONSOLE', '', '']);
        $insert->execute([6, 4, 'Tab', 'd.html', 'TAB', '', 'on']);

        return new class ($pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
    }
}
