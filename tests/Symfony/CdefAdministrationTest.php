<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Kadupul\GraphDefinition\Domain\CdefListCriteria;
use Kadupul\GraphDefinition\Infrastructure\Persistence\DoctrineCdefRealmAccess;
use Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefEditor;
use Kadupul\GraphDefinition\Infrastructure\Persistence\DoctrineCdefCatalog;
use PHPUnit\Framework\TestCase;

final class CdefAdministrationTest extends TestCase
{
    private Connection $database;
    private LegacyCdefEditor $editor;
    private DoctrineCdefCatalog $catalog;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            "INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')",
            'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)',
            "INSERT INTO user_auth VALUES (42, 'operator', 'on', '', '')",
            'CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)',
            'INSERT INTO user_auth_realm VALUES (42, 8), (42, 14)',
            'CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)',
            'CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)',
            'CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)',
            'CREATE TABLE cdef (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT, system INTEGER DEFAULT 0, name TEXT)',
            'CREATE TABLE cdef_items (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT, cdef_id INTEGER, sequence INTEGER, type INTEGER, value TEXT)',
            'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, cdef_id INTEGER, local_graph_id INTEGER, graph_template_id INTEGER)',
            "INSERT INTO cdef VALUES (1, 'hash-one', 0, 'Traffic <peak>'), (2, 'hash-two', 0, 'Unused'), (3, 'hash-three', 0, 'Nested'), (4, 'hash-system', 1, 'System'), (5, 'hash-five', 0, 'Needle %_ literal')",
            "INSERT INTO cdef_items VALUES (11, 'item-one', 1, 1, 4, 'CURRENT_DATA_SOURCE'), (12, 'item-two', 1, 2, 6, '-1'), (13, 'item-three', 1, 3, 2, '3'), (14, 'item-four', 1, 4, 5, '3'), (31, 'nested-item', 3, 1, 4, 'CURRENT_DATA_SOURCE'), (21, 'free-item', 2, 1, 6, 'custom')",
            'INSERT INTO graph_templates_item VALUES (1, 1, 5, 0), (2, 1, 0, 7), (3, 1, 5, 0)',
        ] as $sql) {
            $this->database->executeStatement($sql);
        }
        $this->editor = new LegacyCdefEditor($this->database);
        $this->catalog = new DoctrineCdefCatalog($this->database);
    }

    public function testListCountsGraphTemplateAndNestedCdefUsageAndEscapesSearch(): void
    {
        $rows = $this->catalog->list(new CdefListCriteria('Traffic <', 1, 30));
        self::assertCount(1, $rows);
        self::assertSame('Traffic <peak>', $rows[0]->name);
        self::assertSame(1, $rows[0]->graphs);
        self::assertSame(1, $rows[0]->templates);
        self::assertSame(0, $this->catalog->count(new CdefListCriteria('Needle x')));
        self::assertSame(1, $this->catalog->count(new CdefListCriteria('Needle %_')));
        self::assertSame([5, 3, 1, 2], array_map(static fn($row): int => $row->id, $this->catalog->list(new CdefListCriteria('', 1, 30, 'name', 'asc'))));
        self::assertSame(1, $this->catalog->list(new CdefListCriteria('', 1, 30, 'graphs', 'desc'))[0]->id);
        self::assertSame(1, $this->catalog->list(new CdefListCriteria('', 1, 30, 'name', 'asc', true))[0]->id);
    }

    public function testPreviewExpandsNestedCdefAndMapsTypedValuesToRrdTokens(): void
    {
        self::assertSame('CURRENT_DATA_SOURCE,-1,*,CURRENT_DATA_SOURCE', $this->catalog->preview(1));
        $record = $this->catalog->find(1);
        self::assertNotNull($record);
        self::assertSame('*', CdefFunctions::itemLabel(2, '3'));
        self::assertSame('Nested', $record['items'][3]['label']);
        self::assertNull($this->catalog->find(4));
    }

    public function testDeleteRefusesGraphAndNestedReferencesButAllowsSelectedDependencySet(): void
    {
        try {
            $this->editor->act(42, 'delete', [2, 1]);
            self::fail('A CDEF used by graph items must not be deleted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('graphs or graph templates', $error->getMessage());
        }
        self::assertSame(5, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef'));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef_items WHERE cdef_id = 2'));

        $this->database->executeStatement('DELETE FROM graph_templates_item WHERE cdef_id = 1');
        try {
            $this->editor->act(42, 'delete', [3]);
            self::fail('A CDEF referenced by another CDEF must not be deleted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('referenced by another CDEF', $error->getMessage());
        }
        $this->editor->act(42, 'delete', [1, 3]);
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef WHERE id IN (1, 3)'));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef_items WHERE cdef_id IN (1, 3)'));
    }

    public function testDeleteUnusedCdefAndDuplicatePreserveTypedSequenceAndValues(): void
    {
        $this->editor->act(42, 'delete', [2]);
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef WHERE id = 2'));

        $this->editor->act(42, 'duplicate', [1], '<cdef_title> copy');
        $copy = $this->database->fetchAssociative("SELECT id, name FROM cdef WHERE name = 'Traffic <peak> copy'");
        self::assertNotFalse($copy);
        self::assertSame([
            ['sequence' => 1, 'type' => 4, 'value' => 'CURRENT_DATA_SOURCE'],
            ['sequence' => 2, 'type' => 6, 'value' => '-1'],
            ['sequence' => 3, 'type' => 2, 'value' => '3'],
            ['sequence' => 4, 'type' => 5, 'value' => '3'],
        ], array_map(
            static fn(array $row): array => ['sequence' => (int) $row['sequence'], 'type' => (int) $row['type'], 'value' => $row['value']],
            $this->database->fetchAllAssociative('SELECT sequence, type, value FROM cdef_items WHERE cdef_id = ? ORDER BY sequence', [(int) $copy['id']])
        ));
        self::assertNotSame('hash-one', $this->database->fetchOne('SELECT hash FROM cdef WHERE id = ?', [(int) $copy['id']]));
    }

    public function testTypedItemValidationCycleProtectionAndCompleteReorder(): void
    {
        $this->editor->saveItem(42, 1, 0, 1, '1');
        $functionId = (int) $this->database->fetchOne("SELECT id FROM cdef_items WHERE cdef_id = 1 AND type = 1");
        $this->editor->saveItem(42, 1, 0, 2, '1');
        $operatorId = (int) $this->database->fetchOne("SELECT id FROM cdef_items WHERE cdef_id = 1 AND type = 2 ORDER BY id DESC");
        $this->editor->saveItem(42, 1, 0, 4, 'ALL_DATA_SOURCES_DUPS');
        $sourceId = (int) $this->database->fetchOne("SELECT id FROM cdef_items WHERE cdef_id = 1 AND type = 4 ORDER BY id DESC");
        $this->editor->saveItem(42, 1, 0, 5, '3');
        $referenceId = (int) $this->database->fetchOne("SELECT id FROM cdef_items WHERE cdef_id = 1 AND type = 5 ORDER BY id DESC");
        $this->editor->saveItem(42, 1, 0, 6, 'raw, "quoted" & 100%');
        $stringId = (int) $this->database->fetchOne("SELECT id FROM cdef_items WHERE cdef_id = 1 AND type = 6 ORDER BY id DESC");
        $initialOrder = array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM cdef_items WHERE cdef_id = 1 ORDER BY sequence'));
        $this->editor->reorder(42, 1, [$stringId, $referenceId, $sourceId, $operatorId, $functionId, 11, 12, 13, 14], $initialOrder);
        self::assertSame($stringId, (int) $this->database->fetchOne('SELECT id FROM cdef_items WHERE cdef_id = 1 ORDER BY sequence LIMIT 1'));
        $before = array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM cdef_items WHERE cdef_id = 1 ORDER BY sequence'));
        try {
            $this->editor->reorder(42, 1, $initialOrder, $initialOrder);
            self::fail('A stale form must not reorder unchanged IDs after their persisted order has changed.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('The CDEF item selection changed. Reload the form.', $error->getMessage());
            self::assertSame($before, array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM cdef_items WHERE cdef_id = 1 ORDER BY sequence')));
        }
        try {
            $this->editor->reorder(42, 1, [$functionId], $before);
            self::fail('Reorder must include each item once.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM cdef_items WHERE cdef_id = 1 ORDER BY sequence')));
        }
        try {
            $this->editor->saveItem(42, 3, 0, 5, '1');
            self::fail('New references must not create cycles.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('reference cycle', $error->getMessage());
        }
        try {
            $this->editor->saveItem(42, 1, 0, 1, '999');
            self::fail('Unknown function IDs are invalid.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('valid CDEF function', $error->getMessage());
        }
    }

    public function testDeleteItemNormalizesSequenceAndCrossParentItemMutationIsRejected(): void
    {
        $this->editor->deleteItem(42, 1, 12);
        self::assertSame([1, 2, 3], array_map('intval', $this->database->fetchFirstColumn('SELECT sequence FROM cdef_items WHERE cdef_id = 1 ORDER BY sequence')));
        try {
            $this->editor->deleteItem(42, 3, 12);
            self::fail('An item cannot be removed through the wrong parent CDEF.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('CDEF item not found.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef_items WHERE id = 12'));
    }

    public function testWritesRecheckRealmInsideTransactionAndHideSystemDefinitions(): void
    {
        self::assertNull($this->catalog->find(4));
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 42 AND realm_id = 14');
        try {
            $this->editor->save(42, 2, 'Unauthorized change');
            self::fail('Removed graph-definition realm must block writes.');
        } catch (\RuntimeException $error) {
            self::assertSame('Access denied.', $error->getMessage());
        }
        self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM cdef WHERE id = 2'));

        $this->database->executeStatement("INSERT INTO user_auth_realm VALUES (42, 14)");
        $this->database->executeStatement("UPDATE user_auth SET must_change_password = 'on' WHERE id = 42");
        try {
            $this->editor->save(42, 2, 'Still unauthorized');
            self::fail('A password-change-required actor must not mutate CDEFs.');
        } catch (\RuntimeException $error) {
            self::assertSame('Access denied.', $error->getMessage());
        }
        self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM cdef WHERE id = 2'));
    }

    public function testWritesRequireCurrentDirectOrEnabledGroupConsoleAndCdefRealms(): void
    {
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 42');
        $this->database->executeStatement("INSERT INTO user_auth_group VALUES (7, 'on')");
        $this->database->executeStatement('INSERT INTO user_auth_group_members VALUES (7, 42)');
        $this->database->executeStatement('INSERT INTO user_auth_group_realm VALUES (7, 8), (7, 14)');

        $this->editor->save(42, 2, 'Authorized by enabled group');
        self::assertSame('Authorized by enabled group', $this->database->fetchOne('SELECT name FROM cdef WHERE id = 2'));

        $this->database->executeStatement("UPDATE user_auth_group SET enabled = '' WHERE id = 7");
        try {
            $this->editor->save(42, 2, 'Stale realm snapshot');
            self::fail('A disabled group must not authorize writes from a stale actor snapshot.');
        } catch (\RuntimeException $error) {
            self::assertSame('Access denied.', $error->getMessage());
        }
        self::assertSame('Authorized by enabled group', $this->database->fetchOne('SELECT name FROM cdef WHERE id = 2'));
    }

    public function testWritesRecheckAccountAndAuthenticationPolicyBeforeChangingData(): void
    {
        foreach ([
            "UPDATE user_auth SET enabled = '' WHERE id = 42",
            "UPDATE user_auth SET enabled = 'on', locked = 'on' WHERE id = 42",
            "UPDATE user_auth SET locked = '', must_change_password = 'on' WHERE id = 42",
            "UPDATE user_auth SET must_change_password = '' WHERE id = 42; UPDATE settings SET value = '99' WHERE name = 'auth_method'",
            "UPDATE settings SET value = '1' WHERE name = 'auth_method'; UPDATE settings SET value = 'operator' WHERE name = 'guest_user'",
        ] as $policyChange) {
            foreach (explode('; ', $policyChange) as $sql) {
                $this->database->executeStatement($sql);
            }
            try {
                $this->editor->save(42, 2, 'Policy must deny this');
                self::fail('Disabled, locked, password-change-required, unsupported-auth, and guest accounts must not write.');
            } catch (\RuntimeException $error) {
                self::assertSame('Access denied.', $error->getMessage());
            }
            self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM cdef WHERE id = 2'));
            $this->database->executeStatement("UPDATE settings SET value = '1' WHERE name = 'auth_method'");
            $this->database->executeStatement("UPDATE settings SET value = 'guest' WHERE name = 'guest_user'");
            $this->database->executeStatement("UPDATE user_auth SET enabled = 'on', locked = '', must_change_password = '' WHERE id = 42");
        }
    }

    public function testRrdtoolVersionControlsRoundFunctionAndExistingCyclesRenderSafely(): void
    {
        self::assertArrayNotHasKey('59', $this->catalog->functions());
        $this->database->executeStatement("INSERT INTO settings VALUES ('rrdtool_version', '1.8.x')");
        self::assertSame('ROUND', $this->catalog->functions()['59']);
        $this->editor->saveItem(42, 1, 0, 1, '59');
        self::assertStringContainsString('ROUND', $this->catalog->preview(1));

        $this->database->executeStatement("INSERT INTO cdef_items VALUES (33, 'cycle-item', 3, 2, 5, '1')");
        self::assertStringContainsString('[recursive CDEF]', $this->catalog->preview(1));
    }

    public function testDefinitionRealmAcceptsDirectAndEnabledGroupGrantsOnly(): void
    {
        $realm = new DoctrineCdefRealmAccess($this->database);
        self::assertTrue($realm->canManageDefinitions(42));
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 42 AND realm_id = 14');
        $this->database->executeStatement('INSERT INTO user_auth_group VALUES (7, \'on\')');
        $this->database->executeStatement('INSERT INTO user_auth_group_members VALUES (7, 42)');
        $this->database->executeStatement('INSERT INTO user_auth_group_realm VALUES (7, 14)');
        self::assertTrue($realm->canManageDefinitions(42));
        $this->database->executeStatement("UPDATE user_auth_group SET enabled = '' WHERE id = 7");
        self::assertFalse($realm->canManageDefinitions(42));
    }
}
