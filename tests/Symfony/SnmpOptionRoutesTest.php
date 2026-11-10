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
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
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
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new AutomationTemplateAccessDenied(false) : $this->actor);
        $container->set(AutomationTemplateAccess::class, $access);

        $sets = [3 => new SnmpOptionSet(3, 'Core <b>', self::REVISION)];
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
            '/automation/snmp-options/3/entries/5/edit', '/automation/snmp-options/3/entries/5/delete'];
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

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    /** @param array<string, mixed> $fields */
    private function post(string $uri, array $fields): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', $fields, server: ['HTTP_ORIGIN' => 'http://localhost']));
    }
}
