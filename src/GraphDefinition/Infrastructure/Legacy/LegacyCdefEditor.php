<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\GraphDefinition\Application\Port\CdefEditor;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Kadupul\Platform\Contract\LegacyConfiguration;

final readonly class LegacyCdefEditor implements CdefEditor
{
    public function __construct(private Connection $database, private LegacyConfiguration $configuration) {}

    public function save(int $actorId, int $id, string $name): int
    {
        if ($id < 0 || trim($name) === '' || mb_strlen($name) > 255 || preg_match('/[\x00\r\n]/', $name)) {
            throw new \InvalidArgumentException('Enter a valid CDEF name.');
        }
        return $this->transaction($actorId, function () use ($id, $name): int {
            if ($id > 0) {
                $this->lockCdef($id);
                $this->database->update('cdef', ['name' => $name], ['id' => $id]);
                return $id;
            }
            $this->database->insert('cdef', ['hash' => bin2hex(random_bytes(16)), 'system' => 0, 'name' => $name]);
            return (int) $this->database->lastInsertId();
        });
    }

    public function saveItem(int $actorId, int $cdefId, int $itemId, int $type, string $value): void
    {
        if ($cdefId < 1 || $itemId < 0 || mb_strlen($value) > 150 || preg_match('/[\x00\r\n]/', $value)) {
            throw new \InvalidArgumentException('Invalid CDEF item selection or value.');
        }
        $this->transaction($actorId, function () use ($cdefId, $itemId, $type, $value): void {
            $lockIds = [$cdefId];
            if ($type === 5 && preg_match('/^[1-9][0-9]{0,7}$/D', $value)) {
                $lockIds[] = (int) $value;
            }
            $lockIds = array_values(array_unique($lockIds));
            sort($lockIds, SORT_NUMERIC);
            foreach ($lockIds as $lockId) {
                $this->lockCdef($lockId);
            }
            $this->validateItem($type, $value, $cdefId);
            if ($itemId > 0) {
                if ($this->database->fetchOne('SELECT id FROM cdef_items WHERE id = ? AND cdef_id = ?' . $this->forUpdate(), [$itemId, $cdefId]) === false) {
                    throw new \InvalidArgumentException('CDEF item not found.');
                }
                $this->database->update('cdef_items', ['type' => $type, 'value' => $value], ['id' => $itemId, 'cdef_id' => $cdefId]);
                return;
            }
            $sequence = (int) $this->database->fetchOne('SELECT COALESCE(MAX(sequence), 0) + 1 FROM cdef_items WHERE cdef_id = ?', [$cdefId]);
            $this->database->insert('cdef_items', [
                'hash' => bin2hex(random_bytes(16)), 'cdef_id' => $cdefId, 'sequence' => $sequence, 'type' => $type, 'value' => $value,
            ]);
        });
    }

    public function deleteItem(int $actorId, int $cdefId, int $itemId): void
    {
        if ($cdefId < 1 || $itemId < 1) {
            throw new \InvalidArgumentException('Invalid CDEF item selection.');
        }
        $this->transaction($actorId, function () use ($cdefId, $itemId): void {
            $this->lockCdef($cdefId);
            if ($this->database->fetchOne('SELECT id FROM cdef_items WHERE id = ? AND cdef_id = ?' . $this->forUpdate(), [$itemId, $cdefId]) === false) {
                throw new \InvalidArgumentException('CDEF item not found.');
            }
            $this->database->delete('cdef_items', ['id' => $itemId, 'cdef_id' => $cdefId]);
            $this->normalizeSequence($cdefId);
        });
    }

    public function reorder(int $actorId, int $cdefId, array $orderedItemIds, array $expectedItemIds): void
    {
        if ($cdefId < 1 || count($orderedItemIds) > 500 || count($expectedItemIds) > 500
            || array_filter($orderedItemIds, static fn(mixed $id): bool => !is_int($id) || $id < 1 || $id > 16777215) !== []
            || array_filter($expectedItemIds, static fn(mixed $id): bool => !is_int($id) || $id < 1 || $id > 16777215) !== []) {
            throw new \InvalidArgumentException('The CDEF item order is invalid.');
        }
        $this->transaction($actorId, function () use ($cdefId, $orderedItemIds, $expectedItemIds): void {
            $this->lockCdef($cdefId);
            $rows = $this->database->fetchFirstColumn('SELECT id FROM cdef_items WHERE cdef_id = ? ORDER BY sequence, id' . $this->forUpdate(), [$cdefId]);
            $expected = array_map('intval', $rows);
            if ($expected !== $expectedItemIds) {
                throw new \InvalidArgumentException('The CDEF item selection changed. Reload the form.');
            }
            $actual = array_values(array_unique($orderedItemIds));
            $sortedExpected = $expected;
            $sortedActual = $actual;
            sort($sortedExpected);
            sort($sortedActual);
            if ($sortedExpected !== $sortedActual || count($actual) !== count($orderedItemIds)) {
                throw new \InvalidArgumentException('The CDEF item order is stale or invalid.');
            }
            foreach ($actual as $index => $itemId) {
                $this->database->update('cdef_items', ['sequence' => $index + 1], ['id' => $itemId, 'cdef_id' => $cdefId]);
            }
        });
    }

    public function act(int $actorId, string $action, array $ids, string $titleFormat = '<cdef_title> (1)'): void
    {
        if (!in_array($action, ['delete', 'duplicate'], true) || $ids === [] || count($ids) > 500
            || count(array_unique($ids)) !== count($ids)
            || array_filter($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1 || $id > 16777215) !== []) {
            throw new \InvalidArgumentException('Invalid CDEF action.');
        }
        if (mb_strlen($titleFormat) > 255 || preg_match('/[\x00\r\n]/', $titleFormat)) {
            throw new \InvalidArgumentException('Enter a valid CDEF duplicate title format.');
        }
        $this->transaction($actorId, function () use ($action, $ids, $titleFormat): void {
            $lockIds = $ids;
            if ($action === 'duplicate') {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                foreach ($this->database->fetchFirstColumn("SELECT value FROM cdef_items WHERE type = 5 AND cdef_id IN ($marks)", $ids) as $reference) {
                    if (is_string($reference) && preg_match('/^[1-9][0-9]{0,7}$/D', $reference)) {
                        $lockIds[] = (int) $reference;
                    }
                }
            }
            $lockIds = array_values(array_unique($lockIds));
            sort($lockIds, SORT_NUMERIC);
            foreach ($lockIds as $id) {
                $this->lockCdef($id);
            }
            if ($action === 'delete') {
                $inside = implode(',', array_fill(0, count($ids), '?'));
                $graphUsage = $this->database->fetchFirstColumn("SELECT id FROM graph_templates_item WHERE cdef_id IN ($inside)" . $this->forUpdate(), $ids);
                if ($graphUsage !== []) {
                    throw new \InvalidArgumentException('CDEFs in use by graphs or graph templates cannot be deleted.');
                }
                $notSelected = implode(',', array_fill(0, count($ids), '?'));
                $referenceValue = 'CAST(value AS ' . ($this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'UNSIGNED' : 'INTEGER') . ')';
                $references = $this->database->fetchFirstColumn("SELECT id FROM cdef_items WHERE type = 5 AND $referenceValue IN ($inside) AND cdef_id NOT IN ($notSelected)" . $this->forUpdate(), [...$ids, ...$ids]);
                if ($references !== []) {
                    throw new \InvalidArgumentException('CDEFs referenced by another CDEF cannot be deleted.');
                }
                $this->database->executeStatement("DELETE FROM cdef_items WHERE cdef_id IN ($inside)", $ids);
                $this->database->executeStatement("DELETE FROM cdef WHERE id IN ($inside) AND `system` = 0", $ids);
                return;
            }

            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->database->fetchFirstColumn("SELECT value FROM cdef_items WHERE type = 5 AND cdef_id IN ($marks)", $ids) as $reference) {
                if (is_string($reference) && preg_match('/^[1-9][0-9]{0,7}$/D', $reference) && !in_array((int) $reference, $lockIds, true)) {
                    throw new \InvalidArgumentException('CDEF changed during duplication. Reload the list and try again.');
                }
            }

            foreach ($ids as $id) {
                $source = $this->database->fetchAssociative('SELECT name FROM cdef WHERE id = ? AND `system` = 0', [$id]);
                if ($source === false) {
                    throw new \InvalidArgumentException('CDEF not found.');
                }
                $name = mb_substr(str_replace('<cdef_title>', (string) $source['name'], $titleFormat), 0, 255);
                $this->database->insert('cdef', ['hash' => bin2hex(random_bytes(16)), 'system' => 0, 'name' => $name]);
                $copyId = (int) $this->database->lastInsertId();
                $items = $this->database->fetchAllAssociative('SELECT sequence, type, value FROM cdef_items WHERE cdef_id = ? ORDER BY sequence, id', [$id]);
                foreach ($items as $item) {
                    $this->database->insert('cdef_items', ['hash' => bin2hex(random_bytes(16)), 'cdef_id' => $copyId] + $item);
                }
            }
        });
    }

    private function validateItem(int $type, string $value, int $cdefId): void
    {
        if (!isset(CdefFunctions::TYPES[$type])) {
            throw new \InvalidArgumentException('Choose a valid CDEF item type.');
        }
        if ($type === 1 && !isset(CdefFunctions::functions($this->roundSupported())[$value])) {
            throw new \InvalidArgumentException('Choose a valid CDEF function.');
        }
        if ($type === 2 && !isset(CdefFunctions::OPERATORS[$value])) {
            throw new \InvalidArgumentException('Choose a valid CDEF operator.');
        }
        if ($type === 4 && !isset(CdefFunctions::DATA_SOURCES[$value])) {
            throw new \InvalidArgumentException('Choose a valid special data source.');
        }
        if ($type === 5) {
            if (!preg_match('/^[1-9][0-9]{0,7}$/D', $value) || (int) $value === $cdefId
                || $this->database->fetchOne('SELECT id FROM cdef WHERE id = ? AND `system` = 0', [(int) $value]) === false
                || $this->referencesCdef((int) $value, $cdefId)) {
                throw new \InvalidArgumentException('Choose a valid CDEF that does not create a reference cycle.');
            }
        }
    }

    private function referencesCdef(int $fromCdefId, int $targetCdefId, array $visited = [], int $depth = 0): bool
    {
        if ($depth > 128) {
            return true;
        }
        if (isset($visited[$fromCdefId])) {
            return false;
        }
        $visited[$fromCdefId] = true;
        $references = $this->database->fetchFirstColumn("SELECT value FROM cdef_items WHERE cdef_id = ? AND type = 5", [$fromCdefId]);
        foreach ($references as $reference) {
            $next = (int) $reference;
            if ($next === $targetCdefId || $this->referencesCdef($next, $targetCdefId, $visited, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    private function lockCdef(int $id): void
    {
        if ($this->database->fetchOne('SELECT id FROM cdef WHERE id = ? AND `system` = 0' . $this->forUpdate(), [$id]) === false) {
            throw new \InvalidArgumentException('CDEF not found.');
        }
    }

    private function normalizeSequence(int $cdefId): void
    {
        $ids = $this->database->fetchFirstColumn('SELECT id FROM cdef_items WHERE cdef_id = ? ORDER BY sequence, id', [$cdefId]);
        foreach ($ids as $index => $id) {
            $this->database->update('cdef_items', ['sequence' => $index + 1], ['id' => (int) $id, 'cdef_id' => $cdefId]);
        }
    }

    private function transaction(int $actorId, callable $operation): mixed
    {
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException('CDEF mutations cannot join an existing transaction.');
        }
        $native = $this->database->getNativeConnection();
        if ($native instanceof \PDO && $native->inTransaction()) {
            throw new \RuntimeException('CDEF mutations cannot join an existing transaction.');
        }
        if ($this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
                throw new \RuntimeException('CDEF mutations require the primary collector.');
            }
            foreach (['cdef', 'cdef_items', 'graph_templates_item', 'settings', 'user_auth', 'user_auth_realm',
                'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'] as $table) {
                // SHOW CREATE checks the table this connection actually uses,
                // including temporary fixtures that may shadow persistent tables.
                $definition = $this->database->fetchNumeric('SHOW CREATE TABLE ' . $this->database->quoteIdentifier($table));
                if ($definition === false || !preg_match('/\n\) ENGINE=InnoDB\b/i', (string) $definition[1])) {
                    throw new \RuntimeException('CDEF mutations require InnoDB tables.');
                }
            }
            // Dependency range locks must remain effective when the session's
            // default isolation was changed by another database user.
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
            throw new CdefAccessDenied();
        }
        $lock = $this->forUpdate();
        // This shared policy row serializes every reference-graph mutation,
        // including writers with different actors and disjoint endpoints.
        $authMethod = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'auth_method'" . $lock);
        $user = $this->database->fetchAssociative('SELECT id, username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $lock, [$actorId]);
        $guest = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'guest_user'" . $lock);
        if ($user === false || $user['enabled'] !== 'on' || $user['locked'] === 'on' || $user['must_change_password'] === 'on'
            || ($authMethod === false || !in_array((int) $authMethod, [1, 2, 3, 4], true))
            || $actorId === (int) $guest || $user['username'] === $guest
            || !$this->hasRealm($actorId, 8) || !$this->hasRealm($actorId, 14)) {
            throw new CdefAccessDenied();
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

    private function roundSupported(): bool
    {
        $version = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'rrdtool_version'");
        $version = is_string($version) ? str_replace(['rrd-', '.x'], ['', '.0'], $version) : '1.4.0';
        return version_compare($version, '1.8.0', '>=');
    }
}
