<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\GraphDefinition\Domain\VdefListCriteria;
use Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyVdefEditor;
use Kadupul\GraphDefinition\Infrastructure\Persistence\DoctrineVdefCatalog;
use PHPUnit\Framework\TestCase;

final class VdefAdministrationTest extends TestCase
{
    private \Doctrine\DBAL\Connection $database;
    private LegacyVdefEditor $editor;
    private DoctrineVdefCatalog $catalog;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            'INSERT INTO settings VALUES (\'auth_method\', \'1\'), (\'guest_user\', \'guest\')',
            'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT, password_change TEXT)',
            "INSERT INTO user_auth VALUES (42, 'operator', 'on', '', '', '')",
            'CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)',
            'INSERT INTO user_auth_realm VALUES (42, 8), (42, 14)',
            'CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)',
            'CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)',
            'CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)',
            'CREATE TABLE vdef (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT, name TEXT)',
            'CREATE TABLE vdef_items (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT, vdef_id INTEGER, sequence INTEGER, type INTEGER, value TEXT)',
            'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, vdef_id INTEGER, local_graph_id INTEGER, graph_template_id INTEGER)',
            "INSERT INTO vdef VALUES (1, 'hash-one', 'Traffic <peak>'), (2, 'hash-two', 'Unused')",
            "INSERT INTO vdef_items VALUES (11, 'item-one', 1, 1, 4, 'CURRENT_DATA_SOURCE'), (12, 'item-two', 1, 2, 1, '8'), (21, 'item-three', 2, 1, 6, 'custom')",
            'INSERT INTO graph_templates_item VALUES (1, 1, 5, 0), (2, 1, 0, 7), (3, 1, 5, 0)',
        ] as $sql) {
            $this->database->executeStatement($sql);
        }
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->editor = new LegacyVdefEditor($this->database, $configuration);
        $this->catalog = new DoctrineVdefCatalog($this->database);
    }

    public function testBulkSelectionReadsFiveHundredRevisionsInTwoQueries(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $queries = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql'];
                }
            }
        };
        $config = new \Doctrine\DBAL\Configuration();
        $config->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $database->executeStatement('CREATE TABLE vdef (id INTEGER PRIMARY KEY, name TEXT)');
        $database->executeStatement('CREATE TABLE vdef_items (id INTEGER PRIMARY KEY, vdef_id INTEGER, sequence INTEGER, type INTEGER, value TEXT)');
        $ids = range(1, 500);
        foreach ($ids as $id) {
            $database->insert('vdef', ['id' => $id, 'name' => 'Selected ' . $id]);
            $database->insert('vdef_items', ['id' => $id, 'vdef_id' => $id, 'sequence' => 1, 'type' => 5, 'value' => '999']);
        }
        $catalog = new DoctrineVdefCatalog($database);
        $logger->queries = [];
        $selected = $catalog->selected($ids);
        self::assertCount(2, $logger->queries);
        self::assertCount(500, $selected);
        foreach ($selected as $id => $record) {
            self::assertSame($id, $record['id']);
            self::assertSame('Selected ' . $id, $record['name']);
            self::assertSame(\Kadupul\GraphDefinition\Domain\VdefRevision::fromState($record['name'], [['id' => $id, 'sequence' => 1, 'type' => 5, 'value' => '999']]), $record['revision']);
        }
        $database->executeStatement('DELETE FROM vdef WHERE id = 500');
        self::assertArrayNotHasKey(500, $catalog->selected($ids));
        $database->close();
    }

    public function testInUseDefinitionCanBeDuplicatedButCannotBeDeleted(): void
    {
        $this->editor->act(42, 'duplicate', [1], '<vdef_title> copy', [1 => $this->revision(1)]);
        self::assertSame('Traffic <peak> copy', $this->database->fetchOne('SELECT name FROM vdef WHERE id = 3'));
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef_items WHERE vdef_id = 3'));
        $this->expectExceptionMessage('VDEFs in use cannot be deleted.');
        $this->editor->act(42, 'delete', [1], '', [1 => $this->revision(1)]);
    }

    public function testCallerOwnedTransactionIsPreservedWhenMutationIsRefused(): void
    {
        $this->database->beginTransaction();
        $this->database->executeStatement("UPDATE vdef SET name='caller-private' WHERE id=2");
        try {
            $this->editor->save(42, 2, 'should-not-save', $this->revision(2));
            self::fail('Caller-owned transaction was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('VDEF writes require their own transaction.', $error->getMessage());
        }
        self::assertTrue($this->database->isTransactionActive());
        self::assertSame('caller-private', $this->database->fetchOne('SELECT name FROM vdef WHERE id=2'));
        $this->database->rollBack();
        self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM vdef WHERE id=2'));
    }

    public function testNativeCallerTransactionAndDoctrineNestingRemainUntouched(): void
    {
        $native = $this->database->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->beginTransaction();
        $this->database->executeStatement("UPDATE vdef SET name='caller-native' WHERE id=2");
        $nesting = $this->database->getTransactionNestingLevel();
        self::assertSame(0, $nesting);
        try {
            $this->editor->save(42, 2, 'should-not-save', $this->revision(2));
            self::fail('Native caller-owned transaction was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('VDEF writes require their own transaction.', $error->getMessage());
        }
        self::assertTrue($native->inTransaction());
        self::assertSame($nesting, $this->database->getTransactionNestingLevel());
        self::assertSame('caller-native', $this->database->fetchOne('SELECT name FROM vdef WHERE id=2'));
        $native->rollBack();
        self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM vdef WHERE id=2'));
    }

    public function testListCountsGraphAndTemplateReferencesAndEscapesSearchWildcards(): void
    {
        $page = $this->catalog->list(new VdefListCriteria('Traffic <', 1, 30, 'name', 'asc'));

        self::assertCount(1, $page);
        self::assertSame('Traffic <peak>', $page[0]->name);
        self::assertSame(1, $page[0]->graphs);
        self::assertSame(1, $page[0]->templates);
        self::assertSame(1, $this->catalog->count(new VdefListCriteria('Traffic <')));
        self::assertSame(1, $this->catalog->list(new VdefListCriteria('', 1, 30, 'graphs', 'desc'))[0]->id);
    }

    public function testDeletingReferencedDefinitionIsRejectedAndWholeBulkActionRollsBack(): void
    {
        try {
            $this->editor->act(42, 'delete', [2, 1], '<vdef_title> (1)', [2 => $this->revision(2), 1 => $this->revision(1)]);
            self::fail('A referenced VDEF must not be deleted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('VDEFs in use cannot be deleted.', $error->getMessage());
        }

        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef'));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef_items WHERE vdef_id = 2'));
    }

    public function testDeleteUnusedDefinitionRemovesItemsButUsageProtectsReferencedOnes(): void
    {
        $this->editor->act(42, 'delete', [2], '<vdef_title> (1)', [2 => $this->revision(2)]);

        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef WHERE id = 2'));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef_items WHERE vdef_id = 2'));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef WHERE id = 1'));
    }

    public function testReferencesWithinTheWholeDeletionSelectionDoNotBlockDeletion(): void
    {
        $this->database->executeStatement("INSERT INTO vdef VALUES (3, 'hash-three', 'Nested target')");
        $this->database->executeStatement("INSERT INTO vdef_items VALUES (31, 'nested-ref', 2, 2, 5, '3 '), (32, 'return-ref', 3, 1, 5, '02')");
        $this->editor->act(42, 'delete', [2, 3], '<vdef_title> (1)', [2 => $this->revision(2), 3 => $this->revision(3)]);
        self::assertSame([1], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM vdef ORDER BY id')));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef_items WHERE vdef_id IN (2, 3)'));
    }

    public function testDeleteProtectsDefinitionsReferencedByAnotherVdefItem(): void
    {
        $this->database->executeStatement("INSERT INTO vdef VALUES (3, 'hash-three', 'Nested target')");
        $this->database->executeStatement("INSERT INTO vdef_items VALUES (31, 'nested-ref', 2, 2, 5, '3')");
        $row = $this->catalog->list(new VdefListCriteria('', 1, 30, 'name', 'asc'))[0];
        self::assertSame(3, $row->id);
        self::assertTrue($row->inUse());
        self::assertSame(1, $row->referencingVdefs);

        try {
            $this->editor->act(42, 'delete', [3], '<vdef_title> (1)', [3 => $this->revision(3)]);
            self::fail('A VDEF referenced by another VDEF must not be deleted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('VDEFs in use cannot be deleted.', $error->getMessage());
        }

        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef WHERE id = 3'));
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef_items WHERE vdef_id = 2'));
    }

    /** @dataProvider legacyReferenceValues */
    public function testLegacyReferenceIdentityMatchesPreviewAndDeletion(string $value): void
    {
        $this->database->executeStatement("INSERT INTO vdef VALUES (3, 'hash-three', 'Nested target')");
        $this->database->insert('vdef_items', ['hash' => 'nested', 'vdef_id' => 2, 'sequence' => 2, 'type' => 5, 'value' => $value]);
        $this->database->insert('vdef_items', ['hash' => 'same-owner-alias', 'vdef_id' => 2, 'sequence' => 3, 'type' => 5, 'value' => '3']);
        $target = array_values(array_filter($this->catalog->list(new VdefListCriteria()), static fn($row): bool => $row->id === 3))[0];
        self::assertSame(1, $target->referencingVdefs, 'Distinct owners use the same PHP numeric identity as preview.');
        self::assertTrue($target->inUse());
        $this->database->executeStatement("DELETE FROM vdef_items WHERE hash = 'same-owner-alias'");
        $before = $this->database->fetchAllAssociative('SELECT * FROM vdef_items ORDER BY id');
        try {
            $this->editor->act(42, 'delete', [3], '<vdef_title> (1)', [3 => $this->revision(3)]);
            self::fail('A preserved legacy reference was not protected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('VDEFs in use cannot be deleted.', $error->getMessage());
        }
        self::assertSame($before, $this->database->fetchAllAssociative('SELECT * FROM vdef_items ORDER BY id'));
        self::assertSame('Nested target', $this->database->fetchOne('SELECT name FROM vdef WHERE id = 3'));
    }

    /** @dataProvider legacyReferenceValues */
    public function testSelfReferenceDoesNotCountAsExternalUsage(string $value): void
    {
        $this->database->insert('vdef', ['id' => 3, 'hash' => 'self-parent', 'name' => 'Self reference']);
        $this->database->insert('vdef_items', ['hash' => 'self-item', 'vdef_id' => 3, 'sequence' => 1, 'type' => 5, 'value' => $value]);
        $rows = array_values(array_filter($this->catalog->list(new VdefListCriteria()), static fn($row): bool => $row->id === 3));
        self::assertSame(0, $rows[0]->referencingVdefs);
        self::assertFalse($rows[0]->inUse());
    }

    /** @dataProvider legacyReferenceValues */
    public function testDeletingSelfReferenceRemovesOwnedItemsAtomically(string $value): void
    {
        $this->database->insert('vdef', ['id' => 3, 'hash' => 'self-parent', 'name' => 'Self reference']);
        $this->database->insert('vdef_items', ['hash' => 'self-item', 'vdef_id' => 3, 'sequence' => 1, 'type' => 5, 'value' => $value]);
        $this->editor->act(42, 'delete', [3], '', [3 => $this->revision(3)]);
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef WHERE id = 3'));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef_items WHERE vdef_id = 3'));
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM vdef'));
    }

    public static function legacyReferenceValues(): array
    {
        return array_map(static fn(string $value): array => [$value], ['03', '3 ', ' 3', '+3', '3tail', '3.9', '3e0', '30e-1']);
    }

    /** @dataProvider invalidDuplicateTitles */
    public function testDuplicateRejectsInvalidNamesWithoutPartialCopies(string $format): void
    {
        $parents = $this->database->fetchAllAssociative('SELECT * FROM vdef ORDER BY id');
        $items = $this->database->fetchAllAssociative('SELECT * FROM vdef_items ORDER BY id');
        try {
            $this->editor->act(42, 'duplicate', [2], $format, [2 => $this->revision(2)]);
            self::fail('A duplicate name outside the save invariant was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter a valid VDEF name.', $error->getMessage());
        }
        self::assertSame($parents, $this->database->fetchAllAssociative('SELECT * FROM vdef ORDER BY id'));
        self::assertSame($items, $this->database->fetchAllAssociative('SELECT * FROM vdef_items ORDER BY id'));
        self::assertFalse($this->database->isTransactionActive());
    }

    public static function invalidDuplicateTitles(): array
    {
        return array_map(static fn(string $value): array => [$value], ['', '  ', "bad\nname", "bad\rname", "bad\0name", str_repeat('é', 256), '<vdef_title>' . str_repeat('x', 250)]);
    }

    public function testDuplicatePreservesSequenceTypeAndValueAndSubstitutesTitle(): void
    {
        $this->editor->act(42, 'duplicate', [1], '<vdef_title> copy', [1 => $this->revision(1)]);

        $copy = $this->database->fetchAssociative("SELECT id, name FROM vdef WHERE name = 'Traffic <peak> copy'");
        self::assertNotFalse($copy);
        self::assertSame([
            ['sequence' => 1, 'type' => 4, 'value' => 'CURRENT_DATA_SOURCE'],
            ['sequence' => 2, 'type' => 1, 'value' => '8'],
        ], array_map(static fn(array $row): array => ['sequence' => (int) $row['sequence'], 'type' => (int) $row['type'], 'value' => $row['value']], $this->database->fetchAllAssociative('SELECT sequence, type, value FROM vdef_items WHERE vdef_id = ? ORDER BY sequence', [(int) $copy['id']])));
    }

    public function testItemCreateUpdateAndCompleteReorderAreTransactional(): void
    {
        $this->editor->saveItem(42, 1, 0, 6, 'quoted "value" & 50%', $this->revision(1));
        $newItem = (int) $this->database->fetchOne("SELECT id FROM vdef_items WHERE vdef_id = 1 AND value = 'quoted \"value\" & 50%'");
        self::assertGreaterThan(0, $newItem);
        $staleRevision = $this->revision(1);
        $this->editor->reorder(42, 1, [$newItem, 12, 11], $staleRevision);
        self::assertSame([$newItem, 12, 11], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM vdef_items WHERE vdef_id = 1 ORDER BY sequence')));

        try {
            $this->editor->reorder(42, 1, [11, 12], $staleRevision);
            self::fail('The item order must include every current item exactly once.');
        } catch (\InvalidArgumentException) {
            self::assertSame([$newItem, 12, 11], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM vdef_items WHERE vdef_id = 1 ORDER BY sequence')));
        }
    }

    public function testMutationRechecksRealmInsideTransaction(): void
    {
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 42 AND realm_id = 14');

        try {
            $this->editor->save(42, 2, 'Unauthorized change');
            self::fail('Removed graph administration realm must block writes.');
        } catch (\RuntimeException $error) {
            self::assertSame('Access denied.', $error->getMessage());
        }

        self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM vdef WHERE id = 2'));
    }

    public function testMutationRechecksForcedPasswordChangeInsideTransaction(): void
    {
        $this->database->executeStatement("UPDATE user_auth SET must_change_password = 'on', password_change = 'on' WHERE id = 42");

        try {
            $this->editor->save(42, 2, 'Unauthorized change');
            self::fail('A session that requires a password change must not write.');
        } catch (\RuntimeException $error) {
            self::assertSame('Access denied.', $error->getMessage());
        }

        self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM vdef WHERE id = 2'));
    }

    public function testMutationRechecksAccountAndConsoleAccessForStaleSessionActor(): void
    {
        $revocations = [
            "UPDATE user_auth SET enabled = '' WHERE id = 42",
            "UPDATE user_auth SET locked = 'on' WHERE id = 42",
            "UPDATE settings SET value = 'operator' WHERE name = 'guest_user'",
            "UPDATE settings SET value = '99' WHERE name = 'auth_method'",
            'DELETE FROM user_auth_realm WHERE user_id = 42 AND realm_id = 8',
        ];

        foreach ($revocations as $revoke) {
            $this->database->executeStatement("UPDATE user_auth SET enabled = 'on', locked = '', must_change_password = '', password_change = '' WHERE id = 42");
            $this->database->executeStatement("UPDATE settings SET value = 'guest' WHERE name = 'guest_user'");
            $this->database->executeStatement("UPDATE settings SET value = '1' WHERE name = 'auth_method'");
            $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 42');
            $this->database->executeStatement('INSERT INTO user_auth_realm VALUES (42, 8), (42, 14)');
            // Prove this boundary starts from an authorized actor. Previous
            // revocations must not mask the revocation being tested.
            $this->editor->save(42, 2, 'Unused', $this->revision(2));
            self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM vdef WHERE id = 2'));
            $revision = $this->revision(2);
            $this->database->executeStatement($revoke);
            try {
                // This models a request whose ConsoleAccess snapshot was
                // acquired before the account or console grant was revoked.
                $this->editor->save(42, 2, 'Unauthorized change', $revision);
                self::fail('A stale console actor must not retain write access.');
            } catch (\RuntimeException $error) {
                self::assertSame('Access denied.', $error->getMessage());
            }

            self::assertSame('Unused', $this->database->fetchOne('SELECT name FROM vdef WHERE id = 2'));
        }
    }

    public function testRevisionRejectsStaleParentAndItemEditors(): void
    {
        $parentRevision = $this->revision(2);
        $this->editor->save(42, 2, 'First writer', $parentRevision);
        try {
            $this->editor->save(42, 2, 'Stale writer', $parentRevision);
            self::fail('A stale VDEF editor must not overwrite a newer name.');
        } catch (\InvalidArgumentException) {
            self::assertSame('First writer', $this->database->fetchOne('SELECT name FROM vdef WHERE id = 2'));
        }

        $itemRevision = $this->revision(2);
        $this->editor->saveItem(42, 2, 21, 6, 'first item writer', $itemRevision);
        try {
            $this->editor->saveItem(42, 2, 21, 6, 'stale item writer', $itemRevision);
            self::fail('A stale item editor must not overwrite a newer item value.');
        } catch (\InvalidArgumentException) {
            self::assertSame('first item writer', $this->database->fetchOne('SELECT value FROM vdef_items WHERE id = 21'));
        }
    }

    public function testRevisionRejectsStaleSameItemSetReorder(): void
    {
        $revision = $this->revision(1);
        $ids = array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM vdef_items WHERE vdef_id = 1 ORDER BY sequence, id'));
        $reordered = array_reverse($ids);
        $this->editor->reorder(42, 1, $reordered, $revision);

        try {
            $this->editor->reorder(42, 1, $ids, $revision);
            self::fail('A stale same-ID reorder must not overwrite a newer item order.');
        } catch (\InvalidArgumentException) {
            self::assertSame($reordered, array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM vdef_items WHERE vdef_id = 1 ORDER BY sequence, id')));
        }
    }

    private function revision(int $id): string
    {
        return (string) $this->catalog->find($id)['revision'];
    }
}
