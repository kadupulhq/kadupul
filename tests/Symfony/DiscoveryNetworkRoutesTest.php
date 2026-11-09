<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\DiscoveryNetworkStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\DiscoveryNetwork;
use Kadupul\Automation\Domain\DiscoveryNetworkChange;
use Kadupul\Automation\Domain\DiscoveryNetworkRejected;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DiscoveryNetworkRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const array SETTINGS = ['name' => 'Core <b>', 'poller_id' => 1, 'site_id' => 0, 'subnet_range' => '192.168.1.0/24', 'dns_servers' => '',
        'enabled' => true, 'enable_netbios' => false, 'add_to_cacti' => false, 'same_sysname' => false, 'rerun_data_queries' => false,
        'notification_enabled' => false, 'notification_email' => '', 'notification_fromname' => '', 'notification_fromemail' => '',
        'snmp_id' => 1, 'ping_method' => 2, 'ping_port' => 23, 'ping_timeout' => 400, 'ping_retries' => 1,
        'sched_type' => 3, 'threads' => 1, 'run_limit' => 1200, 'start_at' => '2026-11-09 10:00', 'recur_every' => 1,
        'day_of_week' => [2], 'month' => [], 'day_of_month' => [], 'monthly_week' => [], 'monthly_day' => []];
    /** The browser submission of SETTINGS: unchecked boxes and empty selections are absent. */
    private const array FIELDS = ['name' => 'Core', 'poller_id' => '1', 'site_id' => '0', 'subnet_range' => '192.168.1.0/24', 'dns_servers' => '',
        'enabled' => '1', 'notification_email' => '', 'notification_fromname' => '', 'notification_fromemail' => '', 'snmp_id' => '1', 'ping_method' => '2',
        'ping_port' => '23', 'ping_timeout' => '400', 'ping_retries' => '1', 'sched_type' => '3', 'threads' => '1', 'run_limit' => '1200',
        'start_at' => '2026-11-09 10:00', 'recur_every' => '1', 'day_of_week' => ['2'], 'revision' => self::REVISION, '_token' => 'csrf-token'];

    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private int $reads = 0;
    private ?DiscoveryNetworkRejected $reject = null;
    /** @var array<string, mixed> */
    private array $configuration = ['collector_id' => 1];
    /** @var list<array{int, ?int, DiscoveryNetworkChange, ?string}> */
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

        $store = $this->createMock(DiscoveryNetworkStore::class);
        $store->method('defaults')->willReturnCallback(function (): array {
            $this->reads++;
            return ['name' => '', 'subnet_range' => '', 'sched_type' => 1, 'day_of_week' => [], 'enabled' => false] + self::SETTINGS;
        });
        $store->method('choices')->willReturnCallback(function (): array {
            $this->reads++;
            return ['pollers' => [1 => 'Main <i>'], 'sites' => [3 => 'Core'], 'snmp' => [1 => 'Default']];
        });
        $store->method('find')->willReturnCallback(function (int $id): ?DiscoveryNetwork {
            $this->reads++;
            return $id === 7 ? new DiscoveryNetwork(7, 'Core <b>', 1234567, self::SETTINGS, self::REVISION) : null;
        });
        $store->method('save')->willReturnCallback(function (int $actor, ?int $id, DiscoveryNetworkChange $change, ?string $revision): int {
            if ($this->reject !== null) {
                throw $this->reject;
            }
            if ($revision === 'stale') {
                throw new \InvalidArgumentException('The discovery network changed since you opened this form. Reload before saving.');
            }
            $this->writes[] = [$actor, $id, $change, $revision];
            return $id ?? 8;
        });
        $container->set(DiscoveryNetworkStore::class, $store);
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
            foreach (['/automation/networks/new', '/automation/networks/7/edit'] as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->post($path, self::FIELDS)->getStatusCode(), $path);
            }
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
    }

    public function testEditorEscapesStoredTextAndShowsTheStoredSelections(): void
    {
        $response = $this->get('/automation/networks/7/edit');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $page = (string) $response->getContent();
        foreach (['Core &lt;b&gt;', 'Main &lt;i&gt;', '1,234,567'] as $text) {
            self::assertStringContainsString($text, $page);
        }
        self::assertStringNotContainsString('Core <b>', $page);
        self::assertStringNotContainsString('Main <i>', $page);
        self::assertMatchesRegularExpression('/<input type="checkbox"[^>]*name="discovery_network\[day_of_week\]\[\]"[^>]*value="2" checked="checked"/', $page);
        self::assertMatchesRegularExpression('/<option value="3" selected="selected">/', $page);
        foreach (['/automation/networks/9/edit', '/automation/networks/0/edit', '/automation/networks/99999999999/edit'] as $missing) {
            self::assertSame(404, $this->get($missing)->getStatusCode(), $missing);
        }
        $new = (string) $this->get('/automation/networks/new')->getContent();
        self::assertStringContainsString('Network Discovery Range [new]', $new);
        self::assertStringNotContainsString('Total IP Addresses', $new);
        self::assertSame(200, $this->get('/automation/networks/7/edit?saved[]=1')->getStatusCode());
        self::assertStringContainsString('Discovery network saved.', (string) $this->get('/automation/networks/7/edit?saved=1')->getContent());
    }

    public function testInvalidSubmissionsAreRefusedBeforeTheStore(): void
    {
        $cases = [
            'missing token' => array_diff_key(self::FIELDS, ['_token' => true]),
            'extra field' => ['extra' => '1'] + self::FIELDS,
            'deleted collector' => ['poller_id' => '2'] + self::FIELDS,
            'unlisted thread count' => ['threads' => '11'] + self::FIELDS,
            'port is not a number' => ['ping_port' => 'abc'] + self::FIELDS,
            'empty port' => ['ping_port' => ''] + self::FIELDS,
            'invalid range' => ['subnet_range' => '10.0.0.0/33<script>'] + self::FIELDS,
            'weekly without days' => array_diff_key(self::FIELDS, ['day_of_week' => true]),
            'scalar where a list is expected' => ['day_of_week' => '2'] + self::FIELDS,
        ];
        foreach ($cases as $name => $fields) {
            $response = $this->post('/automation/networks/7/edit', $fields);
            self::assertSame(422, $response->getStatusCode(), $name);
            self::assertStringNotContainsString('<script>', (string) $response->getContent(), $name);
        }
        self::assertStringContainsString(
            'Network &#039;10.0.0.0/33&lt;script&gt;&#039; is invalid.',
            (string) $this->post('/automation/networks/7/edit', ['subnet_range' => '10.0.0.0/33<script>'] + self::FIELDS)->getContent()
        );
        self::assertSame([], $this->writes);
        self::assertSame(409, $this->post('/automation/networks/7/edit', ['revision' => 'stale'] + self::FIELDS)->getStatusCode());
        // A reference the store cannot find is reported on its field.
        $this->reject = new DiscoveryNetworkRejected('Choose an existing SNMP Option Set.', [], 'snmp_id');
        $rejected = $this->post('/automation/networks/7/edit', self::FIELDS);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertStringContainsString('Choose an existing SNMP Option Set.', (string) $rejected->getContent());
    }

    public function testValidSubmissionsSaveThroughTheStore(): void
    {
        $saved = $this->post('/automation/networks/7/edit', ['subnet_range' => "10.0.0.0/24,\r\n10.1.*.*", 'month' => ['12', '1']] + self::FIELDS);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/automation/networks/7/edit?saved=1', $saved->headers->get('Location'));
        [$actor, $id, $change, $revision] = $this->writes[0];
        self::assertSame([42, 7, self::REVISION], [$actor, $id, $revision]);
        self::assertSame(['10.0.0.0/24,10.1.*.*', true, false, [2], [1, 12], 3, 0], [$change->settings['subnet_range'], $change->settings['enabled'],
            $change->settings['add_to_cacti'], $change->settings['day_of_week'], $change->settings['month'], $change->settings['sched_type'], $change->settings['site_id']]);
        self::assertSame(254 + 65534, $change->totalIps);

        $created = $this->post('/automation/networks/new', ['site_id' => '3'] + self::FIELDS);
        self::assertSame('/automation/networks/8/edit?saved=1', $created->headers->get('Location'));
        self::assertSame([null, null, 3], [$this->writes[1][1], $this->writes[1][3], $this->writes[1][2]->settings['site_id']]);
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
        $page = (string) $this->kernel->handle(Request::create('/automation/networks/7/edit', 'GET', [], ['Cacti' => 'fixture']))->getContent();
        self::assertStringContainsString('<html lang="fr">', $page);
        foreach (['Plage de découverte réseau [modifier : Core &lt;b&gt;]', 'Planification de la découverte', 'Hebdomadaire', '%count%'] as $text) {
            $text === '%count%' ? self::assertStringNotContainsString($text, $page) : self::assertStringContainsString($text, $page);
        }
        self::assertStringContainsString('20 threads', $page);
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    /** @param array<string, mixed> $fields */
    private function post(string $uri, array $fields): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', ['discovery_network' => $fields], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }
}
