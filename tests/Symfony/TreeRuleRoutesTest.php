<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\TreeRuleStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\TreeRule;
use Kadupul\Automation\Domain\TreeRuleChange;
use Kadupul\Automation\Domain\TreeRuleRejected;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TreeRuleRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    /** The browser submission of the stored rule; unchecked boxes are absent. */
    private const array FIELDS = ['name' => 'Routers <b>', 'tree_id' => '2', 'tree_item_id' => '12', 'leaf_type' => '2', 'host_grouping_type' => '1', 'enabled' => '1',
        'revision' => self::REVISION, '_token' => 'csrf-token'];

    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private int $reads = 0;
    private ?TreeRuleRejected $reject = null;
    /** @var array<string, mixed> */
    private array $configuration = ['collector_id' => 1];
    /** @var list<array{int, ?int, TreeRuleChange, ?string}> */
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

        $store = $this->createMock(TreeRuleStore::class);
        $store->method('choices')->willReturnCallback(function (): array {
            $this->reads++;
            return ['trees' => [1 => 'Default Tree', 2 => 'Network <i>'], 'headers' => [20 => ['tree' => 1, 'title' => 'Servers', 'depth' => 1],
                11 => ['tree' => 2, 'title' => 'Core <u>', 'depth' => 1], 12 => ['tree' => 2, 'title' => 'Routers', 'depth' => 2]]];
        });
        $store->method('find')->willReturnCallback(function (int $id): ?TreeRule {
            $this->reads++;
            return $id === 7 ? new TreeRule(7, 'Routers <b>', 2, 12, 2, 1, true, self::REVISION) : null;
        });
        $store->method('save')->willReturnCallback(function (int $actor, ?int $id, TreeRuleChange $change, ?string $revision): int {
            if ($this->reject !== null) {
                throw $this->reject;
            }
            if ($revision === 'stale') {
                throw new \InvalidArgumentException('The tree rule changed since you opened this form. Reload before saving.');
            }
            $this->writes[] = [$actor, $id, $change, $revision];
            return $id ?? 8;
        });
        $container->set(TreeRuleStore::class, $store);
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
            foreach (['/automation/tree-rules/new', '/automation/tree-rules/7/edit'] as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->post($path, self::FIELDS)->getStatusCode(), $path);
            }
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
    }

    public function testEditorEscapesStoredTextAndGroupsHeadersByTree(): void
    {
        $response = $this->get('/automation/tree-rules/7/edit');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $page = (string) $response->getContent();
        foreach (['Tree Rule Selection [edit: Routers &lt;b&gt;]', 'Network &lt;i&gt;', 'Core &lt;u&gt;', 'automation_tree_rules.php?action=edit&amp;id=7',
            'Remove graph-based criteria'] as $text) {
            self::assertStringContainsString($text, $page);
        }
        self::assertStringNotContainsString('Routers <b>', $page);
        self::assertMatchesRegularExpression('/<option value="0">\[root\]<\/option>\s*<optgroup label="Default Tree">\s*<option value="20">- Servers<\/option>/', $page);
        self::assertMatchesRegularExpression('/<optgroup label="Network &lt;i&gt;">\s*<option value="11">- Core &lt;u&gt;<\/option>\s*<option value="12" selected="selected">-- Routers<\/option>/', $page);
        self::assertMatchesRegularExpression('/<option value="2" selected="selected">Graph<\/option>/', $page);
        foreach (['/automation/tree-rules/9/edit', '/automation/tree-rules/0/edit', '/automation/tree-rules/99999999999/edit'] as $missing) {
            self::assertSame(404, $this->get($missing)->getStatusCode(), $missing);
        }
        $new = (string) $this->get('/automation/tree-rules/new')->getContent();
        self::assertStringContainsString('Tree Rules Selection [new]', $new);
        self::assertStringNotContainsString('Remove graph-based criteria', $new);
        self::assertStringNotContainsString('automation_tree_rules.php?action=edit', $new);
        self::assertSame(200, $this->get('/automation/tree-rules/7/edit?saved[]=1')->getStatusCode());
        self::assertStringContainsString('Tree rule saved.', (string) $this->get('/automation/tree-rules/7/edit?saved=1')->getContent());
    }

    public function testInvalidSubmissionsAreRefusedBeforeTheStore(): void
    {
        $cases = [
            'missing token' => array_diff_key(self::FIELDS, ['_token' => true]),
            'extra field' => ['extra' => '1'] + self::FIELDS,
            'empty name' => ['name' => ' '] + self::FIELDS,
            'unlisted tree' => ['tree_id' => '3'] + self::FIELDS,
            'unlisted header' => ['tree_item_id' => '99<script>'] + self::FIELDS,
            'header leaf type' => ['leaf_type' => '1'] + self::FIELDS,
            'unlisted grouping' => ['host_grouping_type' => '3'] + self::FIELDS,
            'array where a scalar is expected' => ['tree_id' => ['2']] + self::FIELDS,
        ];
        foreach ($cases as $name => $fields) {
            $response = $this->post('/automation/tree-rules/7/edit', $fields);
            self::assertSame(422, $response->getStatusCode(), $name);
            self::assertStringNotContainsString('<script>', (string) $response->getContent(), $name);
        }
        self::assertSame(422, $this->post('/automation/tree-rules/new', ['remove_graph_criteria' => '1'] + self::FIELDS)->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame(409, $this->post('/automation/tree-rules/7/edit', ['revision' => 'stale'] + self::FIELDS)->getStatusCode());
        // An unconfirmed removal is reported on its checkbox.
        $this->reject = new TreeRuleRejected('Device leaves cannot use graph-based criteria. Confirm the removal of %count% criteria to save.', ['%count%' => '3'], 'remove_graph_criteria');
        $rejected = $this->post('/automation/tree-rules/7/edit', ['leaf_type' => '3'] + self::FIELDS);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertStringContainsString('Confirm the removal of 3 criteria to save.', (string) $rejected->getContent());
    }

    public function testValidSubmissionsSaveThroughTheStore(): void
    {
        $saved = $this->post('/automation/tree-rules/7/edit', array_diff_key(['name' => ' Routers ', 'leaf_type' => '3', 'remove_graph_criteria' => '1'] + self::FIELDS, ['enabled' => true]));
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/automation/tree-rules/7/edit?saved=1', $saved->headers->get('Location'));
        [$actor, $id, $change, $revision] = $this->writes[0];
        self::assertSame([42, 7, self::REVISION, true], [$actor, $id, $revision, $change->removeGraphCriteria]);
        self::assertSame(['name' => 'Routers', 'tree_id' => 2, 'tree_item_id' => 12, 'leaf_type' => 3, 'host_grouping_type' => 1, 'enabled' => ''], $change->columns());

        $created = $this->post('/automation/tree-rules/new', ['tree_id' => '1', 'tree_item_id' => '0', 'revision' => ''] + self::FIELDS);
        self::assertSame('/automation/tree-rules/8/edit?saved=1', $created->headers->get('Location'));
        self::assertSame([null, null, 1, 0, false], [$this->writes[1][1], $this->writes[1][3], $this->writes[1][2]->treeId, $this->writes[1][2]->treeItemId,
            $this->writes[1][2]->removeGraphCriteria]);
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
        $page = (string) $this->kernel->handle(Request::create('/automation/tree-rules/7/edit', 'GET', [], ['Cacti' => 'fixture']))->getContent();
        self::assertStringContainsString('<html lang="fr">', $page);
        foreach (['Sélection de règle d’arbre [modifier : Routers &lt;b&gt;]', 'Type d’élément feuille', 'Équipement', 'Supprimer les critères sur les graphiques'] as $text) {
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
        return $this->kernel->handle(Request::create($uri, 'POST', ['tree_rule' => $fields], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }
}
