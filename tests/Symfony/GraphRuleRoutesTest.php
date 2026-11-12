<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\GraphRuleStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\GraphRule;
use Kadupul\Automation\Domain\GraphRuleChange;
use Kadupul\Automation\Domain\GraphRuleRejected;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GraphRuleRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    /** The browser submission of the stored rule; an unchecked box is absent. */
    private const array FIELDS = ['name' => 'Traffic <b>', 'snmp_query_id' => '1', 'graph_type_id' => '12', 'enabled' => '1', 'revision' => self::REVISION, '_token' => 'csrf-token'];

    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private int $reads = 0;
    private ?GraphRuleRejected $reject = null;
    /** @var array<string, mixed> */
    private array $configuration = ['collector_id' => 1];
    /** @var list<array{int, ?int, GraphRuleChange, ?string}> */
    private array $writes = [];

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

        $store = $this->createMock(GraphRuleStore::class);
        $store->method('choices')->willReturnCallback(function (): array {
            $this->reads++;
            return ['queries' => [3 => 'Disks <i>', 1 => 'Interfaces'], 'graphTypes' => [2 => ['query' => 1, 'name' => 'Bits'], 12 => ['query' => 1, 'name' => 'Bits (64-bit)'],
                17 => ['query' => 3, 'name' => 'Used <u>']]];
        });
        $store->method('find')->willReturnCallback(function (int $id): ?GraphRule {
            $this->reads++;
            return $id === 7 ? new GraphRule(7, 'Traffic <b>', 1, 12, true, self::REVISION) : null;
        });
        $store->method('save')->willReturnCallback(function (int $actor, ?int $id, GraphRuleChange $change, ?string $revision): int {
            if ($this->reject !== null) {
                throw $this->reject;
            }
            if ($revision === 'stale') {
                throw new \InvalidArgumentException('The graph rule changed since you opened this form. Reload before saving.');
            }
            $this->writes[] = [$actor, $id, $change, $revision];
            return $id ?? 8;
        });
        $container->set(GraphRuleStore::class, $store);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturnCallback(fn(): array => $this->configuration);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAnonymousAndUnauthorizedRequestsStopBeforeTheStore(): void
    {
        foreach ([[null, false, 401], [new Actor(42, 'operator'), true, 403]] as [$actor, $denied, $status]) {
            $this->actor = $actor;
            $this->denied = $denied;
            foreach (['/automation/graph-rules/new', '/automation/graph-rules/7/edit'] as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->post($path, self::FIELDS)->getStatusCode(), $path);
            }
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
    }

    public function testEditorEscapesStoredTextAndGroupsGraphTypesByDataQuery(): void
    {
        $response = $this->get('/automation/graph-rules/7/edit');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $page = (string) $response->getContent();
        foreach (['Rule Selection [edit: Traffic &lt;b&gt;]', 'Disks &lt;i&gt;', 'Used &lt;u&gt;', 'automation_graph_rules.php?action=edit&amp;id=7'] as $text) {
            self::assertStringContainsString($text, $page);
        }
        self::assertStringNotContainsString('Traffic <b>', $page);
        self::assertMatchesRegularExpression('/<optgroup label="Interfaces">\s*<option value="2">Bits<\/option>\s*<option value="12" selected="selected">Bits \(64-bit\)<\/option>/', $page);
        self::assertMatchesRegularExpression('/<input type="checkbox"[^>]*name="graph_rule\[enabled\]"[^>]*checked="checked"/', $page);
        foreach (['/automation/graph-rules/9/edit', '/automation/graph-rules/0/edit', '/automation/graph-rules/99999999999/edit'] as $missing) {
            self::assertSame(404, $this->get($missing)->getStatusCode(), $missing);
        }
        $new = (string) $this->get('/automation/graph-rules/new')->getContent();
        self::assertStringContainsString('Rule Selection [new]', $new);
        // The first Data Query by name is preselected, as legacy's drop-down showed it.
        self::assertMatchesRegularExpression('/<option value="3" selected="selected">Disks &lt;i&gt;<\/option>/', $new);
        self::assertStringNotContainsString('automation_graph_rules.php?action=edit', $new);
        self::assertSame(200, $this->get('/automation/graph-rules/7/edit?saved[]=1')->getStatusCode());
        self::assertStringContainsString('Graph rule saved.', (string) $this->get('/automation/graph-rules/7/edit?saved=1')->getContent());
    }

    public function testInvalidSubmissionsAreRefusedBeforeTheStore(): void
    {
        $cases = [
            'missing token' => array_diff_key(self::FIELDS, ['_token' => true]),
            'extra field' => ['extra' => '1'] + self::FIELDS,
            'empty name' => ['name' => ' '] + self::FIELDS,
            'multi-line name' => ['name' => "a\nb"] + self::FIELDS,
            'unlisted Data Query' => ['snmp_query_id' => '2'] + self::FIELDS,
            'unlisted Graph Type' => ['graph_type_id' => '99<script>'] + self::FIELDS,
            'no Graph Type' => ['graph_type_id' => ''] + self::FIELDS,
            'array where a scalar is expected' => ['name' => ['x']] + self::FIELDS,
        ];
        foreach ($cases as $name => $fields) {
            $response = $this->post('/automation/graph-rules/7/edit', $fields);
            self::assertSame(422, $response->getStatusCode(), $name);
            self::assertStringNotContainsString('<script>', (string) $response->getContent(), $name);
        }
        self::assertSame([], $this->writes);
        self::assertSame(409, $this->post('/automation/graph-rules/7/edit', ['revision' => 'stale'] + self::FIELDS)->getStatusCode());
        // A Graph Type of another Data Query passes the form and is refused by the store on its field.
        $this->reject = new GraphRuleRejected('Choose a Graph Type of the selected Data Query.', [], 'graph_type_id');
        $rejected = $this->post('/automation/graph-rules/7/edit', ['graph_type_id' => '17'] + self::FIELDS);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertStringContainsString('Choose a Graph Type of the selected Data Query.', (string) $rejected->getContent());
    }

    public function testValidSubmissionsSaveThroughTheStore(): void
    {
        $saved = $this->post('/automation/graph-rules/7/edit', array_diff_key(['name' => ' Traffic '] + self::FIELDS, ['enabled' => true]));
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/automation/graph-rules/7/edit?saved=1', $saved->headers->get('Location'));
        [$actor, $id, $change, $revision] = $this->writes[0];
        self::assertSame([42, 7, self::REVISION], [$actor, $id, $revision]);
        self::assertSame(['name' => 'Traffic', 'snmp_query_id' => 1, 'graph_type_id' => 12, 'enabled' => ''], $change->columns());

        $created = $this->post('/automation/graph-rules/new', ['snmp_query_id' => '3', 'graph_type_id' => '17', 'revision' => ''] + self::FIELDS);
        self::assertSame('/automation/graph-rules/8/edit?saved=1', $created->headers->get('Location'));
        self::assertSame([null, null, 3, 17], [$this->writes[1][1], $this->writes[1][3], $this->writes[1][2]->snmpQueryId, $this->writes[1][2]->graphTypeId]);
    }

    public function testTheEditorRendersInTheConfiguredLocale(): void
    {
        // The locale subscriber selects the forced installation locale for a signed-in browser, as production does.
        $this->configuration['forced_locale'] = 'fr-FR';
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT, value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $container->set(LocalePreference::class, $this->createMock(LocalePreference::class));
        $page = (string) $this->kernel->handle(Request::create('/automation/graph-rules/7/edit', 'GET', [], ['Cacti' => 'fixture']))->getContent();
        self::assertStringContainsString('<html lang="fr">', $page);
        foreach (['Sélection de règle [modifier : Traffic &lt;b&gt;]', 'Requête de données', 'Type de graphique', 'Activer la règle'] as $text) {
            self::assertStringContainsString($text, $page);
        }
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    /** @param array<string, mixed> $fields */
    private function post(string $uri, array $fields): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', ['graph_rule' => $fields], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }
}
