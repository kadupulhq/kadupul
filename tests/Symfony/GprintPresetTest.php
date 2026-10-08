<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;
use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetPreferences;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class GprintPresetTest extends TestCase
{
    private \PDO $db;
    private LegacyGprintPresetStore $store;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE graph_templates_gprint (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT NOT NULL DEFAULT \'\', name TEXT NOT NULL, gprint_text TEXT);
            CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, gprint_id INT, graph_template_id INT, local_graph_id INT);
            CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT);
            CREATE TABLE settings_user (user_id INT, name TEXT, value TEXT, PRIMARY KEY (user_id, name));
            CREATE TABLE user_auth (id INT PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT);
            CREATE TABLE user_auth_realm (user_id INT, realm_id INT)');
        $this->store = new LegacyGprintPresetStore($this->connection(), $this->createMock(GprintPresetAccess::class), $this->createMock(AuditTrail::class), $this->createMock(LegacyConfiguration::class));
    }

    public function testCreateStoresRawTextWithALegacyHashAndEditRequiresTheCurrentRevision(): void
    {
        $id = $this->store->save(1, null, '<b>Exact & "raw"</b>', '%8.2lf %s', null);
        $preset = $this->store->find($id);
        self::assertSame('<b>Exact & "raw"</b>', $preset->name);
        self::assertSame('%8.2lf %s', $preset->gprintText);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $this->db->query('SELECT hash FROM graph_templates_gprint')->fetchColumn());

        $this->store->save(1, $id, 'Fresh', '%6.0lf', $preset->revision);
        foreach ([$preset->revision, null] as $stale) {
            try {
                $this->store->save(1, $id, 'Stale', '%1.0lf', $stale);
                self::fail('A stale GPRINT edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        self::assertSame('Fresh', $this->store->find($id)->name);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());
    }

    public function testValidationMatchesTheLegacyFieldLimits(): void
    {
        foreach ([['', '%s'], ['name', ''], [str_repeat('n', 51), '%s'], ['name', str_repeat('x', 51)], ["bad\0", '%s'], ["\xff", '%s']] as [$name, $text]) {
            try {
                $this->store->save(1, null, $name, $text, null);
                self::fail('Invalid GPRINT input was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());
            }
        }
        self::assertGreaterThan(0, $this->store->save(1, null, str_repeat('é', 50), str_repeat('x', 50), null));
    }

    public function testInUseOrChangedPresetsProtectTheWholeDeleteSelection(): void
    {
        $free = $this->store->save(1, null, 'Free', '%s', null);
        $graph = $this->store->save(1, null, 'Graph', '%s', null);
        $template = $this->store->save(1, null, 'Template', '%s', null);
        $this->db->exec("INSERT INTO graph_templates_item VALUES (1, $graph, 3, 7), (2, $template, 3, 0)");
        self::assertTrue($this->store->find($free)->isDeletable());
        self::assertFalse($this->store->find($graph)->isDeletable());
        self::assertFalse($this->store->find($template)->isDeletable());
        foreach ([$graph, $template] as $used) {
            try {
                $this->store->delete(1, [$free, $used], $this->revisions([$free, $used]));
                self::fail('An in-use GPRINT preset was deleted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('GPRINT Presets in use by a graph or graph template cannot be deleted.', $error->getMessage());
            }
        }
        $revisions = $this->revisions([$free]);
        $this->store->save(1, $free, 'Renamed', '%s', $revisions[$free]);
        try {
            $this->store->delete(1, [$free], $revisions);
            self::fail('A changed GPRINT preset was deleted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        self::assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());

        $this->store->delete(1, [$free], $this->revisions([$free]));
        self::assertNull($this->store->find($free));
    }

    public function testDeleteRejectsMissingOversizedAndMalformedSelectionsBeforeWriting(): void
    {
        $id = $this->store->save(1, null, 'Kept', '%s', null);
        foreach ([[], range(1, GprintPresetStore::MAX_DELETE_SELECTION + 1), [0], ['1'], [$id, $id + 1]] as $ids) {
            try {
                $this->store->delete(1, $ids, $this->revisions([$id]));
                self::fail('An invalid GPRINT selection was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertNotNull($this->store->find($id));
            }
        }
    }

    public function testWriterDoesNotTouchACallerOwnedTransaction(): void
    {
        $this->db->beginTransaction();
        $this->db->exec("INSERT INTO graph_templates_gprint (name, gprint_text) VALUES ('caller', '%s')");
        try {
            $this->store->save(1, null, 'nested', '%s', null);
            self::fail('A nested GPRINT write was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('GPRINT transaction unavailable.', $error->getMessage());
            self::assertTrue($this->db->inTransaction());
            self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());
        } finally {
            $this->db->rollBack();
        }
    }

    public function testListFiltersByNameAndCountsGraphsLikeTheLegacyPage(): void
    {
        $graphs = $this->store->save(1, null, 'Graphs only', '%s', null);
        $templates = $this->store->save(1, null, 'Templates only', '%s', null);
        $this->store->save(1, null, 'Unused', 'Graphs', null);
        // Two items of the same graph count once, as the legacy GROUP BY did.
        $this->db->exec("INSERT INTO graph_templates_item VALUES (1, $graphs, 3, 7), (2, $graphs, 3, 7), (3, $graphs, 3, 8), (4, $templates, 3, 0), (5, $templates, 4, 0)");

        $page = $this->store->list(GprintPresetFilters::fromQuery(['filter' => 'Graphs'], 25));
        self::assertSame(1, $page->total);
        self::assertSame([2, 0], [$page->presets[0]->graphs, $page->presets[0]->templates]);

        $page = $this->store->list(GprintPresetFilters::fromQuery(['has_graphs' => 'true'], 25));
        self::assertSame(['Graphs only'], array_map(static fn($preset): string => $preset->name, $page->presets));

        $page = $this->store->list(GprintPresetFilters::fromQuery(['sort_column' => 'templates', 'sort_direction' => 'DESC', 'rows' => '1', 'page' => '1'], 25));
        self::assertSame(3, $page->total);
        self::assertSame('Templates only', $page->presets[0]->name);
        self::assertSame(2, $page->presets[0]->templates);
        self::assertTrue($page->hasNext());
    }

    public function testFiltersRejectUnknownNestedAndOutOfRangeValues(): void
    {
        foreach ([['named' => 'true'], ['filter' => ['x']], ['rows' => '0'], ['rows' => '5001'], ['sort_column' => 'nosort'], ['sort_direction' => 'sideways'], ['has_graphs' => 'yes'], ['page' => '-1']] as $query) {
            try {
                GprintPresetFilters::fromQuery($query, 25);
                self::fail('Invalid GPRINT filters were accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(['filter' => '', 'rows' => '10', 'page' => 1, 'sort_column' => 'name', 'sort_direction' => 'ASC', 'has_graphs' => 'true'], GprintPresetFilters::fromQuery(['rows' => '10'], 25, true)->query());
    }

    public function testPreferencesRoundTripPerUserAndRefuseSecondaryCollectors(): void
    {
        $access = $this->createMock(GprintPresetAccess::class);
        $access->method('authorize')->willReturn(new Actor(9, 'operator'));
        foreach ([1 => true, 2 => false] as $collector => $writes) {
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => $collector]);
            $preferences = new LegacyGprintPresetPreferences($access, $this->connection(), $configuration);
            try {
                $preferences->save(['filter' => 'cpu', 'rows' => '10', 'has_graphs' => 'true']);
                self::assertTrue($writes);
            } catch (\RuntimeException $error) {
                self::assertFalse($writes);
                self::assertSame('Filter preferences require the primary collector.', $error->getMessage());
                self::assertFalse($this->db->inTransaction());
            }
        }
        self::assertSame(['filter' => 'cpu', 'rows' => '10', 'has_graphs' => 'true'], $preferences->load());
        $this->db->exec("UPDATE settings_user SET value = '{\"named\":\"true\"}'");
        self::assertNull($preferences->load());
        $this->expectException(\InvalidArgumentException::class);
        $preferences->save(['named' => 'true']);
    }

    public function testAccessRequiresTheGprintRealmAndAnUsableAccount(): void
    {
        $this->db->exec("INSERT INTO user_auth VALUES (1, 'admin', 'on', '', ''), (2, 'console-only', 'on', '', ''), (3, 'disabled', '', '', ''), (4, 'guest', 'on', '', '');
            INSERT INTO settings VALUES ('guest_user', 'guest');
            INSERT INTO user_auth_realm VALUES (1, 8), (1, 5), (2, 8), (3, 8), (3, 5), (4, 8), (4, 5)");
        foreach ([2, 3, 4] as $id) {
            try {
                $this->access($id)->authorize();
                self::fail('User ' . $id . ' was admitted to GPRINT presets.');
            } catch (GprintPresetAccessDenied $error) {
                self::assertFalse($error->unauthenticated);
            }
        }
        $access = $this->access(1);
        self::assertSame(1, $access->authorize()->id);
        $this->db->beginTransaction();
        try {
            $access->assertCurrent(1);
            // A grant revoked after the page loaded must stop the write.
            $this->db->exec('DELETE FROM user_auth_realm WHERE user_id = 1 AND realm_id = 5');
            $this->expectException(GprintPresetAccessDenied::class);
            $access->assertCurrent(1);
        } finally {
            $this->db->rollBack();
        }
    }

    private function access(int $id): LegacyGprintPresetAccess
    {
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor($id, 'user' . $id));
        return new LegacyGprintPresetAccess($console, $this->connection());
    }

    private function connection(): DatabaseConnection
    {
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($this->db);
        return $connection;
    }

    /** @param list<int> $ids @return array<int, string> */
    private function revisions(array $ids): array
    {
        $revisions = [];
        foreach ($this->store->findMany($ids) as $preset) {
            $revisions[$preset->id] = $preset->revision;
        }
        return $revisions;
    }
}
