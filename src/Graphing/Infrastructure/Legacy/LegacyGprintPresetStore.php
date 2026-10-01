<?php

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

final readonly class LegacyGprintPresetStore implements GprintPresetStore
{
    public function __construct(
        private DatabaseConnection $database,
        private GprintPresetAccess $access,
        private AuditTrail $audit,
        private LegacyConfiguration $configuration,
    ) {}

    public function defaultRows(): int
    {
        $query = $this->database->get()->query("SELECT value FROM settings WHERE name = 'num_rows_table'");
        $rows = filter_var($query->fetchColumn(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }

    public function defaultHasGraphs(): bool
    {
        $query = $this->database->get()->query("SELECT value FROM settings WHERE name = 'default_has'");
        return $query->fetchColumn() === 'on';
    }

    public function find(int $id): ?GprintPreset
    {
        if ($id < 1 || $id > 2147483647) {
            return null;
        }
        $query = $this->database->get()->prepare($this->presetQuery() . ' WHERE gp.id = ? GROUP BY gp.id, gp.name, gp.gprint_text, gp.hash');
        $query->execute([$id]);
        $row = $query->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->database->get()->prepare($this->presetQuery() . ' WHERE gp.id IN (' . $placeholders . ') GROUP BY gp.id, gp.name, gp.gprint_text, gp.hash ORDER BY gp.id');
        $query->execute($ids);
        return array_map($this->hydrate(...), $query->fetchAll());
    }

    public function list(GprintPresetFilters $filters): GprintPresetPage
    {
        $db = $this->database->get();
        $where = '';
        $parameters = [];
        if ($filters->filter !== '') {
            $where = ' WHERE gp.name LIKE ?';
            $parameters[] = '%' . $filters->filter . '%';
        }
        $having = $filters->hasGraphs ? ' HAVING graphs > 0' : '';
        $count = $db->prepare('SELECT COUNT(*) FROM (SELECT gp.id, SUM(CASE WHEN ref.local_graph_id > 0 THEN 1 ELSE 0 END) graphs,
            SUM(CASE WHEN ref.local_graph_id = 0 THEN 1 ELSE 0 END) templates FROM graph_templates_gprint gp
            LEFT JOIN (' . $this->referenceQuery() . ') ref ON ref.gprint_id = gp.id' . $where . ' GROUP BY gp.id' . $having . ') counted');
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();

        $sort = match ($filters->sortColumn) {
            'gprint_text' => 'gp.gprint_text', 'graphs' => 'graphs', 'templates' => 'templates', default => 'gp.name',
        };
        $offset = ($filters->page - 1) * $filters->rows;
        $query = $db->prepare('SELECT gp.id, gp.name, gp.gprint_text, gp.hash,
            SUM(CASE WHEN ref.local_graph_id > 0 THEN 1 ELSE 0 END) graphs,
            SUM(CASE WHEN ref.local_graph_id = 0 THEN 1 ELSE 0 END) templates
            FROM graph_templates_gprint gp LEFT JOIN (' . $this->referenceQuery() . ') ref ON ref.gprint_id = gp.id'
            . $where . ' GROUP BY gp.id, gp.name, gp.gprint_text, gp.hash' . $having
            . ' ORDER BY ' . $sort . ' ' . $filters->sortDirection . ', gp.id ASC LIMIT ? OFFSET ?');
        $query->execute([...$parameters, $filters->rows, $offset]);
        $presets = array_map($this->hydrate(...), $query->fetchAll());
        return new GprintPresetPage($presets, $total, $filters);
    }

    public function save(int $actorId, ?int $id, string $name, string $gprintText, ?string $revision): int
    {
        $db = $this->database->get();
        $target = $id === null ? 'new' : (string) $id;
        $this->assertTransactionAvailable($db);
        $ownsTransaction = false;
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $this->prepareMutation($db);
            $ownsTransaction = $db->beginTransaction();
            if (!$ownsTransaction) {
                throw new \RuntimeException('GPRINT transaction could not be started.');
            }
            $this->access->assertCurrent($actorId);
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $current = null;
            if ($id !== null) {
                $suffix = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $query = $db->prepare('SELECT id, name, gprint_text, hash FROM graph_templates_gprint WHERE id = ?' . $suffix);
                $query->execute([$id]);
                $current = $query->fetch() ?: null;
                if ($current === null) {
                    throw new \InvalidArgumentException('GPRINT Preset not found.');
                }
                $expected = $this->revision((int) $current['id'], (string) $current['name'], (string) $current['gprint_text'], (string) $current['hash']);
                if (!is_string($revision) || !hash_equals($expected, $revision)) {
                    throw new \InvalidArgumentException('GPRINT Preset changed since you opened this form. Reload before saving.');
                }
            }
            $this->validate($name, 'name');
            $this->validate($gprintText, 'gprint_text');
            if ($current === null) {
                $hash = bin2hex(random_bytes(16));
                $insert = $db->prepare('INSERT INTO graph_templates_gprint (hash, name, gprint_text) VALUES (?, ?, ?)');
                $insert->execute([$hash, $name, $gprintText]);
                $savedId = (int) $db->lastInsertId();
                if ($savedId < 1) {
                    throw new \RuntimeException('GPRINT Preset creation was not confirmed.');
                }
                $target = (string) $savedId;
            } else {
                $update = $db->prepare('UPDATE graph_templates_gprint SET name = ?, gprint_text = ? WHERE id = ?');
                $update->execute([$name, $gprintText, $id]);
                $savedId = $id;
            }
            if (!$db->commit()) {
                throw new \RuntimeException('GPRINT Preset save was not confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $savedId;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) {
                $this->rollbackOwned($db, $error);
            }
            throw $error;
        } finally {
            $this->record($actorId, $id === null ? 'graphing.gprint.create' : 'graphing.gprint.edit', $target, $decision, $outcome);
        }
    }

    public function delete(int $actorId, array $ids, array $revisions): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        if ($ids === [] || count($ids) > 100 || array_filter($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Invalid GPRINT preset selection.');
        }
        ksort($revisions, SORT_NUMERIC);
        if (array_keys($revisions) !== $ids || array_filter($revisions, static fn(mixed $revision): bool => !is_string($revision) || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) !== []) {
            throw new \InvalidArgumentException('Invalid GPRINT preset selection.');
        }
        $db = $this->database->get();
        $target = 'selection-' . substr(hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR)), 0, 32);
        $this->assertTransactionAvailable($db);
        $ownsTransaction = false;
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $this->prepareMutation($db);
            $ownsTransaction = $db->beginTransaction();
            if (!$ownsTransaction) {
                throw new \RuntimeException('GPRINT transaction could not be started.');
            }
            $this->access->assertCurrent($actorId);
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $suffix = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $query = $db->prepare('SELECT id, name, gprint_text, hash FROM graph_templates_gprint WHERE id IN (' . $placeholders . ') ORDER BY id' . $suffix);
            $query->execute($ids);
            $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
            $found = array_map('intval', array_column($rows, 'id'));
            if ($found !== $ids) {
                throw new \InvalidArgumentException('One or more selected GPRINT Presets no longer exist.');
            }
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!hash_equals($this->revision($id, (string) $row['name'], (string) $row['gprint_text'], (string) $row['hash']), $revisions[$id])) {
                    throw new \InvalidArgumentException('A GPRINT Preset changed. Reload before deleting.');
                }
            }
            $refs = $db->prepare('SELECT gprint_id, local_graph_id, graph_template_id FROM graph_templates_item WHERE gprint_id IN (' . $placeholders . ') ORDER BY gprint_id, graph_template_id, local_graph_id' . $suffix);
            $refs->execute($ids);
            if ($refs->fetch() !== false) {
                throw new \InvalidArgumentException('GPRINT Presets in use by a graph or graph template cannot be deleted.');
            }
            $delete = $db->prepare('DELETE FROM graph_templates_gprint WHERE id IN (' . $placeholders . ')');
            $delete->execute($ids);
            if ($delete->rowCount() !== count($ids)) {
                throw new \RuntimeException('GPRINT Preset deletion was not confirmed.');
            }
            if (!$db->commit()) {
                throw new \RuntimeException('GPRINT Preset deletion was not confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) {
                $this->rollbackOwned($db, $error);
            }
            throw $error;
        } finally {
            $this->record($actorId, 'graphing.gprint.delete', $target, $decision, $outcome);
        }
    }

    private function rollbackOwned(\PDO $db, \Throwable $error): void
    {
        try {
            $confirmed = $db->rollBack();
        } catch (\Throwable $rollbackError) {
            throw new \RuntimeException('GPRINT rollback could not be confirmed.', 0, $rollbackError);
        }
        if (!$confirmed) {
            throw new \RuntimeException('GPRINT rollback could not be confirmed.', 0, $error);
        }
    }

    private function assertTransactionAvailable(\PDO $db): void
    {
        if ($db->inTransaction()) {
            throw new \LogicException('GPRINT mutations require ownership of their transaction.');
        }
    }

    private function prepareMutation(\PDO $db): void
    {
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('GPRINT mutations require the primary installation.');
        }
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver !== 'mysql') {
            throw new \RuntimeException('Unsupported GPRINT mutation database.');
        }
        $required = ['graph_templates_gprint', 'graph_templates_item', 'settings', 'user_auth', 'user_auth_realm'];
        $optional = ['user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'];
        foreach ([...$required, ...$optional] as $table) {
            try {
                // Inspect the table this connection will actually mutate,
                // including a temporary table that shadows a permanent one.
                $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
                $definition = $query === false ? false : $query->fetch(\PDO::FETCH_NUM);
            } catch (\PDOException $error) {
                if (in_array($table, $optional, true) && ($error->errorInfo[1] ?? null) === 1146) {
                    continue;
                }
                throw $error;
            }
            if (!is_array($definition) || preg_match('/\)\s*ENGINE\s*=\s*InnoDB(?:\s|$)/i', (string) ($definition[1] ?? '')) !== 1) {
                throw new \RuntimeException('GPRINT mutations require InnoDB tables: ' . $table);
            }
        }
        // Dependency and authorization gap locks must also work when the
        // session default was configured as READ COMMITTED.
        if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
            throw new \RuntimeException('GPRINT transaction isolation could not be confirmed.');
        }
    }

    private function validate(string $value, string $field): void
    {
        if ($value === '' || mb_strlen($value, 'UTF-8') > 50 || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('GPRINT ' . $field . ' is invalid.');
        }
    }

    private function revision(int $id, string $name, string $text, string $hash): string
    {
        return hash('sha256', json_encode([$id, $name, $text, $hash], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function hydrate(array $row): GprintPreset
    {
        return new GprintPreset((int) $row['id'], (string) $row['name'], (string) $row['gprint_text'], (int) ($row['graphs'] ?? 0), (int) ($row['templates'] ?? 0), (string) $row['hash']);
    }

    private function presetQuery(): string
    {
        return 'SELECT gp.id, gp.name, gp.gprint_text, gp.hash,
            SUM(CASE WHEN ref.local_graph_id > 0 THEN 1 ELSE 0 END) graphs,
            SUM(CASE WHEN ref.local_graph_id = 0 THEN 1 ELSE 0 END) templates
            FROM graph_templates_gprint gp LEFT JOIN (' . $this->referenceQuery() . ') ref ON ref.gprint_id = gp.id';
    }

    private function referenceQuery(): string
    {
        return 'SELECT gprint_id, graph_template_id, local_graph_id FROM graph_templates_item GROUP BY gprint_id, graph_template_id, local_graph_id';
    }

    private function record(int $actorId, string $action, string $target, string $decision, string $outcome): void
    {
        try {
            $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId > 0 ? $actorId : null, $action, 'gprint_preset', $target, $decision, $outcome));
        } catch (\Throwable) {
            // Audit records intentionally omit preset values and cannot change persistence outcomes.
        }
    }
}
