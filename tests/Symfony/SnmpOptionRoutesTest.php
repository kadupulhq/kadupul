<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\SnmpOptionStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\SnmpOptionEntries;
use Kadupul\Automation\Domain\SnmpOptionEntry;
use Kadupul\Automation\Domain\SnmpOptionEntryChange;
use Kadupul\Automation\Domain\SnmpOptionSet;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Symfony\LegacyPageForwarder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class SnmpOptionRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string ORDER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const array ENTRY = ['snmp_version' => '2', 'snmp_port' => '161', 'snmp_timeout' => '500', 'snmp_retries' => '3', 'max_oids' => '10', 'bulk_walk_size' => '-1',
        'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => '',
        'snmp_community' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_priv_passphrase' => '', 'revision' => self::REVISION, '_token' => 'csrf-token'];

    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private bool $authorizationFails = false;
    private bool $readFails = false;
    private int $reads = 0;
    /** @var list<array{string, list<mixed>}> */
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
        $access->method('authorize')->willReturnCallback(fn(): Actor => match (true) {
            $this->denied => throw new AutomationTemplateAccessDenied(false),
            $this->authorizationFails => throw new \RuntimeException('authorization backend down'),
            default => $this->actor,
        });
        $container->set(AutomationTemplateAccess::class, $access);

        $sets = [3 => new SnmpOptionSet(3, 'Core <b>', self::REVISION), 4 => new SnmpOptionSet(4, 'Edge', self::REVISION)];
        $entries = [
            5 => new SnmpOptionEntry(5, 3, 1, 3, 161, 500, 3, 10, -1, 'SHA', 'AES', 'ctx', '', self::REVISION),
            6 => new SnmpOptionEntry(6, 3, 2, 2, 1161, 900, 2, 20, 0, '[None]', '[None]', '', '', self::REVISION),
        ];
        $store = $this->createMock(SnmpOptionStore::class);
        $store->method('entryDefaults')->willReturn((new SnmpOptionEntry(0, 0, 0, 2, 161, 500, 3, 10, 0, '[None]', '[None]', '', '', ''))->settings());
        $store->method('findSet')->willReturnCallback(function (int $id) use ($sets): ?SnmpOptionSet {
            $this->reads++;
            return $sets[$id] ?? null;
        });
        $store->method('findSets')->willReturnCallback(function (array $ids) use ($sets): array {
            $this->reads++;
            if ($this->readFails) {
                throw new \RuntimeException('database unavailable');
            }
            return array_intersect_key($sets, array_flip($ids));
        });
        foreach (['deleteSets', 'duplicateSets'] as $method) {
            $store->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): void {
                $revisions = is_array($arguments[2]) ? $arguments[2] : [];
                match (true) {
                    in_array('stale', $revisions, true) => throw new \InvalidArgumentException('The SNMP Option Set changed since you opened this form. Reload before saving.'),
                    in_array('gone', $revisions, true) => throw new \InvalidArgumentException('One or more selected SNMP Option Sets no longer exist.'),
                    in_array('revoked', $revisions, true) => throw new AutomationTemplateAccessDenied(false),
                    in_array('remote', $revisions, true) => throw new \RuntimeException('SNMP Option Sets must be changed on the primary collector.'),
                    ($arguments[3] ?? null) === '' => throw new \InvalidArgumentException('The name format must give every copy a name of 1 to 100 characters.'),
                    default => $this->writes[] = [$method, $arguments],
                };
            });
        }
        $store->method('entries')->willReturn(new SnmpOptionEntries(array_values($entries), self::ORDER));
        $store->method('findEntry')->willReturnCallback(fn(int $set, int $id): ?SnmpOptionEntry => $set === 3 ? ($entries[$id] ?? null) : null);
        foreach (['saveSet', 'saveEntry', 'deleteEntry', 'moveEntry'] as $method) {
            $store->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): int {
                if (str_contains(json_encode(array_filter($arguments, 'is_string'), JSON_THROW_ON_ERROR), 'stale')) {
                    throw new \InvalidArgumentException('The SNMP entry changed since you opened this form. Reload before saving.');
                }
                $this->writes[] = [$method, $arguments];
                return 3;
            });
        }
        $container->set(SnmpOptionStore::class, $store);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAnonymousAndUnauthorizedRequestsStopBeforeTheStore(): void
    {
        $paths = ['/automation/snmp-options/new', '/automation/snmp-options/3/edit', '/automation/snmp-options/3/entries/new',
            '/automation/snmp-options/3/entries/5/edit', '/automation/snmp-options/3/entries/5/delete',
            '/automation/snmp-options/actions/delete?ids[]=3', '/automation/snmp-options/actions/duplicate?ids[]=3', '/automation/snmp-options/legacy?action=edit&id=3'];
        foreach ([[null, false, 401], [new Actor(42, 'operator'), true, 403]] as [$actor, $denied, $status]) {
            $this->actor = $actor;
            $this->denied = $denied;
            foreach ($paths as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->post($path, [])->getStatusCode(), $path);
            }
            self::assertSame($status, $this->post('/automation/snmp-options/3/entries/5/move/down', ['automation_snmp_entry_order_5' => ['revision' => self::ORDER, '_token' => 'csrf-token']])->getStatusCode());
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
    }

    public function testSetPageEscapesTheNameListsEntriesAndSavesThroughTheStore(): void
    {
        $page = (string) $this->get('/automation/snmp-options/3/edit')->getContent();
        self::assertStringContainsString('Core &lt;b&gt;', $page);
        self::assertStringNotContainsString('Core <b>', $page);
        self::assertStringContainsString('/automation/snmp-options/3/entries/5/edit', $page);
        self::assertStringContainsString('/automation/snmp-options/3/entries/new', $page);
        // Only the first entry moves down and only the last moves up.
        self::assertStringContainsString('/automation/snmp-options/3/entries/5/move/down', $page);
        self::assertStringNotContainsString('/automation/snmp-options/3/entries/5/move/up', $page);
        self::assertStringContainsString('/automation/snmp-options/3/entries/6/move/up', $page);
        self::assertSame(404, $this->get('/automation/snmp-options/9/edit')->getStatusCode());
        self::assertSame(404, $this->get('/automation/snmp-options/9999999999/edit')->getStatusCode());

        self::assertSame(422, $this->post('/automation/snmp-options/3/edit', ['automation_snmp_option' => ['name' => 'Renamed', 'revision' => self::REVISION]])->getStatusCode());
        self::assertSame(422, $this->post('/automation/snmp-options/3/edit', ['automation_snmp_option' => ['name' => 'Renamed', 'revision' => self::REVISION, 'extra' => '1', '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame(409, $this->post('/automation/snmp-options/3/edit', ['automation_snmp_option' => ['name' => 'Renamed', 'revision' => 'stale', '_token' => 'csrf-token']])->getStatusCode());
        $saved = $this->post('/automation/snmp-options/3/edit', ['automation_snmp_option' => ['name' => 'Renamed', 'revision' => self::REVISION, '_token' => 'csrf-token']]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/automation/snmp-options/3/edit?saved=1', $saved->headers->get('Location'));
        self::assertSame(['saveSet', [42, 3, 'Renamed', self::REVISION]], $this->writes[0]);
        $this->post('/automation/snmp-options/new', ['automation_snmp_option' => ['name' => 'New', '_token' => 'csrf-token']]);
        self::assertSame(['saveSet', [42, null, 'New', null]], $this->writes[1]);
        self::assertStringContainsString('SNMP Option Set saved.', (string) $this->get('/automation/snmp-options/3/edit?saved=1')->getContent());
        // An array where a flag is expected is ignored rather than raising an error.
        self::assertSame(200, $this->get('/automation/snmp-options/3/edit?saved[]=1&entry[]=saved')->getStatusCode());
    }

    public function testEntryEditorNeverRendersCredentialsAndRequiresAnExplicitChoice(): void
    {
        $page = (string) $this->get('/automation/snmp-options/3/entries/5/edit')->getContent();
        self::assertStringContainsString('<option value="keep" selected="selected">', $page);
        foreach (['snmp_community', 'snmp_username', 'snmp_password', 'snmp_priv_passphrase'] as $field) {
            self::assertMatchesRegularExpression('/<input type="password"[^>]*name="automation_snmp_entry\[' . $field . '\]"(?![^>]*value="[^"])/', $page, $field);
        }
        self::assertSame(404, $this->get('/automation/snmp-options/3/entries/9/edit')->getStatusCode());
        self::assertSame(404, $this->get('/automation/snmp-options/9/entries/5/edit')->getStatusCode());

        $keep = ['keep_credentials' => 'keep'] + self::ENTRY;
        self::assertSame(422, $this->post('/automation/snmp-options/3/entries/6/edit', ['automation_snmp_entry' => ['snmp_password' => 'typed-anyway'] + $keep])->getStatusCode());
        self::assertSame(422, $this->post('/automation/snmp-options/3/entries/6/edit', ['automation_snmp_entry' => ['snmp_port' => ''] + $keep])->getStatusCode());
        self::assertSame(422, $this->post('/automation/snmp-options/3/entries/6/edit', ['automation_snmp_entry' => ['snmp_version' => '0'] + $keep])->getStatusCode());
        $rejected = $this->post('/automation/snmp-options/3/entries/6/edit', ['automation_snmp_entry' => ['snmp_password' => 'typed-anyway'] + $keep]);
        self::assertStringNotContainsString('typed-anyway', (string) $rejected->getContent());
        self::assertSame([], $this->writes);

        $saved = $this->post('/automation/snmp-options/3/entries/6/edit', ['automation_snmp_entry' => ['snmp_port' => '1161'] + $keep]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/automation/snmp-options/3/edit?entry=saved', $saved->headers->get('Location'));
        [$method, [$actor, $set, $entry, $change, $revision]] = $this->writes[0];
        self::assertSame(['saveEntry', 42, 3, 6, self::REVISION, true, 1161], [$method, $actor, $set, $entry, $revision, $change->keepCredentials, $change->settings['snmp_port']]);
        self::assertInstanceOf(SnmpOptionEntryChange::class, $change);

        // A new entry has no stored credentials, so the choice is absent and they are required.
        $new = (string) $this->get('/automation/snmp-options/3/entries/new')->getContent();
        self::assertStringNotContainsString('keep_credentials', $new);
        self::assertSame(422, $this->post('/automation/snmp-options/3/entries/new', ['automation_snmp_entry' => self::ENTRY])->getStatusCode());
        $this->post('/automation/snmp-options/3/entries/new', ['automation_snmp_entry' => ['snmp_community' => 'public'] + self::ENTRY]);
        self::assertSame([null, null, false], [$this->writes[1][1][2], $this->writes[1][1][4], $this->writes[1][1][3]->keepCredentials]);
    }

    public function testDeleteAndMoveNeedAPostWithTheShownRevision(): void
    {
        $confirmation = (string) $this->get('/automation/snmp-options/3/entries/5/delete')->getContent();
        self::assertStringContainsString('Click Continue to delete the following SNMP Option item.', $confirmation);
        self::assertStringContainsString('Core &lt;b&gt;', $confirmation);
        self::assertSame(422, $this->post('/automation/snmp-options/3/entries/5/delete', ['snmp_option_entry_confirm' => ['revision' => self::REVISION]])->getStatusCode());
        self::assertSame(409, $this->post('/automation/snmp-options/3/entries/5/delete', ['automation_snmp_entry_confirm' => ['revision' => 'stale', '_token' => 'csrf-token']])->getStatusCode());
        $deleted = $this->post('/automation/snmp-options/3/entries/5/delete', ['automation_snmp_entry_confirm' => ['revision' => self::REVISION, '_token' => 'csrf-token']]);
        self::assertSame('/automation/snmp-options/3/edit?entry=deleted', $deleted->headers->get('Location'));
        self::assertSame(['deleteEntry', [42, 3, 5, self::REVISION]], $this->writes[0]);

        self::assertSame(405, $this->get('/automation/snmp-options/3/entries/5/move/up')->getStatusCode());
        self::assertSame(422, $this->post('/automation/snmp-options/3/entries/5/move/up', ['automation_snmp_entry_order_5' => ['revision' => self::ORDER]])->getStatusCode());
        self::assertSame(422, $this->post('/automation/snmp-options/3/entries/5/move/up', ['automation_snmp_entry_order_6' => ['revision' => self::ORDER, '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame(409, $this->post('/automation/snmp-options/3/entries/5/move/up', ['automation_snmp_entry_order_5' => ['revision' => 'stale', '_token' => 'csrf-token']])->getStatusCode());
        $moved = $this->post('/automation/snmp-options/3/entries/6/move/up', ['automation_snmp_entry_order_6' => ['revision' => self::ORDER, '_token' => 'csrf-token']]);
        self::assertSame('/automation/snmp-options/3/edit?entry=moved', $moved->headers->get('Location'));
        self::assertSame(['moveEntry', [42, 3, 6, -1, self::ORDER]], $this->writes[1]);
        self::assertSame(404, $this->post('/automation/snmp-options/3/entries/6/move/left', ['automation_snmp_entry_order_6' => ['revision' => self::ORDER, '_token' => 'csrf-token']])->getStatusCode());
    }

    public function testBulkConfirmationListsTheSelectionAndRefusesMalformedOnesBeforeReading(): void
    {
        $action = '/automation/snmp-options/actions/';
        $response = $this->get($action . 'delete?ids[]=4&ids[]=3&ids[]=4');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $page = (string) $response->getContent();
        self::assertStringContainsString('Click Continue to delete the following SNMP Option Sets and all their entries.', $page);
        self::assertStringContainsString('<li>Core &lt;b&gt;</li>', $page);
        self::assertStringContainsString('<li>Edge</li>', $page);
        self::assertStringContainsString('name="automation_snmp_option_action[selection]" value="[3,4]"', $page);
        self::assertStringContainsString('action="/automation/snmp-options/actions/delete?ids%5B0%5D=3&amp;ids%5B1%5D=4"', $page);
        self::assertStringContainsString('href="/automation/snmp-options"', $page);
        self::assertStringNotContainsString('name_format', $page);
        // CSP: the page carries no inline script or handler.
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)|\son[a-z]+=/i', $page);
        self::assertSame('', $this->kernel->handle(Request::create($action . 'delete?ids[]=3', 'HEAD'))->getContent());

        $duplicate = (string) $this->get($action . 'duplicate?ids[]=3')->getContent();
        self::assertStringContainsString('name="automation_snmp_option_action[name_format]"', $duplicate);
        self::assertStringContainsString('value="&lt;name&gt; (1)"', $duplicate);
        self::assertStringContainsString('maxlength="255"', $duplicate);

        $reads = $this->reads;
        self::assertStringContainsString('Select at least one SNMP Option Set.', (string) $this->get($action . 'delete')->getContent());
        self::assertStringContainsString('Select at most 100 SNMP Option Sets at a time.', (string) $this->get($action . 'delete?' . http_build_query(['ids' => array_map('strval', range(1, 101))]))->getContent());
        foreach (['', 'ids=3', 'ids[]=x', 'ids[]=0', 'ids[]=03', 'ids[]=1e2', 'ids[]=-3', 'ids[]=3.0', 'ids[]=4294967296', 'ids[]=99999999999', 'ids[a]=3', 'ids[][]=3', 'ids[]=3&ids[]=',
            http_build_query(['ids' => array_map('strval', range(1, 101))])] as $query) {
            foreach (['delete', 'duplicate'] as $operation) {
                self::assertSame(400, $this->get($action . $operation . '?' . $query)->getStatusCode(), $query);
                self::assertSame(400, $this->post($action . $operation . '?' . $query, ['automation_snmp_option_action' => ['selection' => '[3]', 'revisions' => '{"3":"x"}', '_token' => 'csrf-token']])->getStatusCode(), $query);
            }
        }
        self::assertSame($reads, $this->reads);
        self::assertSame(404, $this->get($action . 'delete?ids[]=3&ids[]=9')->getStatusCode());
        self::assertSame(404, $this->get($action . 'purge?ids[]=3')->getStatusCode());
        self::assertSame(200, $this->get($action . 'delete?' . http_build_query(['ids' => array_fill(0, 100, '3')]))->getStatusCode());
        self::assertSame([], $this->writes);
    }

    public function testBulkActionsWriteOnlyFromAPostWithTheTokenSelectionAndRevisions(): void
    {
        $delete = '/automation/snmp-options/actions/delete?ids[]=3&ids[]=4';
        $fields = ['selection' => '[3,4]', 'revisions' => json_encode(['3' => self::REVISION, '4' => self::REVISION]), '_token' => 'csrf-token'];
        foreach ([
            'missing token' => array_diff_key($fields, ['_token' => true]),
            'extra field' => $fields + ['drp_action' => '1'],
            'name format on a delete' => $fields + ['name_format' => '<name>'],
            'another selection' => ['selection' => '[3]'] + $fields,
            'text ids' => ['selection' => '["3","4"]'] + $fields,
            'malformed selection' => ['selection' => '['] + $fields,
            'array selection' => ['selection' => ['3', '4']] + $fields,
            'revisions for other sets' => ['revisions' => json_encode(['3' => self::REVISION, '5' => self::REVISION])] + $fields,
            'missing revision' => ['revisions' => json_encode(['3' => self::REVISION])] + $fields,
            'non-text revision' => ['revisions' => '{"3":1,"4":2}'] + $fields,
            'nested revisions' => ['revisions' => '{"3":{"a":"b"},"4":"x"}'] + $fields,
            'malformed revisions' => ['revisions' => 'null'] + $fields,
        ] as $case => $body) {
            self::assertSame(422, $this->post($delete, ['automation_snmp_option_action' => $body])->getStatusCode(), $case);
        }
        self::assertSame(422, $this->post($delete, ['automation_snmp_option_action' => $fields], 'https://attacker.invalid')->getStatusCode());
        self::assertSame([], $this->writes);

        foreach (['stale' => 409, 'gone' => 404, 'remote' => 502] as $revision => $status) {
            $refused = $this->post($delete, ['automation_snmp_option_action' => ['revisions' => json_encode(['3' => self::REVISION, '4' => $revision])] + $fields]);
            self::assertSame($status, $refused->getStatusCode(), $revision);
            self::assertStringNotContainsString('primary collector', (string) $refused->getContent());
        }
        self::assertStringContainsString('Delete outcome is uncertain.', (string) $this->post($delete, ['automation_snmp_option_action' => ['revisions' => json_encode(['3' => self::REVISION, '4' => 'remote'])] + $fields])->getContent());
        // Access revoked while the write runs is a denial, not an uncertain outcome.
        foreach (['delete' => $delete, 'duplicate' => '/automation/snmp-options/actions/duplicate?ids[]=3&ids[]=4'] as $operation => $uri) {
            $revoked = $this->post($uri, ['automation_snmp_option_action' => ['revisions' => json_encode(['3' => self::REVISION, '4' => 'revoked'])] + ($operation === 'duplicate' ? ['name_format' => '<name> (1)'] : []) + $fields]);
            self::assertSame(403, $revoked->getStatusCode(), $operation);
            self::assertStringContainsString('Access denied.', (string) $revoked->getContent(), $operation);
            self::assertStringNotContainsString('outcome is uncertain', (string) $revoked->getContent(), $operation);
        }
        $uncertain = $this->post('/automation/snmp-options/actions/duplicate?ids[]=3&ids[]=4', ['automation_snmp_option_action' => ['revisions' => json_encode(['3' => self::REVISION, '4' => 'remote']), 'name_format' => '<name> (1)'] + $fields]);
        self::assertSame(502, $uncertain->getStatusCode());
        self::assertStringContainsString('Duplicate outcome is uncertain.', (string) $uncertain->getContent());
        self::assertSame([], $this->writes);

        $deleted = $this->post($delete, ['automation_snmp_option_action' => $fields]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/automation/snmp-options', $deleted->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $deleted->headers->get('Cache-Control'));
        self::assertSame([['deleteSets', [42, [3, 4], [3 => self::REVISION, 4 => self::REVISION]]]], $this->writes);

        $duplicate = '/automation/snmp-options/actions/duplicate?ids[]=3';
        $one = ['selection' => '[3]', 'revisions' => json_encode(['3' => self::REVISION]), 'name_format' => ' <name> copy ', '_token' => 'csrf-token'];
        self::assertSame(422, $this->post($duplicate, ['automation_snmp_option_action' => ['name_format' => ''] + $one])->getStatusCode());
        self::assertSame(422, $this->post($duplicate, ['automation_snmp_option_action' => ['name_format' => ['<name>']] + $one])->getStatusCode());
        self::assertCount(1, $this->writes);
        self::assertSame(303, $this->post($duplicate, ['automation_snmp_option_action' => $one])->getStatusCode());
        // The format is passed as typed; legacy did not trim it either.
        self::assertSame(['duplicateSets', [42, [3], [3 => self::REVISION], ' <name> copy ']], $this->writes[1]);
    }

    public function testLegacyEntryNavigatesAndNeverReadsOrWrites(): void
    {
        $legacy = '/automation/snmp-options/legacy';
        foreach ([
            ['action' => 'actions', 'drp_action' => '1', 'chk_3' => 'on'],
            ['action' => 'actions', 'drp_action' => '1', 'selected_items' => 'a:1:{i:0;s:1:"3";}'],
            ['action' => 'actions', 'drp_action' => '2', 'selected_items' => 'a:1:{i:0;s:1:"3";}', 'name_format' => '<name> (1)'],
            ['action' => 'save', 'id' => '3', 'name' => 'Replayed'],
            ['action' => 'ajax_dnd', 'snmp_item' => ['line5', 'line6']],
            ['action' => ['actions']],
            [],
        ] as $body) {
            $response = $this->post($legacy, $body);
            self::assertSame(409, $response->getStatusCode());
            self::assertStringContainsString('This SNMP Option Set form has expired.', (string) $response->getContent());
        }
        foreach ([
            '?action=edit&id=3' => '/automation/snmp-options/3/edit',
            '?action=edit' => '/automation/snmp-options/new',
            '?action=edit&id=0&header=false' => '/automation/snmp-options/new',
            '?action=item_edit&id=3&item_id=5' => '/automation/snmp-options/3/entries/5/edit',
            '?action=item_edit&id=3' => '/automation/snmp-options/3/entries/new',
            '?action=item_edit&item_id=5' => '/automation/snmp-options',
            '?action=actions&drp_action=1' => '/automation/snmp-options',
            '?header=false' => '/automation/snmp-options',
            '?clear=1&header=false&filter=old' => '/automation/snmp-options',
            '?filter=core&rows=-1&header=false' => '/automation/snmp-options?filter=core&rows=-1',
            '?sort_column=v3entries&sort_direction=DESC&page=2' => '/automation/snmp-options?page=2&sort_column=v3entries&sort_direction=DESC',
            '' => '/automation/snmp-options',
        ] as $query => $location) {
            $response = $this->get($legacy . $query);
            self::assertSame(302, $response->getStatusCode(), $query);
            self::assertSame($location, $response->headers->get('Location'), $query);
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
        foreach (['save', 'ajax_dnd', 'item_movedown', 'item_moveup', 'item_remove_confirm', 'item_remove'] as $action) {
            self::assertSame(409, $this->get($legacy . '?action=' . $action . '&id=3&item_id=5')->getStatusCode(), $action);
        }
        foreach (['action=edit&id=x', 'action=edit&id[]=3', 'action=edit&id=03', 'action=edit&id=99999999999', 'action=item_edit&id=3&item_id=1e1', 'action[]=edit', 'action=other',
            'clear=2', 'rows=0', 'page=0', 'sort_column=snmp_password', 'sort_direction=down', 'filter[]=x'] as $query) {
            self::assertSame(400, $this->get($legacy . '?' . $query)->getStatusCode(), $query);
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
    }

    public function testBackendFailuresBecomeBadGatewayWithoutReadingOrWriting(): void
    {
        $action = '/automation/snmp-options/actions/delete?ids[]=3';
        $this->authorizationFails = true;
        foreach (['/automation/snmp-options/legacy?action=edit&id=3', $action] as $uri) {
            $response = $this->get($uri);
            self::assertSame(502, $response->getStatusCode(), $uri);
            self::assertStringContainsString('Unable to load SNMP Option Sets. Reload before retrying.', (string) $response->getContent(), $uri);
        }
        self::assertSame(502, $this->post($action, ['automation_snmp_option_action' => ['selection' => '[3]', 'revisions' => '{"3":"x"}', '_token' => 'csrf-token']])->getStatusCode());
        self::assertSame(0, $this->reads);

        $this->authorizationFails = false;
        $this->readFails = true;
        $response = $this->get($action);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Unable to load SNMP Option Sets. Reload before retrying.', (string) $response->getContent());
        self::assertSame(1, $this->reads);
        self::assertSame([], $this->writes);
    }

    public function testSubdirectoryInstallsKeepEveryLinkAndRedirectBelowTheirPath(): void
    {
        $server = ['SCRIPT_NAME' => '/cacti/app.php', 'SCRIPT_FILENAME' => '/srv/cacti/app.php', 'HTTP_ORIGIN' => 'http://localhost'];
        $page = (string) $this->kernel->handle(Request::create('/cacti/app.php/automation/snmp-options/actions/duplicate?ids[]=3', server: $server))->getContent();
        self::assertStringContainsString('action="/cacti/app.php/automation/snmp-options/actions/duplicate?ids%5B0%5D=3"', $page);
        self::assertStringContainsString('href="/cacti/app.php/automation/snmp-options"', $page);
        $fields = ['selection' => '[3]', 'revisions' => json_encode(['3' => self::REVISION]), '_token' => 'csrf-token'];
        $deleted = $this->kernel->handle(Request::create('/cacti/app.php/automation/snmp-options/actions/delete?ids[]=3', 'POST', ['automation_snmp_option_action' => $fields], server: $server));
        self::assertSame('/cacti/app.php/automation/snmp-options', $deleted->headers->get('Location'));
        // automation_snmp.php in the same directory forwards with this script name.
        $forwarded = LegacyPageForwarder::request(Request::create('/cacti/automation_snmp.php?action=edit&id=3', server: ['SCRIPT_NAME' => '/cacti/automation_snmp.php', 'SCRIPT_FILENAME' => '/srv/cacti/automation_snmp.php']), '/srv/cacti', '/automation/snmp-options/legacy');
        self::assertSame('/cacti/app.php/automation/snmp-options/3/edit', $this->kernel->handle($forwarded)->headers->get('Location'));
    }

    public function testBulkPagesFollowTheUsersFrenchPreferenceButNotStoredText(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT, value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $preference = $this->createMock(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn('fr-FR');
        $container->set(LocalePreference::class, $preference);
        $request = Request::create('/automation/snmp-options/actions/duplicate?ids[]=3', cookies: ['Cacti' => 'fixture']);
        $html = (string) $this->kernel->handle($request)->getContent();
        self::assertSame('fr', $request->getLocale());
        self::assertStringContainsString('<html lang="fr">', $html);
        self::assertStringContainsString('Dupliquer des ensembles d’options SNMP', $html);
        self::assertStringContainsString('Format de nom', $html);
        self::assertStringContainsString('Core &lt;b&gt;', $html);
        // The token the server replaces is not translated.
        self::assertStringContainsString('value="&lt;name&gt; (1)"', $html);
        $expired = $this->kernel->handle(Request::create('/automation/snmp-options/legacy', 'POST', ['action' => 'save'], ['Cacti' => 'fixture'], server: ['HTTP_ORIGIN' => 'http://localhost']));
        self::assertStringContainsString('Ce formulaire d’ensemble d’options SNMP a expiré.', (string) $expired->getContent());
    }

    public function testAnOfflineCollectorDoesNotServeTheBulkOrLegacyRoutesFromLocalData(): void
    {
        $directory = sys_get_temp_dir() . '/snmp-options-offline-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/config.php', "<?php\n\$poller_id = 2;\n\$conn_mode = 'offline';\n");
        try {
            foreach (['automation_snmp_option_action', 'automation_snmp_options_legacy'] as $route) {
                $requests = new RequestStack();
                $request = Request::create('/automation/snmp-options/legacy');
                $request->attributes->set('_route', $route);
                $requests->push($request);
                try {
                    (new InstallationConfiguration($directory, $requests))->values();
                    self::fail('An offline collector configured ' . $route);
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('requires the primary MySQL installation', $error->getMessage());
                }
            }
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
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
