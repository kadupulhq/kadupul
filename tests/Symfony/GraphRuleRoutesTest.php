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
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class GraphRuleRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    /** The browser submission of the stored rule; an unchecked box is absent. */
    private const array FIELDS = ['name' => 'Traffic <b>', 'snmp_query_id' => '1', 'graph_type_id' => '12', 'enabled' => '1', 'revision' => self::REVISION, '_token' => 'csrf-token'];

    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private bool $authorizationFails = false;
    private bool $readFails = false;
    private int $reads = 0;
    private ?GraphRuleRejected $reject = null;
    /** @var array<string, mixed> */
    private array $configuration = ['collector_id' => 1];
    /** @var list<array{int, ?int, GraphRuleChange, ?string}> */
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
        $rules = [3 => new GraphRule(3, 'Disks <i>', 3, 17, false, self::REVISION), 7 => new GraphRule(7, 'Traffic <b>', 1, 12, true, self::REVISION)];
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
                    in_array('stale', $revisions, true) => throw new \InvalidArgumentException('The graph rule changed since you opened this form. Reload before saving.'),
                    in_array('gone', $revisions, true) => throw new \InvalidArgumentException('One or more selected Graph Rules no longer exist.'),
                    in_array('revoked', $revisions, true) => throw new AutomationTemplateAccessDenied(false),
                    in_array('remote', $revisions, true) => throw new \RuntimeException('Graph Rules must be changed on the primary collector.'),
                    ($arguments[3] ?? null) === '' => throw new \InvalidArgumentException('The name format must give every copy a name of 1 to 255 characters.'),
                    default => $this->bulk[] = [$method, $arguments],
                };
            });
        }
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
            foreach (['/automation/graph-rules/new', '/automation/graph-rules/7/edit', '/automation/graph-rules/actions/delete?ids[]=7', '/automation/graph-rules/actions/duplicate?ids[]=7',
                '/automation/graph-rules/actions/enable?ids[]=7', '/automation/graph-rules/actions/disable?ids[]=7'] as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->post($path, self::FIELDS)->getStatusCode(), $path);
                self::assertSame($status, $this->submit($path, ['automation_graph_rule_action' => self::action([7])])->getStatusCode(), $path);
            }
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->bulk);
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

    public function testBulkConfirmationListsTheSelectionAndRefusesMalformedOnesBeforeReading(): void
    {
        $action = '/automation/graph-rules/actions/';
        $response = $this->get($action . 'delete?ids[]=7&ids[]=3&ids[]=7');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $page = (string) $response->getContent();
        self::assertStringContainsString('Click Continue to delete the following Graph Rules with their Device Selection and Graph Creation Criteria.', $page);
        self::assertStringContainsString('<li>Disks &lt;i&gt;</li>', $page);
        self::assertStringContainsString('<li>Traffic &lt;b&gt;</li>', $page);
        self::assertStringContainsString('name="automation_graph_rule_action[selection]" value="[3,7]"', $page);
        self::assertStringContainsString('action="/automation/graph-rules/actions/delete?ids%5B0%5D=3&amp;ids%5B1%5D=7"', $page);
        self::assertStringContainsString('href="/automation/graph-rules"', $page);
        self::assertStringNotContainsString('name_format', $page);
        // CSP: the page carries no inline script or handler.
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)|\son[a-z]+=/i', $page);
        self::assertSame('', $this->kernel->handle(Request::create($action . 'delete?ids[]=7', 'HEAD'))->getContent());

        foreach (['enable' => 'Click Continue to enable the following Graph Rules. Make sure that they have been tested successfully.',
            'disable' => 'Click Continue to disable the following Graph Rules.'] as $operation => $intro) {
            $confirmation = (string) $this->get($action . $operation . '?ids[]=7')->getContent();
            self::assertStringContainsString($intro, $confirmation, $operation);
            self::assertStringNotContainsString('name_format', $confirmation, $operation);
        }
        $duplicate = (string) $this->get($action . 'duplicate?ids[]=7')->getContent();
        self::assertStringContainsString('name="automation_graph_rule_action[name_format]"', $duplicate);
        self::assertStringContainsString('value="&lt;rule_name&gt; (1)"', $duplicate);
        self::assertStringContainsString('maxlength="255"', $duplicate);
        self::assertStringContainsString('Title Format', $duplicate);

        $reads = $this->reads;
        self::assertStringContainsString('Select at least one Graph Rule.', (string) $this->get($action . 'delete')->getContent());
        self::assertStringContainsString('Select at most 100 Graph Rules at a time.', (string) $this->get($action . 'enable?' . http_build_query(['ids' => array_map('strval', range(1, 101))]))->getContent());
        foreach (['', 'ids=7', 'ids[]=x', 'ids[]=0', 'ids[]=07', 'ids[]=1e2', 'ids[]=-7', 'ids[]=7.0', 'ids[]=16777216', 'ids[]=999999999', 'ids[a]=7', 'ids[][]=7', 'ids[]=7&ids[]=',
            http_build_query(['ids' => array_map('strval', range(1, 101))])] as $query) {
            foreach (['delete', 'duplicate', 'enable', 'disable'] as $operation) {
                self::assertSame(400, $this->get($action . $operation . '?' . $query)->getStatusCode(), $query);
                self::assertSame(400, $this->submit($action . $operation . '?' . $query, ['automation_graph_rule_action' => self::action([7])])->getStatusCode(), $query);
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
        $delete = '/automation/graph-rules/actions/delete?ids[]=3&ids[]=7';
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
            self::assertSame(422, $this->submit($delete, ['automation_graph_rule_action' => $body])->getStatusCode(), $case);
        }
        self::assertSame(422, $this->submit($delete, ['automation_graph_rule_action' => $fields], 'https://attacker.invalid')->getStatusCode());
        self::assertSame([], $this->bulk);

        foreach (['stale' => 409, 'gone' => 404, 'remote' => 502] as $revision => $status) {
            $refused = $this->submit($delete, ['automation_graph_rule_action' => ['revisions' => json_encode(['3' => self::REVISION, '7' => $revision])] + $fields]);
            self::assertSame($status, $refused->getStatusCode(), $revision);
            self::assertStringNotContainsString('primary collector', (string) $refused->getContent());
        }
        foreach (['delete' => 'Delete', 'duplicate' => 'Duplicate', 'enable' => 'Enable', 'disable' => 'Disable'] as $operation => $label) {
            $uri = '/automation/graph-rules/actions/' . $operation . '?ids[]=3&ids[]=7';
            $extra = $operation === 'duplicate' ? ['name_format' => GraphRule::NAME_FORMAT] : [];
            $uncertain = $this->submit($uri, ['automation_graph_rule_action' => ['revisions' => json_encode(['3' => self::REVISION, '7' => 'remote'])] + $extra + $fields]);
            self::assertSame(502, $uncertain->getStatusCode(), $operation);
            self::assertStringContainsString($label . ' outcome is uncertain. Check the Graph Rule list before retrying.', (string) $uncertain->getContent(), $operation);
            // Access revoked while the write runs is a denial, not an uncertain outcome.
            $revoked = $this->submit($uri, ['automation_graph_rule_action' => ['revisions' => json_encode(['3' => self::REVISION, '7' => 'revoked'])] + $extra + $fields]);
            self::assertSame(403, $revoked->getStatusCode(), $operation);
            self::assertStringContainsString('Access denied.', (string) $revoked->getContent(), $operation);
        }
        self::assertSame([], $this->bulk);

        $deleted = $this->submit($delete, ['automation_graph_rule_action' => $fields]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/automation/graph-rules', $deleted->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $deleted->headers->get('Cache-Control'));
        $revisions = [3 => self::REVISION, 7 => self::REVISION];
        self::assertSame([['deleteRules', [42, [3, 7], $revisions]]], $this->bulk);
        foreach (['enable' => true, 'disable' => false] as $operation => $enabled) {
            self::assertSame(303, $this->submit('/automation/graph-rules/actions/' . $operation . '?ids[]=3&ids[]=7', ['automation_graph_rule_action' => $fields])->getStatusCode());
            self::assertSame(['setEnabled', [42, [3, 7], $revisions, $enabled]], $this->bulk[array_key_last($this->bulk)]);
        }

        $duplicate = '/automation/graph-rules/actions/duplicate?ids[]=7';
        $one = ['name_format' => ' <rule_name> copy '] + self::action([7]);
        self::assertSame(422, $this->submit($duplicate, ['automation_graph_rule_action' => ['name_format' => ''] + $one])->getStatusCode());
        self::assertSame(422, $this->submit($duplicate, ['automation_graph_rule_action' => ['name_format' => ['<rule_name>']] + $one])->getStatusCode());
        self::assertCount(3, $this->bulk);
        self::assertSame(303, $this->submit($duplicate, ['automation_graph_rule_action' => $one])->getStatusCode());
        // The format is passed as typed; legacy did not trim it either.
        self::assertSame(['duplicateRules', [42, [7], [7 => self::REVISION], ' <rule_name> copy ']], $this->bulk[3]);
    }

    public function testBulkBackendFailuresBecomeBadGatewayWithoutWriting(): void
    {
        $action = '/automation/graph-rules/actions/enable?ids[]=7';
        $this->authorizationFails = true;
        $response = $this->get($action);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Unable to load Graph Rules. Reload before retrying.', (string) $response->getContent());
        self::assertSame(502, $this->submit($action, ['automation_graph_rule_action' => self::action([7])])->getStatusCode());
        self::assertSame(0, $this->reads);

        $this->authorizationFails = false;
        $this->readFails = true;
        $response = $this->get($action);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Unable to load Graph Rules. Reload before retrying.', (string) $response->getContent());
        self::assertSame(1, $this->reads);
        self::assertSame([], $this->bulk);
    }

    public function testBulkPagesKeepEveryLinkAndRedirectBelowASubdirectory(): void
    {
        $server = ['SCRIPT_NAME' => '/cacti/app.php', 'SCRIPT_FILENAME' => '/srv/cacti/app.php', 'HTTP_ORIGIN' => 'http://localhost'];
        $page = (string) $this->kernel->handle(Request::create('/cacti/app.php/automation/graph-rules/actions/duplicate?ids[]=7', server: $server))->getContent();
        self::assertStringContainsString('action="/cacti/app.php/automation/graph-rules/actions/duplicate?ids%5B0%5D=7"', $page);
        self::assertStringContainsString('href="/cacti/app.php/automation/graph-rules"', $page);
        $disabled = $this->kernel->handle(Request::create('/cacti/app.php/automation/graph-rules/actions/disable?ids[]=7', 'POST', ['automation_graph_rule_action' => self::action([7])], server: $server));
        self::assertSame('/cacti/app.php/automation/graph-rules', $disabled->headers->get('Location'));
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
        $html = (string) $this->kernel->handle(Request::create('/automation/graph-rules/actions/duplicate?ids[]=7', 'GET', [], ['Cacti' => 'fixture']))->getContent();
        self::assertStringContainsString('<html lang="fr">', $html);
        foreach (['Dupliquer des règles de graphique', 'Format du titre', 'Continuer', 'Annuler', 'Traffic &lt;b&gt;', 'value="&lt;rule_name&gt; (1)"'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testAnOfflineCollectorDoesNotServeTheBulkActionsFromLocalData(): void
    {
        $directory = sys_get_temp_dir() . '/graph-rules-offline-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/config.php', "<?php\n\$poller_id = 2;\n\$conn_mode = 'offline';\n");
        try {
            $requests = new RequestStack();
            $request = Request::create('/automation/graph-rules/actions/delete');
            $request->attributes->set('_route', 'automation_graph_rule_action');
            $requests->push($request);
            try {
                (new InstallationConfiguration($directory, $requests))->values();
                self::fail('An offline collector configured the Graph Rule bulk actions.');
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
        return $this->kernel->handle(Request::create($uri, 'POST', ['graph_rule' => $fields], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }

    /** @param array<string, mixed> $body */
    private function submit(string $uri, array $body, string $origin = 'http://localhost'): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', $body, server: ['HTTP_ORIGIN' => $origin]));
    }
}
