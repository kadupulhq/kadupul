<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\ColorTemplateStore;
use Kadupul\Graphing\Domain\ColorTemplate;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\Graphing\Domain\ColorTemplateItem;
use Kadupul\Graphing\Domain\ColorTemplatePage;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ColorTemplateStore::class)]
final readonly class LegacyColorTemplateStore implements ColorTemplateStore
{
    public const string CHANGED = 'The selected Color Templates changed. Reload before continuing.';
    public const string LAST_COLOR = 'A Color Template used by an aggregate graph or aggregate template must keep at least one color.';

    public function __construct(private DatabaseConnection $database, private ColorTemplateAccess $access, private AuditTrail $audit, private LegacyConfiguration $configuration) {}

    public function defaultRows(): int
    {
        $rows = filter_var(ColorTemplateSql::column(ColorTemplateSql::execute($this->database->get(), "SELECT value FROM settings WHERE name = 'num_rows_table'")), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }

    public function defaultHasGraphs(): bool
    {
        return ColorTemplateSql::column(ColorTemplateSql::execute($this->database->get(), "SELECT value FROM settings WHERE name = 'default_has'")) === 'on';
    }

    public function list(ColorTemplateFilters $filters): ColorTemplatePage
    {
        $parts = [];
        $params = [];
        if ($filters->filter !== '') {
            $parts[] = 'listed.name LIKE ?';
            $params[] = '%' . $filters->filter . '%';
        }
        if ($filters->hasGraphs) {
            $parts[] = '(listed.graphs > 0 OR listed.templates > 0)';
        }
        $where = $parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts);
        $sort = match ($filters->sortColumn) {
            'graphs' => 'listed.graphs', 'templates' => 'listed.templates', default => 'listed.name',
        };
        $db = $this->database->get();
        $count = ColorTemplateSql::execute($db, 'SELECT COUNT(*) FROM (' . $this->query() . ') listed' . $where, $params);
        $query = ColorTemplateSql::execute($db, 'SELECT * FROM (' . $this->query() . ') listed' . $where . ' ORDER BY ' . $sort . ' ' . $filters->sortDirection . ', listed.color_template_id ASC LIMIT ? OFFSET ?', [...$params, $filters->rows, ($filters->page - 1) * $filters->rows]);
        return new ColorTemplatePage(array_map($this->hydrate(...), ColorTemplateSql::all($query)), (int) ColorTemplateSql::column($count), $filters);
    }

    public function find(int $id): ?ColorTemplate
    {
        $row = ColorTemplateSql::one(ColorTemplateSql::execute($this->database->get(), $this->query() . ' WHERE ct.color_template_id = ?', [$id]));
        return $row ? $this->hydrate($row) : null;
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $query = ColorTemplateSql::execute($this->database->get(), $this->query() . ' WHERE ct.color_template_id IN (' . $this->placeholders($ids) . ') ORDER BY ct.color_template_id', $ids);
        return array_map($this->hydrate(...), ColorTemplateSql::all($query));
    }

    public function items(int $templateId): array
    {
        $query = ColorTemplateSql::execute($this->database->get(), 'SELECT i.color_template_item_id, i.color_template_id, i.color_id, i.sequence, c.hex, c.name
            FROM color_template_items i LEFT JOIN colors c ON c.id = i.color_id
            WHERE i.color_template_id = ? ORDER BY i.sequence, i.color_template_item_id', [$templateId]);
        return array_map(static fn(array $row): ColorTemplateItem => new ColorTemplateItem((int) $row['color_template_item_id'], (int) $row['color_template_id'], (int) $row['color_id'], (int) $row['sequence'], (string) ($row['hex'] ?? ''), (string) ($row['name'] ?? '')), ColorTemplateSql::all($query));
    }

    public function colors(): array
    {
        // Hex order, as the legacy color drop-down listed them.
        $query = ColorTemplateSql::execute($this->database->get(), 'SELECT id, name, hex FROM colors ORDER BY hex, id');
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'hex' => (string) $row['hex']], ColorTemplateSql::all($query));
    }

    public function selectionRevisions(array $ids): array
    {
        return self::revisions($this->database->get(), $this->selection($ids), false);
    }

    public function save(int $actorId, ?int $id, string $name, ?string $revision): int
    {
        ColorTemplate::validateName($name);
        return $this->write($actorId, $id === null ? 'create' : 'edit', $id === null ? 'new' : (string) $id, function (\PDO $db) use ($id, $name, $revision): int {
            if ($id === null) {
                ColorTemplateSql::execute($db, 'INSERT INTO color_templates (name) VALUES (?)', [$name]);
                $newId = (int) $db->lastInsertId();
                if ($newId < 1) {
                    throw new \RuntimeException('Color Template creation was not confirmed.');
                }
                return $newId;
            }
            $row = ColorTemplateSql::one(ColorTemplateSql::execute($db, 'SELECT color_template_id, name FROM color_templates WHERE color_template_id = ?' . $this->lock(), [$id]));
            if (!$row) {
                throw new \InvalidArgumentException('Color Template not found.');
            }
            if ($revision === null || !hash_equals((new ColorTemplate($id, (string) $row['name']))->revision, $revision)) {
                throw new \InvalidArgumentException('Color Template changed since you opened this form. Reload before saving.');
            }
            ColorTemplateSql::execute($db, 'UPDATE color_templates SET name = ? WHERE color_template_id = ?', [$name, $id]);
            return $id;
        });
    }

    public function saveItem(int $actorId, int $templateId, ?int $itemId, int $colorId, ?string $revision): int
    {
        if ($colorId < 1) {
            throw new \InvalidArgumentException('Select a valid color.');
        }
        return $this->write($actorId, $itemId === null ? 'item.create' : 'item.edit', (string) $templateId, function (\PDO $db) use ($templateId, $itemId, $colorId, $revision): int {
            $this->lockTemplate($db, $templateId);
            // A shared lock keeps the palette color from being deleted under this write.
            $color = ColorTemplateSql::execute($db, 'SELECT id FROM colors WHERE id = ?' . $this->lock(true), [$colorId]);
            if (ColorTemplateSql::column($color) === false) {
                throw new \InvalidArgumentException('Select a valid color.');
            }
            if ($itemId === null) {
                $next = ColorTemplateSql::column(ColorTemplateSql::execute($db, 'SELECT COALESCE(MAX(sequence), 0) + 1 FROM color_template_items WHERE color_template_id = ?', [$templateId]));
                ColorTemplateSql::execute($db, 'INSERT INTO color_template_items (color_template_id, color_id, sequence) VALUES (?, ?, ?)', [$templateId, $colorId, (int) $next]);
                $newId = (int) $db->lastInsertId();
                if ($newId < 1) {
                    throw new \RuntimeException('Color item creation was not confirmed.');
                }
                return $newId;
            }
            $this->lockItem($db, $templateId, $itemId, $revision);
            ColorTemplateSql::execute($db, 'UPDATE color_template_items SET color_id = ? WHERE color_template_item_id = ? AND color_template_id = ?', [$colorId, $itemId, $templateId]);
            return $itemId;
        });
    }

    public function removeItem(int $actorId, int $templateId, int $itemId, string $revision): void
    {
        $this->write($actorId, 'item.delete', (string) $templateId, function (\PDO $db) use ($templateId, $itemId, $revision): void {
            $this->lockTemplate($db, $templateId);
            $this->lockItem($db, $templateId, $itemId, $revision);
            // push_out_aggregates() takes each item index modulo the color count,
            // so an empty template that aggregates still use breaks their push-out.
            $remaining = (int) ColorTemplateSql::column(ColorTemplateSql::execute($db, 'SELECT COUNT(*) FROM color_template_items WHERE color_template_id = ?', [$templateId]));
            if ($remaining === 1 && $this->referenced($db, [$templateId])) {
                throw new \InvalidArgumentException(self::LAST_COLOR);
            }
            $query = ColorTemplateSql::execute($db, 'DELETE FROM color_template_items WHERE color_template_item_id = ? AND color_template_id = ?', [$itemId, $templateId]);
            if ($query->rowCount() !== 1) {
                throw new \RuntimeException('Color item removal was not confirmed.');
            }
        });
    }

    public function reorder(int $actorId, int $templateId, array $itemIds, string $revision): void
    {
        if (!array_is_list($itemIds) || count($itemIds) > 1000 || count(array_unique($itemIds)) !== count($itemIds)
            || array_filter($itemIds, static fn(mixed $id): bool => !is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Invalid color item order.');
        }
        $this->write($actorId, 'item.reorder', (string) $templateId, function (\PDO $db) use ($templateId, $itemIds, $revision): void {
            $this->lockTemplate($db, $templateId);
            $rows = ColorTemplateSql::all(ColorTemplateSql::execute($db, 'SELECT color_template_item_id, color_template_id, color_id, sequence FROM color_template_items WHERE color_template_id = ? ORDER BY sequence, color_template_item_id' . $this->lock(), [$templateId]));
            $current = array_map(static fn(array $row): ColorTemplateItem => new ColorTemplateItem((int) $row['color_template_item_id'], $templateId, (int) $row['color_id'], (int) $row['sequence'], ''), $rows);
            $stored = array_map(static fn(ColorTemplateItem $item): int => $item->id, $current);
            $submitted = $itemIds;
            sort($stored, SORT_NUMERIC);
            sort($submitted, SORT_NUMERIC);
            if (!hash_equals(ColorTemplateItem::orderRevision($current), $revision) || $stored !== $submitted) {
                throw new \InvalidArgumentException('Color item order changed. Reload before reordering.');
            }
            foreach ($itemIds as $position => $itemId) {
                ColorTemplateSql::execute($db, 'UPDATE color_template_items SET sequence = ? WHERE color_template_item_id = ? AND color_template_id = ?', [$position + 1, $itemId, $templateId]);
            }
        });
    }

    public function delete(int $actorId, array $ids, array $revisions): void
    {
        $ids = $this->selection($ids);
        $this->write($actorId, 'delete', $this->target($ids), function (\PDO $db) use ($ids, $revisions): void {
            $this->assertRevisions($db, $ids, $revisions);
            $placeholders = $this->placeholders($ids);
            // The legacy page only disabled checkboxes; refuse referenced templates here.
            if ($this->referenced($db, $ids)) {
                throw new \InvalidArgumentException('Color Templates used by an aggregate graph or aggregate template cannot be deleted.');
            }
            ColorTemplateSql::execute($db, 'DELETE FROM color_template_items WHERE color_template_id IN (' . $placeholders . ')', $ids);
            if (ColorTemplateSql::execute($db, 'DELETE FROM color_templates WHERE color_template_id IN (' . $placeholders . ')', $ids)->rowCount() !== count($ids)) {
                throw new \RuntimeException('Color Template deletion was not confirmed.');
            }
        });
    }

    public function duplicate(int $actorId, array $ids, string $titleFormat, array $revisions): void
    {
        $ids = $this->selection($ids);
        $this->write($actorId, 'duplicate', $this->target($ids), function (\PDO $db) use ($ids, $titleFormat, $revisions): void {
            $names = $this->assertRevisions($db, $ids, $revisions);
            foreach ($ids as $id) {
                ColorTemplateSql::execute($db, 'INSERT INTO color_templates (name) VALUES (?)', [ColorTemplate::duplicateName($titleFormat, $names[$id])]);
                $copy = (int) $db->lastInsertId();
                if ($copy < 1) {
                    throw new \RuntimeException('Color Template duplication was not confirmed.');
                }
                ColorTemplateSql::execute($db, 'INSERT INTO color_template_items (color_template_id, color_id, sequence)
                    SELECT ?, color_id, sequence FROM color_template_items WHERE color_template_id = ? ORDER BY sequence, color_template_item_id', [$copy, $id]);
            }
        });
    }

    /** @return array<int, string> names of the locked selection */
    private function assertRevisions(\PDO $db, array $ids, array $expected): array
    {
        $rows = ColorTemplateSql::all(ColorTemplateSql::execute($db, 'SELECT color_template_id, name FROM color_templates WHERE color_template_id IN (' . $this->placeholders($ids) . ') ORDER BY color_template_id' . $this->lock(), $ids));
        if (count($rows) !== count($ids)) {
            throw new \InvalidArgumentException('One or more selected Color Templates no longer exist.');
        }
        foreach (self::revisions($db, $ids, true) as $id => $revision) {
            if (!is_string($expected[$id] ?? null) || !hash_equals($revision, $expected[$id])) {
                throw new \InvalidArgumentException(self::CHANGED);
            }
        }
        return array_column($rows, 'name', 'color_template_id');
    }

    /**
     * One revision per template over its name and ordered items. The
     * synchronization worker checks the same value without the container.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function revisions(\PDO $db, array $ids, bool $lock): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $suffix = $lock && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $names = array_column(ColorTemplateSql::all(ColorTemplateSql::execute($db, 'SELECT color_template_id, name FROM color_templates WHERE color_template_id IN (' . $placeholders . ') ORDER BY color_template_id' . $suffix, $ids)), 'name', 'color_template_id');
        $items = [];
        $query = ColorTemplateSql::execute($db, 'SELECT color_template_id, color_template_item_id, color_id, sequence FROM color_template_items WHERE color_template_id IN (' . $placeholders . ') ORDER BY color_template_id, sequence, color_template_item_id' . $suffix, $ids);
        foreach (ColorTemplateSql::all($query) as $row) {
            $items[(int) $row['color_template_id']][] = [(int) $row['color_template_item_id'], (int) $row['color_id'], (int) $row['sequence']];
        }
        $revisions = [];
        foreach ($names as $id => $name) {
            $revisions[(int) $id] = hash('sha256', json_encode([(int) $id, (string) $name, $items[(int) $id] ?? []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        return $revisions;
    }

    /** Lock the reference rows so a concurrent aggregate save waits for this write. */
    private function referenced(\PDO $db, array $ids): bool
    {
        foreach (['aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            $query = ColorTemplateSql::execute($db, 'SELECT color_template FROM ' . $table . ' WHERE color_template IN (' . $this->placeholders($ids) . ')' . $this->lock(), $ids);
            if (ColorTemplateSql::column($query) !== false) {
                return true;
            }
        }
        return false;
    }

    private function lockTemplate(\PDO $db, int $templateId): void
    {
        if (ColorTemplateSql::column(ColorTemplateSql::execute($db, 'SELECT color_template_id FROM color_templates WHERE color_template_id = ?' . $this->lock(), [$templateId])) === false) {
            throw new \InvalidArgumentException('Color Template not found.');
        }
    }

    private function lockItem(\PDO $db, int $templateId, int $itemId, ?string $revision): void
    {
        $row = ColorTemplateSql::one(ColorTemplateSql::execute($db, 'SELECT color_template_item_id, color_template_id, color_id, sequence FROM color_template_items WHERE color_template_item_id = ? AND color_template_id = ?' . $this->lock(), [$itemId, $templateId]));
        if (!$row) {
            throw new \InvalidArgumentException('Color item not found.');
        }
        $current = new ColorTemplateItem($itemId, $templateId, (int) $row['color_id'], (int) $row['sequence'], '');
        if ($revision === null || !hash_equals($current->revision, $revision)) {
            throw new \InvalidArgumentException('Color item changed since you opened this form. Reload before saving.');
        }
    }

    /** @return list<int> */
    private function selection(array $ids): array
    {
        if ($ids === [] || count($ids) > ColorTemplateStore::MAX_SELECTION || array_filter($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Invalid color template selection.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function target(array $ids): string
    {
        return count($ids) === 1 ? (string) $ids[0] : 'selection-' . substr(hash('sha256', implode(',', $ids)), 0, 32);
    }

    private function write(int $actorId, string $action, string $target, callable $operation): mixed
    {
        $db = $this->database->get();
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        $started = false;
        try {
            if ($db->inTransaction()) {
                throw new \RuntimeException('Color template transaction unavailable.');
            }
            $this->assertTransactional($db);
            if (!$db->beginTransaction()) {
                throw new \RuntimeException('Color template transaction unavailable.');
            }
            $started = true;
            $this->access->assertCurrent($actorId);
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $result = $operation($db);
            if (!$db->commit()) {
                throw new \RuntimeException('Color template commit was not confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } catch (\Throwable $error) {
            if ($started && $db->inTransaction()) {
                try {
                    $rolledBack = $db->rollBack();
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('Color template rollback was not confirmed.', 0, $rollbackError);
                }
                if (!$rolledBack) {
                    throw new \RuntimeException('Color template rollback was not confirmed.', 0, $error);
                }
            }
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'graphing.color_template.' . $action, 'color_template', $target, $decision, $outcome));
            } catch (\Throwable) {
                // Audit failure does not change the confirmed persistence outcome.
            }
        }
    }

    private function assertTransactional(\PDO $db): void
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Color Templates must be changed on the primary collector.');
        }
        foreach (['color_templates', 'color_template_items', 'colors', 'aggregate_graphs_graph_item', 'aggregate_graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            // Check the actual connection table, including temporary shadows.
            $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
            $definition = $query === false ? false : ColorTemplateSql::one($query, \PDO::FETCH_NUM);
            if ($definition === false || !is_string($definition[1] ?? null)
                || preg_match('/\ACREATE TABLE /i', $definition[1]) !== 1
                || preg_match('/\n\) ENGINE=InnoDB\b/i', $definition[1]) !== 1) {
                throw new \RuntimeException('Color template writes require transactional tables.');
            }
        }
        // Keep the reference range lock when the session default is READ COMMITTED.
        if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
            throw new \RuntimeException('Color template transaction isolation was not confirmed.');
        }
    }

    private function lock(bool $shared = false): string
    {
        if ($this->database->get()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return '';
        }
        return $shared ? ' LOCK IN SHARE MODE' : ' FOR UPDATE';
    }

    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    private function query(): string
    {
        // Count reference rows, as the legacy list did.
        return 'SELECT ct.color_template_id, ct.name,
            (SELECT COUNT(*) FROM aggregate_graphs_graph_item g WHERE g.color_template = ct.color_template_id) graphs,
            (SELECT COUNT(*) FROM aggregate_graph_templates_item t WHERE t.color_template = ct.color_template_id) templates,
            (SELECT COUNT(*) FROM color_template_items i WHERE i.color_template_id = ct.color_template_id) items
            FROM color_templates ct';
    }

    private function hydrate(array $row): ColorTemplate
    {
        return new ColorTemplate((int) $row['color_template_id'], (string) $row['name'], (int) ($row['graphs'] ?? 0), (int) ($row['templates'] ?? 0), (int) ($row['items'] ?? 0));
    }
}
