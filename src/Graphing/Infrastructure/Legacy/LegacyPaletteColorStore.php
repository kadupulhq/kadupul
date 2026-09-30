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
        $rows = filter_var($this->database->get()->query("SELECT value FROM settings WHERE name = 'num_rows_table'")->fetchColumn(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }
    public function defaultHasGraphs(): bool
    {
        return $this->database->get()->query("SELECT value FROM settings WHERE name = 'default_has'")->fetchColumn() === 'on';
    }
    public function find(int $id): ?PaletteColor
    {
        $query = $this->database->get()->prepare($this->query() . ' WHERE c.id = ?');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $query = $this->database->get()->prepare($this->query() . ' WHERE c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY c.id');
        $query->execute($ids);
        return array_map($this->hydrate(...), $query->fetchAll(\PDO::FETCH_ASSOC));
    }
    public function list(PaletteColorFilters $filters): PaletteColorPage
    {
        [$where, $params] = $this->where($filters);
        $sort = match ($filters->sortColumn) {
            'hex' => 'c.hex', 'read_only' => 'c.read_only', 'graphs' => 'graphs', 'templates' => 'templates', default => 'c.name',
        };
        $db = $this->database->get();
        $count = $db->prepare('SELECT COUNT(*) FROM (' . $this->query() . $where . ') palette');
        $count->execute($params);
        $query = $db->prepare($this->query() . $where . ' ORDER BY ' . $sort . ' ' . $filters->sortDirection . ', c.id ASC LIMIT ? OFFSET ?');
        $query->execute([...$params, $filters->rows, ($filters->page - 1) * $filters->rows]);
        return new PaletteColorPage(array_map($this->hydrate(...), $query->fetchAll(\PDO::FETCH_ASSOC)), (int) $count->fetchColumn(), $filters);
    }
    public function export(PaletteColorFilters $filters): array
    {
        [$where, $params] = $this->where($filters);
        $query = $this->database->get()->prepare($this->query() . $where . ' ORDER BY c.id');
        $query->execute($params);
        return array_map($this->hydrate(...), $query->fetchAll(\PDO::FETCH_ASSOC));
    }
    public function snapshot(): string
    {
        return $this->snapshotRows($this->lockedRows(false));
    }
    public function save(int $actorId, ?int $id, string $name, string $hex, ?string $revision): int
    {
        PaletteColor::validate($name, $hex);
        return $this->write($actorId, $id === null ? 'create' : 'edit', function (\PDO $db) use ($id, $name, $hex, $revision): int {
            if ($id !== null) {
                $query = $db->prepare('SELECT * FROM colors WHERE id = ?' . $this->lock());
                $query->execute([$id]);
                $row = $query->fetch(\PDO::FETCH_ASSOC);
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
                $db->prepare('UPDATE colors SET name = ?, hex = ? WHERE id = ?')->execute([$name, $hex, $id]);
                return $id;
            }
            $db->prepare("INSERT INTO colors (name, hex, read_only) VALUES (?, ?, '')")->execute([$name, $hex]);
            $newId = (int) $db->lastInsertId();
            if ($newId < 1) {
                throw new \RuntimeException('Color creation was not confirmed.');
            }
            return $newId;
        });
    }
    public function delete(int $actorId, array $ids, array $revisions = []): void
    {
        if ($ids === [] || count($ids) > 100 || array_filter($ids, static fn($id): bool => !is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Invalid color selection.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $this->write($actorId, 'delete', function (\PDO $db) use ($ids, $revisions): void {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = $db->prepare('SELECT * FROM colors WHERE id IN (' . $placeholders . ') ORDER BY id' . $this->lock());
            $query->execute($ids);
            $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
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
                $query = $db->prepare('SELECT color_id FROM ' . $table . ' WHERE color_id IN (' . $placeholders . ')' . $this->lock());
                $query->execute($ids);
                if ($query->fetchColumn() !== false) {
                    throw new \InvalidArgumentException('Colors in use cannot be deleted.');
                }
            }
            $query = $db->prepare('DELETE FROM colors WHERE id IN (' . $placeholders . ')');
            $query->execute($ids);
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
                    $db->prepare('UPDATE colors SET name = ?, hex = ? WHERE id = ?')->execute([$row['name'], $row['hex'], $current['id']]);
                    $counts['updated']++;
                } else {
                    $db->prepare("INSERT INTO colors (name, hex, read_only) VALUES (?, ?, '')")->execute([$row['name'], $row['hex']]);
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
            $this->assertTransactional($db);
            if ($db->inTransaction() || !$db->beginTransaction()) {
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
                $db->rollBack();
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
        $query = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        foreach (['colors', 'graph_templates_item', 'color_template_items', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            $query->execute([$table]);
            if (strcasecmp((string) $query->fetchColumn(), 'InnoDB') !== 0) {
                throw new \RuntimeException('Color writes require transactional tables.');
            }
        }
    }
    private function lock(): string
    {
        return $this->database->get()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
    private function lockedRows(bool $lock): array
    {
        return $this->database->get()->query('SELECT id, name, hex, read_only FROM colors ORDER BY id' . ($lock ? $this->lock() : ''))->fetchAll(\PDO::FETCH_ASSOC);
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
