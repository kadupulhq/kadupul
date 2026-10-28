<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\BrowserSession;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\IdentityAccess\Contract\RealmGrants;
use Kadupul\Navigation\Infrastructure\Legacy\LegacyConsoleMenu;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Contract\PluginConsoleMenu;
use Kadupul\Platform\Infrastructure\Legacy\ConsoleMenuRuntime;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\LegacyBrowserWorker;
use Kadupul\Platform\Infrastructure\Legacy\LegacyPluginConsoleMenu;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Plugin console entries reach Symfony pages through the real worker launcher.
 * The stand-in worker replaces only the legacy bootstrap: it runs the fixture
 * plugin's config_arrays hook against a $menu and realm map shaped like the
 * ones include/global_arrays.php builds, then answers through ConsoleMenuRuntime.
 */
final class PluginMenuParityTest extends TestCase
{
    private const array PLUGIN_REALMS = ['parity.php' => 150, 'parity_tools.php' => 151];

    private string $root;

    private \PDO $pdo;

    private ArrayAdapter $cache;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/plugin-menu-worker-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/include', 0700, true);
        mkdir($this->root . '/bin', 0700);
        file_put_contents($this->root . '/include/config.php', '<?php $url_path = "/kadupul/";');
        file_put_contents($this->root . '/include/cacti_version', '1.3.0');
        $worker = <<<'PHP'
            <?php
            $root = dirname(__DIR__);
            $raw = stream_get_contents(STDIN);
            file_put_contents($root . '/handoff.jsonl', $raw . "\n", FILE_APPEND);
            if (is_file($root . '/slow')) {
                sleep(5);
            }
            if (is_file($root . '/fail')) {
                fwrite(STDERR, '{"phase":"bootstrap","class":"RuntimeException","file":"auth.php","line":1}');
                echo '{"status":"failed"}';
                exit(1);
            }
            if (is_file($root . '/result.json')) {
                echo file_get_contents($root . '/result.json');
                exit(0);
            }
            require AUTOLOAD;
            require FIXTURE;
            function __($text) { return $text; }
            $command = json_decode($raw, true);
            $menu = ['Main Console' => ['index.php' => 'Console Page'], 'Management' => ['host.php' => 'Devices']];
            $user_auth_realm_filenames = ['index.php' => 8, 'host.php' => 3] + REALMS;
            menu_parity_config_arrays();
            $result = (new \Kadupul\Platform\Infrastructure\Legacy\ConsoleMenuRuntime())->capture($menu, $user_auth_realm_filenames, $command['sections'], __(...));
            echo json_encode(['status' => 'ok', 'actor' => $command['actor']] + $result);
            PHP;
        file_put_contents($this->root . '/bin/legacy-console-menu.php', strtr($worker, [
            'AUTOLOAD' => var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true),
            'FIXTURE' => var_export(dirname(__DIR__) . '/Fixtures/plugins/menu_parity/setup.php', true),
            'REALMS' => var_export(self::PLUGIN_REALMS, true),
        ]));

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE version (cacti TEXT); INSERT INTO version VALUES ('1.3.0')");
        $this->pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $this->pdo->prepare("INSERT INTO settings VALUES ('path_php_binary', ?)")->execute([PHP_BINARY]);
        $this->pdo->exec('CREATE TABLE external_links (id INTEGER, sortorder INTEGER, title TEXT, contentfile TEXT, style TEXT, extendedstyle TEXT, enabled TEXT)');
        $this->pdo->exec('CREATE TABLE plugin_hooks (id INTEGER, name TEXT, hook TEXT, file TEXT, function TEXT, status INTEGER)');
        $this->pdo->exec("INSERT INTO plugin_hooks VALUES (1, 'internal', 'config_arrays', '', 'plugin_config_arrays', 1)");
        $this->pdo->exec("INSERT INTO plugin_hooks VALUES (2, 'menu_parity', 'config_arrays', 'setup.php', 'menu_parity_config_arrays', 1)");
        $this->pdo->exec('CREATE TABLE plugin_config (id INTEGER, directory TEXT, name TEXT, status INTEGER, version TEXT)');
        $this->pdo->exec("INSERT INTO plugin_config VALUES (1, 'menu_parity', 'Console Menu Parity Fixture', 1, '1.0.0')");
        $this->pdo->exec('CREATE TABLE plugin_realms (id INTEGER, plugin TEXT, file TEXT, display TEXT)');
        $this->pdo->exec("INSERT INTO plugin_realms VALUES (50, 'menu_parity', 'parity.php,parity_tools.php', 'Menu Parity')");
        $this->pdo->exec('CREATE TABLE user_auth_realm (realm_id INTEGER, user_id INTEGER)');
        $this->pdo->exec('INSERT INTO user_auth_realm VALUES (8, 7), (150, 7)');
        $this->pdo->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
        $this->pdo->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $this->pdo->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        $this->clock = new MockClock();
        $this->cache = new ArrayAdapter(clock: $this->clock);
    }

    protected function tearDown(): void
    {
        foreach (['include/config.php', 'include/cacti_version', 'bin/legacy-console-menu.php', 'handoff.jsonl', 'result.json', 'fail', 'slow'] as $file) {
            if (is_file($this->root . '/' . $file)) {
                unlink($this->root . '/' . $file);
            }
        }
        rmdir($this->root . '/include');
        rmdir($this->root . '/bin');
        rmdir($this->root);
    }

    public function testPluginEntryAppearsForAUserWithItsRealm(): void
    {
        $sections = $this->menu([8, 150, 151])->sections();

        self::assertSame([
            ['label' => 'Main Console', 'items' => [['label' => 'Console Page', 'path' => 'index.php']]],
            ['label' => 'Management', 'items' => [['label' => 'Parity <Checks>', 'path' => 'plugins/menu_parity/parity.php']]],
            ['label' => 'Parity Tools', 'items' => [['label' => 'Parity Tools', 'path' => 'plugins/menu_parity/parity_tools.php?action=list']]],
        ], $sections);
    }

    public function testPluginEntryIsAbsentWithoutItsRealm(): void
    {
        $paths = self::paths($this->menu([8])->sections());

        self::assertSame(['index.php'], $paths);
    }

    public function testAbsoluteAndExternalPluginUrlsAreDropped(): void
    {
        $paths = self::paths($this->menu([8, 150, 151])->sections());

        foreach ($paths as $path) {
            self::assertStringNotContainsString('://', $path);
            self::assertStringNotContainsString('EXTERNAL', $path);
        }
        self::assertNotContains('plugins/menu_parity/unregistered.php', $paths);
    }

    public function testFailingWorkerYieldsTheStaticMenuAndAWarning(): void
    {
        touch($this->root . '/fail');
        $logger = new BufferingLogger();

        $sections = $this->menu([8, 150, 151], $logger)->sections();

        self::assertSame(['index.php'], self::paths($sections));
        $levels = array_column($logger->cleanLogs(), 0);
        self::assertContains('warning', $levels);
    }

    public function testOnePageViewStartsAtMostOneWorker(): void
    {
        $menu = $this->menu([8, 150, 151]);
        $menu->sections();
        $menu->sections();

        self::assertSame(1, $this->workerRuns());
    }

    public function testLaterPageViewsReuseTheCachedEntries(): void
    {
        $first = $this->menu([8, 150, 151])->sections();
        $second = $this->menu([8, 150, 151])->sections();
        $posted = $this->menu([8, 150, 151], null, self::request('POST'))->sections();

        self::assertSame(1, $this->workerRuns());
        self::assertSame($first, $second);
        self::assertSame($first, $posted);
    }

    public function testPluginRealmOrLocaleChangesMissTheCache(): void
    {
        $this->menu([8])->sections();
        $this->pdo->exec("UPDATE plugin_config SET version = '1.0.1'");
        $this->menu([8])->sections();
        $this->pdo->exec("UPDATE plugin_config SET status = 4");
        $this->menu([8])->sections();
        $this->pdo->exec("INSERT INTO plugin_realms VALUES (52, 'menu_parity', 'parity_admin.php', 'Admin')");
        $this->menu([8])->sections();
        self::assertSame(4, $this->workerRuns());

        $this->pdo->exec('INSERT INTO user_auth_realm VALUES (151, 7)');
        $this->menu([8])->sections();
        $this->pdo->exec("INSERT INTO user_auth_group VALUES (1, 'on')");
        $this->pdo->exec('INSERT INTO user_auth_group_members VALUES (1, 7)');
        $this->pdo->exec('INSERT INTO user_auth_group_realm VALUES (1, 15)');
        $this->menu([8])->sections();
        self::assertSame(6, $this->workerRuns());

        $french = self::request();
        $french->setLocale('fr');
        $this->menu([8], null, $french)->sections();
        $this->menu([8], null, null, 'de_DE')->sections();
        self::assertSame(8, $this->workerRuns());

        $this->menu([8], null, null, 'de_DE')->sections();
        self::assertSame(8, $this->workerRuns());
    }

    public function testUsersWithIdenticalRealmsDoNotShareEntries(): void
    {
        $this->pdo->exec('INSERT INTO user_auth_realm VALUES (8, 8), (150, 8)');

        $this->menu([8, 150], null, null, null, 3.0, 7)->sections();
        $this->menu([8, 150], null, null, null, 3.0, 8)->sections();
        $this->menu([8, 150], null, null, null, 3.0, 7)->sections();

        $actors = array_map(static fn(string $line): int => json_decode($line, true, 16, JSON_THROW_ON_ERROR)['actor'], file($this->root . '/handoff.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        self::assertSame([7, 8], $actors);
    }

    public function testMenuHandoffCarriesNoClientChosenRequestInput(): void
    {
        $request = Request::create('https://example.test/kadupul/app.php/x?tab=other&plugin=switch', 'GET', [], [
            'Cacti' => 'abcdefghijklmnopqrstuvwxyz012345', 'plugin_preference' => 'other', 'cacti_remembers' => 'token',
        ], [], [
            'HTTP_REFERER' => 'https://example.test/kadupul/host.php', 'HTTP_ORIGIN' => 'https://example.test',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT_LANGUAGE' => 'fr',
        ]);

        $this->menu([8], null, $request)->sections();

        $handoff = json_decode((string) file_get_contents($this->root . '/handoff.jsonl'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $handoff['query']);
        self::assertSame(['Cacti' => 'abcdefghijklmnopqrstuvwxyz012345'], $handoff['cookies']);
        foreach (['QUERY_STRING', 'HTTP_REFERER', 'HTTP_ORIGIN', 'HTTP_X_REQUESTED_WITH'] as $header) {
            self::assertArrayNotHasKey($header, $handoff['server']);
        }
        self::assertSame('fr', $handoff['server']['HTTP_ACCEPT_LANGUAGE']);
    }

    public function testDisabledThenReenabledPluginRunsTheWorkerAgain(): void
    {
        $this->menu([8])->sections();
        $this->pdo->exec("UPDATE plugin_hooks SET status = 0 WHERE name = 'menu_parity'");
        $this->menu([8])->sections();
        self::assertSame(1, $this->workerRuns());

        $this->pdo->exec("UPDATE plugin_hooks SET status = 1, function = 'menu_parity_config_arrays_v2' WHERE name = 'menu_parity'");
        $this->menu([8])->sections();
        self::assertSame(2, $this->workerRuns());
    }

    public function testAFailureIsRememberedForAMinuteThenRetried(): void
    {
        touch($this->root . '/fail');
        $logger = new BufferingLogger();
        $this->menu([8], $logger)->sections();
        unlink($this->root . '/fail');
        $paths = self::paths($this->menu([8, 150, 151], $logger)->sections());

        self::assertSame(1, $this->workerRuns());
        self::assertSame(['index.php'], $paths);
        self::assertSame(['warning', 'warning'], array_column($logger->cleanLogs(), 0));

        $this->clock->sleep(61);
        $paths = self::paths($this->menu([8, 150, 151])->sections());

        self::assertSame(2, $this->workerRuns());
        self::assertContains('plugins/menu_parity/parity.php', $paths);
    }

    public function testATimedOutWorkerIsAlsoRemembered(): void
    {
        touch($this->root . '/slow');
        $this->menu([8], null, null, null, 0.5)->sections();
        $started = microtime(true);
        $this->menu([8], null, null, null, 0.5)->sections();

        self::assertLessThan(0.4, microtime(true) - $started);
        self::assertSame(1, $this->workerRuns());
    }

    public function testInvalidWorkerOutputIsAlsoRemembered(): void
    {
        file_put_contents($this->root . '/result.json', '{"status":"ok","actor":7,"entries":{"a":1}}');
        foreach ([1, 2] as $attempt) {
            try {
                $this->plugins(self::request())->entries(7, ['Management']);
                self::fail('A malformed result must be refused.');
            } catch (\RuntimeException) {
            }
        }

        self::assertSame(1, $this->workerRuns());
    }

    public function testAcceptLanguageSpellingsOfOneLocaleShareAnEntry(): void
    {
        foreach (['fr', 'fr-CA,fr;q=0.9', 'xx-YY, fr-FR;q=0.8, en;q=0.1'] as $header) {
            $this->menu([8], null, Request::create('https://example.test/kadupul/app.php/x', server: ['HTTP_ACCEPT_LANGUAGE' => $header]))->sections();
        }
        self::assertSame(1, $this->workerRuns());

        $this->menu([8], null, Request::create('https://example.test/kadupul/app.php/x', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB']))->sections();
        self::assertSame(2, $this->workerRuns());
    }

    public function testSlowWorkerIsStoppedAndTheStaticMenuShown(): void
    {
        touch($this->root . '/slow');
        $logger = new BufferingLogger();
        $started = microtime(true);

        $sections = $this->menu([8, 150, 151], $logger, null, null, 0.5)->sections();

        self::assertLessThan(4.0, microtime(true) - $started);
        self::assertSame(['index.php'], self::paths($sections));
        self::assertContains('warning', array_column($logger->cleanLogs(), 0));
    }

    public function testHandoffNamesARealmFreePageAndTheKnownSections(): void
    {
        $this->menu([8])->sections();

        $handoff = json_decode((string) file_get_contents($this->root . '/handoff.jsonl'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('/kadupul/about.php', $handoff['page_path']);
        self::assertSame(7, $handoff['actor']);
        self::assertContains('Management', $handoff['sections']);
        self::assertContains('External Links', $handoff['sections']);
    }

    public function testNoEnabledPluginHookSkipsTheWorker(): void
    {
        $this->pdo->exec("UPDATE plugin_hooks SET status = 0 WHERE name = 'menu_parity'");

        $paths = self::paths($this->menu([8, 150, 151])->sections());

        self::assertFileDoesNotExist($this->root . '/handoff.jsonl');
        self::assertSame(['index.php'], $paths);
    }

    public function testMissingPluginTablesSkipTheWorker(): void
    {
        $this->pdo->exec('DROP TABLE plugin_realms');

        self::assertSame([], $this->plugins(self::request())->entries(7, ['Management']));
        self::assertFileDoesNotExist($this->root . '/handoff.jsonl');
    }

    public function testRequestsThatCannotHandOffTheSessionKeepTheStaticMenuQuietly(): void
    {
        $logger = new BufferingLogger();

        $paths = self::paths($this->menu([8, 150, 151], $logger, Request::create('https://example.test/kadupul/app.php/x', 'POST'))->sections());

        self::assertFileDoesNotExist($this->root . '/handoff.jsonl');
        self::assertSame(['index.php'], $paths);
        self::assertSame([], $logger->cleanLogs());
    }

    public static function malformed(): iterable
    {
        $entry = ['section' => 'Management', 'label' => 'Parity', 'path' => 'plugins/menu_parity/parity.php', 'realm' => 150];
        yield 'failed status' => [['status' => 'failed', 'actor' => 7, 'entries' => []]];
        yield 'other actor' => [['status' => 'ok', 'actor' => 8, 'entries' => []]];
        yield 'missing entries' => [['status' => 'ok', 'actor' => 7]];
        yield 'keyed entries' => [['status' => 'ok', 'actor' => 7, 'entries' => ['a' => $entry]]];
        yield 'extra field' => [['status' => 'ok', 'actor' => 7, 'entries' => [$entry + ['href' => 'x']]]];
        yield 'label type' => [['status' => 'ok', 'actor' => 7, 'entries' => [['label' => ['x']] + $entry]]];
        yield 'realm type' => [['status' => 'ok', 'actor' => 7, 'entries' => [['realm' => '150'] + $entry]]];
        yield 'oversized label' => [['status' => 'ok', 'actor' => 7, 'entries' => [['label' => str_repeat('x', 1025)] + $entry]]];
        yield 'too many entries' => [['status' => 'ok', 'actor' => 7, 'entries' => array_fill(0, 1001, $entry)]];
    }

    /** @param array<string, mixed> $result */
    #[DataProvider('malformed')]
    public function testMalformedWorkerResultsAreRefused(array $result): void
    {
        file_put_contents($this->root . '/result.json', json_encode($result, JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->plugins(self::request())->entries(7, ['Management']);
    }

    public function testRuntimeReportsEveryMenuItemWithItsRealmAndSectionName(): void
    {
        $translate = static fn(string $text): string => ['Management' => 'Gestion', 'Presets' => 'Préréglages'][$text] ?? $text;
        $menu = [
            'Gestion' => ['host.php' => 'Appareils', 'plugins/p/p.php?x=1' => 'P'],
            'Outils' => [
                'plugins/p/sub.php' => ['plugins/p/sub.php' => 'Sub parent', 'plugins/p/child.php' => 'Child'],
                'plugins/p/empty.php' => [],
                'plugins/p/numeric.php' => 5,
            ],
            'Broken' => 'not an array',
            42 => ['plugins/p/n.php' => 'Numbered'],
        ];
        $realms = ['host.php' => 3, 'p.php' => 150, 'sub.php' => '151', 'child.php' => 152, 'n.php' => 'x'];

        $result = new ConsoleMenuRuntime()->capture($menu, $realms, ['Management', 'Presets'], $translate);

        self::assertSame(['entries' => [
            ['section' => 'Management', 'label' => 'Appareils', 'path' => 'host.php', 'realm' => 3],
            ['section' => 'Management', 'label' => 'P', 'path' => 'plugins/p/p.php?x=1', 'realm' => 150],
            ['section' => 'Outils', 'label' => 'Sub parent', 'path' => 'plugins/p/sub.php', 'realm' => 151],
            ['section' => '42', 'label' => 'Numbered', 'path' => 'plugins/p/n.php', 'realm' => null],
        ]], $result);
    }

    #[DataProvider('pluginPaths')]
    public function testOnlyRelativePluginScriptPathsAreAccepted(string $path, bool $accepted): void
    {
        self::assertSame($accepted, LegacyConsoleMenu::isPluginPath($path));
    }

    public static function pluginPaths(): iterable
    {
        yield 'script' => ['plugins/thold/thold.php', true];
        yield 'nested script' => ['plugins/thold/ui/list.php', true];
        yield 'query' => ['plugins/thold/thold.php?action=list&tab=2', true];
        yield 'absolute URL' => ['https://example.com/plugins/x/x.php', false];
        yield 'external marker' => ['EXTERNAL::https://example.com/', false];
        yield 'protocol relative' => ['//example.com/plugins/x/x.php', false];
        yield 'root relative' => ['/plugins/x/x.php', false];
        yield 'traversal' => ['plugins/x/../../etc/x.php', false];
        yield 'dot segment' => ['plugins/./x/x.php', false];
        yield 'not a plugin' => ['vendor/x/x.php', false];
        yield 'not php' => ['plugins/x/x.html', false];
        yield 'fragment' => ['plugins/x/x.php#y', false];
        yield 'query URL' => ['plugins/x/x.php?next=https://example.com', false];
        yield 'newline' => ["plugins/x/x.php\n", false];
        yield 'backslash' => ['plugins\\x\\x.php', false];
    }

    private static function request(string $method = 'GET'): Request
    {
        return Request::create('https://example.test/kadupul/app.php/x', $method);
    }

    private function workerRuns(): int
    {
        return is_file($this->root . '/handoff.jsonl') ? count(file($this->root . '/handoff.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    private function plugins(Request $request, ?string $preferred = null, float $timeout = 3.0, int $actor = 7): LegacyPluginConsoleMenu
    {
        $database = new readonly class ($this->pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
        $session = self::createStub(BrowserSession::class);
        $session->method('read')->willReturn(['sess_user_id' => $actor, 'sess_user_credential' => str_repeat('a', 64)]);
        $requests = new RequestStack();
        $requests->push($request);

        $worker = new LegacyBrowserWorker($this->root, $requests, $session, $database, new InstallationConfiguration($this->root), new NullLogger(), $timeout);
        $locale = self::createStub(LocalePreference::class);
        $locale->method('preferredLocale')->willReturn($preferred);
        $configuration = self::createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);

        return new LegacyPluginConsoleMenu(static fn(): LegacyBrowserWorker => $worker, $database, $this->cache, $requests, $locale, $configuration, dirname(__DIR__, 2));
    }

    /** @param list<int> $granted */
    private function menu(array $granted, ?BufferingLogger $logger = null, ?Request $request = null, ?string $preferred = null, float $timeout = 3.0, int $actor = 7): LegacyConsoleMenu
    {
        $session = new readonly class ($granted, $actor) implements ConsoleAccess, RealmGrants {
            /** @param list<int> $granted */
            public function __construct(private array $granted, private int $actor) {}

            public function consoleActor(): ?Actor
            {
                return new Actor($this->actor, 'operator');
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
        $online = new class implements LegacyConfiguration {
            public function values(): array
            {
                return ['collector_id' => 1];
            }
        };
        $plugins = $this->plugins($request ?? self::request(), $preferred, $timeout, $actor);
        $database = new readonly class ($this->pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };

        return new LegacyConsoleMenu($session, $online, $database, new IdentityTranslator(), $plugins, $logger ?? new NullLogger());
    }

    /**
     * @param list<array{label: string, items: list<array{label: string, path: string}>}> $sections
     * @return list<string>
     */
    private static function paths(array $sections): array
    {
        return array_merge(...array_map(static fn(array $section): array => array_column($section['items'], 'path'), $sections));
    }
}
