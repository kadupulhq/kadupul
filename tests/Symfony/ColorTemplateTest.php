<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\Graphing\Domain\ColorTemplateItem;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyColorTemplateAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyColorTemplateStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class ColorTemplateTest extends TestCase
{
    private \PDO $db;
    private LegacyColorTemplateStore $store;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE color_templates (color_template_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '');
            CREATE TABLE color_template_items (color_template_item_id INTEGER PRIMARY KEY AUTOINCREMENT, color_template_id INT NOT NULL DEFAULT 0, color_id INT NOT NULL DEFAULT 0, sequence INT NOT NULL DEFAULT 0);
            CREATE TABLE colors (id INTEGER PRIMARY KEY, name TEXT, hex TEXT);
            CREATE TABLE aggregate_graphs_graph_item (aggregate_graph_id INT, graph_templates_item_id INT, color_template INT);
            CREATE TABLE aggregate_graph_templates_item (aggregate_template_id INT, graph_templates_item_id INT, color_template INT);
            CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT);
            INSERT INTO colors VALUES (1, 'Red', 'FF0000'), (2, '', '00FF00'), (3, 'Blue', '0000FF')");
        $database = new class ($this->db) implements DatabaseConnection {
            public function __construct(private \PDO $db) {}

            public function get(): \PDO
            {
                return $this->db;
            }
        };
        $this->store = new LegacyColorTemplateStore($database, $this->createMock(ColorTemplateAccess::class), $this->createMock(AuditTrail::class), $this->createMock(LegacyConfiguration::class));
    }

    public function testNamesAreStoredRawAndEditsRequireTheCurrentRevision(): void
    {
        $id = $this->store->save(1, null, '<b>Exact & "raw"</b>', null);
        $template = $this->store->find($id);
        self::assertSame('<b>Exact & "raw"</b>', $template->name);

        $this->store->save(1, $id, 'Fresh', $template->revision);
        foreach ([$template->revision, null] as $stale) {
            try {
                $this->store->save(1, $id, 'Stale', $stale);
                self::fail('A stale color template edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        self::assertSame('Fresh', $this->store->find($id)->name);
    }

    public function testNamesAndSearchesCountCharactersNotBytes(): void
    {
        self::assertGreaterThan(0, $this->store->save(1, null, str_repeat('é', 255), null));
        foreach (['', str_repeat('é', 256), "bad\0", "\xff"] as $name) {
            try {
                $this->store->save(1, null, $name, null);
                self::fail('An invalid color template name was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM color_templates')->fetchColumn());
            }
        }
        self::assertSame(str_repeat('界', 200), ColorTemplateFilters::fromQuery(['filter' => str_repeat('界', 200)], 25)->filter);
        $this->expectException(\InvalidArgumentException::class);
        ColorTemplateFilters::fromQuery(['filter' => str_repeat('界', 201)], 25);
    }

    public function testItemsKeepTheirSequenceAndRefuseStaleForms(): void
    {
        $id = $this->store->save(1, null, 'Template', null);
        $first = $this->store->saveItem(1, $id, null, 1, null);
        $second = $this->store->saveItem(1, $id, null, 2, null);
        $third = $this->store->saveItem(1, $id, null, 3, null);
        $items = $this->store->items($id);
        self::assertSame([[$first, 1, 'FF0000'], [$second, 2, '00FF00'], [$third, 3, '0000FF']], array_map(static fn(ColorTemplateItem $item): array => [$item->id, $item->sequence, $item->hex], $items));

        $this->store->saveItem(1, $id, $second, 3, $items[1]->revision);
        try {
            $this->store->saveItem(1, $id, $second, 1, $items[1]->revision);
            self::fail('A stale color item edit was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        try {
            $this->store->saveItem(1, $id, null, 99, null);
            self::fail('A missing palette color was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Select a valid color.', $error->getMessage());
        }

        $revision = ColorTemplateItem::orderRevision($this->store->items($id));
        $this->store->reorder(1, $id, [$third, $first, $second], $revision);
        self::assertSame([$third, $first, $second], array_map(static fn(ColorTemplateItem $item): int => $item->id, $this->store->items($id)));
        foreach ([[$third, $first, $second], [$third, $first]] as $order) {
            try {
                $this->store->reorder(1, $id, $order, $revision);
                self::fail('A stale or partial color item order was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('order changed', $error->getMessage());
            }
        }

        $current = $this->store->items($id)[0];
        $this->store->removeItem(1, $id, $current->id, $current->revision);
        self::assertSame([$first, $second], array_map(static fn(ColorTemplateItem $item): int => $item->id, $this->store->items($id)));
        $this->expectException(\InvalidArgumentException::class);
        $this->store->removeItem(1, $id, $current->id, $current->revision);
    }

    public function testAReferencedTemplateKeepsItsLastColor(): void
    {
        foreach (['aggregate_graphs_graph_item', 'aggregate_graph_templates_item'] as $table) {
            $id = $this->store->save(1, null, 'Used by ' . $table, null);
            $this->store->saveItem(1, $id, null, 1, null);
            $this->store->saveItem(1, $id, null, 2, null);
            $this->db->exec("INSERT INTO $table VALUES (7, 1, $id)");
            $first = $this->store->items($id)[0];
            $this->store->removeItem(1, $id, $first->id, $first->revision);
            $last = $this->store->items($id)[0];
            try {
                $this->store->removeItem(1, $id, $last->id, $last->revision);
                self::fail('The last color of a referenced template was removed.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(LegacyColorTemplateStore::LAST_COLOR, $error->getMessage());
            }
            self::assertCount(1, $this->store->items($id));
        }
        $free = $this->store->save(1, null, 'Unused', null);
        $this->store->saveItem(1, $free, null, 1, null);
        $item = $this->store->items($free)[0];
        $this->store->removeItem(1, $free, $item->id, $item->revision);
        self::assertSame([], $this->store->items($free));
    }

    public function testDeletionRefusesAggregateGraphAndTemplateReferences(): void
    {
        $free = $this->store->save(1, null, 'Free', null);
        $graph = $this->store->save(1, null, 'Graph', null);
        $template = $this->store->save(1, null, 'Template', null);
        $this->store->saveItem(1, $free, null, 1, null);
        $this->db->exec("INSERT INTO aggregate_graphs_graph_item VALUES (7, 1, $graph); INSERT INTO aggregate_graph_templates_item VALUES (8, 1, $template)");
        self::assertTrue($this->store->find($free)->isDeletable());
        foreach ([$graph, $template] as $used) {
            self::assertFalse($this->store->find($used)->isDeletable());
            try {
                $this->store->delete(1, [$free, $used], $this->store->selectionRevisions([$free, $used]));
                self::fail('A referenced color template was deleted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('aggregate graph or aggregate template', $error->getMessage());
            }
        }
        self::assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM color_templates')->fetchColumn());

        $revisions = $this->store->selectionRevisions([$free]);
        $this->db->exec("UPDATE color_template_items SET color_id = 2 WHERE color_template_id = $free");
        try {
            $this->store->delete(1, [$free], $revisions);
            self::fail('A color template changed after confirmation was deleted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(LegacyColorTemplateStore::CHANGED, $error->getMessage());
        }
        $this->store->delete(1, [$free], $this->store->selectionRevisions([$free]));
        self::assertNull($this->store->find($free));
        self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM color_template_items WHERE color_template_id = $free")->fetchColumn());
    }

    public function testDuplicationCopiesItemsAndAppliesTheTitleFormat(): void
    {
        $id = $this->store->save(1, null, 'Reds', null);
        $this->store->saveItem(1, $id, null, 3, null);
        $this->store->saveItem(1, $id, null, 1, null);
        $revisions = $this->store->selectionRevisions([$id]);
        $this->store->duplicate(1, [$id], '<template_title> (1)', $revisions);
        $copy = (int) $this->db->query("SELECT color_template_id FROM color_templates WHERE name = 'Reds (1)'")->fetchColumn();
        self::assertGreaterThan($id, $copy);
        self::assertSame([[3, 1], [1, 2]], array_map(static fn(ColorTemplateItem $item): array => [$item->colorId, $item->sequence], $this->store->items($copy)));

        $this->store->save(1, $id, 'Renamed', $this->store->find($id)->revision);
        try {
            $this->store->duplicate(1, [$id], 'Copy of <template_title>', $revisions);
            self::fail('A renamed color template was duplicated under its confirmed name.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(LegacyColorTemplateStore::CHANGED, $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->store->duplicate(1, [$id], str_repeat('x', 256), $this->store->selectionRevisions([$id]));
    }

    public function testListCountsReferencesAndFiltersTemplatesInUse(): void
    {
        $used = $this->store->save(1, null, 'Alpha used', null);
        $this->store->save(1, null, 'Beta unused', null);
        $this->db->exec("INSERT INTO aggregate_graphs_graph_item VALUES (7, 1, $used), (7, 2, $used); INSERT INTO aggregate_graph_templates_item VALUES (8, 1, $used)");
        $page = $this->store->list(ColorTemplateFilters::fromQuery(['sort_column' => 'graphs', 'sort_direction' => 'DESC'], 25));
        self::assertSame(['Alpha used', 'Beta unused'], array_map(static fn($template): string => $template->name, $page->templates));
        self::assertSame([2, 1], [$page->templates[0]->graphs, $page->templates[0]->templates]);
        $page = $this->store->list(ColorTemplateFilters::fromQuery(['has_graphs' => 'true'], 25));
        self::assertSame(1, $page->total);
        $page = $this->store->list(ColorTemplateFilters::fromQuery(['filter' => 'Beta'], 25));
        self::assertSame(['Beta unused'], array_map(static fn($template): string => $template->name, $page->templates));
    }

    public function testAccessNeedsTheTemplatesRealmAndRechecksBothRealmsForWrites(): void
    {
        $this->db->exec("CREATE TABLE user_auth (id INT PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT);
            CREATE TABLE user_auth_realm (user_id INT, realm_id INT);
            CREATE TABLE user_auth_group (id INT, enabled TEXT);
            CREATE TABLE user_auth_group_members (group_id INT, user_id INT);
            CREATE TABLE user_auth_group_realm (group_id INT, realm_id INT);
            INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest');
            INSERT INTO user_auth VALUES (9, 'operator', 'on', '', ''), (10, 'guest', 'on', '', '');
            INSERT INTO user_auth_realm VALUES (9, 8), (10, 8), (10, 5)");
        $console = $this->createMock(ConsoleAccess::class);
        $actor = new Actor(9, 'operator');
        $console->method('consoleActor')->willReturnCallback(static function () use (&$actor): Actor {
            return $actor;
        });
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($this->db);
        $access = new LegacyColorTemplateAccess($console, $database);
        $denied = static function (callable $check): bool {
            try {
                $check();
            } catch (ColorTemplateAccessDenied) {
                return true;
            }
            return false;
        };
        self::assertTrue($denied(static fn() => $access->authorize()));
        $this->db->exec('INSERT INTO user_auth_group VALUES (3, \'on\'); INSERT INTO user_auth_group_members VALUES (3, 9); INSERT INTO user_auth_group_realm VALUES (3, 5)');
        self::assertSame(9, $access->authorize()->id);

        $this->db->beginTransaction();
        $access->assertCurrent(9);
        self::assertTrue($denied(static fn() => $access->assertCurrent(10)));
        $this->db->exec('DELETE FROM user_auth_realm WHERE user_id = 9 AND realm_id = 8');
        self::assertTrue($denied(fn() => LegacyColorTemplateAccess::assertGranted($this->db, 9)));
        $this->db->rollBack();

        $actor = new Actor(10, 'guest');
        self::assertTrue($denied(static fn() => $access->authorize()));
        $this->expectException(\LogicException::class);
        LegacyColorTemplateAccess::assertGranted($this->db, 9);
    }
}
