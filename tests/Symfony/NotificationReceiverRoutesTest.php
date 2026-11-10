<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Application\Port\NotificationReceiverAccess;
use Kadupul\Platform\Application\Port\NotificationReceiverStore;
use Kadupul\Platform\Application\Query\NotificationReceiverAccessDenied;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Domain\NotificationReceiver;
use Kadupul\Platform\Domain\NotificationReceiverChange;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class NotificationReceiverRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const array FIELDS = ['description' => 'NOC', 'hostname' => 'trap.example.net', 'max_log_size' => '31', 'snmp_version' => '2',
        'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_engine_id' => '', 'snmp_port' => '162', 'snmp_message_type' => '1', 'notes' => '',
        'snmp_community' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_password_confirm' => '', 'snmp_priv_passphrase' => '', 'snmp_priv_passphrase_confirm' => '',
        'revision' => self::REVISION, '_token' => 'csrf-token'];

    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private int $reads = 0;
    /** @var list<array{int, ?int, NotificationReceiverChange, ?string}> */
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
        $access = $this->createMock(NotificationReceiverAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new NotificationReceiverAccessDenied(false) : $this->actor);
        $container->set(NotificationReceiverAccess::class, $access);

        $receiver = new NotificationReceiver(7, 'Core <b>', 'trap.example.net', false, 31, 3, 'SHA', 'AES', '0x80001f88', 162, 2, 'notes', self::REVISION);
        $store = $this->createMock(NotificationReceiverStore::class);
        $store->method('defaults')->willReturnCallback(function (): array {
            $this->reads++;
            return ['description' => '', 'hostname' => '', 'disabled' => false, 'max_log_size' => 31, 'snmp_version' => 2, 'snmp_auth_protocol' => '[None]',
                'snmp_priv_protocol' => '[None]', 'snmp_engine_id' => '', 'snmp_port' => 161, 'snmp_message_type' => 1, 'notes' => ''];
        });
        $store->method('find')->willReturnCallback(function (int $id) use ($receiver): ?NotificationReceiver {
            $this->reads++;
            return $id === 7 ? $receiver : null;
        });
        $store->method('save')->willReturnCallback(function (int $actor, ?int $id, NotificationReceiverChange $change, ?string $revision): int {
            if ($revision === 'stale') {
                throw new \InvalidArgumentException('The notification receiver changed since you opened this form. Reload before saving.');
            }
            $this->writes[] = [$actor, $id, $change, $revision];
            return $id ?? 8;
        });
        $container->set(NotificationReceiverStore::class, $store);
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
        foreach ([[null, false, 401], [new Actor(42, 'operator'), true, 403]] as [$actor, $denied, $status]) {
            $this->actor = $actor;
            $this->denied = $denied;
            foreach (['/utilities/notification-receivers/new', '/utilities/notification-receivers/7/edit'] as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->post($path, ['notification_receiver' => self::FIELDS])->getStatusCode(), $path);
            }
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
    }

    public function testEditorEscapesTheReceiverNeverRendersCredentialsAndLinksTheLegacyTabs(): void
    {
        $page = (string) $this->get('/utilities/notification-receivers/7/edit')->getContent();
        self::assertStringContainsString('Core &lt;b&gt;', $page);
        self::assertStringNotContainsString('Core <b>', $page);
        self::assertStringContainsString('<option value="keep" selected="selected">', $page);
        foreach (['snmp_community', 'snmp_username', 'snmp_password', 'snmp_password_confirm', 'snmp_priv_passphrase', 'snmp_priv_passphrase_confirm'] as $field) {
            self::assertMatchesRegularExpression('/<input type="password"[^>]*name="notification_receiver\[' . $field . '\]"(?![^>]*value="[^"])/', $page, $field);
        }
        self::assertStringContainsString('managers.php?action=edit&amp;id=7&amp;tab=notifications', $page);
        self::assertStringContainsString('managers.php?action=edit&amp;id=7&amp;tab=logs', $page);
        self::assertSame(404, $this->get('/utilities/notification-receivers/9/edit')->getStatusCode());
        self::assertSame(404, $this->get('/utilities/notification-receivers/0/edit')->getStatusCode());
        self::assertSame(404, $this->get('/utilities/notification-receivers/99999999999/edit')->getStatusCode());
        // A new receiver has no stored credentials, so the choice and the other tabs are absent.
        $new = (string) $this->get('/utilities/notification-receivers/new')->getContent();
        self::assertStringNotContainsString('keep_credentials', $new);
        self::assertStringNotContainsString('tab=logs', $new);
        // An array where a flag is expected is ignored rather than raising an error.
        self::assertSame(200, $this->get('/utilities/notification-receivers/7/edit?saved[]=1')->getStatusCode());
        self::assertStringContainsString('Notification receiver saved.', (string) $this->get('/utilities/notification-receivers/7/edit?saved=1')->getContent());
    }

    public function testInvalidSubmissionsAreRefusedBeforeTheStore(): void
    {
        $keep = ['keep_credentials' => 'keep'] + self::FIELDS;
        $v3 = ['keep_credentials' => 'replace', 'snmp_version' => '3', 'snmp_username' => 'trapper', 'snmp_engine_id' => '0x80001f88', 'snmp_auth_protocol' => 'SHA',
            'snmp_password' => 'authpass1', 'snmp_password_confirm' => 'authpass1'] + self::FIELDS;
        $cases = [
            'missing token' => array_diff_key($keep, ['_token' => true]),
            'extra field' => ['extra' => '1'] + $keep,
            'typed while keeping' => ['snmp_password' => 'typed-anyway', 'snmp_password_confirm' => 'typed-anyway'] + $keep,
            'empty hostname' => ['hostname' => ' '] + $keep,
            'hostname wider than the column' => ['hostname' => str_repeat('h', 101)] + $keep,
            'control character' => ['description' => "NOC\nRelay"] + $keep,
            'log size' => ['max_log_size' => '32'] + $keep,
            'port' => ['snmp_port' => '65536'] + $keep,
            'empty port' => ['snmp_port' => ''] + $keep,
            'message type' => ['snmp_message_type' => '3'] + $keep,
            'version' => ['snmp_version' => '0'] + $keep,
            'confirmation mismatch' => ['snmp_password_confirm' => 'authpass2'] + $v3,
            'v3 without engine ID' => ['snmp_engine_id' => ''] + $v3,
            'password without protocol' => ['snmp_auth_protocol' => '[None]'] + $v3,
            'short password' => ['snmp_password' => 'short', 'snmp_password_confirm' => 'short'] + $v3,
            'privacy without authentication' => ['snmp_password' => '', 'snmp_password_confirm' => '', 'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => 'AES',
                'snmp_priv_passphrase' => 'privpass1', 'snmp_priv_passphrase_confirm' => 'privpass1'] + $v3,
        ];
        foreach ($cases as $name => $fields) {
            $response = $this->post('/utilities/notification-receivers/7/edit', ['notification_receiver' => $fields]);
            self::assertSame(422, $response->getStatusCode(), $name);
            foreach (['typed-anyway', 'authpass', 'privpass'] as $secret) {
                self::assertStringNotContainsString($secret, (string) $response->getContent(), $name);
            }
        }
        // A new receiver has nothing to keep.
        self::assertSame(422, $this->post('/utilities/notification-receivers/new', ['notification_receiver' => $keep])->getStatusCode());
        self::assertSame([], $this->writes);
        self::assertSame(409, $this->post('/utilities/notification-receivers/7/edit', ['notification_receiver' => ['revision' => 'stale'] + $keep])->getStatusCode());
    }

    public function testValidSubmissionsSaveThroughTheStore(): void
    {
        $saved = $this->post('/utilities/notification-receivers/7/edit', ['notification_receiver' => ['keep_credentials' => 'keep', 'disabled' => '1', 'notes' => "line 1\nline 2"] + self::FIELDS]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/utilities/notification-receivers/7/edit?saved=1', $saved->headers->get('Location'));
        [$actor, $id, $change, $revision] = $this->writes[0];
        self::assertSame([42, 7, self::REVISION, true], [$actor, $id, $revision, $change->keepCredentials]);
        self::assertSame(['disabled' => true, 'snmp_port' => 162, 'notes' => "line 1\nline 2"], array_intersect_key($change->settings, ['disabled' => 1, 'snmp_port' => 1, 'notes' => 1]));

        $created = $this->post('/utilities/notification-receivers/new', ['notification_receiver' => ['snmp_community' => 'public'] + self::FIELDS]);
        self::assertSame('/utilities/notification-receivers/8/edit?saved=1', $created->headers->get('Location'));
        self::assertSame([null, null, false], [$this->writes[1][1], $this->writes[1][3], $this->writes[1][2]->keepCredentials]);
        self::assertSame('public', $this->writes[1][2]->resolve([])['snmp_community']);
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
