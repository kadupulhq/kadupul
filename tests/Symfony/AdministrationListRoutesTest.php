<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Navigation\Application\Port\AdministrationListAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Application\Query\ViewAdministrationList;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdministrationListRoutesTest extends TestCase
{
    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private bool $failure = false;
    private int $reads = 0;
    /** @var list<ListScreen> */
    private array $authorized = [];
    private bool $empty = false;

    protected function setUp(): void
    {
        $this->actor = new Actor(42, 'operator');
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(AdministrationListAccess::class);
        $access->method('authorize')->willReturnCallback(function (ListScreen $screen): void {
            if ($this->actor === null || $this->denied) {
                throw new AdministrationListDenied($this->actor === null);
            }
            $this->authorized[] = $screen;
        });
        $container->set(AdministrationListAccess::class, $access);
        $catalog = $this->createMock(AdministrationCatalog::class);
        $catalog->method('supports')->willReturn(true);
        $catalog->method('read')->willReturnCallback(function (ListScreen $screen, ListFilters $filters): array {
            ++$this->reads;
            if ($this->failure) {
                throw new \RuntimeException('database credentials must stay private');
            }
            if ($this->empty) {
                return [];
            }
            $row = array_fill_keys(array_keys($screen->columns()), 'example <script>alert(1)</script>');
            $row['id'] = 7;
            foreach (['event_time', 'last_check'] as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = 1700000000;
                }
            }
            $row['name'] = 'Router <img src=x onerror=alert(1)>';
            return array_fill(0, $filters->rows + 1, $row);
        });
        $container->set(ViewAdministrationList::class, new ViewAdministrationList([$catalog], $access));
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1, 'url_path' => '/monitor/']);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAllRoutesRenderEscapedTablesAndLegacyFormLinks(): void
    {
        foreach (ListScreen::cases() as $screen) {
            $response = $this->get($screen, '?filter=Router&rows=10&sort_column=' . (array_key_exists('id', $screen->columns()) ? 'id' : 'name') . '&sort_direction=DESC');
            self::assertSame(200, $response->getStatusCode(), $screen->value . ': ' . $response->getContent());
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $html = (string) $response->getContent();
            self::assertStringContainsString('Router &lt;img', $html);
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringNotContainsString('<img', $html);
            $dom = new \DOMDocument();
            self::assertTrue(@$dom->loadHTML($html));
            $xpath = new \DOMXPath($dom);
            self::assertSame(10, $xpath->query('//table[@id="administration-list"]/tbody/tr')->length);
            self::assertSame(count($screen->columns()), $xpath->query('//table[@id="administration-list"]/thead/tr/th[@scope="col"]')->length);
            self::assertSame(1, $xpath->query('//a[@rel="next"]')->length);
            self::assertStringContainsString('filter=Router', $xpath->query('//a[@rel="next"]')->item(0)->getAttribute('href'));
            self::assertSame(0, $xpath->query('//form[translate(@method,"POST","post")="post"]')->length);
            $links = array_map(static fn(\DOMElement $link): string => $link->getAttribute('href'), iterator_to_array($xpath->query('//a')));
            if ($screen->creatable()) {
                self::assertContains('/monitor/' . $screen->legacyScript() . '?' . http_build_query($screen->editQuery()), $links);
            } else {
                self::assertStringNotContainsString('>Create</a>', $html);
            }
            if ($screen->editable()) {
                self::assertContains('/monitor/' . $screen->legacyScript() . '?' . http_build_query($screen->editQuery(7)), $links);
            } else {
                self::assertStringNotContainsString('class="linkEditMain"', $html);
            }
            self::assertContains('/monitor/' . $screen->legacyScript() . '?' . http_build_query($screen->legacyQuery() + ['filter' => 'Router', 'rows' => '10']), $links);
        }
        self::assertSame(array_merge(...array_map(static fn(ListScreen $screen): array => [$screen, $screen], ListScreen::cases())), $this->authorized);
    }

    public function testAuthorizationPrecedesParsingAndCatalogAccess(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $this->actor = $actor;
            $this->denied = $actor !== null;
            foreach (ListScreen::cases() as $screen) {
                self::assertSame($actor === null ? 401 : 403, $this->get($screen, '?filter[]=x')->getStatusCode());
            }
        }
        self::assertSame(0, $this->reads);
    }

    public function testMalformedFiltersNeverReachACatalog(): void
    {
        foreach (['filter[]=x', 'rows=0', 'rows=-1', 'rows=1000', 'page=0', 'page=1e2', 'page=1.5', 'page=100000', 'page=01', 'sort_column=password', 'sort_direction=desc', 'action=delete', 'filter=%00', 'filter=' . str_repeat('a', 256)] as $query) {
            foreach (ListScreen::cases() as $screen) {
                self::assertSame(400, $this->get($screen, '?' . $query)->getStatusCode(), $screen->value . ': ' . $query);
            }
        }
        self::assertSame(0, $this->reads);
    }

    public function testHeadAndPostAndEmptyAndFailureOutcomes(): void
    {
        foreach (ListScreen::cases() as $screen) {
            $path = substr($screen->path(), 7);
            self::assertSame('', $this->kernel->handle(Request::create($path, 'HEAD'))->getContent());
            self::assertSame(405, $this->kernel->handle(Request::create($path, 'POST', ['action' => 'delete']))->getStatusCode());
        }
        self::assertSame(count(ListScreen::cases()), $this->reads);
        $this->empty = true;
        $html = (string) $this->get(ListScreen::DataQueries)->getContent();
        self::assertStringContainsString('No records found.', $html);
        self::assertStringNotContainsString('rel="next"', $html);
        $this->failure = true;
        $response = $this->get(ListScreen::SnmpOptions);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('credentials', (string) $response->getContent());
    }

    public function testAllRoutesSelectAndRenderTheUserLocale(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $database = $this->createStub(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $preference = $this->createStub(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn('fr-FR');
        $container->set(LocalePreference::class, $preference);
        foreach (ListScreen::cases() as $screen) {
            $request = Request::create(substr($screen->path(), 7), cookies: ['Cacti' => 'fixture']);
            $response = $this->kernel->handle($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('fr', $request->getLocale());
            self::assertStringContainsString('Actions groupées et filtres avancés', (string) $response->getContent());
            if ($screen->creatable()) {
                self::assertStringContainsString('Créer', (string) $response->getContent());
            } else {
                self::assertStringNotContainsString('>Créer</a>', (string) $response->getContent());
            }
        }
    }

    private function get(ListScreen $screen, string $query = ''): Response
    {
        return $this->kernel->handle(Request::create(substr($screen->path(), 7) . $query));
    }
}
