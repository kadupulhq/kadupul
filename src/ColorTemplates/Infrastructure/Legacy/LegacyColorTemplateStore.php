<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Legacy;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;
use Kadupul\ColorTemplates\Domain\ColorTemplate;
use Kadupul\ColorTemplates\Domain\ColorTemplateFilters;
use Kadupul\ColorTemplates\Domain\ColorTemplateItem;
use Kadupul\ColorTemplates\Domain\ColorTemplatePage;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PDO;

final readonly class LegacyColorTemplateStore implements ColorTemplateStore
{
    public function __construct(private DatabaseConnection $database, private ColorTemplateAccess $access, private AuditTrail $audit, private LegacyConfiguration $configuration) {}

    public function defaultRows(): int
    {
        $value = $this->fetchScalar($this->database->get()->query("SELECT value FROM settings WHERE name = 'num_rows_table'"));
        $rows = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }

    public function defaultHasGraphs(): bool
    {
        return $this->fetchScalar($this->database->get()->query("SELECT value FROM settings WHERE name = 'default_has'")) === 'on';
    }

    public function list(ColorTemplateFilters $filters): ColorTemplatePage
    {
        $where = '';
        $usedCondition = '';
        $parameters = [];
        if ($filters->filter !== '') {
            $where = ' WHERE ct.name LIKE ?';
            $parameters[] = '%' . $filters->filter . '%';
        }
        if ($filters->hasGraphs) {
            $usedCondition = ' WHERE listed.graphs > 0 OR listed.templates > 0';
        }
        $aggregate = 'SELECT ct.color_template_id, ct.name,
            COALESCE(graphs.graphs, 0) AS graphs, COALESCE(templates.templates, 0) AS templates,
            COALESCE(items.items, 0) AS items FROM color_templates ct
            LEFT JOIN (SELECT color_template, COUNT(*) graphs FROM aggregate_graphs_graph_item GROUP BY color_template) graphs ON graphs.color_template=ct.color_template_id
            LEFT JOIN (SELECT color_template, COUNT(*) templates FROM aggregate_graph_templates_item GROUP BY color_template) templates ON templates.color_template=ct.color_template_id
            LEFT JOIN (SELECT color_template_id, COUNT(*) items FROM color_template_items GROUP BY color_template_id) items ON items.color_template_id=ct.color_template_id' . $where;
        $count = $this->database->get()->prepare('SELECT COUNT(*) FROM (' . $aggregate . ') listed' . $usedCondition);
        $this->execute($count, $parameters);
        $total = (int) $this->fetchScalar($count);
        $sort = match ($filters->sortColumn) {
            'graphs' => 'graphs', 'templates' => 'templates', default => 'name',
        };
        $offset = ($filters->page - 1) * $filters->rows;
        $query = $this->database->get()->prepare('SELECT * FROM (' . $aggregate . ') listed' . $usedCondition . ' ORDER BY ' . $sort . ' ' . $filters->sortDirection . ', color_template_id ASC LIMIT ? OFFSET ?');
        $this->execute($query, [...$parameters, $filters->rows, $offset]);
        $templates = array_map(fn(array $row): ColorTemplate => $this->hydrateTemplate($row), $this->fetchAll($query, PDO::FETCH_ASSOC));
        return new ColorTemplatePage($templates, $total, $filters);
    }

    public function find(int $id): ?ColorTemplate
    {
        $query = $this->database->get()->prepare('SELECT ct.color_template_id, ct.name,
            (SELECT COUNT(*) FROM aggregate_graphs_graph_item WHERE color_template=ct.color_template_id) graphs,
            (SELECT COUNT(*) FROM aggregate_graph_templates_item WHERE color_template=ct.color_template_id) templates,
            (SELECT COUNT(*) FROM color_template_items WHERE color_template_id=ct.color_template_id) items
            FROM color_templates ct WHERE ct.color_template_id=?');
        $this->execute($query, [$id]);
        $row = $this->fetchOne($query, PDO::FETCH_ASSOC);
        return $row ? $this->hydrateTemplate($row) : null;
    }

    public function items(int $templateId): array
    {
        $query = $this->database->get()->prepare('SELECT i.color_template_item_id, i.color_template_id, i.color_id, i.sequence, c.hex
            FROM color_template_items i LEFT JOIN colors c ON c.id=i.color_id WHERE i.color_template_id=? ORDER BY i.sequence, i.color_template_item_id');
        $this->execute($query, [$templateId]);
        return array_map(static fn(array $row): ColorTemplateItem => new ColorTemplateItem((int) $row['color_template_item_id'], (int) $row['color_template_id'], (int) $row['color_id'], (int) $row['sequence'], (string) ($row['hex'] ?? '')), $this->fetchAll($query, PDO::FETCH_ASSOC));
    }

    public function colors(): array
    {
        $query = $this->database->get()->query('SELECT id, name, hex FROM colors ORDER BY name, id');
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'hex' => (string) $row['hex']], $this->fetchAll($query, PDO::FETCH_ASSOC));
    }

    public function saveTemplate(int $actorId, ?int $id, string $name, ?string $revision): int
    {
        $this->validateName($name);
        return $this->transaction($actorId, 'color.template.' . ($id === null ? 'create' : 'edit'), $id === null ? 'new' : (string) $id, function (PDO $db) use ($id, $name, $revision): int {
            if ($id === null) {
                $query = $db->prepare('INSERT INTO color_templates (name) VALUES (?)');
                $this->execute($query, [$name]);
                return (int) $db->lastInsertId();
            }
            $query = $db->prepare('SELECT color_template_id, name FROM color_templates WHERE color_template_id=?' . $this->lockSuffix($db));
            $this->execute($query, [$id]);
            $current = $this->fetchOne($query, PDO::FETCH_ASSOC);
            if (!$current) {
                throw new \InvalidArgumentException('Color template not found.');
            }
            $expected = hash('sha256', json_encode([(int) $current['color_template_id'], (string) $current['name']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if (!is_string($revision) || !hash_equals($expected, $revision)) {
                throw new \InvalidArgumentException('Color template changed since you opened the form. Reload before saving.');
            }
            $query = $db->prepare('UPDATE color_templates SET name=? WHERE color_template_id=?');
            $this->execute($query, [$name, $id]);
            return $id;
        });
    }

    public function saveItem(int $actorId, int $templateId, ?int $itemId, int $colorId, ?string $revision): int
    {
        if ($templateId < 1 || $colorId < 1) {
            throw new \InvalidArgumentException('Choose a valid color template and color.');
        }
        return $this->transaction($actorId, 'color.item.' . ($itemId === null ? 'create' : 'edit'), $itemId === null ? (string) $templateId : (string) $itemId, function (PDO $db) use ($templateId, $itemId, $colorId, $revision): int {
            $this->requireTemplate($db, $templateId, true);
            $color = $db->prepare('SELECT id FROM colors WHERE id=?' . $this->lockSuffix($db));
            $this->execute($color, [$colorId]);
            if ($this->fetchScalar($color) === false) {
                throw new \InvalidArgumentException('Selected color is unavailable.');
            }
            if ($itemId === null) {
                $sequence = $db->prepare('SELECT COALESCE(MAX(sequence),0)+1 FROM color_template_items WHERE color_template_id=?');
                $this->execute($sequence, [$templateId]);
                $insert = $db->prepare('INSERT INTO color_template_items (color_template_id,color_id,sequence) VALUES (?,?,?)');
                $this->execute($insert, [$templateId, $colorId, (int) $this->fetchScalar($sequence)]);
                return (int) $db->lastInsertId();
            }
            $query = $db->prepare('SELECT color_template_item_id, color_template_id, color_id, sequence FROM color_template_items WHERE color_template_item_id=? AND color_template_id=?' . $this->lockSuffix($db));
            $this->execute($query, [$itemId, $templateId]);
            $current = $this->fetchOne($query, PDO::FETCH_ASSOC);
            if (!$current) {
                throw new \InvalidArgumentException('Color template item not found.');
            }
            $expected = hash('sha256', json_encode([(int) $current['color_template_item_id'], (int) $current['color_template_id'], (int) $current['color_id'], (int) $current['sequence']], JSON_THROW_ON_ERROR));
            if (!is_string($revision) || !hash_equals($expected, $revision)) {
                throw new \InvalidArgumentException('Color template item changed. Reload before saving.');
            }
            $update = $db->prepare('UPDATE color_template_items SET color_id=? WHERE color_template_item_id=? AND color_template_id=?');
            $this->execute($update, [$colorId, $itemId, $templateId]);
            return $itemId;
        });
    }

    public function removeItem(int $actorId, int $templateId, int $itemId, ?string $revision): void
    {
        $this->transaction($actorId, 'color.item.delete', (string) $itemId, function (PDO $db) use ($templateId, $itemId, $revision): void {
            $query = $db->prepare('SELECT color_template_item_id, color_template_id, color_id, sequence FROM color_template_items WHERE color_template_item_id=? AND color_template_id=?' . $this->lockSuffix($db));
            $this->execute($query, [$itemId, $templateId]);
            $current = $this->fetchOne($query, PDO::FETCH_ASSOC);
            if (!$current) {
                throw new \InvalidArgumentException('Color template item not found.');
            }
            $expected = hash('sha256', json_encode([(int) $current['color_template_item_id'], (int) $current['color_template_id'], (int) $current['color_id'], (int) $current['sequence']], JSON_THROW_ON_ERROR));
            if (!is_string($revision) || !hash_equals($expected, $revision)) {
                throw new \InvalidArgumentException('Color template item changed. Reload before removing it.');
            }
            $delete = $db->prepare('DELETE FROM color_template_items WHERE color_template_item_id=? AND color_template_id=?');
            $this->execute($delete, [$itemId, $templateId]);
        });
    }

    public function reorder(int $actorId, int $templateId, array $orderedItemIds, ?string $revision): void
    {
        if ($templateId < 1 || count($orderedItemIds) > 1000 || count(array_unique($orderedItemIds)) !== count($orderedItemIds)
            || array_filter($orderedItemIds, static fn($id): bool => !is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Invalid color template order.');
        }
        $this->transaction($actorId, 'color.item.reorder', (string) $templateId, function (PDO $db) use ($templateId, $orderedItemIds, $revision): void {
            $this->requireTemplate($db, $templateId, true);
            $query = $db->prepare('SELECT color_template_item_id FROM color_template_items WHERE color_template_id=? ORDER BY sequence, color_template_item_id' . $this->lockSuffix($db));
            $this->execute($query, [$templateId]);
            $current = array_map('intval', $this->fetchAll($query, PDO::FETCH_COLUMN));
            $expectedRevision = hash('sha256', json_encode($current, JSON_THROW_ON_ERROR));
            if (!is_string($revision) || !hash_equals($expectedRevision, $revision)) {
                throw new \InvalidArgumentException('Color template order changed. Reload before reordering.');
            }
            $actual = $current;
            $submitted = $orderedItemIds;
            sort($actual, SORT_NUMERIC);
            $sorted = $submitted;
            sort($sorted, SORT_NUMERIC);
            if ($actual !== $sorted) {
                throw new \InvalidArgumentException('Color template items changed. Reload before reordering.');
            }
            $update = $db->prepare('UPDATE color_template_items SET sequence=? WHERE color_template_id=? AND color_template_item_id=?');
            foreach ($submitted as $position => $itemId) {
                $this->execute($update, [$position + 1, $templateId, $itemId]);
            }
        });
    }

    public function actionRevisions(array $templates): array
    {
        if (array_filter($templates, static fn($template): bool => !$template instanceof ColorTemplate) !== []) {
            throw new \InvalidArgumentException('Invalid color template selection.');
        }
        $ids = $this->normalizeIds(array_map(static fn(ColorTemplate $template): int => $template->id, $templates));
        if (count($ids) !== count($templates)) {
            throw new \InvalidArgumentException('Invalid color template selection.');
        }
        usort($templates, static fn(ColorTemplate $left, ColorTemplate $right): int => $left->id <=> $right->id);
        // The revision must describe the names shown on the confirmation,
        // including when a rename occurs between that read and this snapshot.
        $rows = array_map(static fn(ColorTemplate $template): array => ['color_template_id' => $template->id, 'name' => $template->name], $templates);
        return $this->selectionRevisions($this->database->get(), $rows, false);
    }

    private function selectionRevisions(PDO $db, array $templates, bool $lock): array
    {
        $ids = array_map(static fn(array $template): int => (int) $template['color_template_id'], $templates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $db->prepare("SELECT color_template_id,color_template_item_id,color_id,sequence FROM color_template_items WHERE color_template_id IN ($placeholders) ORDER BY color_template_id,sequence,color_template_item_id" . ($lock ? $this->lockSuffix($db) : ''));
        $this->execute($query, $ids);
        $items = [];
        foreach ($this->fetchAll($query, PDO::FETCH_ASSOC) as $row) {
            $items[(int) $row['color_template_id']][] = [(int) $row['color_template_item_id'], (int) $row['color_id'], (int) $row['sequence']];
        }
        $revisions = [];
        foreach ($templates as $template) {
            $id = (int) $template['color_template_id'];
            $revisions[$id] = hash('sha256', json_encode([$id, (string) $template['name'], $items[$id] ?? []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        return $revisions;
    }

    private function assertSelectionRevisions(PDO $db, array $templates, array $expected): void
    {
        $current = $this->selectionRevisions($db, $templates, true);
        ksort($expected, SORT_NUMERIC);
        if (array_keys($current) !== array_keys($expected)) {
            throw new \InvalidArgumentException('The selected color templates changed. Reload before continuing.');
        }
        foreach ($current as $id => $revision) {
            if (!is_string($expected[$id]) || !hash_equals($revision, $expected[$id])) {
                throw new \InvalidArgumentException('The selected color templates changed. Reload before continuing.');
            }
        }
    }

    public function delete(int $actorId, array $ids, array $revisions): void
    {
        $ids = $this->normalizeIds($ids);
        $this->transaction($actorId, 'color.template.delete', $this->selectionTarget($ids), function (PDO $db) use ($ids, $revisions): void {
            $templates = $this->lockedTemplates($db, $ids);
            if (count($templates) !== count($ids)) {
                throw new \InvalidArgumentException('One or more color templates no longer exist.');
            }
            $this->assertSelectionRevisions($db, $templates, $revisions);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach (['aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
                $references = $db->prepare("SELECT color_template FROM $table WHERE color_template IN ($placeholders) ORDER BY color_template" . $this->lockSuffix($db));
                $this->execute($references, $ids);
                if ($this->fetchScalar($references) !== false) {
                    throw new \InvalidArgumentException('Color templates referenced by aggregate graphs or templates cannot be deleted.');
                }
            }
            $items = $db->prepare("DELETE FROM color_template_items WHERE color_template_id IN ($placeholders)");
            $this->execute($items, $ids);
            $delete = $db->prepare("DELETE FROM color_templates WHERE color_template_id IN ($placeholders)");
            $this->execute($delete, $ids);
        });
    }

    public function duplicate(int $actorId, array $ids, string $titleFormat, array $revisions): void
    {
        $ids = $this->normalizeIds($ids);
        if ($titleFormat === '' || mb_strlen($titleFormat, 'UTF-8') > 255 || preg_match('//u', $titleFormat) !== 1 || str_contains($titleFormat, "\0")) {
            throw new \InvalidArgumentException('Enter a valid title format.');
        }
        $this->transaction($actorId, 'color.template.duplicate', $this->selectionTarget($ids), function (PDO $db) use ($ids, $titleFormat, $revisions): void {
            $templates = $this->lockedTemplates($db, $ids);
            if (count($templates) !== count($ids)) {
                throw new \InvalidArgumentException('One or more color templates no longer exist.');
            }
            $this->assertSelectionRevisions($db, $templates, $revisions);
            $insert = $db->prepare('INSERT INTO color_templates (name) VALUES (?)');
            $readItems = $db->prepare('SELECT color_id, sequence FROM color_template_items WHERE color_template_id=? ORDER BY sequence, color_template_item_id' . $this->lockSuffix($db));
            $insertItem = $db->prepare('INSERT INTO color_template_items (color_template_id,color_id,sequence) VALUES (?,?,?)');
            foreach ($templates as $template) {
                $name = str_replace('<template_title>', (string) $template['name'], $titleFormat);
                $this->validateName($name);
                $this->execute($insert, [$name]);
                $newId = (int) $db->lastInsertId();
                $this->execute($readItems, [(int) $template['color_template_id']]);
                foreach ($this->fetchAll($readItems, PDO::FETCH_ASSOC) as $item) {
                    $this->execute($insertItem, [$newId, (int) $item['color_id'], (int) $item['sequence']]);
                }
            }
        });
    }

    private function transaction(int $actorId, string $action, string $target, callable $operation): mixed
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
                throw new \RuntimeException('Color template commit failed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } catch (\Throwable $error) {
            if ($started && $db->inTransaction()) {
                $this->rollbackOwned($db, $error);
            }
            if ($error instanceof \InvalidArgumentException) {
                $decision = AuditEvent::DENIED;
                $outcome = AuditEvent::DENIED;
            }
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId > 0 ? $actorId : null, $action, 'color_template', $target, $decision, $outcome));
            } catch (\Throwable) {
                // Audit sink failure must not change a committed template operation.
            }
        }
    }

    private function execute(\PDOStatement|false $statement, array $parameters): void
    {
        if ($statement === false || !$statement->execute($parameters)) {
            throw new \RuntimeException('Color template database operation could not be confirmed.');
        }
    }

    private function fetchOne(\PDOStatement|false $statement, int $mode = PDO::FETCH_ASSOC): array|false
    {
        if ($statement === false) {
            throw new \RuntimeException('Color template database result could not be confirmed.');
        }
        $row = $statement->fetch($mode);
        $this->assertReadConfirmed($statement);
        return $row;
    }

    private function fetchAll(\PDOStatement|false $statement, int $mode = PDO::FETCH_ASSOC): array
    {
        if ($statement === false) {
            throw new \RuntimeException('Color template database result could not be confirmed.');
        }
        $rows = $statement->fetchAll($mode);
        $this->assertReadConfirmed($statement);
        return $rows;
    }

    private function fetchScalar(\PDOStatement|false $statement): mixed
    {
        if ($statement === false) {
            throw new \RuntimeException('Color template database result could not be confirmed.');
        }
        $value = $statement->fetchColumn();
        $this->assertReadConfirmed($statement);
        return $value;
    }

    private function assertReadConfirmed(\PDOStatement $statement): void
    {
        if ($statement->errorCode() !== '00000') {
            throw new \RuntimeException('Color template database result could not be confirmed.');
        }
    }

    private function rollbackOwned(PDO $db, \Throwable $error): void
    {
        try {
            $confirmed = $db->rollBack();
        } catch (\Throwable $rollbackError) {
            throw new \RuntimeException('Color template rollback could not be confirmed.', 0, $rollbackError);
        }
        if (!$confirmed) {
            throw new \RuntimeException('Color template rollback could not be confirmed.', 0, $error);
        }
    }

    private function assertTransactional(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver !== 'mysql') {
            throw new \RuntimeException('Unsupported color template mutation database.');
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Color templates must be changed on the primary collector.');
        }
        foreach (['color_templates', 'color_template_items', 'colors', 'aggregate_graphs_graph_item', 'aggregate_graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            // Inspect this connection's actual table, including temporary shadows.
            $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
            if ($query === false) {
                throw new \RuntimeException('Color template storage could not be verified.');
            }
            $definition = $this->fetchOne($query, PDO::FETCH_NUM);
            if (!is_array($definition) || preg_match('/\n\) ENGINE=InnoDB\b/i', (string) ($definition[1] ?? '')) !== 1) {
                throw new \RuntimeException('Color template writes require transactional tables.');
            }
        }
        if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
            throw new \RuntimeException('Color template transaction isolation could not be confirmed.');
        }
    }

    private function lockedTemplates(PDO $db, array $ids): array
    {
        $query = $db->prepare('SELECT color_template_id,name FROM color_templates WHERE color_template_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY color_template_id' . $this->lockSuffix($db));
        $this->execute($query, $ids);
        return $this->fetchAll($query, PDO::FETCH_ASSOC);
    }

    private function requireTemplate(PDO $db, int $templateId, bool $lock): void
    {
        $query = $db->prepare('SELECT color_template_id FROM color_templates WHERE color_template_id=?' . ($lock ? $this->lockSuffix($db) : ''));
        $this->execute($query, [$templateId]);
        if ($this->fetchScalar($query) === false) {
            throw new \InvalidArgumentException('Color template not found.');
        }
    }

    private function normalizeIds(array $ids): array
    {
        if ($ids === [] || count($ids) > 100 || array_filter($ids, static fn($id): bool => !is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Select between 1 and 100 color templates.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function validateName(string $name): void
    {
        if ($name === '' || mb_strlen($name, 'UTF-8') > 255 || preg_match('//u', $name) !== 1 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Color template name must contain 1 to 255 valid characters.');
        }
    }

    private function selectionTarget(array $ids): string
    {
        return 'selection-' . substr(hash('sha256', implode(',', $ids)), 0, 32);
    }

    private function lockSuffix(PDO $db): string
    {
        return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function hydrateTemplate(array $row): ColorTemplate
    {
        return new ColorTemplate((int) $row['color_template_id'], (string) $row['name'], (int) $row['graphs'], (int) $row['templates'], (int) ($row['items'] ?? 0));
    }
}
