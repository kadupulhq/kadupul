<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\AutomationTemplatePreferences;
use Kadupul\Automation\Application\Port\AutomationTemplateStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\AutomationTemplate;
use Kadupul\Automation\Domain\AutomationTemplateFilters;
use Kadupul\Automation\Domain\AutomationTemplatePage;
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

final class AutomationTemplateRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string ORDER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Kernel $kernel;
    private AutomationTemplateStore&MockObject $store;
    private AutomationTemplatePreferences&MockObject $preferences;
    private ?Actor $actor;
    private bool $denied = false;
    private ?string $locale = null;
    /** @var list<array{string, list<mixed>}> */
    private array $writes = [];
    /** @var list<AutomationTemplateFilters> */
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
        $access = $this->createMock(AutomationTemplateAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new AutomationTemplateAccessDenied(false) : $this->actor);
        $container->set(AutomationTemplateAccess::class, $access);
        $templates = [
            3 => new AutomationTemplate(3, 7, 'Linux <b>', 1, '&lt;b&gt;Linux', 'core-*', '', 1, self::REVISION),
            4 => new AutomationTemplate(4, 8, 'Switch', 3, 'Cisco', '', '1.3.6.1.4.1.9', 2, self::REVISION),
            5 => new AutomationTemplate(5, 99, null, 2, '', '', '', 3, self::REVISION),
        ];
        $this->store = $this->createMock(AutomationTemplateStore::class);
        $this->store->method('defaultRows')->willReturn(25);
        $this->store->method('defaultAvailability')->willReturn(2);
        $this->store->method('list')->willReturnCallback(function (AutomationTemplateFilters $filters) use ($templates): AutomationTemplatePage {
            $this->listed[] = $filters;
            return new AutomationTemplatePage(array_values($templates), 60, $filters, self::ORDER, 3, 9);
        });
        $this->store->method('find')->willReturnCallback(fn(int $id): ?AutomationTemplate => $templates[$id] ?? null);
        $this->store->method('findMany')->willReturnCallback(fn(array $ids): array => array_intersect_key($templates, array_flip($ids)));
        // Two device templates share a name; both must stay selectable.
        $this->store->method('deviceTemplates')->willReturn([7 => 'Linux <b>', 8 => 'Switch', 10 => 'Switch']);
        foreach (['save', 'move', 'delete'] as $method) {
            $this->store->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): int {
                if (str_contains(json_encode($arguments, JSON_THROW_ON_ERROR), '"stale"')) {
                    throw new \InvalidArgumentException('The automation template changed since you opened this form. Reload before saving.');
                }
                $this->writes[] = [$method, $arguments];
                return 3;
            });
        }
        $container->set(AutomationTemplateStore::class, $this->store);
        $this->preferences = $this->createMock(AutomationTemplatePreferences::class);
        $this->preferences->method('save')->willReturnCallback(function (array $filters): void {
            $this->saved[] = $filters;
        });
        $container->set(AutomationTemplatePreferences::class, $this->preferences);
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
        $paths = ['/automation/templates', '/automation/templates/new', '/automation/templates/3/edit',
            '/automation/templates/delete?ids[]=4', '/automation/templates/legacy'];
        foreach ([[null, false, 401], [new Actor(42, 'operator'), true, 403]] as [$actor, $denied, $status]) {
            $this->actor = $actor;
            $this->denied = $denied;
            foreach ($paths as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
            }
            self::assertSame($status, $this->post('/automation/templates/3/move/down', ['automation_template_order_3' => ['revision' => self::ORDER, '_token' => 'csrf-token']])->getStatusCode());
            self::assertSame($status, $this->post('/automation/templates/3/edit', ['automation_template' => ['host_template' => '7', '_token' => 'csrf-token']])->getStatusCode());
            self::assertSame($status, $this->post('/automation/templates/legacy', ['action' => 'actions'])->getStatusCode());
        }
        self::assertSame([], $this->listed);
        self::assertSame([], $this->writes);
    }

    public function testListShowsMatchOrderEscapesStoredTextAndRemembersFilters(): void
    {
        $response = $this->get('/automation/templates?filter=linux&rows=10');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('Linux &lt;b&gt;', $html);
        self::assertStringNotContainsString('Linux <b>', $html);
        // The stored description is shown as stored, like the legacy list.
        self::assertStringContainsString('<td>&amp;lt;b&amp;gt;Linux</td>', $html);
        self::assertStringContainsString('Ping and SNMP Uptime', $html);
        self::assertStringContainsString('Unknown Template', $html);
        self::assertStringContainsString('class="cactiTable"', $html);
        self::assertStringContainsString('<option value="10" selected>', $html);
        self::assertStringContainsString('name="automation_template_order_4[revision]" value="' . self::ORDER . '"', $html);
        // The first template overall cannot move up; the last one is on another page.
        self::assertStringNotContainsString('/automation/templates/3/move/up', $html);
        self::assertStringContainsString('/automation/templates/4/move/up', $html);
        self::assertStringContainsString('/automation/templates/5/move/down', $html);
        self::assertStringContainsString('form="automation-template-move-5"', $html);
        self::assertSame(['linux', 10, 1], [$this->listed[0]->filter, $this->listed[0]->rows, $this->listed[0]->page]);
        self::assertSame(['filter' => 'linux', 'rows' => '10', 'page' => '1'], $this->saved[0]);
        foreach (['rows=0', 'page=0', 'filter[]=x', 'deleted=2', 'sort_column=sequence', 'unknown=1', 'filter=' . str_repeat('x', 201)] as $query) {
            self::assertSame(400, $this->get('/automation/templates?' . $query)->getStatusCode(), $query);
        }
    }

    public function testPlainVisitRestoresSavedFiltersAndClearResetsThem(): void
    {
        $this->preferences->expects(self::once())->method('load')->willReturn(['filter' => 'saved']);
        $this->get('/automation/templates');
        self::assertSame('saved', $this->listed[0]->filter);
        self::assertSame([], $this->saved);
        $this->get('/automation/templates?clear=1');
        self::assertSame('', $this->listed[1]->filter);
        self::assertSame('', $this->saved[0]['filter']);
    }

    public function testEditorSavesWithCsrfAndRevisionAndKeepsDuplicateNamesDistinct(): void
    {
        $page = (string) $this->get('/automation/templates/3/edit')->getContent();
        self::assertStringContainsString('Automation Templates [edit: Linux &lt;b&gt;]', $page);
        self::assertStringContainsString('<option value="7" selected="selected">Linux &lt;b&gt;</option>', $page);
        self::assertStringContainsString('<option value="8">Switch</option>', $page);
        self::assertStringContainsString('<option value="10">Switch</option>', $page);
        self::assertStringContainsString('<option value="1" selected="selected">Ping and SNMP Uptime</option>', $page);
        self::assertStringContainsString('value="&amp;lt;b&amp;gt;Linux"', $page);
        self::assertStringContainsString('name="automation_template[revision]" value="' . self::REVISION . '"', $page);
        self::assertSame(404, $this->get('/automation/templates/9/edit')->getStatusCode());
        self::assertSame(404, $this->get('/automation/templates/0/edit')->getStatusCode());

        $orphan = (string) $this->get('/automation/templates/5/edit')->getContent();
        self::assertStringContainsString('Automation Templates for [Deleted Template]', $orphan);
        self::assertStringNotContainsString('selected="selected">Linux', $orphan);

        $fields = ['host_template' => '10', 'availability_method' => '3', 'sysDescr' => '&lt;b&gt;Linux', 'sysName' => 'edge', 'sysOid' => '', 'revision' => self::REVISION, '_token' => 'csrf-token'];
        foreach ([
            'missing token' => ['automation_template' => array_diff_key($fields, ['_token' => true])],
            'extra field' => ['automation_template' => $fields + ['sequence' => '1']],
            'unknown device template' => ['automation_template' => ['host_template' => '99'] + $fields],
            'no device template' => ['automation_template' => ['host_template' => ''] + $fields],
            'array device template' => ['automation_template' => ['host_template' => ['7']] + $fields],
            'unknown availability' => ['automation_template' => ['availability_method' => '7'] + $fields],
            'array match' => ['automation_template' => ['sysName' => ['x']] + $fields],
        ] as $case => $body) {
            self::assertSame(422, $this->post('/automation/templates/3/edit', $body)->getStatusCode(), $case);
        }
        self::assertSame(422, $this->post('/automation/templates/3/edit', ['automation_template' => $fields], 'https://attacker.invalid')->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame(409, $this->post('/automation/templates/3/edit', ['automation_template' => ['revision' => 'stale'] + $fields])->getStatusCode());
        $response = $this->post('/automation/templates/3/edit', ['automation_template' => $fields]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/automation/templates?saved=1', $response->headers->get('Location'));
        self::assertSame(['save', [42, 3, 10, 3, '&lt;b&gt;Linux', 'edge', '', self::REVISION]], $this->writes[0]);

        $new = (string) $this->get('/automation/templates/new')->getContent();
        self::assertStringContainsString('Automation Templates [new]', $new);
        self::assertStringContainsString('<option value="2" selected="selected">SNMP Uptime</option>', $new);
        $created = $this->post('/automation/templates/new', ['automation_template' => ['revision' => ''] + $fields]);
        self::assertSame(303, $created->getStatusCode());
        self::assertSame(['save', [42, null, 10, 3, '&lt;b&gt;Linux', 'edge', '', null]], $this->writes[1]);
    }

    public function testMoveNeedsAPostWithTheListOrder(): void
    {
        self::assertSame(405, $this->get('/automation/templates/4/move/up')->getStatusCode());
        self::assertSame(422, $this->post('/automation/templates/4/move/up', ['automation_template_order_4' => ['revision' => self::ORDER]])->getStatusCode());
        self::assertSame(422, $this->post('/automation/templates/4/move/up', ['automation_template_order_3' => ['revision' => self::ORDER, '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame(409, $this->post('/automation/templates/4/move/up', ['automation_template_order_4' => ['revision' => 'stale', '_token' => 'csrf-token']])->getStatusCode());
        $moved = $this->post('/automation/templates/4/move/up', ['automation_template_order_4' => ['revision' => self::ORDER, '_token' => 'csrf-token']]);
        self::assertSame(303, $moved->getStatusCode());
        self::assertSame('/automation/templates', $moved->headers->get('Location'));
        self::assertSame(['move', [42, 4, -1, self::ORDER]], $this->writes[0]);
        $this->post('/automation/templates/3/move/down', ['automation_template_order_3' => ['revision' => self::ORDER, '_token' => 'csrf-token']]);
        self::assertSame(['move', [42, 3, 1, self::ORDER]], $this->writes[1]);
        self::assertSame(404, $this->post('/automation/templates/3/move/left', ['automation_template_order_3' => ['revision' => self::ORDER, '_token' => 'csrf-token']])->getStatusCode());
    }

    public function testDeleteConfirmationListsTheSelectionAndChecksRevisions(): void
    {
        $confirmation = (string) $this->get('/automation/templates/delete?ids[]=4&ids[]=3')->getContent();
        self::assertStringContainsString('Click Continue to delete the following Automation Templates.', $confirmation);
        self::assertStringContainsString('<li>Switch (Cisco)</li>', $confirmation);
        self::assertSame(404, $this->get('/automation/templates/delete?ids[]=9')->getStatusCode());
        self::assertSame(400, $this->get('/automation/templates/delete')->getStatusCode());
        self::assertSame(400, $this->get('/automation/templates/delete?ids[]=x')->getStatusCode());
        self::assertSame(400, $this->get('/automation/templates/delete?ids=3')->getStatusCode());
        self::assertSame(400, $this->get('/automation/templates/delete?' . http_build_query(['ids' => array_map('strval', range(1, 101))]))->getStatusCode());

        $one = ['selection' => '[4]', 'revisions' => json_encode(['4' => self::REVISION]), '_token' => 'csrf-token'];
        self::assertSame(422, $this->post('/automation/templates/delete?ids[]=4', ['automation_template_delete' => array_diff_key($one, ['_token' => true])])->getStatusCode());
        self::assertSame(422, $this->post('/automation/templates/delete?ids[]=4', ['automation_template_delete' => ['selection' => '[3]'] + $one])->getStatusCode());
        self::assertSame(422, $this->post('/automation/templates/delete?ids[]=4', ['automation_template_delete' => ['selection' => '{'] + $one])->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame(409, $this->post('/automation/templates/delete?ids[]=4', ['automation_template_delete' => ['revisions' => json_encode(['4' => 'stale'])] + $one])->getStatusCode());
        $deleted = $this->post('/automation/templates/delete?ids[]=4', ['automation_template_delete' => $one]);
        self::assertSame('/automation/templates?deleted=1', $deleted->headers->get('Location'));
        self::assertSame(['delete', [42, [4], [4 => self::REVISION]]], $this->writes[0]);
    }

    public function testLegacyEntryNavigatesAndNeverWrites(): void
    {
        $legacy = '/automation/templates/legacy';
        self::assertSame(409, $this->post($legacy, ['action' => 'actions', 'drp_action' => '1', 'selected_items' => 'a:1:{i:0;s:1:"4";}'])->getStatusCode());
        self::assertSame(409, $this->post($legacy, ['action' => 'ajax_dnd', 'template_ids' => ['line4', 'line3']])->getStatusCode());
        foreach ([
            '?action=edit&id=3' => '/automation/templates/3/edit',
            '?action=edit' => '/automation/templates/new',
            '?action=edit&id=0&header=false' => '/automation/templates/new',
            '?header=false&id=3' => '/automation/templates',
            '?clear=1&header=false' => '/automation/templates?clear=1',
            '' => '/automation/templates',
        ] as $query => $location) {
            self::assertSame($location, $this->get($legacy . $query)->headers->get('Location'), $query);
        }
        self::assertStringContainsString('filter=linux', (string) $this->get($legacy . '?filter=linux&rows=-1&has_graphs=false')->headers->get('Location') ?? '');
        foreach (['save', 'actions', 'remove', 'moveup', 'movedown', 'ajax_dnd'] as $action) {
            self::assertSame(405, $this->get($legacy . '?action=' . $action . '&id=3')->getStatusCode(), $action);
        }
        foreach (['action=edit&id=x', 'action[]=edit', 'action=other', 'clear=2', 'rows=0'] as $query) {
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
        foreach (['/automation/templates', '/automation/templates/3/edit'] as $path) {
            $html = (string) $this->kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']))->getContent();
            self::assertStringContainsString('<html lang="fr">', $html, $path);
            self::assertStringContainsString('Ping et uptime SNMP', $html, $path);
            self::assertStringContainsString('Linux &lt;b&gt;', $html, $path);
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
