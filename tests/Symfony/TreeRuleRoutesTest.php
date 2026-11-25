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
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
    private bool $authorizationFails = false;
    private bool $readFails = false;
    private int $reads = 0;
    private ?TreeRuleRejected $reject = null;
    /** @var array<string, mixed> */
    private array $configuration = ['collector_id' => 1];
    /** @var list<array{int, ?int, TreeRuleChange, ?string}> */
    private array $writes = [];
    /** @var list<array{string, list<mixed>}> */
    private array $bulk = [];

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
        $access->method('authorize')->willReturnCallback(fn(): Actor => match (true) {
            $this->denied => throw new AutomationTemplateAccessDenied(false),
            $this->authorizationFails => throw new \RuntimeException('authorization backend down'),
            default => $this->actor,
        });
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
        $rules = [3 => new TreeRule(3, 'Graphs <i>', 1, 0, 2, 1, false, self::REVISION), 7 => new TreeRule(7, 'Routers <b>', 2, 12, 2, 1, true, self::REVISION)];
        $store->method('findRules')->willReturnCallback(function (array $ids) use ($rules): array {
            $this->reads++;
            if ($this->readFails) {
                throw new \RuntimeException('database unavailable');
            }
            return array_intersect_key($rules, array_flip($ids));
        });
        foreach (['deleteRules', 'duplicateRules', 'setEnabled'] as $method) {
            $store->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): void {
                $revisions = is_array($arguments[2]) ? $arguments[2] : [];
                match (true) {
                    in_array('stale', $revisions, true) => throw new \InvalidArgumentException('The tree rule changed since you opened this form. Reload before saving.'),
                    in_array('gone', $revisions, true) => throw new \InvalidArgumentException('One or more selected Tree Rules no longer exist.'),
                    in_array('revoked', $revisions, true) => throw new AutomationTemplateAccessDenied(false),
                    in_array('remote', $revisions, true) => throw new \RuntimeException('Tree Rules must be changed on the primary collector.'),
                    ($arguments[3] ?? null) === '' => throw new \InvalidArgumentException('The name format must give every copy a name of 1 to 255 characters.'),
                    default => $this->bulk[] = [$method, $arguments],
                };
            });
        }
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
            foreach (['/automation/tree-rules/new', '/automation/tree-rules/7/edit', '/automation/tree-rules/actions/delete?ids[]=7', '/automation/tree-rules/actions/duplicate?ids[]=7',
                '/automation/tree-rules/actions/enable?ids[]=7', '/automation/tree-rules/actions/disable?ids[]=7'] as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->post($path, self::FIELDS)->getStatusCode(), $path);
                self::assertSame($status, $this->submit($path, ['automation_tree_rule_action' => self::action([7])])->getStatusCode(), $path);
            }
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->bulk);
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

    public function testBulkConfirmationListsTheSelectionAndRefusesMalformedOnesBeforeReading(): void
    {
        $action = '/automation/tree-rules/actions/';
        $response = $this->get($action . 'delete?ids[]=7&ids[]=3&ids[]=7');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $page = (string) $response->getContent();
        self::assertStringContainsString('Click Continue to delete the following Tree Rules with their Object Selection and Tree Creation Criteria.', $page);
        self::assertStringContainsString('<li>Graphs &lt;i&gt;</li>', $page);
        self::assertStringContainsString('<li>Routers &lt;b&gt;</li>', $page);
        self::assertStringContainsString('name="automation_tree_rule_action[selection]" value="[3,7]"', $page);
        self::assertStringContainsString('action="/automation/tree-rules/actions/delete?ids%5B0%5D=3&amp;ids%5B1%5D=7"', $page);
        self::assertStringContainsString('href="/automation/tree-rules"', $page);
        self::assertStringNotContainsString('name_format', $page);
        // CSP: the page carries no inline script or handler.
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)|\son[a-z]+=/i', $page);
        self::assertSame('', $this->kernel->handle(Request::create($action . 'delete?ids[]=7', 'HEAD'))->getContent());

        foreach (['enable' => 'Click Continue to enable the following Tree Rules. Make sure that they have been tested successfully.',
            'disable' => 'Click Continue to disable the following Tree Rules.'] as $operation => $intro) {
            $confirmation = (string) $this->get($action . $operation . '?ids[]=7')->getContent();
            self::assertStringContainsString($intro, $confirmation, $operation);
            self::assertStringNotContainsString('name_format', $confirmation, $operation);
        }
        $duplicate = (string) $this->get($action . 'duplicate?ids[]=7')->getContent();
        self::assertStringContainsString('name="automation_tree_rule_action[name_format]"', $duplicate);
        self::assertStringContainsString('value="&lt;rule_name&gt; (1)"', $duplicate);
        self::assertStringContainsString('maxlength="255"', $duplicate);
        self::assertStringContainsString('Title Format', $duplicate);

        $reads = $this->reads;
        self::assertStringContainsString('Select at least one Tree Rule.', (string) $this->get($action . 'delete')->getContent());
        self::assertStringContainsString('Select at most 100 Tree Rules at a time.', (string) $this->get($action . 'enable?' . http_build_query(['ids' => array_map('strval', range(1, 101))]))->getContent());
        foreach (['', 'ids=7', 'ids[]=x', 'ids[]=0', 'ids[]=07', 'ids[]=1e2', 'ids[]=-7', 'ids[]=7.0', 'ids[]=16777216', 'ids[]=999999999', 'ids[a]=7', 'ids[][]=7', 'ids[]=7&ids[]=',
            http_build_query(['ids' => array_map('strval', range(1, 101))])] as $query) {
            foreach (['delete', 'duplicate', 'enable', 'disable'] as $operation) {
                self::assertSame(400, $this->get($action . $operation . '?' . $query)->getStatusCode(), $query);
                self::assertSame(400, $this->submit($action . $operation . '?' . $query, ['automation_tree_rule_action' => self::action([7])])->getStatusCode(), $query);
            }
        }
        self::assertSame($reads, $this->reads);
        self::assertSame(404, $this->get($action . 'delete?ids[]=7&ids[]=9')->getStatusCode());
        self::assertSame(404, $this->get($action . 'purge?ids[]=7')->getStatusCode());
        self::assertSame(200, $this->get($action . 'delete?' . http_build_query(['ids' => array_fill(0, 100, '7')]))->getStatusCode());
        // The largest ID the column holds passes the parser and is then looked up.
        self::assertSame(404, $this->get($action . 'delete?ids[]=16777215&ids[]=7')->getStatusCode());
        self::assertSame([], $this->bulk);
    }

    public function testBulkActionsWriteOnlyFromAPostWithTheTokenSelectionAndRevisions(): void
    {
        $delete = '/automation/tree-rules/actions/delete?ids[]=3&ids[]=7';
        $fields = self::action([3, 7]);
        foreach ([
            'missing token' => array_diff_key($fields, ['_token' => true]),
            'extra field' => $fields + ['drp_action' => '99'],
            'name format on a delete' => $fields + ['name_format' => '<rule_name>'],
            'another selection' => ['selection' => '[7]'] + $fields,
            'text ids' => ['selection' => '["3","7"]'] + $fields,
            'malformed selection' => ['selection' => '['] + $fields,
            'array selection' => ['selection' => ['3', '7']] + $fields,
            'revisions for other rules' => ['revisions' => json_encode(['3' => self::REVISION, '5' => self::REVISION])] + $fields,
            'missing revision' => ['revisions' => json_encode(['3' => self::REVISION])] + $fields,
            'non-text revision' => ['revisions' => '{"3":1,"7":2}'] + $fields,
            'nested revisions' => ['revisions' => '{"3":{"a":"b"},"7":"x"}'] + $fields,
            'malformed revisions' => ['revisions' => 'null'] + $fields,
        ] as $case => $body) {
            self::assertSame(422, $this->submit($delete, ['automation_tree_rule_action' => $body])->getStatusCode(), $case);
        }
        self::assertSame(422, $this->submit($delete, ['automation_tree_rule_action' => $fields], 'https://attacker.invalid')->getStatusCode());
        self::assertSame([], $this->bulk);

        foreach (['stale' => 409, 'gone' => 404, 'remote' => 502] as $revision => $status) {
            $refused = $this->submit($delete, ['automation_tree_rule_action' => ['revisions' => json_encode(['3' => self::REVISION, '7' => $revision])] + $fields]);
            self::assertSame($status, $refused->getStatusCode(), $revision);
            self::assertStringNotContainsString('primary collector', (string) $refused->getContent());
        }
        foreach (['delete' => 'Delete', 'duplicate' => 'Duplicate', 'enable' => 'Enable', 'disable' => 'Disable'] as $operation => $label) {
            $uri = '/automation/tree-rules/actions/' . $operation . '?ids[]=3&ids[]=7';
            $extra = $operation === 'duplicate' ? ['name_format' => TreeRule::NAME_FORMAT] : [];
            $uncertain = $this->submit($uri, ['automation_tree_rule_action' => ['revisions' => json_encode(['3' => self::REVISION, '7' => 'remote'])] + $extra + $fields]);
            self::assertSame(502, $uncertain->getStatusCode(), $operation);
            self::assertStringContainsString($label . ' outcome is uncertain. Check the Tree Rule list before retrying.', (string) $uncertain->getContent(), $operation);
            // Access revoked while the write runs is a denial, not an uncertain outcome.
            $revoked = $this->submit($uri, ['automation_tree_rule_action' => ['revisions' => json_encode(['3' => self::REVISION, '7' => 'revoked'])] + $extra + $fields]);
            self::assertSame(403, $revoked->getStatusCode(), $operation);
            self::assertStringContainsString('Access denied.', (string) $revoked->getContent(), $operation);
        }
        self::assertSame([], $this->bulk);

        $deleted = $this->submit($delete, ['automation_tree_rule_action' => $fields]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/automation/tree-rules', $deleted->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $deleted->headers->get('Cache-Control'));
        $revisions = [3 => self::REVISION, 7 => self::REVISION];
        self::assertSame([['deleteRules', [42, [3, 7], $revisions]]], $this->bulk);
        foreach (['enable' => true, 'disable' => false] as $operation => $enabled) {
            self::assertSame(303, $this->submit('/automation/tree-rules/actions/' . $operation . '?ids[]=3&ids[]=7', ['automation_tree_rule_action' => $fields])->getStatusCode());
            self::assertSame(['setEnabled', [42, [3, 7], $revisions, $enabled]], $this->bulk[array_key_last($this->bulk)]);
        }

        $duplicate = '/automation/tree-rules/actions/duplicate?ids[]=7';
        $one = ['name_format' => ' <rule_name> copy '] + self::action([7]);
        self::assertSame(422, $this->submit($duplicate, ['automation_tree_rule_action' => ['name_format' => ''] + $one])->getStatusCode());
        self::assertSame(422, $this->submit($duplicate, ['automation_tree_rule_action' => ['name_format' => ['<rule_name>']] + $one])->getStatusCode());
        self::assertCount(3, $this->bulk);
        self::assertSame(303, $this->submit($duplicate, ['automation_tree_rule_action' => $one])->getStatusCode());
        // The format is passed as typed; legacy did not trim it either.
        self::assertSame(['duplicateRules', [42, [7], [7 => self::REVISION], ' <rule_name> copy ']], $this->bulk[3]);
    }

    public function testBulkBackendFailuresBecomeBadGatewayWithoutWriting(): void
    {
        $action = '/automation/tree-rules/actions/enable?ids[]=7';
        $this->authorizationFails = true;
        $response = $this->get($action);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Unable to load Tree Rules. Reload before retrying.', (string) $response->getContent());
        self::assertSame(502, $this->submit($action, ['automation_tree_rule_action' => self::action([7])])->getStatusCode());
        self::assertSame(0, $this->reads);

        $this->authorizationFails = false;
        $this->readFails = true;
        $response = $this->get($action);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Unable to load Tree Rules. Reload before retrying.', (string) $response->getContent());
        self::assertSame(1, $this->reads);
        self::assertSame([], $this->bulk);
    }

    public function testBulkPagesKeepEveryLinkAndRedirectBelowASubdirectory(): void
    {
        $server = ['SCRIPT_NAME' => '/cacti/app.php', 'SCRIPT_FILENAME' => '/srv/cacti/app.php', 'HTTP_ORIGIN' => 'http://localhost'];
        $page = (string) $this->kernel->handle(Request::create('/cacti/app.php/automation/tree-rules/actions/duplicate?ids[]=7', server: $server))->getContent();
        self::assertStringContainsString('action="/cacti/app.php/automation/tree-rules/actions/duplicate?ids%5B0%5D=7"', $page);
        self::assertStringContainsString('href="/cacti/app.php/automation/tree-rules"', $page);
        $disabled = $this->kernel->handle(Request::create('/cacti/app.php/automation/tree-rules/actions/disable?ids[]=7', 'POST', ['automation_tree_rule_action' => self::action([7])], server: $server));
        self::assertSame('/cacti/app.php/automation/tree-rules', $disabled->headers->get('Location'));
    }

    public function testBulkPagesRenderInTheConfiguredLocale(): void
    {
        $this->configuration['forced_locale'] = 'fr-FR';
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT, value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $container->set(LocalePreference::class, $this->createMock(LocalePreference::class));
        $html = (string) $this->kernel->handle(Request::create('/automation/tree-rules/actions/duplicate?ids[]=7', 'GET', [], ['Cacti' => 'fixture']))->getContent();
        self::assertStringContainsString('<html lang="fr">', $html);
        foreach (['Dupliquer des règles d’arbre', 'Format du titre', 'Continuer', 'Annuler', 'Routers &lt;b&gt;', 'value="&lt;rule_name&gt; (1)"'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testAnOfflineCollectorDoesNotServeTheBulkActionsFromLocalData(): void
    {
        $directory = sys_get_temp_dir() . '/tree-rules-offline-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/config.php', "<?php\n\$poller_id = 2;\n\$conn_mode = 'offline';\n");
        try {
            $requests = new RequestStack();
            $request = Request::create('/automation/tree-rules/actions/delete');
            $request->attributes->set('_route', 'automation_tree_rule_action');
            $requests->push($request);
            try {
                (new InstallationConfiguration($directory, $requests))->values();
                self::fail('An offline collector configured the Tree Rule bulk actions.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('requires the primary MySQL installation', $error->getMessage());
            }
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }

    /**
     * The confirmation form's fields for these rules, each at the fixture revision.
     *
     * @param list<int> $ids
     * @return array<string, string>
     */
    private static function action(array $ids): array
    {
        return ['selection' => json_encode($ids, JSON_THROW_ON_ERROR), 'revisions' => json_encode(array_fill_keys(array_map('strval', $ids), self::REVISION), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), '_token' => 'csrf-token'];
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

    /** @param array<string, mixed> $body */
    private function submit(string $uri, array $body, string $origin = 'http://localhost'): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', $body, server: ['HTTP_ORIGIN' => $origin]));
    }
}
