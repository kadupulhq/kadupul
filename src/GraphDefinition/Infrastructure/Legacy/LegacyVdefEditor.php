<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\GraphDefinition\Application\Query\VdefAccessDenied;
use Kadupul\GraphDefinition\Application\Port\VdefEditor;
use Kadupul\GraphDefinition\Domain\VdefFunctions;
use Kadupul\GraphDefinition\Domain\VdefRevision;

final readonly class LegacyVdefEditor implements VdefEditor
{
    public function __construct(private Connection $database, private LegacyConfiguration $configuration) {}

    public function save(int $actorId, int $id, string $name, string $revision = ''): int
    {
        if ($id < 0) {
            throw new \InvalidArgumentException('Enter a valid VDEF name.');
        }
        $this->validateName($name);
        return $this->transaction($actorId, function () use ($id, $name, $revision): int {
            if ($id > 0) {
                $this->assertRevision($this->lockState($id), $revision);
                $this->database->executeStatement('UPDATE vdef SET name = ? WHERE id = ?', [$name, $id]);
                return $id;
            }
            $this->database->insert('vdef', ['hash' => bin2hex(random_bytes(16)), 'name' => $name]);
            return (int) $this->database->lastInsertId();
        });
    }

    public function saveItem(int $actorId, int $vdefId, int $itemId, int $type, string $value, string $revision): void
    {
        if ($vdefId < 1 || $itemId < 0) {
            throw new \InvalidArgumentException('Invalid VDEF item selection.');
        }
        $this->transaction($actorId, function () use ($vdefId, $itemId, $type, $value, $revision): void {
            $this->assertRevision($this->lockState($vdefId), $revision);
            $this->validateItem($type, $value);
            if ($itemId > 0) {
                $item = $this->database->fetchAssociative('SELECT id FROM vdef_items WHERE id = ? AND vdef_id = ?' . $this->forUpdate(), [$itemId, $vdefId]);
                if ($item === false) {
                    throw new \InvalidArgumentException('VDEF item not found.');
                }
                $this->database->update('vdef_items', ['type' => $type, 'value' => $value], ['id' => $itemId, 'vdef_id' => $vdefId]);
                return;
            }
            $sequence = (int) $this->database->fetchOne('SELECT COALESCE(MAX(sequence), 0) + 1 FROM vdef_items WHERE vdef_id = ?', [$vdefId]);
            $this->database->insert('vdef_items', ['hash' => bin2hex(random_bytes(16)), 'vdef_id' => $vdefId, 'sequence' => $sequence, 'type' => $type, 'value' => $value]);
        });
    }

    public function deleteItem(int $actorId, int $vdefId, int $itemId, string $revision): void
    {
        if ($vdefId < 1 || $itemId < 1) {
            throw new \InvalidArgumentException('Invalid VDEF item selection.');
        }
        $this->transaction($actorId, function () use ($vdefId, $itemId, $revision): void {
            $this->assertRevision($this->lockState($vdefId), $revision);
            if ($this->database->fetchOne('SELECT id FROM vdef_items WHERE id = ? AND vdef_id = ?' . $this->forUpdate(), [$itemId, $vdefId]) === false) {
                throw new \InvalidArgumentException('VDEF item not found.');
            }
            $this->database->executeStatement('DELETE FROM vdef_items WHERE id = ? AND vdef_id = ?', [$itemId, $vdefId]);
        });
    }

    public function reorder(int $actorId, int $vdefId, array $orderedItemIds, string $revision): void
    {
        if ($vdefId < 1 || count($orderedItemIds) > 500 || array_filter($orderedItemIds, static fn(mixed $id): bool => !is_int($id) || $id < 1 || $id > 2147483647) !== []) {
            throw new \InvalidArgumentException('The VDEF item order is invalid.');
        }
        $this->transaction($actorId, function () use ($vdefId, $orderedItemIds, $revision): void {
            $state = $this->lockState($vdefId);
            $this->assertRevision($state, $revision);
            $rows = array_column($state['items'], 'id');
            $expected = array_map('intval', $rows);
            $actual = array_values(array_unique($orderedItemIds));
            sort($expected);
            $sortedActual = $actual;
            sort($sortedActual);
            if ($expected !== $sortedActual || count($actual) !== count($orderedItemIds)) {
                throw new \InvalidArgumentException('The VDEF item order is stale or invalid.');
            }
            foreach ($actual as $index => $itemId) {
                $this->database->update('vdef_items', ['sequence' => $index + 1], ['id' => $itemId, 'vdef_id' => $vdefId]);
            }
        });
    }

    public function act(int $actorId, string $action, array $ids, string $titleFormat = '<vdef_title> (1)', array $revisions = []): void
    {
        if (!in_array($action, ['delete', 'duplicate'], true) || $ids === [] || count($ids) > 500
            || count(array_unique($ids)) !== count($ids)
            || array_filter($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1 || $id > 99999999) !== []) {
            throw new \InvalidArgumentException('Invalid VDEF action.');
        }
        $this->transaction($actorId, function () use ($action, $ids, $titleFormat, $revisions): void {
            foreach ($ids as $id) {
                $state = $this->lockState($id);
                $this->assertRevision($state, is_string($revisions[$id] ?? null) ? $revisions[$id] : '');
                $vdef = ['name' => $state['name']];
                if ($action === 'delete') {
                    $usage = $this->database->fetchFirstColumn('SELECT id FROM graph_templates_item WHERE vdef_id = ?' . $this->forUpdate(), [$id]);
                    // Preserve the PHP integer identity used by legacy preview/runtime.
                    $marks = implode(',', array_fill(0, count($ids), '?'));
                    $references = $this->database->fetchFirstColumn('SELECT value FROM vdef_items WHERE type = 5 AND vdef_id NOT IN (' . $marks . ')' . $this->forUpdate(), $ids);
                    $nestedUsage = array_filter($references, static fn(mixed $value): bool => (int) $value === $id);
                    if ($usage !== [] || $nestedUsage !== []) {
                        throw new \InvalidArgumentException('VDEFs in use cannot be deleted.');
                    }
                    $this->database->executeStatement('DELETE FROM vdef_items WHERE vdef_id = ?', [$id]);
                    $this->database->executeStatement('DELETE FROM vdef WHERE id = ?', [$id]);
                    continue;
                }
                $name = str_replace('<vdef_title>', (string) $vdef['name'], $titleFormat);
                $this->validateName($name);
                $this->database->insert('vdef', ['hash' => bin2hex(random_bytes(16)), 'name' => $name]);
                $copyId = (int) $this->database->lastInsertId();
                $items = $this->database->fetchAllAssociative('SELECT sequence, type, value FROM vdef_items WHERE vdef_id = ? ORDER BY sequence, id', [$id]);
                foreach ($items as $item) {
                    $this->database->insert('vdef_items', ['hash' => bin2hex(random_bytes(16)), 'vdef_id' => $copyId] + $item);
                }
            }
        });
    }

    private function validateName(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name) > 255 || preg_match('/[\x00\r\n]/', $name)) {
            throw new \InvalidArgumentException('Enter a valid VDEF name.');
        }
    }

    private function validateItem(int $type, string $value): void
    {
        if ($type === 1 && !isset(VdefFunctions::FUNCTIONS[$value])) {
            throw new \InvalidArgumentException('Choose a valid VDEF function.');
        }
        if ($type === 4 && !isset(VdefFunctions::DATA_SOURCES[$value])) {
            throw new \InvalidArgumentException('Choose a valid special data source.');
        }
        if ($type === 6 && (mb_strlen($value) > 150 || preg_match('/[\x00\r\n]/', $value))) {
            throw new \InvalidArgumentException('Enter a valid custom VDEF value.');
        }
        if (!in_array($type, [1, 4, 6], true)) {
            throw new \InvalidArgumentException('Choose a valid VDEF item type.');
        }
    }

    /** @return array{name:string, items:list<array{id:int, sequence:int, type:int, value:string}>, revision:string} */
    private function lockState(int $id): array
    {
        $vdef = $this->database->fetchAssociative('SELECT id, name FROM vdef WHERE id = ?' . $this->forUpdate(), [$id]);
        if ($vdef === false) {
            throw new \InvalidArgumentException('VDEF not found.');
        }
        $items = $this->database->fetchAllAssociative('SELECT id, sequence, type, value FROM vdef_items WHERE vdef_id = ? ORDER BY sequence, id' . $this->forUpdate(), [$id]);
        $normalized = array_map(static fn(array $item): array => [
            'id' => (int) $item['id'], 'sequence' => (int) $item['sequence'],
            'type' => (int) $item['type'], 'value' => (string) $item['value'],
        ], $items);
        return [
            'name' => (string) $vdef['name'],
            'items' => $normalized,
            'revision' => VdefRevision::fromState((string) $vdef['name'], $normalized),
        ];
    }

    private function assertRevision(array $state, string $revision): void
    {
        if ($revision === '' || !hash_equals($state['revision'], $revision)) {
            throw new \InvalidArgumentException('The VDEF changed after this form was opened. Reload and try again.');
        }
    }

    private function transaction(int $actorId, callable $operation): mixed
    {
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException('VDEF writes require their own transaction.');
        }
        $native = $this->database->getNativeConnection();
        if ($native instanceof \PDO && $native->inTransaction()) {
            throw new \RuntimeException('VDEF writes require their own transaction.');
        }
        if ($this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
                throw new \RuntimeException('VDEF writes require the primary collector.');
            }
            foreach (['vdef', 'vdef_items', 'graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
                // Inspect the table this connection uses, including temporary
                // tables that shadow otherwise transactional persistent tables.
                $definition = $this->database->fetchNumeric('SHOW CREATE TABLE ' . $this->database->quoteIdentifier($table));
                if ($definition === false || !preg_match('/\n\) ENGINE=InnoDB\b/i', (string) $definition[1])) {
                    throw new \RuntimeException('VDEF writes require transactional tables.');
                }
            }
            $this->database->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->database->beginTransaction();
        try {
            $this->assertCanMutate($actorId);
            $result = $operation();
            $this->database->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function assertCanMutate(int $actorId): void
    {
        if ($actorId < 1) {
            throw new VdefAccessDenied();
        }
        $lock = $this->forUpdate();
        $authMethod = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'auth_method'" . $lock);
        $user = $this->database->fetchAssociative('SELECT id, username, enabled, locked, must_change_password, password_change FROM user_auth WHERE id = ?' . $lock, [$actorId]);
        $guest = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'guest_user'" . $lock);
        if ($user === false || $user['enabled'] !== 'on' || $user['locked'] === 'on'
            || $user['must_change_password'] === 'on'
            || ($authMethod !== false && !in_array((int) $authMethod, [1, 2, 3, 4], true))
            || $actorId === (int) $guest || $user['username'] === $guest
            || !$this->hasRealm($actorId, 8) || !$this->hasRealm($actorId, 14)) {
            throw new VdefAccessDenied();
        }
    }

    private function hasRealm(int $actorId, int $realm): bool
    {
        $lock = $this->forUpdate();
        if ($this->database->fetchOne('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $lock, [$actorId, $realm]) !== false) {
            return true;
        }
        return $this->database->fetchOne("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE m.user_id = ? AND r.realm_id = ? AND g.enabled = 'on' LIMIT 1" . $lock, [$actorId, $realm]) !== false;
    }

    private function forUpdate(): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
    }
}
