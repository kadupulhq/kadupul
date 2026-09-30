<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\CollectorAdministration\Infrastructure\Legacy\CollectorRevisionKey;
use Kadupul\CollectorAdministration\Infrastructure\Legacy\LegacyCollectorEditor;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class LegacyCollectorEditorTest extends TestCase
{
    private string $root;
    private \PDO $pdo;
    private LegacyCollectorEditor $editor;

    public function testRevokedDatabaseGrantCannotSaveWithAnOutdatedAccessSnapshot(): void
    {
        $details = $this->editor->find(2);
        $this->pdo->exec('DELETE FROM user_auth_realm WHERE realm_id = 3');
        try {
            $this->editor->save(42, 2, $this->formValues($details, ['notes' => 'forged']));
            self::fail('A revoked grant must reject the mutation.');
        } catch (\Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied) {
            self::assertSame('old', $this->pdo->query('SELECT notes FROM poller WHERE id = 2')->fetchColumn());
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testCreationRejectsLocalhostAndPersistsTheNewCredential(): void
    {
        $details = $this->editor->find(2);
        $values = $this->formValues($details, ['hostname' => 'new-collector.invalid', 'dbhost' => 'localhost', 'dbpass' => 'created-password-marker']);
        try {
            $this->editor->save(42, null, $values);
            self::fail('A new remote collector cannot use localhost.');
        } catch (\InvalidArgumentException) {
            self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM poller')->fetchColumn());
        }
        $values['dbhost'] = 'new-db.invalid';
        $id = $this->editor->save(42, null, $values);
        self::assertGreaterThan(2, $id);
        self::assertSame('created-password-marker', $this->pdo->query('SELECT dbpass FROM poller WHERE id = ' . $id)->fetchColumn());
        self::assertTrue($this->editor->find($id)->passwordConfigured);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/collector-editor-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/include/vendor/csrf', 0700, true);
        file_put_contents($this->root . '/include/vendor/csrf/csrf-secret.php', random_bytes(48));
        chmod($this->root . '/include/vendor/csrf/csrf-secret.php', 0600);
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE settings (name TEXT, value TEXT); INSERT INTO settings VALUES ('auth_method','1');
            CREATE TABLE user_auth (id INTEGER, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT);
            INSERT INTO user_auth VALUES (42,'operator','on','','');
            CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER);
            INSERT INTO user_auth_realm VALUES (42,8),(42,3);
            CREATE TABLE user_auth_group (id INTEGER, enabled TEXT);
            CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER);
            CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER);");
        $this->pdo->exec('CREATE TABLE poller (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, hostname TEXT, timezone TEXT, notes TEXT, processes INTEGER, threads INTEGER, sync_interval INTEGER, dbdefault TEXT, dbhost TEXT, dbuser TEXT, dbpass TEXT, dbport INTEGER, dbretries INTEGER, dbssl TEXT, dbsslkey TEXT, dbsslcert TEXT, dbsslca TEXT)');
        $this->pdo->exec("INSERT INTO poller VALUES (1, 'Main', 'main.example.invalid', 'UTC', '', 1, 1, 0, 'main', 'primary-db', 'root', 'secret-main', 3306, 5, '', '', '', '')");
        $this->pdo->exec("INSERT INTO poller VALUES (2, 'Remote', 'remote.example.invalid', 'UTC', 'old', 2, 3, 3600, 'remote', 'db.remote.invalid', 'svc', 'keep exactly  ', 3306, 5, '', '', '', '')");
        $db = new class ($this->pdo) implements DatabaseConnection {
            public function __construct(private readonly \PDO $pdo) {}
            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
        $access = new class implements ConsoleAccess {
            public function consoleActor(): ?Actor
            {
                return new Actor(42, 'operator');
            }
            public function canManageDevices(Actor $actor): bool
            {
                return $actor->id === 42;
            }
        };
        $audit = new class implements AuditTrail {
            public array $events = [];
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $configuration = new class ($this->root) implements LegacyConfiguration {
            public function __construct(private readonly string $root) {}
            public function values(): array
            {
                return ['root' => $this->root];
            }
        };
        $this->editor = new LegacyCollectorEditor($db, $access, $audit, new CollectorRevisionKey($configuration));
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/include/vendor/csrf/csrf-secret.php');
        rmdir($this->root . '/include/vendor/csrf');
        rmdir($this->root . '/include/vendor');
        rmdir($this->root . '/include');
        rmdir($this->root);
    }

    public function testReadAndBlankPasswordSaveNeverExposeOrReplaceTheStoredSecret(): void
    {
        $details = $this->editor->find(2);
        self::assertNotNull($details);
        self::assertTrue($details->passwordConfigured);
        self::assertArrayNotHasKey('dbpass', $details->settings);
        self::assertStringNotContainsString('keep exactly', serialize($details));

        $this->editor->save(42, 2, $this->formValues($details, ['hostname' => 'remote-new.example.invalid', 'dbpass' => '']));
        self::assertSame('keep exactly  ', $this->pdo->query('SELECT dbpass FROM poller WHERE id = 2')->fetchColumn());
    }

    public function testStaleCollectorAndCredentialRevisionsAreRejected(): void
    {
        $details = $this->editor->find(2);
        self::assertNotNull($details);
        $this->pdo->exec("UPDATE poller SET hostname = 'changed.example.invalid' WHERE id = 2");
        try {
            $this->editor->save(42, 2, $this->formValues($details));
            self::fail('A stale hostname revision was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }

        $fresh = $this->editor->find(2);
        self::assertNotNull($fresh);
        $this->pdo->exec("UPDATE poller SET dbpass = 'rotated-secret' WHERE id = 2");
        try {
            $this->editor->save(42, 2, $this->formValues($fresh, ['dbpass' => 'replacement']));
            self::fail('A stale credential revision was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
    }

    public function testMainCollectorSavePreservesRemoteDatabaseConfiguration(): void
    {
        $details = $this->editor->find(1);
        self::assertNotNull($details);
        $this->editor->save(42, 1, $this->formValues($details, ['name' => 'Main updated']));
        self::assertSame('secret-main', $this->pdo->query('SELECT dbpass FROM poller WHERE id = 1')->fetchColumn());
        self::assertSame('primary-db', $this->pdo->query('SELECT dbhost FROM poller WHERE id = 1')->fetchColumn());
    }

    public function testMalformedTextAndDuplicateHostnameAreRejected(): void
    {
        $details = $this->editor->find(2);
        self::assertNotNull($details);
        try {
            $this->editor->save(42, 2, $this->formValues($details, ['timezone' => str_repeat('x', 41)]));
            self::fail('An overlong timezone was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('timezone', $error->getMessage());
        }
        try {
            $this->editor->save(42, 2, $this->formValues($details, ['hostname' => "bad\0host"]));
            self::fail('A NUL-containing hostname was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('hostname', $error->getMessage());
        }
        try {
            $this->editor->save(42, 2, $this->formValues($details, ['hostname' => 'main.example.invalid']));
            self::fail('A duplicate hostname was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('already in use', $error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function formValues(object $details, array $changes = []): array
    {
        return array_replace([
            'name' => $details->name, 'hostname' => $details->hostname, 'timezone' => $details->timezone,
            'notes' => $details->notes, 'processes' => $details->processes, 'threads' => $details->threads,
            'revision' => $details->revision, 'sync_interval' => $details->syncInterval,
            'dbdefault' => $details->settings['dbdefault'], 'dbhost' => $details->settings['dbhost'],
            'dbuser' => $details->settings['dbuser'], 'dbpass' => '', 'dbport' => (int) $details->settings['dbport'],
            'dbretries' => (int) $details->settings['dbretries'], 'dbssl' => false,
            'dbsslkey' => $details->settings['dbsslkey'], 'dbsslcert' => $details->settings['dbsslcert'], 'dbsslca' => $details->settings['dbsslca'],
        ], $changes);
    }
}
