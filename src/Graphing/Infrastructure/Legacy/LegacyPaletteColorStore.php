<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Domain\PaletteColorPage;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

final readonly class LegacyPaletteColorStore implements PaletteColorStore
{
    public function __construct(private DatabaseConnection $database, private PaletteColorAccess $access, private AuditTrail $audit, private LegacyConfiguration $configuration) {}
    public function defaultRows(): int
    {
        $rows = filter_var(PaletteSql::column(PaletteSql::execute($this->database->get(), "SELECT value FROM settings WHERE name = 'num_rows_table'")), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }
    public function defaultHasGraphs(): bool
    {
        return PaletteSql::column(PaletteSql::execute($this->database->get(), "SELECT value FROM settings WHERE name = 'default_has'")) === 'on';
    }
    public function find(int $id): ?PaletteColor
    {
        $query = PaletteSql::execute($this->database->get(), $this->query() . ' WHERE c.id = ?', [$id]);
        $row = PaletteSql::one($query);
        return $row ? $this->hydrate($row) : null;
    }
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $query = PaletteSql::execute($this->database->get(), $this->query() . ' WHERE c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY c.id', $ids);
        return array_map($this->hydrate(...), PaletteSql::all($query));
    }
    public function list(PaletteColorFilters $filters): PaletteColorPage
    {
        [$where, $params] = $this->where($filters);
        $sort = match ($filters->sortColumn) {
            'hex' => 'c.hex', 'read_only' => 'c.read_only', 'graphs' => 'graphs', 'templates' => 'templates', default => 'c.name',
        };
        $db = $this->database->get();
        $count = PaletteSql::execute($db, 'SELECT COUNT(*) FROM (' . $this->query() . $where . ') palette', $params);
        $query = PaletteSql::execute($db, $this->query() . $where . ' ORDER BY ' . $sort . ' ' . $filters->sortDirection . ', c.id ASC LIMIT ? OFFSET ?', [...$params, $filters->rows, ($filters->page - 1) * $filters->rows]);
        return new PaletteColorPage(array_map($this->hydrate(...), PaletteSql::all($query)), (int) PaletteSql::column($count), $filters);
    }
    public function export(PaletteColorFilters $filters): array
    {
        [$where, $params] = $this->where($filters);
        $query = PaletteSql::execute($this->database->get(), $this->query() . $where . ' ORDER BY c.id', $params);
        return array_map($this->hydrate(...), PaletteSql::all($query));
    }
    public function snapshot(): string
    {
        return $this->snapshotRows($this->lockedRows(false));
    }
    public function save(int $actorId, ?int $id, string $name, string $hex, ?string $revision): int
    {
        PaletteColor::validate($name, $hex);
        try {
            return $this->write($actorId, $id === null ? 'create' : 'edit', function (\PDO $db) use ($id, $name, $hex, $revision): int {
                if ($id !== null) {
                    $query = PaletteSql::execute($db, 'SELECT * FROM colors WHERE id = ?' . $this->lock(), [$id]);
                    $row = PaletteSql::one($query);
                    if (!$row) {
                        throw new \InvalidArgumentException('Color not found.');
                    }
                    $color = $this->hydrate($row);
                    if ($revision === null || !hash_equals($color->revision, $revision)) {
                        throw new \InvalidArgumentException('Color changed since you opened this form. Reload before saving.');
                    }
                    if ($color->readOnly) {
                        throw new \InvalidArgumentException('Named colors are read only.');
                    }
                    PaletteSql::execute($db, 'UPDATE colors SET name = ?, hex = ? WHERE id = ?', [$name, $hex, $id]);
                    return $id;
                }
                PaletteSql::execute($db, "INSERT INTO colors (name, hex, read_only) VALUES (?, ?, '')", [$name, $hex]);
                $newId = (int) $db->lastInsertId();
                if ($newId < 1) {
                    throw new \RuntimeException('Color creation was not confirmed.');
                }
                return $newId;
            });
        } catch (\PDOException $error) {
            // write() only rethrows after its owned transaction was rolled back.
            // A failed rollback is a RuntimeException and keeps the uncertain outcome.
            $db = $this->database->get();
            $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $unique = $driver === 'mysql' ? ($error->errorInfo[1] ?? null) === 1062
                : ($driver === 'sqlite' && ($error->errorInfo[1] ?? null) === 19
                    && ($error->errorInfo[2] ?? '') === 'UNIQUE constraint failed: colors.hex');
            if ($unique && !$db->inTransaction()) {
                $query = PaletteSql::execute($db, 'SELECT id FROM colors WHERE hex = ? AND (? IS NULL OR id <> ?)', [$hex, $id, $id]);
                if (PaletteSql::column($query) !== false) {
                    throw new \InvalidArgumentException('A Color with this hex value already exists.', 0, $error);
                }
            }
            throw $error;
        }
    }
    public function delete(int $actorId, array $ids, array $revisions = []): void
    {
        if ($ids === [] || count($ids) > PaletteColorStore::MAX_DELETE_SELECTION || array_filter($ids, static fn($id): bool => !is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Invalid color selection.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $this->write($actorId, 'delete', function (\PDO $db) use ($ids, $revisions): void {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = PaletteSql::execute($db, 'SELECT * FROM colors WHERE id IN (' . $placeholders . ') ORDER BY id' . $this->lock(), $ids);
            $rows = PaletteSql::all($query);
            if (count($rows) !== count($ids)) {
                throw new \InvalidArgumentException('One or more selected Colors no longer exist.');
            }
            foreach ($rows as $row) {
                $color = $this->hydrate($row);
                if (!is_string($revisions[$color->id] ?? null) || !hash_equals($color->revision, $revisions[$color->id])) {
                    throw new \InvalidArgumentException('Color changed since you opened this form. Reload before saving.');
                }
                if ($color->readOnly) {
                    throw new \InvalidArgumentException('Named colors are read only.');
                }
            }
            foreach (['graph_templates_item', 'color_template_items'] as $table) {
                $query = PaletteSql::execute($db, 'SELECT color_id FROM ' . $table . ' WHERE color_id IN (' . $placeholders . ')' . $this->lock(), $ids);
                if (PaletteSql::column($query) !== false) {
                    throw new \InvalidArgumentException('Colors in use cannot be deleted.');
                }
            }
            $query = PaletteSql::execute($db, 'DELETE FROM colors WHERE id IN (' . $placeholders . ')', $ids);
            if ($query->rowCount() !== count($ids)) {
                throw new \RuntimeException('Color deletion was not confirmed.');
            }
        });
    }
    public function import(int $actorId, array $rows, bool $allowUpdate, string $revision): array
    {
        if ($rows === [] || count($rows) > 5000) {
            throw new \InvalidArgumentException('Invalid CSV color selection.');
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['name', 'hex']) !== [] || !is_string($row['name'] ?? null) || !is_string($row['hex'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSV color selection.');
            }
            PaletteColor::validate($row['name'], $row['hex']);
            $key = strtolower($row['hex']);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('CSV contains a duplicate hex value.');
            }
            $seen[$key] = true;
        }
        return $this->write($actorId, 'import', function (\PDO $db) use ($rows, $allowUpdate, $revision): array {
            $existing = $this->lockedRows(true);
            if (!hash_equals($this->snapshotRows($existing), $revision)) {
                throw new \InvalidArgumentException('Color changed since you opened this form. Reload before saving.');
            }
            $byHex = [];
            foreach ($existing as $row) {
                $byHex[strtolower($row['hex'])] = $row;
            }
            $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($rows as $row) {
                PaletteColor::validate($row['name'], $row['hex']);
                $current = $byHex[strtolower($row['hex'])] ?? null;
                if ($current !== null) {
                    if (!$allowUpdate || $current['read_only'] === 'on') {
                        $counts['skipped']++;
                        continue;
                    }
                    PaletteSql::execute($db, 'UPDATE colors SET name = ?, hex = ? WHERE id = ?', [$row['name'], $row['hex'], $current['id']]);
                    $counts['updated']++;
                } else {
                    PaletteSql::execute($db, "INSERT INTO colors (name, hex, read_only) VALUES (?, ?, '')", [$row['name'], $row['hex']]);
                    $counts['inserted']++;
                }
            }
            return $counts;
        });
    }
    private function write(int $actorId, string $action, callable $operation): mixed
    {
        $db = $this->database->get();
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        $started = false;
        try {
            if ($db->inTransaction()) {
                throw new \RuntimeException('Color transaction unavailable.');
            }
            $this->assertTransactional($db);
            if (!$db->beginTransaction()) {
                throw new \RuntimeException('Color transaction unavailable.');
            }
            $started = true;
            $this->access->assertCurrent($actorId);
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $result = $operation($db);
            if (!$db->commit()) {
                throw new \RuntimeException('Color operation commit was not confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } catch (\Throwable $error) {
            if ($started && $db->inTransaction()) {
                try {
                    $rolledBack = $db->rollBack();
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('Color operation rollback was not confirmed.', 0, $rollbackError);
                }
                if (!$rolledBack) {
                    throw new \RuntimeException('Color operation rollback was not confirmed.', 0, $error);
                }
            }
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'graphing.palette.' . $action, 'palette', 'palette', $decision, $outcome));
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
            throw new \RuntimeException('Colors must be changed on the primary collector.');
        }
        foreach (['colors', 'graph_templates_item', 'color_template_items', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            // Check the actual connection table, including temporary shadows.
            $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
            $definition = $query === false ? false : PaletteSql::one($query, \PDO::FETCH_NUM);
            if ($definition === false || !preg_match('/\n\) ENGINE=InnoDB\b/i', (string) $definition[1])) {
                throw new \RuntimeException('Color writes require transactional tables.');
            }
        }
        // Preserve dependency range locks when the session default was changed.
        if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
            throw new \RuntimeException('Color transaction isolation was not confirmed.');
        }
    }
    private function lock(): string
    {
        return $this->database->get()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
    private function lockedRows(bool $lock): array
    {
        return PaletteSql::all(PaletteSql::execute($this->database->get(), 'SELECT id, name, hex, read_only FROM colors ORDER BY id' . ($lock ? $this->lock() : '')));
    }
    private function snapshotRows(array $rows): string
    {
        return hash('sha256', json_encode(array_map(static fn(array $row): array => [(int) $row['id'], (string) $row['name'], (string) $row['hex'], (string) $row['read_only']], $rows), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    private function where(PaletteColorFilters $filters): array
    {
        $parts = [];
        $params = [];
        if ($filters->filter !== '') {
            $parts[] = '(c.name LIKE ? OR c.hex LIKE ?)';
            $params = ['%' . $filters->filter . '%', '%' . $filters->filter . '%'];
        }
        if ($filters->named) {
            $parts[] = "c.read_only = 'on'";
        }
        if ($filters->hasGraphs) {
            $parts[] = 'EXISTS (SELECT 1 FROM graph_templates_item gi WHERE gi.color_id = c.id)';
        }
        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $params];
    }
    private function query(): string
    {
        return 'SELECT c.*, (SELECT COUNT(*) FROM (SELECT DISTINCT color_id, graph_template_id, local_graph_id FROM graph_templates_item WHERE local_graph_id > 0) g WHERE g.color_id = c.id) graphs,
            (SELECT COUNT(*) FROM (SELECT DISTINCT color_id, graph_template_id, local_graph_id FROM graph_templates_item WHERE local_graph_id = 0) t WHERE t.color_id = c.id) templates,
            (SELECT COUNT(*) FROM color_template_items ci WHERE ci.color_id = c.id) other_refs FROM colors c';
    }
    private function hydrate(array $row): PaletteColor
    {
        return new PaletteColor((int) $row['id'], (string) $row['name'], (string) $row['hex'], $row['read_only'] === 'on', (int) ($row['graphs'] ?? 0), (int) ($row['templates'] ?? 0), (int) ($row['other_refs'] ?? 0));
    }
}
