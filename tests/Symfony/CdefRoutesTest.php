<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Application\Port\CdefPreferences;
use Kadupul\GraphDefinition\Application\Port\CdefStore;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Domain\Cdef;
use Kadupul\GraphDefinition\Domain\CdefFilters;
use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Kadupul\GraphDefinition\Domain\CdefItem;
use Kadupul\GraphDefinition\Domain\CdefPage;
use Kadupul\GraphDefinition\Domain\CdefSummary;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CdefRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Kernel $kernel;
    private CdefStore&MockObject $store;
    private CdefPreferences&MockObject $preferences;
    private ?Actor $actor;
    private bool $denied = false;
    private ?string $locale = null;
    /** @var list<array{string, list<mixed>}> */
    private array $writes = [];
    /** @var list<CdefFilters> */
    private array $listed = [];
    /** @var list<array<string, string>> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(CdefAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new CdefAccessDenied(false) : $this->actor);
        $container->set(CdefAccess::class, $access);
        $this->store = $this->createMock(CdefStore::class);
        $this->store->method('defaultRows')->willReturn(25);
        $this->store->method('defaultHasGraphs')->willReturn(false);
        $this->store->method('list')->willReturnCallback(function (CdefFilters $filters): CdefPage {
            $this->listed[] = $filters;
            return new CdefPage([new CdefSummary(3, 'Bits <b>', 2, 1, 0), new CdefSummary(4, 'Free', 0, 0, 0)], 60, $filters);
        });
        $this->store->method('find')->willReturnCallback(fn(int $id): ?Cdef => $id === 3 ? new Cdef(3, 'Bits <b>', self::REVISION, [
            new CdefItem(10, 1, CdefFunctions::SPECIAL_DATA_SOURCE, 'CURRENT_DATA_SOURCE', 'Current Graph Item Data Source'),
            new CdefItem(11, 2, CdefFunctions::CUSTOM_STRING, '8', '8'),
            new CdefItem(12, 3, CdefFunctions::OPERATOR, '3', '*'),
        ], 'CURRENT_DATA_SOURCE,8,*') : null);
        $this->store->method('findMany')->willReturnCallback(fn(array $ids): array => array_intersect_key([
            3 => ['summary' => new CdefSummary(3, 'Bits <b>', 2, 1, 0), 'revision' => self::REVISION],
            4 => ['summary' => new CdefSummary(4, 'Free', 0, 0, 0), 'revision' => self::REVISION],
        ], array_flip($ids)));
        $this->store->method('functions')->willReturn(['1' => 'SIN', '42' => 'ABS']);
        $this->store->method('references')->willReturn(['4' => 'Free']);
        foreach (['save', 'saveItem', 'deleteItem', 'moveItem', 'duplicate', 'delete'] as $method) {
            $this->store->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): int {
                if (str_contains(json_encode($arguments, JSON_THROW_ON_ERROR), '"stale"')) {
                    throw new \InvalidArgumentException('The CDEF changed since you opened this form. Reload before saving.');
                }
                $this->writes[] = [$method, $arguments];
                return 3;
            });
        }
        $container->set(CdefStore::class, $this->store);
        $this->preferences = $this->createMock(CdefPreferences::class);
        $this->preferences->method('save')->willReturnCallback(function (array $filters): void {
            $this->saved[] = $filters;
        });
        $container->set(CdefPreferences::class, $this->preferences);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturnCallback(fn(): array => ['collector_id' => 1] + ($this->locale === null ? [] : ['forced_locale' => $this->locale]));
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAnonymousAndUnauthorizedRequestsStopBeforeTheStore(): void
    {
        $paths = ['/graph-definitions/cdefs', '/graph-definitions/cdefs/new', '/graph-definitions/cdefs/3/edit',
            '/graph-definitions/cdefs/3/items/0', '/graph-definitions/cdefs/3/items/10/delete',
            '/graph-definitions/cdefs/actions/delete?ids[]=4', '/graph-definitions/cdefs/legacy'];
        foreach ([[null, false, 401], [new Actor(42, 'operator'), true, 403]] as [$actor, $denied, $status]) {
            $this->actor = $actor;
            $this->denied = $denied;
            foreach ($paths as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
            }
            self::assertSame($status, $this->post('/graph-definitions/cdefs/3/items/10/move/up', ['cdef_item_change_10' => ['revision' => self::REVISION, '_token' => 'csrf-token']])->getStatusCode());
            self::assertSame($status, $this->post('/graph-definitions/cdefs/legacy', ['action' => 'actions'])->getStatusCode());
        }
        self::assertSame([], $this->listed);
        self::assertSame([], $this->writes);
    }

    public function testListEscapesNamesMarksUsageAndRemembersFilters(): void
    {
        $response = $this->get('/graph-definitions/cdefs?filter=bits&rows=10&sort_column=graphs&sort_direction=DESC&has_graphs=true');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('Bits &lt;b&gt;', $html);
        self::assertStringNotContainsString('Bits <b>', $html);
        self::assertStringContainsString('class="cactiTable"', $html);
        self::assertStringContainsString('class="filterTable"', $html);
        self::assertStringContainsString('<option value="10" selected>', $html);
        self::assertStringContainsString('rel="next"', $html);
        self::assertMatchesRegularExpression('#<td>Bits &lt;b&gt;</a></td>|<td>No</td>#', $html);
        self::assertSame(['bits', 10, 'graphs', 'DESC', true], [$this->listed[0]->filter, $this->listed[0]->rows, $this->listed[0]->sortColumn, $this->listed[0]->sortDirection, $this->listed[0]->hasGraphs]);
        self::assertSame('bits', $this->saved[0]['filter']);
        foreach (['sort_column=c.name', 'sort_direction=up', 'rows=0', 'filter[]=x', 'has_graphs=yes', 'deleted=2', 'unknown=1'] as $query) {
            self::assertSame(400, $this->get('/graph-definitions/cdefs?' . $query)->getStatusCode(), $query);
        }
    }

    public function testPlainVisitRestoresSavedFiltersAndClearResetsThem(): void
    {
        $this->preferences->expects(self::once())->method('load')->willReturn(['filter' => 'saved']);
        $this->get('/graph-definitions/cdefs');
        self::assertSame('saved', $this->listed[0]->filter);
        self::assertSame([], $this->saved);
        $this->get('/graph-definitions/cdefs?clear=1');
        self::assertSame('', $this->listed[1]->filter);
        self::assertSame('', $this->saved[0]['filter']);
    }

    public function testEditorSavesWithCsrfAndRevisionAndRendersItems(): void
    {
        $page = (string) $this->get('/graph-definitions/cdefs/3/edit')->getContent();
        self::assertStringContainsString('cdef=CURRENT_DATA_SOURCE,8,*', $page);
        self::assertStringContainsString('Current Graph Item Data Source', $page);
        self::assertStringContainsString('name="cdef[revision]" value="' . self::REVISION . '"', $page);
        self::assertStringContainsString('/graph-definitions/cdefs/3/items/11/move/up', $page);
        self::assertSame(404, $this->get('/graph-definitions/cdefs/9/edit')->getStatusCode());

        $fields = ['name' => 'Renamed', 'revision' => self::REVISION, '_token' => 'csrf-token'];
        self::assertSame(422, $this->post('/graph-definitions/cdefs/3/edit', ['cdef' => ['name' => 'Renamed', 'revision' => self::REVISION]])->getStatusCode());
        self::assertSame(422, $this->post('/graph-definitions/cdefs/3/edit', ['cdef' => $fields], 'https://attacker.invalid')->getStatusCode());
        self::assertSame(422, $this->post('/graph-definitions/cdefs/3/edit', ['cdef' => $fields + ['system' => '1']])->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame(409, $this->post('/graph-definitions/cdefs/3/edit', ['cdef' => ['revision' => 'stale'] + $fields])->getStatusCode());
        $response = $this->post('/graph-definitions/cdefs/3/edit', ['cdef' => $fields]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/graph-definitions/cdefs/3/edit?saved=1', $response->headers->get('Location'));
        self::assertSame(['save', [42, 3, 'Renamed', self::REVISION]], $this->writes[0]);
        $created = $this->post('/graph-definitions/cdefs/new', ['cdef' => ['name' => 'New', 'revision' => '', '_token' => 'csrf-token']]);
        self::assertSame(303, $created->getStatusCode());
        self::assertSame(['save', [42, null, 'New', null]], $this->writes[1]);
    }

    public function testItemTypeComesFromThePageNotTheSubmittedForm(): void
    {
        $page = (string) $this->get('/graph-definitions/cdefs/3/items/0?type=2')->getContent();
        self::assertStringContainsString('<option value="2" selected>', $page);
        self::assertStringContainsString('<option value="3">*</option>', $page);
        self::assertStringNotContainsString('name="cdef_item[type]"', $page);
        self::assertSame(400, $this->get('/graph-definitions/cdefs/3/items/0?type=3')->getStatusCode());
        self::assertSame(404, $this->get('/graph-definitions/cdefs/3/items/99')->getStatusCode());

        $path = '/graph-definitions/cdefs/3/items/0?type=2';
        self::assertSame(422, $this->post($path, ['cdef_item' => ['value' => '3', 'revision' => self::REVISION, 'type' => '1', '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame(422, $this->post($path, ['cdef_item' => ['value' => '42', 'revision' => self::REVISION, '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame(303, $this->post($path, ['cdef_item' => ['value' => '3', 'revision' => self::REVISION, '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame(['saveItem', [42, 3, null, CdefFunctions::OPERATOR, '3', self::REVISION]], $this->writes[0]);
        // An existing item opens with its own type and value.
        self::assertStringContainsString('value="8"', (string) $this->get('/graph-definitions/cdefs/3/items/11')->getContent());
        self::assertStringContainsString('<option value="4">Free</option>', (string) $this->get('/graph-definitions/cdefs/3/items/0?type=5')->getContent());
    }

    public function testItemDeleteAndMoveNeedAPostWithTheRevision(): void
    {
        $confirmation = (string) $this->get('/graph-definitions/cdefs/3/items/12/delete')->getContent();
        self::assertStringContainsString('Click Continue to delete the following CDEF Item.', $confirmation);
        self::assertStringContainsString('<strong>*</strong>', $confirmation);
        self::assertSame(422, $this->post('/graph-definitions/cdefs/3/items/12/delete', ['cdef_item_change' => ['revision' => self::REVISION]])->getStatusCode());
        self::assertSame(409, $this->post('/graph-definitions/cdefs/3/items/12/delete', ['cdef_item_change' => ['revision' => 'stale', '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame(303, $this->post('/graph-definitions/cdefs/3/items/12/delete', ['cdef_item_change' => ['revision' => self::REVISION, '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame(['deleteItem', [42, 3, 12, self::REVISION]], $this->writes[0]);

        self::assertSame(405, $this->get('/graph-definitions/cdefs/3/items/11/move/up')->getStatusCode());
        self::assertSame(422, $this->post('/graph-definitions/cdefs/3/items/11/move/up', ['cdef_item_change_11' => ['revision' => self::REVISION]])->getStatusCode());
        self::assertSame(303, $this->post('/graph-definitions/cdefs/3/items/11/move/down', ['cdef_item_change_11' => ['revision' => self::REVISION, '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame(['moveItem', [42, 3, 11, 1, self::REVISION]], $this->writes[1]);
    }

    public function testDeleteAndDuplicateConfirmations(): void
    {
        $delete = (string) $this->get('/graph-definitions/cdefs/actions/delete?ids[]=4')->getContent();
        self::assertStringNotContainsString('title_format', $delete);
        self::assertStringContainsString('Continue', $delete);
        $used = (string) $this->get('/graph-definitions/cdefs/actions/delete?ids[]=3&ids[]=4')->getContent();
        self::assertStringContainsString('cannot be deleted', $used);
        self::assertStringNotContainsString('<button type="submit">Continue', $used);
        self::assertSame(404, $this->get('/graph-definitions/cdefs/actions/delete?ids[]=9')->getStatusCode());
        self::assertSame(400, $this->get('/graph-definitions/cdefs/actions/delete?ids[]=x')->getStatusCode());
        self::assertSame(400, $this->get('/graph-definitions/cdefs/actions/delete?' . http_build_query(['ids' => array_map('strval', range(1, 101))]))->getStatusCode());

        $selection = ['selection' => '[3,4]', 'revisions' => json_encode(['3' => self::REVISION, '4' => self::REVISION]), '_token' => 'csrf-token'];
        self::assertSame(422, $this->post('/graph-definitions/cdefs/actions/delete?ids[]=3&ids[]=4', ['cdef_action' => $selection])->getStatusCode());
        $one = ['selection' => '[4]', 'revisions' => json_encode(['4' => self::REVISION]), '_token' => 'csrf-token'];
        self::assertSame(422, $this->post('/graph-definitions/cdefs/actions/delete?ids[]=4', ['cdef_action' => ['selection' => '[3]'] + $one])->getStatusCode());
        self::assertSame(409, $this->post('/graph-definitions/cdefs/actions/delete?ids[]=4', ['cdef_action' => ['revisions' => json_encode(['4' => 'stale'])] + $one])->getStatusCode());
        $deleted = $this->post('/graph-definitions/cdefs/actions/delete?ids[]=4', ['cdef_action' => $one]);
        self::assertSame('/graph-definitions/cdefs?deleted=1', $deleted->headers->get('Location'));
        self::assertSame(['delete', [42, [4], [4 => self::REVISION]]], $this->writes[0]);

        self::assertStringContainsString('value="&lt;cdef_title&gt; (1)"', (string) $this->get('/graph-definitions/cdefs/actions/duplicate?ids[]=3')->getContent());
        $duplicated = $this->post('/graph-definitions/cdefs/actions/duplicate?ids[]=3', ['cdef_action' => ['selection' => '[3]', 'revisions' => json_encode(['3' => self::REVISION]), 'title_format' => '<cdef_title> copy', '_token' => 'csrf-token']]);
        self::assertSame('/graph-definitions/cdefs?duplicated=1', $duplicated->headers->get('Location'));
        self::assertSame(['duplicate', [42, [3], [3 => self::REVISION], '<cdef_title> copy']], $this->writes[1]);
    }

    public function testLegacyEntryNavigatesAndNeverWrites(): void
    {
        $legacy = '/graph-definitions/cdefs/legacy';
        self::assertSame(409, $this->post($legacy, ['action' => 'actions', 'drp_action' => '1', 'selected_items' => 'a:1:{i:0;s:1:"4";}'])->getStatusCode());
        foreach ([
            '?action=edit&id=3' => '/graph-definitions/cdefs/3/edit',
            '?action=edit' => '/graph-definitions/cdefs/new',
            '?action=item_edit&cdef_id=3&id=11&header=false' => '/graph-definitions/cdefs/3/items/11',
            '?action=item_edit&cdef_id=3&type_select=5' => '/graph-definitions/cdefs/3/items/0?type=5',
            '?action=item_remove_confirm&id=3&cdef_id=12' => '/graph-definitions/cdefs/3/items/12/delete',
            '?clear=1&header=false' => '/graph-definitions/cdefs?clear=1',
            '' => '/graph-definitions/cdefs',
        ] as $query => $location) {
            self::assertSame($location, $this->get($legacy . $query)->headers->get('Location'), $query);
        }
        self::assertStringContainsString('filter=bits', (string) $this->get($legacy . '?filter=bits&rows=-1')->headers->get('Location'));
        self::assertSame(405, $this->get($legacy . '?action=item_remove&id=3&cdef_id=12')->getStatusCode());
        foreach (['action=edit&id=x', 'action=item_edit&id=1', 'action[]=edit', 'action=other', 'clear=2', 'sort_column=id'] as $query) {
            self::assertSame(400, $this->get($legacy . '?' . $query)->getStatusCode(), $query);
        }
        self::assertSame([], $this->writes);
    }

    public function testFrenchRequestsTranslateLabelsButNotStoredText(): void
    {
        $this->locale = 'fr';
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT, value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $container->set(LocalePreference::class, $this->createMock(LocalePreference::class));
        foreach (['/graph-definitions/cdefs/3/edit', '/graph-definitions/cdefs/3/items/10/delete'] as $path) {
            $html = (string) $this->kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']))->getContent();
            self::assertStringContainsString('<html lang="fr">', $html, $path);
            self::assertStringContainsString('Source de données de l’élément de graphique courant', $html, $path);
            self::assertStringContainsString('Bits &lt;b&gt;', $html, $path);
        }
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    /** @param array<string, mixed> $fields */
    private function post(string $uri, array $fields, string $origin = 'http://localhost'): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', $fields, server: ['HTTP_ORIGIN' => $origin]));
    }
}
