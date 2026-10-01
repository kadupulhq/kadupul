<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Legacy;

use Kadupul\Navigation\Application\Port\LinkStore;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Domain\ExternalLink;
use Kadupul\Navigation\Domain\LinkConflict;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

final readonly class LegacyLinkStore implements LinkStore
{
    public function __construct(
        private DatabaseConnection $database,
        private LinkAccess $access,
        private AuditTrail $audit,
        private LegacyConfiguration $configuration,
        private string $projectDir
    ) {}
    public function files(): array
    {
        $directory = $this->projectDir . '/include/content';
        $files = [];
        foreach (glob($directory . '/*') ?: [] as $path) {
            $name = basename($path);
            if (is_file($path) && !is_link($path) && !in_array($name, ['README', 'index.php'], true)
                && preg_match('/^[A-Za-z0-9_.-]+$/D', $name) === 1) {
                $files[] = $name;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }
    public function defaultRows(): int
    {
        $rows = filter_var(
            $this->database->get()->query("SELECT value FROM settings WHERE name = 'num_rows_table'")->fetchColumn(),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5000]]
        );
        return $rows === false ? 25 : $rows;
    }
    public function list(array $filters): array
    {
        $sort = $filters['sort_column'];
        if (!in_array($sort, ['sortorder', 'title', 'contentfile', 'style', 'enabled'], true) || !in_array($filters['sort_direction'], ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid link list filters.');
        }
        $where = $filters['filter'] === '' ? '' : ' WHERE title LIKE ? OR contentfile LIKE ?';
        $params = $where === '' ? [] : ['%' . $filters['filter'] . '%', '%' . $filters['filter'] . '%'];
        $db = $this->database->get();
        $count = $db->prepare('SELECT COUNT(*) FROM external_links' . $where);
        $count->execute($params);
        $query = $db->prepare('SELECT id, sortorder, title, contentfile, style, extendedstyle, enabled, refresh FROM external_links' . $where . ' ORDER BY ' . $sort . ' ' . $filters['sort_direction'] . ', id ASC LIMIT ? OFFSET ?');
        $query->execute([...$params, $filters['limit'], ((int) $filters['page'] - 1) * $filters['limit']]);
        $links = array_map(static fn(array $row): ExternalLink => new ExternalLink((int) $row['id'], (int) $row['sortorder'], (string) $row['title'], (string) $row['contentfile'], (string) $row['style'], (string) $row['extendedstyle'], $row['enabled'] === 'on', (int) $row['refresh']), $query->fetchAll(\PDO::FETCH_ASSOC));
        return ['links' => $links, 'total' => (int) $count->fetchColumn()];
    }
    public function snapshot(): array
    {
        $rows = $this->rows(false);
        return ['links' => array_map($this->hydrate(...), $rows), 'revision' => $this->revision($rows)];
    }
    public function save(int $actorId, ?int $id, array $fields, string $revision): int
    {
        $values = ExternalLink::validate($fields, $this->files());
        return $this->write($actorId, 'save', function (\PDO $db) use ($actorId, $id, $values, $revision, $fields): int {
            $rows = $this->checkedRows($revision);
            if ($values['style'] === 'CONSOLE' && $fields['consolesection'] !== ExternalLink::NEW_SECTION_SELECTION && $fields['consolesection'] !== ''
                && !in_array($fields['consolesection'], ['External Links', ...array_column($rows, 'extendedstyle')], true)) {
                throw new \InvalidArgumentException('Invalid console section.');
            }
            $ids = array_column($rows, 'id');
            if ($id !== null && !in_array($id, $ids, true)) {
                throw new LinkConflict('Link no longer exists.');
            }
            // Legacy saves append even edited links to the end; keep that visible ordering contract.
            $order = ($rows === [] ? 0 : max(array_column($rows, 'sortorder'))) + 1;
            if ($order > 4294967295) {
                throw new \RuntimeException('Link order capacity exceeded.');
            }
            if ($id === null) {
                $db->prepare('INSERT INTO external_links (title, contentfile, style, extendedstyle, enabled, refresh, sortorder) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...array_values($values), $order]);
                $id = (int) $db->lastInsertId();
            } else {
                $db->prepare('UPDATE external_links SET title = ?, contentfile = ?, style = ?, extendedstyle = ?, enabled = ?, refresh = ?, sortorder = ? WHERE id = ?')
                    ->execute([...array_values($values), $order, $id]);
            }
            if ($id < 1 || $id > 16767215) {
                throw new \RuntimeException('Link authorization realm capacity exceeded.');
            }
            $db->prepare('REPLACE INTO user_auth_realm (user_id, realm_id) VALUES (?, ?)')->execute([$actorId, $id + 10000]);
            return $id;
        });
    }
    public function mutate(int $actorId, array $ids, string $operation, string $revision): void
    {
        if (!in_array($operation, ['delete', 'enable', 'disable', 'up', 'down'], true) || $ids === [] || count($ids) > 100
            || array_filter($ids, static fn($id): bool => !is_int($id) || $id < 1 || $id > 16767215) !== [] || count(array_unique($ids)) !== count($ids)
            || (in_array($operation, ['up', 'down'], true) && count($ids) !== 1)) {
            throw new \InvalidArgumentException('Invalid link selection.');
        }
        $this->write($actorId, $operation, function (\PDO $db) use ($ids, $operation, $revision): void {
            $rows = $this->checkedRows($revision);
            if (array_diff($ids, array_column($rows, 'id')) !== []) {
                throw new LinkConflict('Link no longer exists.');
            }
            if (in_array($operation, ['up', 'down'], true)) {
                $ordered = $rows;
                usort($ordered, static fn(array $a, array $b): int => [$a['sortorder'], $a['id']] <=> [$b['sortorder'], $b['id']]);
                $index = array_search($ids[0], array_column($ordered, 'id'), true);
                $next = $index + ($operation === 'up' ? -1 : 1);
                if (isset($ordered[$next])) {
                    [$ordered[$index], $ordered[$next]] = [$ordered[$next], $ordered[$index]];
                    // Consecutive positions repair historical gaps/duplicates while preserving every other row's relative order.
                    foreach ($ordered as $i => $row) {
                        $db->prepare('UPDATE external_links SET sortorder = ? WHERE id = ?')->execute([$i + 1, $row['id']]);
                    }
                }
                return;
            }
            foreach ($ids as $id) {
                if ($operation === 'delete') {
                    $db->prepare('DELETE FROM external_links WHERE id = ?')->execute([$id]);
                    $db->prepare('DELETE FROM user_auth_realm WHERE realm_id = ?')->execute([$id + 10000]);
                    $db->prepare('DELETE FROM user_auth_group_realm WHERE realm_id = ?')->execute([$id + 10000]);
                } else {
                    $db->prepare('UPDATE external_links SET enabled = ? WHERE id = ?')->execute([$operation === 'enable' ? 'on' : '', $id]);
                }
            }
            // Bulk deletion intentionally keeps surviving legacy sort values.
        });
    }
    private function write(int $actorId, string $action, callable $operation): mixed
    {
        $db = $this->database->get();
        $started = false;
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $this->assertTransactional($db);
            if ($db->inTransaction() || !$db->beginTransaction()) {
                throw new \RuntimeException('Link transaction unavailable.');
            }
            $started = true;
            $this->access->assertCurrent($actorId);
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $result = $operation($db);
            if (!$db->commit()) {
                throw new \RuntimeException('Link commit was not confirmed.');
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
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'navigation.link.' . $action, 'links', 'links', $decision, $outcome));
            } catch (\Throwable) { /* Audit failure cannot change a confirmed persistence result. */
            }
        }
    }
    private function assertTransactional(\PDO $db): void
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Links must be changed on the primary collector.');
        }
        $query = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        foreach (['external_links', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            $query->execute([$table]);
            if (strcasecmp((string) $query->fetchColumn(), 'InnoDB') !== 0) {
                throw new \RuntimeException('Link writes require transactional tables.');
            }
        }
    }
    private function rows(bool $lock): array
    {
        $suffix = $lock && $this->database->get()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $rows = $this->database->get()->query('SELECT id, sortorder, title, contentfile, style, extendedstyle, enabled, refresh FROM external_links ORDER BY id' . $suffix)->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'sortorder' => (int) $row['sortorder'], 'title' => (string) $row['title'], 'contentfile' => (string) $row['contentfile'], 'style' => (string) $row['style'], 'extendedstyle' => (string) $row['extendedstyle'], 'enabled' => (string) $row['enabled'], 'refresh' => (int) $row['refresh']], $rows);
    }
    private function checkedRows(string $revision): array
    {
        $rows = $this->rows(true);
        if (!hash_equals($this->revision($rows), $revision)) {
            throw new LinkConflict('Links changed since you opened this form. Reload before saving.');
        }
        return $rows;
    }
    private function revision(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    private function hydrate(array $row): ExternalLink
    {
        return new ExternalLink($row['id'], $row['sortorder'], $row['title'], $row['contentfile'], $row['style'], $row['extendedstyle'], $row['enabled'] === 'on', $row['refresh']);
    }
}
