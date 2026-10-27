<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Navigation\Application\Port\ManagementCatalogAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Application\Query\ViewManagementCatalog;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ManagementCatalogRoutesTest extends TestCase
{
    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private bool $consoleAllowed = true;
    private \PDO $preferences;
    private bool $failure = false;
    private bool $missing = false;
    private bool $empty = false;
    private array $reads = [];
    private ?array $operationOverride = null;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->consoleAllowed ? $this->actor : null);
        $container->set(ConsoleAccess::class, $console);
        $identity = $this->createStub(AuthenticatedAccess::class);
        $identity->method('authenticatedActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(AuthenticatedAccess::class, $identity);
        $this->preferences = new \PDO('sqlite::memory:');
        $this->preferences->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $this->preferences->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)');
        $database = $this->createStub(DatabaseConnection::class);
        $database->method('get')->willReturn($this->preferences);
        $container->set(DatabaseConnection::class, $database);
        $access = $this->createMock(ManagementCatalogAccess::class);
        $access->method('authorize')->willReturnCallback(fn(CatalogScreen $screen): Actor => $this->actor === null || $this->denied ? throw new AdministrationListDenied($this->actor === null) : $this->actor);
        $container->set(ManagementCatalogAccess::class, $access);
        $catalog = $this->createMock(ManagementCatalog::class);
        $catalog->method('supports')->willReturn(true);
        $catalog->method('read')->willReturnCallback(function (CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage {
            $this->reads[] = [$screen, $filters, $actorId, $parentId];
            if ($this->failure) {
                throw new \RuntimeException('Private database credentials');
            }
            if ($this->missing) {
                throw new CatalogParentNotFound();
            }
            $record = array_fill_keys(array_keys($screen->columns()), '<script>window.bad = 1</script>');
            $record['id'] = 7;
            if (isset($record['lastsent'])) {
                $record['lastsent'] = 0;
                $record['mailtime'] = 1700000000;
            }
            $record['name'] = 'Router <img src=x onerror=alert(1)>';
            return new CatalogPage($this->empty ? [] : array_fill(0, $filters->rows + 1, $record), $screen->scoped() ? 'Parent <b>' : null, $this->operationOverride);
        });
        $container->set(ViewManagementCatalog::class, new ViewManagementCatalog([$catalog], $access));
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1, 'url_path' => '/monitor/']);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public static function screens(): iterable
    {
        foreach (CatalogScreen::cases() as $screen) {
            yield $screen->value => [$screen, substr($screen->path(), 7) . ($screen->scoped() ? '/4' : '')];
        }
    }

    #[DataProvider('screens')]
    public function testEveryScreenRendersEscapedDataAndPreservesLegacyOperations(CatalogScreen $screen, string $path): void
    {
        $response = $this->get($path . '?filter=router&rows=10&sort_column=name&sort_direction=DESC&page=2');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('Router &lt;img', $html);
        self::assertStringNotContainsString('<script>window.bad', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        $dom = new \DOMDocument();
        self::assertTrue(@$dom->loadHTML($html));
        $xpath = new \DOMXPath($dom);
        self::assertSame(1, $xpath->query('//main[@id="management-catalog"]')->length);
        self::assertSame(count($screen->columns()), $xpath->query('//main//th[@scope="col"]')->length);
        self::assertSame(10, $xpath->query('//main//tbody/tr')->length);
        self::assertSame(0, $xpath->query('//main//script | //main//*[@onclick] | //main//form[@method="post"]')->length);
        $next = $xpath->query('//a[@rel="next"]')->item(0)->getAttribute('href');
        foreach (['filter=router', 'rows=10', 'sort_column=name', 'sort_direction=DESC', 'page=3'] as $part) {
            self::assertStringContainsString($part, $next);
        }
        if ($screen->scoped()) {
            self::assertStringContainsString($path . '?', $next);
            self::assertStringContainsString('Parent &lt;b&gt; (4)', $html);
        }
        $legacy = '/monitor/' . $screen->legacyScript();
        self::assertStringContainsString($legacy, $html);
        if ($screen->editableRecord()) {
            $query = http_build_query($screen->legacyQuery(4, 7));
            self::assertStringContainsString('/monitor/' . $screen->recordScript() . '?' . htmlspecialchars($query, ENT_QUOTES), $html);
        }
        self::assertSame(42, $this->reads[0][2]);
        self::assertSame($screen->scoped() ? 4 : 0, $this->reads[0][3]);
        $head = $this->kernel->handle(Request::create($path, 'HEAD'));
        self::assertSame(200, $head->getStatusCode());
        self::assertSame('', $head->getContent());
        $reads = count($this->reads);
        self::assertSame(405, $this->kernel->handle(Request::create($path, 'POST', ['action' => 'delete']))->getStatusCode());
        self::assertCount($reads, $this->reads);
    }

    #[DataProvider('screens')]
    public function testAdmissionPrecedesMalformedInputAndDataAccess(CatalogScreen $screen, string $path): void
    {
        $this->actor = null;
        self::assertSame(401, $this->get($path . '?filter[]=bad')->getStatusCode());
        $this->actor = new Actor(42, 'operator');
        $this->denied = true;
        self::assertSame(403, $this->get($path . '?filter[]=bad')->getStatusCode());
        self::assertSame([], $this->reads);
    }

    public function testPersonalReportsDoNotRequireAConsoleGrant(): void
    {
        $this->consoleAllowed = false;
        self::assertSame(200, $this->get('/management/my-reports')->getStatusCode());
        self::assertSame(401, $this->get('/management/reports')->getStatusCode());
    }

    public function testMissingParentsFailedReadsAndEmptyListsHaveDifferentOutcomes(): void
    {
        $this->missing = true;
        self::assertSame(404, $this->get('/management/graph-template-items/4')->getStatusCode());
        $this->missing = false;
        $this->failure = true;
        $response = $this->get('/management/plugins');
        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('Private database', (string) $response->getContent());
        $this->failure = false;
        $this->empty = true;
        $response = $this->get('/management/graph-template-inputs/4');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No records found.', (string) $response->getContent());
        self::assertStringNotContainsString('rel="next"', (string) $response->getContent());
    }

    public function testFilterShapesRangesUnicodeAndParentIdentitiesAreValidated(): void
    {
        foreach (['filter[]=x', 'page=0', 'page=-1', 'page=1e3', 'page=100000', 'rows=0', 'rows=-1', 'rows=101', 'sort_column=password', 'sort_direction=sideways', 'purge=1', 'filter=%00', 'filter=' . str_repeat('x', 256)] as $query) {
            self::assertSame(400, $this->get('/management/graphs?' . $query)->getStatusCode(), $query);
        }
        self::assertSame(400, $this->get('/management/graph-template-inputs/16777216')->getStatusCode());
        self::assertSame([], $this->reads);
        self::assertSame(200, $this->get('/management/graphs?filter=' . rawurlencode(str_repeat('é', 255)))->getStatusCode());
        self::assertSame(400, $this->get('/management/graphs?filter=' . rawurlencode(str_repeat('é', 256)))->getStatusCode());
        self::assertCount(1, $this->reads);
    }

    public function testFrenchPreferenceIsSelectedThroughTheRealSubscriber(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->preferences->exec("INSERT INTO settings VALUES ('i18n_language_support','1'), ('i18n_auto_detection','0'), ('i18n_default_language','en')");
        $preference = $this->createStub(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn('fr-CA');
        $container->set(LocalePreference::class, $preference);
        foreach (self::screens() as [$screen, $path]) {
            $response = $this->kernel->handle(Request::create($path, cookies: ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<html lang="fr">', (string) $response->getContent());
            self::assertStringContainsString('Modification, actions et filtres avancés', (string) $response->getContent());
        }
    }

    public function testInstallationAndFrontControllerPathsRemainInScopedLinks(): void
    {
        $response = $this->kernel->handle(Request::create('https://localhost/monitor/app.php/management/graph-template-items/4', server: [
            'SCRIPT_NAME' => '/monitor/app.php', 'SCRIPT_FILENAME' => '/srv/monitor/app.php', 'PHP_SELF' => '/monitor/app.php/management/graph-template-items/4',
        ]));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/monitor/app.php/management/graph-template-items/4?', (string) $response->getContent());
        self::assertStringContainsString('/monitor/graph_templates_items.php?', (string) $response->getContent());
    }

    public function testAllCollectorRoutesRejectAnOfflineLocalFallback(): void
    {
        $directory = sys_get_temp_dir() . '/management-offline-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/config.php', "<?php\n\$poller_id = 2;\n\$conn_mode = 'offline';\n");
        try {
            foreach (CatalogScreen::cases() as $screen) {
                $requests = new \Symfony\Component\HttpFoundation\RequestStack();
                $request = Request::create('/management/graphs');
                $request->attributes->set('_route', $screen->route());
                $requests->push($request);
                try {
                    (new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory, $requests))->values();
                    self::fail('Offline collector must not use local data for this catalog.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Online primary configuration is required for collector administration.', $error->getMessage());
                }
            }
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }

    public function testContainerWiresExactlyOneOwningCatalogPerScreen(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $catalogs = [
                $container->get(\Kadupul\Graphing\Infrastructure\Persistence\DbalMappingCatalog::class),
                $container->get(\Kadupul\Platform\Infrastructure\Persistence\DbalMibCatalog::class),
                $container->get(\Kadupul\Graphing\Infrastructure\Persistence\DbalGraphingManagementCatalog::class),
                $container->get(\Kadupul\DataInput\Infrastructure\Persistence\DbalQueryGraphCatalog::class),
                $container->get(\Kadupul\Reporting\Infrastructure\Persistence\DbalReportManagementCatalog::class),
                $container->get(\Kadupul\Platform\Infrastructure\Persistence\DbalPluginManagementCatalog::class),
            ];
            foreach (CatalogScreen::cases() as $screen) {
                self::assertCount(1, array_filter($catalogs, static fn(ManagementCatalog $catalog): bool => $catalog->supports($screen)), $screen->value);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testOperationLinksCarryTheAuthoritativeOwningTemplateAndQuery(): void
    {
        $this->operationOverride = ['action' => 'input_edit', 'id' => 4, 'graph_template_id' => 99];
        $response = $this->get('/management/graph-input-items/4');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/monitor/graph_templates_inputs.php?action=input_edit&amp;id=4&amp;graph_template_id=99', (string) $response->getContent());
        $this->operationOverride = ['action' => 'item_edit', 'id' => 4, 'snmp_query_id' => 98];
        self::assertStringContainsString('/monitor/data_queries.php?action=item_edit&amp;id=4&amp;snmp_query_id=98', (string) $this->get('/management/query-data-sources/4')->getContent());
        self::assertSame(200, $this->get('/management/mib-objects/4294967295')->getStatusCode());
        self::assertSame(4294967295, $this->reads[2][3]);
        self::assertSame(400, $this->get('/management/mib-objects/4294967296')->getStatusCode());
    }

    public function testMibRegistryLinksToEachNativeMetadataView(): void
    {
        $response = $this->get('/management/snmp-mibs');
        self::assertSame(200, $response->getStatusCode());
        foreach (['mib-objects', 'mib-conventions', 'mib-notification-attributes'] as $child) {
            self::assertStringContainsString('/management/' . $child . '/7', (string) $response->getContent());
        }
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }
}
