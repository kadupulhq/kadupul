<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;
use Kadupul\Graphing\Domain\GprintPreset;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Domain\GprintPresetPage;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(GprintPresetStore::class)]
final readonly class LegacyGprintPresetStore implements GprintPresetStore
{
    public function __construct(private DatabaseConnection $database, private GprintPresetAccess $access, private AuditTrail $audit, private LegacyConfiguration $configuration) {}

    public function defaultRows(): int
    {
        $rows = filter_var(GprintPresetSql::column(GprintPresetSql::execute($this->database->get(), "SELECT value FROM settings WHERE name = 'num_rows_table'")), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }

    public function defaultHasGraphs(): bool
    {
        return GprintPresetSql::column(GprintPresetSql::execute($this->database->get(), "SELECT value FROM settings WHERE name = 'default_has'")) === 'on';
    }

    public function find(int $id): ?GprintPreset
    {
        $row = GprintPresetSql::one(GprintPresetSql::execute($this->database->get(), $this->query() . ' WHERE gp.id = ?', [$id]));
        return $row ? $this->hydrate($row) : null;
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $query = GprintPresetSql::execute($this->database->get(), $this->query() . ' WHERE gp.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY gp.id', $ids);
        return array_map($this->hydrate(...), GprintPresetSql::all($query));
    }

    public function list(GprintPresetFilters $filters): GprintPresetPage
    {
        $parts = [];
        $params = [];
        if ($filters->filter !== '') {
            $parts[] = 'gp.name LIKE ?';
            $params[] = '%' . $filters->filter . '%';
        }
        if ($filters->hasGraphs) {
            $parts[] = 'EXISTS (SELECT 1 FROM graph_templates_item gi WHERE gi.gprint_id = gp.id AND gi.local_graph_id > 0)';
        }
        $where = $parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts);
        $sort = match ($filters->sortColumn) {
            'gprint_text' => 'gp.gprint_text', 'graphs' => 'graphs', 'templates' => 'templates', default => 'gp.name',
        };
        $db = $this->database->get();
        $count = GprintPresetSql::execute($db, 'SELECT COUNT(*) FROM graph_templates_gprint gp' . $where, $params);
        $query = GprintPresetSql::execute($db, $this->query() . $where . ' ORDER BY ' . $sort . ' ' . $filters->sortDirection . ', gp.id ASC LIMIT ? OFFSET ?', [...$params, $filters->rows, ($filters->page - 1) * $filters->rows]);
        return new GprintPresetPage(array_map($this->hydrate(...), GprintPresetSql::all($query)), (int) GprintPresetSql::column($count), $filters);
    }

    public function save(int $actorId, ?int $id, string $name, string $gprintText, ?string $revision): int
    {
        GprintPreset::validate($name, $gprintText);
        return $this->write($actorId, $id === null ? 'create' : 'edit', function (\PDO $db) use ($id, $name, $gprintText, $revision): int {
            if ($id === null) {
                // Same 32-character form as the legacy get_hash_gprint() for templates and exports.
                GprintPresetSql::execute($db, 'INSERT INTO graph_templates_gprint (hash, name, gprint_text) VALUES (?, ?, ?)', [bin2hex(random_bytes(16)), $name, $gprintText]);
                $newId = (int) $db->lastInsertId();
                if ($newId < 1) {
                    throw new \RuntimeException('GPRINT Preset creation was not confirmed.');
                }
                return $newId;
            }
            $row = GprintPresetSql::one(GprintPresetSql::execute($db, 'SELECT id, name, gprint_text, hash FROM graph_templates_gprint WHERE id = ?' . $this->lock(), [$id]));
            if (!$row) {
                throw new \InvalidArgumentException('GPRINT Preset not found.');
            }
            if ($revision === null || !hash_equals($this->hydrate($row)->revision, $revision)) {
                throw new \InvalidArgumentException('GPRINT Preset changed since you opened this form. Reload before saving.');
            }
            GprintPresetSql::execute($db, 'UPDATE graph_templates_gprint SET name = ?, gprint_text = ? WHERE id = ?', [$name, $gprintText, $id]);
            return $id;
        });
    }

    public function delete(int $actorId, array $ids, array $revisions): void
    {
        if ($ids === [] || count($ids) > GprintPresetStore::MAX_DELETE_SELECTION || array_filter($ids, static fn($id): bool => !is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Invalid GPRINT preset selection.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $this->write($actorId, 'delete', function (\PDO $db) use ($ids, $revisions): void {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = GprintPresetSql::all(GprintPresetSql::execute($db, 'SELECT id, name, gprint_text, hash FROM graph_templates_gprint WHERE id IN (' . $placeholders . ') ORDER BY id' . $this->lock(), $ids));
            if (count($rows) !== count($ids)) {
                throw new \InvalidArgumentException('One or more selected GPRINT Presets no longer exist.');
            }
            foreach ($rows as $row) {
                $preset = $this->hydrate($row);
                if (!is_string($revisions[$preset->id] ?? null) || !hash_equals($preset->revision, $revisions[$preset->id])) {
                    throw new \InvalidArgumentException('GPRINT Preset changed since you opened this form. Reload before saving.');
                }
            }
            // The legacy page only disabled the checkbox; refuse in-use presets here.
            $query = GprintPresetSql::execute($db, 'SELECT gprint_id FROM graph_templates_item WHERE gprint_id IN (' . $placeholders . ')' . $this->lock(), $ids);
            if (GprintPresetSql::column($query) !== false) {
                throw new \InvalidArgumentException('GPRINT Presets in use by a graph or graph template cannot be deleted.');
            }
            $query = GprintPresetSql::execute($db, 'DELETE FROM graph_templates_gprint WHERE id IN (' . $placeholders . ')', $ids);
            if ($query->rowCount() !== count($ids)) {
                throw new \RuntimeException('GPRINT Preset deletion was not confirmed.');
            }
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
                throw new \RuntimeException('GPRINT transaction unavailable.');
            }
            $this->assertTransactional($db);
            if (!$db->beginTransaction()) {
                throw new \RuntimeException('GPRINT transaction unavailable.');
            }
            $started = true;
            $this->access->assertCurrent($actorId);
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $result = $operation($db);
            if (!$db->commit()) {
                throw new \RuntimeException('GPRINT operation commit was not confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } catch (\Throwable $error) {
            if ($started && $db->inTransaction()) {
                try {
                    $rolledBack = $db->rollBack();
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('GPRINT operation rollback was not confirmed.', 0, $rollbackError);
                }
                if (!$rolledBack) {
                    throw new \RuntimeException('GPRINT operation rollback was not confirmed.', 0, $error);
                }
            }
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'graphing.gprint.' . $action, 'gprint_preset', 'gprint_preset', $decision, $outcome));
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
            throw new \RuntimeException('GPRINT Presets must be changed on the primary collector.');
        }
        foreach (['graph_templates_gprint', 'graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            // Check the actual connection table, including temporary shadows.
            $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
            $definition = $query === false ? false : GprintPresetSql::one($query, \PDO::FETCH_NUM);
            if ($definition === false || !is_string($definition[1] ?? null)
                || preg_match('/\ACREATE TABLE /i', $definition[1]) !== 1
                || preg_match('/\n\) ENGINE=InnoDB\b/i', $definition[1]) !== 1) {
                throw new \RuntimeException('GPRINT writes require transactional tables.');
            }
        }
        // Keep the dependency range lock when the session default is READ COMMITTED.
        if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
            throw new \RuntimeException('GPRINT transaction isolation was not confirmed.');
        }
    }

    private function lock(): string
    {
        return $this->database->get()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function query(): string
    {
        // Count distinct graph and template uses, as the legacy list did.
        return 'SELECT gp.id, gp.name, gp.gprint_text, gp.hash,
            (SELECT COUNT(*) FROM (SELECT DISTINCT gprint_id, graph_template_id, local_graph_id FROM graph_templates_item WHERE local_graph_id > 0) g WHERE g.gprint_id = gp.id) graphs,
            (SELECT COUNT(*) FROM (SELECT DISTINCT gprint_id, graph_template_id, local_graph_id FROM graph_templates_item WHERE local_graph_id = 0) t WHERE t.gprint_id = gp.id) templates
            FROM graph_templates_gprint gp';
    }

    private function hydrate(array $row): GprintPreset
    {
        return new GprintPreset((int) $row['id'], (string) $row['name'], (string) ($row['gprint_text'] ?? ''), (string) $row['hash'], (int) ($row['graphs'] ?? 0), (int) ($row['templates'] ?? 0));
    }
}
