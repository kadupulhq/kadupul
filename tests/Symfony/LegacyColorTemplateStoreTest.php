<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied;
use Kadupul\ColorTemplates\Domain\ColorTemplateFilters;
use Kadupul\ColorTemplates\Infrastructure\Legacy\LegacyColorTemplateAccess;
use Kadupul\ColorTemplates\Infrastructure\Legacy\LegacyColorTemplateStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class LegacyColorTemplateStoreTest extends TestCase
{
    private \PDO $db;
    private object $console;
    private object $audit;
    private LegacyColorTemplateStore $store;

    /** @dataProvider staleBulkActions */
    public function testBulkActionsRejectChangedSnapshotsBeforeAnySelectionWrite(string $action, string $change): void
    {
        $this->db->exec('DELETE FROM aggregate_graph_templates_item; DELETE FROM aggregate_graphs_graph_item');
        $revisions = $this->store->actionRevisions([$this->store->find(1), $this->store->find(2)]);
        if ($change === 'name') {
            $this->db->exec("UPDATE color_templates SET name='Concurrent name' WHERE color_template_id=2");
        } else {
            $this->db->exec('UPDATE color_template_items SET color_id=2 WHERE color_template_id=2');
        }
        $before = $this->db->query('SELECT * FROM color_templates ORDER BY color_template_id')->fetchAll(\PDO::FETCH_ASSOC);
        $items = $this->db->query('SELECT * FROM color_template_items ORDER BY color_template_item_id')->fetchAll(\PDO::FETCH_ASSOC);
        try {
            if ($action === 'delete') {
                $this->store->delete(42, [1, 2], $revisions);
            } else {
                $this->store->duplicate(42, [1, 2], '<template_title> copy', $revisions);
            }
            self::fail('Stale bulk action was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('The selected color templates changed. Reload before continuing.', $error->getMessage());
        }
        self::assertSame($before, $this->db->query('SELECT * FROM color_templates ORDER BY color_template_id')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertSame($items, $this->db->query('SELECT * FROM color_template_items ORDER BY color_template_item_id')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertFalse($this->db->inTransaction());
    }

    public static function staleBulkActions(): array
    {
        return [['delete', 'name'], ['delete', 'items'], ['duplicate', 'name'], ['duplicate', 'items']];
    }

    /** @dataProvider invalidRevisionMaps */
    public function testBulkSelectionRequiresExactRevisionMap(array $revisions): void
    {
        $this->db->exec('DELETE FROM aggregate_graph_templates_item; DELETE FROM aggregate_graphs_graph_item');
        $this->expectExceptionMessage('The selected color templates changed. Reload before continuing.');
        try {
            $this->store->delete(42, [1, 2], $revisions);
        } finally {
            self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM color_templates')->fetchColumn());
            self::assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM color_template_items')->fetchColumn());
            self::assertFalse($this->db->inTransaction());
        }
    }

    public static function invalidRevisionMaps(): array
    {
        return [[[]], [[1 => 'invalid']], [[1 => 123, 2 => null]], [[1 => str_repeat('0', 64), 2 => str_repeat('0', 64), 3 => str_repeat('0', 64)]]];
    }

    public function testMultibyteSearchMatchesTheBrowserCharacterLimit(): void
    {
        self::assertSame(str_repeat('界', 200), ColorTemplateFilters::fromQuery(['filter' => str_repeat('界', 200)], 25)->filter);
        $this->expectExceptionMessage('Invalid color template filters.');
        ColorTemplateFilters::fromQuery(['filter' => str_repeat('界', 201)], 25);
    }

    public function testConfirmationSnapshotUsesTheDisplayedNameWhenItChangesDuringPreparation(): void
    {
        $displayed = $this->store->find(1);
        $this->db->exec("UPDATE color_templates SET name='Changed during GET' WHERE color_template_id=1");
        $revisions = $this->store->actionRevisions([$displayed]);
        self::assertNotSame($revisions, $this->store->actionRevisions([$this->store->find(1)]));
        $this->expectExceptionMessage('The selected color templates changed. Reload before continuing.');
        $this->store->duplicate(42, [1], '<template_title> copy', $revisions);
    }

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)');
        $this->db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER, PRIMARY KEY(user_id,realm_id))');
        $this->db->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $this->db->exec('CREATE TABLE color_templates (color_template_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE color_template_items (color_template_item_id INTEGER PRIMARY KEY AUTOINCREMENT, color_template_id INTEGER NOT NULL, color_id INTEGER NOT NULL, sequence INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY, name TEXT, hex TEXT)');
        $this->db->exec('CREATE TABLE aggregate_graph_templates_item (aggregate_template_id INTEGER, color_template INTEGER)');
        $this->db->exec('CREATE TABLE aggregate_graphs_graph_item (aggregate_graph_id INTEGER, color_template INTEGER)');
        $this->db->exec("INSERT INTO user_auth VALUES (42,'color-test','on','','')");
        $this->db->exec('INSERT INTO user_auth_realm VALUES (42,8),(42,5)');
        $this->db->exec("INSERT INTO settings VALUES ('auth_method','1'),('guest_user','0'),('num_rows_table','30'),('default_has','')");
        $this->db->exec("INSERT INTO color_templates VALUES (1,'Template A'),(2,'Template B')");
        $this->db->exec("INSERT INTO colors VALUES (1,'Blue','0000FF'),(2,'Red','FF0000'),(3,'Green','00FF00')");
        $this->db->exec('INSERT INTO color_template_items VALUES (1,1,1,1),(2,1,2,2),(3,2,3,1)');
        $this->db->exec('INSERT INTO aggregate_graph_templates_item VALUES (100,2)');
        $this->db->exec('INSERT INTO aggregate_graphs_graph_item VALUES (200,1)');
        $connection = new class ($this->db) implements DatabaseConnection {
            public function __construct(private readonly \PDO $pdo) {}
            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
        $this->console = new class implements ConsoleAccess {
            public ?Actor $actor = null;
            public function consoleActor(): ?Actor
            {
                return $this->actor;
            }
            public function canManageDevices(Actor $actor): bool
            {
                return false;
            }
        };
        $this->console->actor = new Actor(42, 'color-test');
        $this->audit = new class implements AuditTrail {
            public array $events = [];
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $access = new LegacyColorTemplateAccess($this->console, $connection);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new LegacyColorTemplateStore($connection, $access, $this->audit, $configuration);
    }

    public function testCallerTransactionAndItsWritesSurviveRefusedMutation(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec("INSERT INTO color_templates VALUES (99,'Caller owns this')");
            try {
                $this->store->saveTemplate(42, null, 'Must not write', null);
                self::fail('Nested write must be refused.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('transaction unavailable', $error->getMessage());
            }
            self::assertTrue($this->db->inTransaction());
            self::assertSame('Caller owns this', $this->db->query('SELECT name FROM color_templates WHERE color_template_id=99')->fetchColumn());
            self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM color_templates WHERE name='Must not write'")->fetchColumn());
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM color_templates WHERE color_template_id=99')->fetchColumn());
    }

    public function testMultibyteNamesAndTitleFormatsUseCharacterLimits(): void
    {
        $name = str_repeat('界', 255);
        $id = $this->store->saveTemplate(42, null, $name, null);
        self::assertSame($name, $this->store->find($id)?->name);
        $title = str_repeat('é', 255);
        $this->store->duplicate(42, [$id], $title, $this->store->actionRevisions([$this->store->find($id)]));
        self::assertSame($title, $this->db->query('SELECT name FROM color_templates ORDER BY color_template_id DESC LIMIT 1')->fetchColumn());
        foreach ([fn() => $this->store->saveTemplate(42, null, str_repeat('界', 256), null), fn() => $this->store->duplicate(42, [$id], str_repeat('é', 256), $this->store->actionRevisions([$this->store->find($id)]))] as $mutation) {
            try {
                $mutation();
                self::fail('An overlong character value was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertFalse($this->db->inTransaction());
            }
        }
    }

    public function testListCountsReferencesAndFiltersTheLegacyHasGraphsMeaning(): void
    {
        $page = $this->store->list(ColorTemplateFilters::fromQuery([], 30));
        self::assertSame(2, $page->total);
        self::assertSame(0, $page->templates[0]->templates);
        self::assertSame(1, $page->templates[0]->graphs);
        self::assertFalse($page->templates[0]->deletable());
        self::assertSame(1, $page->templates[1]->templates);
        self::assertSame(0, $page->templates[1]->graphs);
        self::assertFalse($page->templates[1]->deletable());
        self::assertSame(1, $page->templates[1]->items);

        $used = $this->store->list(ColorTemplateFilters::fromQuery(['has_graphs' => 'true'], 30));
        self::assertSame(2, $used->total);
        self::assertSame(1, $used->templates[0]->id);
    }

    public function testTemplateItemWriteReorderAndDuplicatePreserveGraphColorSequenceHandoff(): void
    {
        $template = $this->store->find(1);
        self::assertNotNull($template);
        $id = $this->store->saveTemplate(42, 1, 'Template A updated', $template->revision);
        self::assertSame(1, $id);

        $items = $this->store->items(1);
        self::assertSame([1, 2], array_map(static fn($item): int => $item->colorId, $items));
        $revision = hash('sha256', json_encode([1, 2], JSON_THROW_ON_ERROR));
        $this->store->reorder(42, 1, [2, 1], $revision);
        self::assertSame([2, 1], array_map(static fn($item): int => $item->colorId, $this->store->items(1)));
        $this->store->duplicate(42, [1], '<template_title> copied', $this->store->actionRevisions([$this->store->find(1)]));
        $copy = $this->db->query("SELECT color_template_id FROM color_templates WHERE name='Template A updated copied'")->fetchColumn();
        self::assertNotFalse($copy);
        self::assertSame([2, 1], array_map(static fn($item): int => $item->colorId, $this->store->items((int) $copy)));
        self::assertSame([1, 2], array_map(static fn($item): int => $item->sequence, $this->store->items((int) $copy)));
    }

    public function testItemMutationChecksParentOwnershipColorAndStaleRevision(): void
    {
        $id = $this->store->saveItem(42, 1, null, 3, null);
        $item = array_values(array_filter($this->store->items(1), static fn($value): bool => $value->id === $id))[0];
        $this->store->saveItem(42, 1, $id, 2, $item->revision);
        try {
            $this->store->saveItem(42, 2, $id, 1, $item->revision);
            self::fail('A color item assigned to another template was edited.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $this->store->reorder(42, 1, [1], hash('sha256', json_encode([2, 1], JSON_THROW_ON_ERROR)));
            self::fail('A partial ordering was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testReorderRejectsStaleSequenceEvenWhenTheSameIdsRemain(): void
    {
        $staleRevision = hash('sha256', json_encode([1, 2], JSON_THROW_ON_ERROR));
        $this->db->exec('UPDATE color_template_items SET sequence=3 WHERE color_template_item_id=1');
        $this->db->exec('UPDATE color_template_items SET sequence=1 WHERE color_template_item_id=2');
        $this->db->exec('UPDATE color_template_items SET sequence=2 WHERE color_template_item_id=1');
        try {
            $this->store->reorder(42, 1, [1, 2], $staleRevision);
            self::fail('A same-ID stale reorder was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([2, 1], array_map(static fn($item): int => $item->id, $this->store->items(1)));
        }
    }

    public function testDeleteBlocksTemplateReferencesAndDeletesItemsAtomicallyForUnusedTemplates(): void
    {
        try {
            $this->store->delete(42, [2], $this->store->actionRevisions([$this->store->find(2)]));
            self::fail('A referenced color template was deleted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM color_templates WHERE color_template_id=2')->fetchColumn());
        }
        try {
            $this->store->delete(42, [1], $this->store->actionRevisions([$this->store->find(1)]));
            self::fail('A template referenced only by a standalone aggregate graph was deleted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM color_templates WHERE color_template_id=1')->fetchColumn());
        }
        $this->db->exec('DELETE FROM aggregate_graphs_graph_item WHERE aggregate_graph_id=200');
        $this->store->delete(42, [1], $this->store->actionRevisions([$this->store->find(1)]));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM color_templates WHERE color_template_id=1')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM color_template_items WHERE color_template_id=1')->fetchColumn());
    }

    public function testWritesRequireCurrentAccountAndRealmGrants(): void
    {
        $template = $this->store->find(1);
        self::assertNotNull($template);
        $this->db->exec('DELETE FROM user_auth_realm WHERE user_id=42 AND realm_id=8');
        try {
            $this->store->saveTemplate(42, 1, 'Unauthorized change', $template->revision);
            self::fail('A revoked console grant was ignored.');
        } catch (ColorTemplateAccessDenied) {
            self::assertSame('Template A', $this->store->find(1)?->name);
        }
    }

    public function testForcedPasswordFlagDeniesEvenWhenTheSessionActorStillExists(): void
    {
        $this->db->exec("UPDATE user_auth SET must_change_password='on' WHERE id=42");
        $connection = new class ($this->db) implements DatabaseConnection {
            public function __construct(private readonly \PDO $pdo) {}
            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
        $access = new LegacyColorTemplateAccess($this->console, $connection);

        try {
            $access->authorize();
            self::fail('A forced-password account retained color-template access.');
        } catch (ColorTemplateAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
            self::assertSame(42, $this->console->consoleActor()?->id);
        }
    }
}
