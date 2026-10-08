<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Application\Port\CdefStore;
use Kadupul\GraphDefinition\Domain\Cdef;
use Kadupul\GraphDefinition\Domain\CdefFilters;
use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Kadupul\GraphDefinition\Domain\CdefItem;
use Kadupul\GraphDefinition\Domain\CdefPage;
use Kadupul\GraphDefinition\Domain\CdefRevision;
use Kadupul\GraphDefinition\Domain\CdefSummary;
use Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefDeletion;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\CdefReferenceReadiness;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(CdefStore::class)]
final readonly class DbalCdefStore implements CdefStore
{
    private const string STALE = 'The CDEF changed since you opened this form. Reload before saving.';
    // Legacy counts distinct graph/template uses, not graph items.
    private const string USAGE = 'SELECT c.id, c.name,
        (SELECT COUNT(*) FROM (SELECT DISTINCT cdef_id, graph_template_id, local_graph_id FROM graph_templates_item WHERE local_graph_id > 0) g WHERE g.cdef_id = c.id) AS graphs,
        (SELECT COUNT(*) FROM (SELECT DISTINCT cdef_id, graph_template_id, local_graph_id FROM graph_templates_item WHERE local_graph_id = 0) t WHERE t.cdef_id = c.id) AS templates
        FROM cdef c';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private CdefAccess $access,
        private AuditTrail $audit,
        private LegacyConfiguration $configuration,
        private CdefReferenceReadiness $readiness,
    ) {}

    public function defaultRows(): int
    {
        $rows = filter_var($this->database->fetchOne("SELECT value FROM settings WHERE name = 'num_rows_table'"), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }

    public function defaultHasGraphs(): bool
    {
        return $this->database->fetchOne("SELECT value FROM settings WHERE name = 'default_has'") === 'on';
    }

    public function list(CdefFilters $filters): CdefPage
    {
        $where = ' WHERE c.`system` = 0';
        $params = [];
        if ($filters->filter !== '') {
            $where .= " AND c.name LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($filters->filter, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($filters->hasGraphs) {
            $where .= ' AND EXISTS (SELECT 1 FROM graph_templates_item gi WHERE gi.cdef_id = c.id AND gi.local_graph_id > 0)';
        }
        $sort = ['name' => 'c.name', 'graphs' => 'graphs', 'templates' => 'templates'][$filters->sortColumn];
        $total = (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef c' . $where, $params);
        $rows = $this->database->fetchAllAssociative(
            self::USAGE . $where . ' ORDER BY ' . $sort . ' ' . $filters->sortDirection . ', c.id ASC LIMIT ? OFFSET ?',
            [...$params, $filters->rows, ($filters->page - 1) * $filters->rows],
            [...array_fill(0, count($params), ParameterType::STRING), ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return new CdefPage($this->summaries($rows, ''), $total, $filters);
    }

    public function find(int $id): ?Cdef
    {
        $parent = $this->database->fetchAssociative('SELECT id, hash, `system`, name FROM cdef WHERE id = ? AND `system` = 0', [$id]);
        if ($parent === false) {
            return null;
        }
        $rows = $this->items($id, '');
        $version = $this->rrdtoolVersion();
        $items = [];
        foreach ($rows as $row) {
            $type = (int) $row['type'];
            $value = (string) $row['value'];
            $label = match ($type) {
                CdefFunctions::SPECIAL_DATA_SOURCE => CdefFunctions::DATA_SOURCES[$value] ?? $value,
                CdefFunctions::CDEF => (string) ($this->database->fetchOne('SELECT name FROM cdef WHERE id = ?', [(int) $value]) ?: $value),
                default => CdefFunctions::rpn($type, $value, $version),
            };
            $items[] = new CdefItem((int) $row['id'], (int) $row['sequence'], $type, $value, $label);
        }
        return new Cdef($id, (string) $parent['name'], CdefRevision::of($parent, $rows), $items, $this->preview($id, [], $version));
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $parents = $this->database->fetchAllAssociative("SELECT id, hash, `system`, name FROM cdef WHERE `system` = 0 AND id IN ($marks) ORDER BY id", $ids);
        $summaries = $this->summaries($this->database->fetchAllAssociative(self::USAGE . " WHERE c.`system` = 0 AND c.id IN ($marks) ORDER BY c.id", $ids), '', $ids);
        $result = [];
        foreach ($parents as $index => $parent) {
            $id = (int) $parent['id'];
            $result[$id] = ['summary' => $summaries[$index], 'revision' => CdefRevision::of($parent, $this->items($id, ''))];
        }
        return $result;
    }

    public function functions(): array
    {
        return CdefFunctions::functions($this->rrdtoolVersion());
    }

    public function references(int $excludeId): array
    {
        $choices = [];
        foreach ($this->database->fetchAllAssociative('SELECT id, name FROM cdef WHERE `system` = 0 AND id <> ? ORDER BY name, id', [$excludeId]) as $row) {
            $choices[(string) $row['id']] = (string) $row['name'];
        }
        return $choices;
    }

    public function save(int $actorId, ?int $id, string $name, ?string $revision): int
    {
        self::validateName($name);
        return $this->write($actorId, $id === null ? 'create' : 'edit', function () use ($id, $name, $revision): int {
            if ($id === null) {
                // A 32-digit hex hash, the shape get_hash_cdef() gives templates and exports.
                $this->database->insert('cdef', ['hash' => bin2hex(random_bytes(16)), '`system`' => 0, 'name' => $name]);
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('CDEF creation was not confirmed.');
                }
                return $created;
            }
            $this->lockRevision($id, $revision);
            $this->database->update('cdef', ['name' => $name], ['id' => $id]);
            return $id;
        });
    }

    public function saveItem(int $actorId, int $cdefId, ?int $itemId, int $type, string $value, string $revision): void
    {
        if (!isset(CdefFunctions::TYPES[$type])) {
            throw new \InvalidArgumentException('Choose a valid CDEF item type.');
        }
        $this->write($actorId, 'item', function () use ($cdefId, $itemId, $type, $value, $revision): void {
            if ($type === CdefFunctions::CDEF) {
                // Two writers adding edges between disjoint CDEFs can still close a
                // cycle together, so every reference write takes all parent locks.
                $this->database->fetchFirstColumn('SELECT id FROM cdef ORDER BY id' . $this->forUpdate());
            }
            $items = $this->lockRevision($cdefId, $revision);
            $this->validateItem($cdefId, $type, $value);
            if ($itemId === null) {
                $sequence = 1 + max([0, ...array_map(static fn(array $item): int => (int) $item['sequence'], $items)]);
                $this->database->insert('cdef_items', ['hash' => bin2hex(random_bytes(16)), 'cdef_id' => $cdefId, 'sequence' => $sequence, 'type' => $type, 'value' => $value]);
                return;
            }
            if (!in_array($itemId, array_map(static fn(array $item): int => (int) $item['id'], $items), true)) {
                throw new \InvalidArgumentException('CDEF item not found.');
            }
            $this->database->update('cdef_items', ['type' => $type, 'value' => $value], ['id' => $itemId, 'cdef_id' => $cdefId]);
        });
    }

    public function deleteItem(int $actorId, int $cdefId, int $itemId, string $revision): void
    {
        $this->write($actorId, 'item', function () use ($cdefId, $itemId, $revision): void {
            $this->lockRevision($cdefId, $revision);
            if ($this->database->executeStatement('DELETE FROM cdef_items WHERE id = ? AND cdef_id = ?', [$itemId, $cdefId]) !== 1) {
                throw new \InvalidArgumentException('CDEF item not found.');
            }
        });
    }

    public function moveItem(int $actorId, int $cdefId, int $itemId, int $offset, string $revision): void
    {
        if (!in_array($offset, [-1, 1], true)) {
            throw new \InvalidArgumentException('Invalid CDEF item order.');
        }
        $this->write($actorId, 'item', function () use ($cdefId, $itemId, $offset, $revision): void {
            $order = array_map(static fn(array $item): int => (int) $item['id'], $this->lockRevision($cdefId, $revision));
            $position = array_search($itemId, $order, true);
            if (!is_int($position) || !isset($order[$position + $offset])) {
                throw new \InvalidArgumentException('Invalid CDEF item order.');
            }
            [$order[$position], $order[$position + $offset]] = [$order[$position + $offset], $order[$position]];
            foreach ($order as $index => $id) {
                $this->database->update('cdef_items', ['sequence' => $index + 1], ['id' => $id, 'cdef_id' => $cdefId]);
            }
        });
    }

    public function duplicate(int $actorId, array $ids, array $revisions, string $titleFormat): void
    {
        self::validateSelection($ids, $revisions);
        if (mb_strlen($titleFormat) > 255 || preg_match('/[\x00\r\n]/', $titleFormat) === 1 || preg_match('//u', $titleFormat) !== 1) {
            throw new \InvalidArgumentException('Enter a valid title format.');
        }
        $this->write($actorId, 'duplicate', function () use ($ids, $revisions, $titleFormat): void {
            foreach ($ids as $id) {
                $items = $this->lockRevision($id, $revisions[$id]);
                $name = str_replace('<cdef_title>', (string) $this->database->fetchOne('SELECT name FROM cdef WHERE id = ?', [$id]), $titleFormat);
                self::validateName($name);
                $this->database->insert('cdef', ['hash' => bin2hex(random_bytes(16)), '`system`' => 0, 'name' => $name]);
                $copy = (int) $this->database->lastInsertId();
                foreach ($items as $item) {
                    $this->database->insert('cdef_items', ['hash' => bin2hex(random_bytes(16)), 'cdef_id' => $copy, 'sequence' => (int) $item['sequence'], 'type' => (int) $item['type'], 'value' => (string) $item['value']]);
                }
            }
        });
    }

    public function delete(int $actorId, array $ids, array $revisions): void
    {
        self::validateSelection($ids, $revisions);
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $this->prepareWrite();
            $native = $this->database->getNativeConnection();
            if (!$native instanceof \PDO) {
                throw new \RuntimeException('CDEF deletion requires a PDO connection.');
            }
            $mysql = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform;
            $collector = $this->configuration->values()['collector_id'] ?? null;
            // The reference contract owns the transaction, the parent locks and the
            // final dependency check; this store only admits the actor and revisions.
            $deletion = new LegacyCdefDeletion($native, $collector, $mysql ? $this->readiness : null);
            $deletion->delete($ids, function (array $locked) use ($actorId, $ids, $revisions, &$decision, &$outcome): void {
                $this->access->assertCurrent($actorId);
                $decision = AuditEvent::ALLOWED;
                $outcome = AuditEvent::FAILED;
                if ($locked !== $ids) {
                    throw new \InvalidArgumentException('Invalid CDEF selection.');
                }
                foreach ($ids as $id) {
                    $this->lockRevision($id, $revisions[$id]);
                }
                $marks = implode(',', array_fill(0, count($ids), '?'));
                foreach ($this->summaries($this->database->fetchAllAssociative(self::USAGE . " WHERE c.id IN ($marks) ORDER BY c.id", $ids), $this->forUpdate(), $ids) as $summary) {
                    if (!$summary->isDeletable()) {
                        throw new \InvalidArgumentException('CDEFs in use by a graph, template, aggregate or another CDEF cannot be deleted.');
                    }
                }
            });
            $outcome = AuditEvent::SUCCEEDED;
        } finally {
            $this->record($actorId, 'delete', $decision, $outcome);
        }
    }

    /** @return list<array<string, mixed>> */
    private function items(int $cdefId, string $lock): array
    {
        return $this->database->fetchAllAssociative('SELECT id, hash, sequence, type, value FROM cdef_items WHERE cdef_id = ? ORDER BY sequence, id' . $lock, [$cdefId]);
    }

    /**
     * Nested and aggregate references, which the usage query does not count.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int> $selected IDs being deleted together, whose mutual references do not count
     * @return list<CdefSummary>
     */
    private function summaries(array $rows, string $lock, array $selected = []): array
    {
        $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $referencing = [];
        // Values are canonical decimal IDs under the reference contract; compare as text.
        foreach ($this->database->fetchAllAssociative("SELECT cdef_id, value FROM cdef_items WHERE type = 5 AND value IN ($marks)" . $lock, array_map('strval', $ids)) as $row) {
            if (!in_array((int) $row['cdef_id'], $selected, true)) {
                $referencing[(int) $row['value']][(int) $row['cdef_id']] = true;
            }
        }
        $aggregates = [];
        foreach (['aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            foreach ($this->database->fetchFirstColumn("SELECT cdef_id FROM $table WHERE cdef_id IN ($marks)" . $lock, $ids) as $id) {
                $aggregates[(int) $id] = ($aggregates[(int) $id] ?? 0) + 1;
            }
        }
        if ($lock !== '') {
            // Lock the graph item range too, so a writer cannot attach a graph after this check.
            $this->database->fetchFirstColumn("SELECT cdef_id FROM graph_templates_item WHERE cdef_id IN ($marks)" . $lock, $ids);
        }
        return array_map(static fn(array $row): CdefSummary => new CdefSummary(
            (int) $row['id'],
            (string) $row['name'],
            (int) $row['graphs'],
            (int) $row['templates'],
            count($referencing[(int) $row['id']] ?? []),
            $aggregates[(int) $row['id']] ?? 0,
        ), $rows);
    }

    /** @param array<int, true> $visited */
    private function preview(int $id, array $visited, string $version): string
    {
        if (isset($visited[$id])) {
            return '[recursive CDEF]';
        }
        $visited[$id] = true;
        $parts = [];
        foreach ($this->database->fetchAllAssociative('SELECT type, value FROM cdef_items WHERE cdef_id = ? ORDER BY sequence, id', [$id]) as $item) {
            $type = (int) $item['type'];
            $parts[] = $type === CdefFunctions::CDEF
                ? $this->preview((int) $item['value'], $visited, $version)
                : CdefFunctions::rpn($type, (string) $item['value'], $version);
        }
        return implode(',', $parts);
    }

    /**
     * Lock the definition and its items, then refuse a stale form.
     *
     * @return list<array<string, mixed>> the locked items in sequence order
     */
    private function lockRevision(int $id, ?string $revision): array
    {
        $parent = $this->database->fetchAssociative('SELECT id, hash, `system`, name FROM cdef WHERE id = ?' . $this->forUpdate(), [$id]);
        if ($parent === false || (int) $parent['system'] !== 0) {
            throw new \InvalidArgumentException('CDEF not found.');
        }
        $items = $this->items($id, $this->forUpdate());
        if ($revision === null || !hash_equals(CdefRevision::of($parent, $items), $revision)) {
            throw new \InvalidArgumentException(self::STALE);
        }
        return $items;
    }

    private function validateItem(int $cdefId, int $type, string $value): void
    {
        $valid = match ($type) {
            CdefFunctions::FUNCTION => isset($this->functions()[$value]),
            CdefFunctions::OPERATOR => isset(CdefFunctions::OPERATORS[$value]),
            CdefFunctions::SPECIAL_DATA_SOURCE => isset(CdefFunctions::DATA_SOURCES[$value]),
            CdefFunctions::CDEF => preg_match('/\A[1-9][0-9]{0,7}\z/D', $value) === 1
                && $this->database->fetchOne('SELECT id FROM cdef WHERE id = ? AND `system` = 0', [(int) $value]) !== false,
            default => $value !== '' && mb_strlen($value) <= 150 && preg_match('//u', $value) === 1 && preg_match('/[\x00\r\n]/', $value) !== 1,
        };
        if (!$valid) {
            throw new \InvalidArgumentException('Enter a valid value for this CDEF item type.');
        }
        if ($type === CdefFunctions::CDEF && $this->reaches((int) $value, $cdefId)) {
            throw new \InvalidArgumentException('A CDEF cannot include itself, directly or through another CDEF.');
        }
    }

    private function reaches(int $from, int $target): bool
    {
        $edges = [];
        foreach ($this->database->fetchAllAssociative('SELECT cdef_id, value FROM cdef_items WHERE type = 5') as $row) {
            $edges[(int) $row['cdef_id']][] = (int) $row['value'];
        }
        $pending = [$from];
        $seen = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($current === $target) {
                return true;
            }
            if (!isset($seen[$current])) {
                $seen[$current] = true;
                array_push($pending, ...($edges[$current] ?? []));
            }
        }
        return false;
    }

    private static function validateName(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name) > 255 || preg_match('//u', $name) !== 1 || preg_match('/[\x00\r\n]/', $name) === 1) {
            throw new \InvalidArgumentException('Enter a CDEF name of at most 255 characters.');
        }
    }

    /**
     * @param list<int> $ids
     * @param array<int, string> $revisions
     */
    private static function validateSelection(array $ids, array $revisions): void
    {
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        if ($ids === [] || count($ids) > self::MAX_SELECTION || !array_is_list($ids) || $sorted !== $ids
            || count(array_unique($ids)) !== count($ids)
            || array_filter($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1 || $id > 16777215) !== []) {
            throw new \InvalidArgumentException('Invalid CDEF selection.');
        }
        $keys = array_keys($revisions);
        sort($keys, SORT_NUMERIC);
        if ($keys !== $ids || array_filter($revisions, static fn(mixed $revision): bool => !is_string($revision)) !== []) {
            throw new \InvalidArgumentException(self::STALE);
        }
    }

    private function write(int $actorId, string $action, callable $operation): mixed
    {
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $this->prepareWrite();
            $this->database->beginTransaction();
            try {
                $this->access->assertCurrent($actorId);
                $decision = AuditEvent::ALLOWED;
                $outcome = AuditEvent::FAILED;
                $result = $operation();
                $this->database->commit();
            } catch (\Throwable $error) {
                if ($this->database->isTransactionActive()) {
                    $this->database->rollBack();
                }
                throw $error;
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } finally {
            $this->record($actorId, $action, $decision, $outcome);
        }
    }

    private function prepareWrite(): void
    {
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException('CDEF writes require their own transaction.');
        }
        if (!$this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('CDEFs must be changed on the primary collector.');
        }
        foreach (['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item',
            'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            // SHOW CREATE TABLE sees a temporary table that shadows the real one.
            $definition = $this->database->fetchNumeric('SHOW CREATE TABLE ' . $this->database->quoteIdentifier($table));
            if ($definition === false || !is_string($definition[1] ?? null) || preg_match('/\n\) ENGINE=InnoDB\b/i', $definition[1]) !== 1) {
                throw new \RuntimeException('CDEF writes require transactional tables.');
            }
        }
        // Keep the dependency range locks when the session default is READ COMMITTED.
        $this->database->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    private function record(int $actorId, string $action, string $decision, string $outcome): void
    {
        try {
            $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'graph_definition.cdef.' . $action, 'cdef', 'cdef', $decision, $outcome));
        } catch (\Throwable) { /* Audit failure cannot change a confirmed persistence result. */
        }
    }

    private function forUpdate(): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
    }

    private function rrdtoolVersion(): string
    {
        $version = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'rrdtool_version'");
        return is_string($version) ? $version : '';
    }
}
