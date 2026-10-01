<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Domain\LinkConflict;
use Kadupul\Navigation\Domain\ExternalLink;
use Kadupul\Navigation\Infrastructure\Legacy\LegacyLinkStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class LegacyLinkStoreTest extends TestCase
{
    private \PDO $db;
    private LegacyLinkStore $store;
    private LinkAccess $access;
    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE external_links (id INTEGER PRIMARY KEY AUTOINCREMENT, sortorder INTEGER, title TEXT, contentfile TEXT, style TEXT, extendedstyle TEXT, enabled TEXT, refresh INTEGER);
            CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER, PRIMARY KEY(user_id,realm_id)); CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER);
            CREATE TABLE settings (name TEXT, value TEXT); INSERT INTO settings VALUES ('num_rows_table','25');");
        $database = new readonly class ($this->db) implements DatabaseConnection {
            public function __construct(private \PDO $db) {} public function get(): \PDO
            {
                return $this->db;
            }
        };
        $this->access = $this->createMock(LinkAccess::class);
        $this->access->method('authorize')->willReturn(new Actor(1, 'admin'));
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new LegacyLinkStore($database, $this->access, $this->createMock(AuditTrail::class), $configuration, dirname(__DIR__, 2));
    }
    private function create(string $title = 'A'): int
    {
        $fields = ExternalLinkTest::fields();
        $fields['title'] = $title;
        return $this->store->save(1, null, $fields, $this->store->snapshot()['revision']);
    }
    public function testSavePreservesBytesAppendsAndGrantsActor(): void
    {
        $a = $this->create('A');
        $b = $this->create('B');
        $snapshot = $this->store->snapshot();
        $fields = ExternalLinkTest::fields();
        $this->store->save(1, $a, $fields, $snapshot['revision']);
        self::assertSame('3', $this->db->query("SELECT CAST(sortorder AS TEXT) FROM external_links WHERE id=$a")->fetchColumn());
        self::assertSame('A <tag> 東京', $this->db->query("SELECT title FROM external_links WHERE id=$a")->fetchColumn());
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM user_auth_realm')->fetchColumn());
        self::assertSame([$a,$b], array_map(static fn($link) => $link->id, $this->store->snapshot()['links']));
    }

    public function testLiteralOldSentinelSectionCanBeCreatedAndEditedWithoutReplacement(): void
    {
        self::assertGreaterThan(50, mb_strlen(ExternalLink::NEW_SECTION_SELECTION));
        $fields = array_replace(ExternalLinkTest::fields(), ['style' => 'CONSOLE', 'consolesection' => ExternalLink::NEW_SECTION_SELECTION, 'consolenewsection' => '__NEW__']);
        $id = $this->store->save(1, null, $fields, $this->store->snapshot()['revision']);
        $snapshot = $this->store->snapshot();
        self::assertSame('__NEW__', $snapshot['links'][0]->extendedstyle);
        $fields = $snapshot['links'][0]->fields([]);
        $this->store->save(1, $id, $fields, $snapshot['revision']);
        self::assertSame('__NEW__', $this->store->snapshot()['links'][0]->extendedstyle);
    }
    public function testStaleSaveAndActionsDoNotChangeRows(): void
    {
        $a = $this->create();
        $stale = $this->store->snapshot()['revision'];
        $this->create('B');
        $before = $this->store->snapshot();
        foreach (['save','delete'] as $operation) {
            try {
                if ($operation === 'save') {
                    $this->store->save(1, $a, ExternalLinkTest::fields(), $stale);
                } else {
                    $this->store->mutate(1, [$a], 'delete', $stale);
                }self::fail('Expected conflict');
            } catch (LinkConflict) {
                self::assertSame($before['revision'], $this->store->snapshot()['revision']);
            }
        }
    }
    public function testDeleteCleansDirectAndGroupGrantsAndPreservesBulkGaps(): void
    {
        $a = $this->create();
        $b = $this->create('B');
        $this->db->exec('INSERT INTO user_auth_group_realm VALUES (4,' . ($a + 10000) . ')');
        $this->store->mutate(1, [$a], 'delete', $this->store->snapshot()['revision']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_auth_group_realm')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM user_auth_realm')->fetchColumn());
        self::assertSame(2, $this->store->snapshot()['links'][0]->sortorder);
    }
    public function testMovesRepairGapAndStaleReorderFails(): void
    {
        $a = $this->create();
        $b = $this->create('B');
        $this->db->exec("UPDATE external_links SET sortorder=8 WHERE id=$b");
        $revision = $this->store->snapshot()['revision'];
        $this->store->mutate(1, [$b], 'up', $revision);
        self::assertSame([$b,$a], array_map('intval', $this->db->query('SELECT id FROM external_links ORDER BY sortorder')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->expectException(LinkConflict::class);
        $this->store->mutate(1, [$a], 'up', $revision);
    }
    public function testAtomicDeleteRollbackIncludesGrants(): void
    {
        $a = $this->create();
        $b = $this->create('B');
        $this->db->exec("CREATE TRIGGER stop_delete BEFORE DELETE ON external_links WHEN OLD.id=$b BEGIN SELECT RAISE(ABORT,'blocked'); END");
        $before = $this->store->snapshot()['revision'];
        try {
            $this->store->mutate(1, [$a,$b], 'delete', $before);
            self::fail('Expected failure');
        } catch (\PDOException) {
        }
        self::assertSame($before, $this->store->snapshot()['revision']);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM user_auth_realm')->fetchColumn());
    }
    public function testCallerTransactionIsNeverRolledBack(): void
    {
        $this->db->beginTransaction();
        $this->db->exec("INSERT INTO settings VALUES ('caller','before')");
        try {
            $this->store->save(1, null, ExternalLinkTest::fields(), $this->store->snapshot()['revision']);
            self::fail('Expected failure');
        } catch (\RuntimeException) {
        }
        self::assertTrue($this->db->inTransaction());
        self::assertSame('before', $this->db->query("SELECT value FROM settings WHERE name='caller'")->fetchColumn());
        $this->db->rollBack();
    }
    public function testListUsesPreparedLegacyWildcardSearchAndBoundPagination(): void
    {
        $this->create('First');
        $this->create('Last');
        $filters = \Kadupul\Navigation\Infrastructure\Symfony\LinkListParameters::parse(['filter' => 'F%st', 'rows' => '10']);
        $page = $this->store->list($filters);
        self::assertSame(1, $page['total']);
        self::assertSame('First', $page['links'][0]->title);
        $filters['filter'] = "' OR 1=1 --";
        self::assertSame(0, $this->store->list($filters)['total']);
    }
    public function testEnableDisableAndRejectedAuthority(): void
    {
        $a = $this->create();
        $this->store->mutate(1, [$a], 'disable', $this->store->snapshot()['revision']);
        self::assertFalse($this->store->snapshot()['links'][0]->enabled);
        $this->store->mutate(1, [$a], 'enable', $this->store->snapshot()['revision']);
        self::assertTrue($this->store->snapshot()['links'][0]->enabled);
        $this->access->method('assertCurrent')->willThrowException(new \RuntimeException('denied'));
        $before = $this->store->snapshot()['revision'];
        try {
            $this->store->mutate(1, [$a], 'delete', $before);
            self::fail('Expected denial');
        } catch (\RuntimeException) {
        }
        self::assertSame($before, $this->store->snapshot()['revision']);
    }
}
