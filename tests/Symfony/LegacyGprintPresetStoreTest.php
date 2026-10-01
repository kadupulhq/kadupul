<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class LegacyGprintPresetStoreTest extends TestCase
{
    private \PDO $pdo;
    private object $sessionAccess;
    private object $audit;
    private LegacyGprintPresetStore $store;
    private object $configuration;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE graph_templates_gprint (id INTEGER PRIMARY KEY AUTOINCREMENT, hash VARCHAR(32) NOT NULL, name VARCHAR(50) NOT NULL, gprint_text VARCHAR(50) NOT NULL)');
        $this->pdo->exec('CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY AUTOINCREMENT, graph_template_id INTEGER NOT NULL, local_graph_id INTEGER NOT NULL, gprint_id INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $this->pdo->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)');
        $this->pdo->exec('CREATE TABLE user_auth_realm (user_id INTEGER NOT NULL, realm_id INTEGER NOT NULL, PRIMARY KEY (user_id,realm_id))');
        $this->pdo->exec('INSERT INTO user_auth_realm VALUES (42,5),(42,8)');
        $this->pdo->exec("INSERT INTO user_auth VALUES (42,'gprint-test','on','','')");
        $this->pdo->exec("INSERT INTO settings VALUES ('num_rows_table','44'),('default_has',''),('auth_method','1'),('guest_user','0')");
        $this->pdo->exec("INSERT INTO graph_templates_gprint VALUES (1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','In use by graph','%5.2lf'), (2,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','In use by template','%8.2lf'), (3,'cccccccccccccccccccccccccccccccc','Unused preset','%10.2lf')");
        $this->pdo->exec('INSERT INTO graph_templates_item (graph_template_id,local_graph_id,gprint_id) VALUES (4,91,1),(4,0,2)');
        $database = new class ($this->pdo) implements DatabaseConnection {
            public function __construct(private readonly \PDO $connection) {}
            public function get(): \PDO
            {
                return $this->connection;
            }
        };
        $this->sessionAccess = new class implements ConsoleAccess {
            public ?Actor $actor = null;
            public function consoleActor(): ?Actor
            {
                return $this->actor;
            }
            public function canManageDevices(Actor $actor): bool
            {
                return true;
            }
        };
        $this->sessionAccess->actor = new Actor(42, 'gprint-test');
        $this->audit = new class implements AuditTrail {
            public array $events = [];
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $access = new LegacyGprintPresetAccess($this->sessionAccess, $database);
        $this->configuration = new class implements LegacyConfiguration {
            public int $collectorId = 1;
            public function values(): array
            {
                return ['collector_id' => $this->collectorId];
            }
        };
        $this->store = new LegacyGprintPresetStore($database, $access, $this->audit, $this->configuration);
    }

    public function testItCountsGraphAndTemplateReferencesAndFiltersResults(): void
    {
        $page = $this->store->list(GprintPresetFilters::fromQuery(['filter' => 'In use'], 25));
        self::assertSame(2, $page->total);
        self::assertSame(1, $page->presets[0]->graphs);
        self::assertSame(0, $page->presets[0]->templates);
        self::assertFalse($page->presets[0]->isDeletable());
        self::assertSame(1, $page->presets[1]->templates);
        self::assertSame(0, $page->presets[1]->graphs);

        $used = $this->store->list(GprintPresetFilters::fromQuery(['has_graphs' => 'true'], 25));
        self::assertSame(1, $used->total);
        self::assertSame('In use by graph', $used->presets[0]->name);
    }

    public function testItCreatesUpdatesAndRejectsStaleRevisions(): void
    {
        $existing = $this->store->find(3);
        self::assertNotNull($existing);
        $this->store->save(42, 3, 'Edited', '%12.3lf', $existing->revision);
        self::assertSame('Edited', $this->pdo->query('SELECT name FROM graph_templates_gprint WHERE id=3')->fetchColumn());
        self::assertSame('cccccccccccccccccccccccccccccccc', $this->pdo->query('SELECT hash FROM graph_templates_gprint WHERE id=3')->fetchColumn());
        try {
            $this->store->save(42, 3, 'Stale overwrite', '%12.3lf', $existing->revision);
            self::fail('Stale form was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }

        $id = $this->store->save(42, null, 'Fresh', '%9.2lf', null);
        self::assertGreaterThan(3, $id);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $this->pdo->query('SELECT hash FROM graph_templates_gprint WHERE id=' . $id)->fetchColumn());
    }

    public function testItDeletesOnlyUnreferencedPresetsAndRollsBackDeniedMutations(): void
    {
        try {
            $this->store->delete(42, [1, 3]);
            self::fail('An in-use preset was deleted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('cannot be deleted', $error->getMessage());
        }
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());
        $this->store->delete(42, [3]);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());

        $this->sessionAccess->actor = null;
        try {
            $this->store->delete(42, [2]);
            self::fail('An expired actor performed a deletion.');
        } catch (GprintPresetAccessDenied $error) {
            self::assertTrue($error->unauthenticated);
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());
    }

    public function testAuditEventsContainOutcomeButNoPresetContents(): void
    {
        $preset = $this->store->find(3);
        self::assertNotNull($preset);
        $this->store->save(42, 3, 'private-preset-name', 'private-format', $preset->revision);
        $event = $this->audit->events[array_key_last($this->audit->events)];
        self::assertSame('succeeded', $event->outcome);
        self::assertStringNotContainsString('private-preset-name', $event->json());
        self::assertStringNotContainsString('private-format', $event->json());
    }

    public function testMutationsPreserveTheCallersTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec("UPDATE graph_templates_gprint SET name='Caller change' WHERE id=3");
        foreach ([fn() => $this->store->save(42, null, 'New', '%5.2lf', null), fn() => $this->store->delete(42, [3])] as $mutation) {
            try {
                $mutation();
                self::fail('Caller-owned transaction was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('ownership', $error->getMessage());
            }
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame('Caller change', $this->pdo->query('SELECT name FROM graph_templates_gprint WHERE id=3')->fetchColumn());
        }
        $this->pdo->rollBack();
        self::assertSame('Unused preset', $this->pdo->query('SELECT name FROM graph_templates_gprint WHERE id=3')->fetchColumn());
    }

    public function testRemoteCollectorCannotMutateEvenWithAnAccessibleDatabase(): void
    {
        $this->configuration->collectorId = 2;
        foreach ([fn() => $this->store->save(42, null, 'New', '%5.2lf', null), fn() => $this->store->delete(42, [3])] as $mutation) {
            try {
                $mutation();
                self::fail('Collector mutation was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('primary installation', $error->getMessage());
            }
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());
        }
    }
}
