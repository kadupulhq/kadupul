<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Closure;
use Kadupul\Platform\Infrastructure\Legacy\LegacyReferenceWriteTransaction;
use PDO;
use PDOStatement;
use RuntimeException;

/** Keeps buffered cache replacement within the current device assignment. */
final class PollerCacheBufferWrite
{
    /** @param list<int> $ids @param list<string|null|false> $items */
    public function write(PDO $primary, array $ids, array $items, int $poller, string $prefix, string $suffix, Closure $connect, Closure $unavailable): ?int
    {
        if ($poller < 1 || $poller > 65535) {
            throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
        }
        $ids = array_values(array_unique($ids));
        $identities = [];
        $records = [];
        foreach ($items as $item) {
            if ($item === '' || $item === null || $item === false) {
                continue;
            }
            // Inspect only the identity prefix; plugin fields remain byte-exact.
            if (!is_string($item) || preg_match('/\A\s*\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)\s*,/', $item, $match) !== 1
                || strlen($match[1]) > 10 || strlen($match[2]) > 5 || strlen($match[3]) > 8
                || (int) $match[1] < 1 || (int) $match[1] > 4294967295 || (int) $match[2] !== $poller
                || (int) $match[3] > 16777215) {
                throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
            }
            $id = (int) $match[1];
            $host = (int) $match[3];
            if (isset($identities[$id]) && $identities[$id] !== $host) {
                throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
            }
            $identities[$id] = $host;
            $records[] = $item;
        }
        foreach ($ids as $id) {
            if ($id < 1 || $id > 4294967295) {
                throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
            }
        }
        $selected = array_values(array_unique(array_merge($ids, array_keys($identities))));
        sort($selected, SORT_NUMERIC);
        if ($selected === []) {
            return (new LegacyReferenceWriteTransaction($primary))->run(fn(): int => $this->changed($primary), ['settings']);
        }
        return (new LegacyReferenceWriteTransaction($primary))->run(function () use ($primary, $ids, $selected, $identities, $records, $poller, $prefix, $suffix, $connect, $unavailable): int {
            $mapping = $this->mapping($primary, $selected, false);
            $hosts = array_values(array_unique(array_filter(array_values($mapping), static fn(int $id): bool => $id > 0)));
            sort($hosts, SORT_NUMERIC);
            $this->lockHosts($primary, $hosts, $poller);
            // Host-first ordering: a changed mapping refuses instead of taking
            // an additional host lock after data-source locks are acquired.
            if ($this->mapping($primary, $selected, true) !== $mapping) {
                throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
            }
            foreach ($mapping as $id => $host) {
                if ($host === 0 && $poller !== 1 || isset($identities[$id]) && $identities[$id] !== $host) {
                    throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
                }
            }
            $remote = $poller > 1 ? $connect() : null;
            if ($remote !== null && $remote !== false && !$remote instanceof PDO) {
                throw new RuntimeException('Polling cache update connection could not be confirmed.');
            }
            $statements = $this->statements($ids, $records, $poller, $prefix, $suffix);
            $apply = function (PDO $connection) use ($statements, $selected, $ids, $mapping, $identities, $poller): void {
                foreach ($statements as [$sql, $parameters]) {
                    $this->execute($connection, $sql, $parameters);
                }
                $this->confirmCache($connection, $selected, $ids, $mapping, $identities, $poller);
            };
            if ($remote instanceof PDO && $remote !== $primary) {
                (new LegacyReferenceWriteTransaction($remote))->run(function () use ($primary, $remote, $hosts, $poller, $apply): bool {
                    $this->lockHosts($remote, $hosts, $poller);
                    $apply($primary);
                    $apply($remote);
                    return true;
                }, ['host', 'poller_item']);
            } else {
                if ($poller > 1 && !$remote instanceof PDO) {
                    $unavailable();
                }
                $apply($primary);
            }
            return $this->changed($primary);
        }, ['host', 'data_local', 'poller_item', 'settings']);
    }

    private function changed(PDO $primary): int
    {
        $changed = time();
        $this->execute($primary, 'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['time_last_change_poller_item', $changed]);
        $stored = $this->rows($primary, 'SELECT value FROM settings WHERE name = ? FOR UPDATE', ['time_last_change_poller_item']);
        if (count($stored) !== 1 || (string) $stored[0]['value'] !== (string) $changed) {
            throw new RuntimeException('Polling cache timestamp could not be confirmed.');
        }
        return $changed;
    }

    /** @param list<int> $selected @param list<int> $cleanup @param array<int,int> $mapping @param array<int,int> $identities */
    private function confirmCache(PDO $database, array $selected, array $cleanup, array $mapping, array $identities, int $poller): void
    {
        $found = [];
        $cleaned = array_fill_keys($cleanup, true);
        foreach (array_chunk($selected, 500) as $chunk) {
            $rows = $this->rows($database, 'SELECT local_data_id, host_id, present FROM poller_item WHERE poller_id = ? AND local_data_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY local_data_id, rrd_name FOR UPDATE', array_merge([$poller], $chunk));
            foreach ($rows as $row) {
                $id = (int) $row['local_data_id'];
                if (!in_array($id, $chunk, true) || (string) $row['host_id'] !== (string) $mapping[$id]
                    || isset($cleaned[$id]) && (!isset($identities[$id]) || (string) $row['present'] !== '1')) {
                    throw new RuntimeException('Polling cache stored ownership could not be confirmed.');
                }
                if (isset($identities[$id]) && (string) $row['present'] === '1') {
                    $found[$id] = true;
                }
            }
        }
        foreach ($identities as $id => $host) {
            if (!isset($found[$id])) {
                throw new RuntimeException('Polling cache stored ownership could not be confirmed.');
            }
        }
    }

    /** @param list<int> $ids @return array<int,int> */
    private function mapping(PDO $database, array $ids, bool $lock): array
    {
        $result = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->rows($database, 'SELECT id, host_id FROM data_local WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : ''), $chunk);
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!in_array($id, $chunk, true) || isset($result[$id]) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $row['host_id']) || (int) $row['host_id'] > 16777215) {
                    throw new RuntimeException('Polling cache source identity could not be confirmed.');
                }
                $result[$id] = (int) $row['host_id'];
            }
        }
        ksort($result, SORT_NUMERIC);
        if (array_keys($result) !== $ids) {
            throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
        }
        return $result;
    }

    /** @param list<int> $hosts */
    private function lockHosts(PDO $database, array $hosts, int $poller): void
    {
        foreach (array_chunk($hosts, 500) as $chunk) {
            $rows = $this->rows($database, 'SELECT id, poller_id, deleted FROM host WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id FOR UPDATE', $chunk);
            $found = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!in_array($id, $chunk, true) || isset($found[$id]) || (string) $row['poller_id'] !== (string) $poller || $row['deleted'] !== '') {
                    throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
                }
                $found[$id] = true;
            }
            if (array_keys($found) !== $chunk) {
                throw new RuntimeException('Polling cache update could not be confirmed. Rebuild the cache.');
            }
        }
    }

    /** @param list<int> $ids @param list<string> $records @return list<array{string,array}> */
    private function statements(array $ids, array $records, int $poller, string $prefix, string $suffix): array
    {
        $statements = [];
        $selection = implode(', ', $ids);
        if ($selection !== '') {
            $statements[] = ["UPDATE poller_item SET present = 0 WHERE poller_id = ? AND local_data_id IN ($selection)", [$poller]];
        }
        $buffer = '';
        $length = 0;
        foreach ($records as $record) {
            $buffer .= ($buffer === '' ? ' ' : ', ') . $record;
            $length += strlen($record);
            if (strlen($prefix) + strlen($suffix) + $length > 256000 - 1024) {
                $statements[] = [$prefix . $buffer . $suffix, []];
                $buffer = '';
                $length = 0;
            }
        }
        if ($buffer !== '') {
            $statements[] = [$prefix . $buffer . $suffix, []];
        }
        if ($selection !== '') {
            $statements[] = ["DELETE FROM poller_item WHERE present = 0 AND poller_id = ? AND local_data_id IN ($selection)", [$poller]];
        }
        return $statements;
    }

    private function execute(PDO $database, string $sql, array $parameters): PDOStatement
    {
        $query = $database->prepare($sql);
        if (!$query instanceof PDOStatement || $database->errorCode() !== '00000' || !$query->execute($parameters) || $query->errorCode() !== '00000') {
            throw new RuntimeException('Polling cache database operation could not be confirmed.');
        }
        return $query;
    }

    /** @return list<array<string,mixed>> */
    private function rows(PDO $database, string $sql, array $parameters): array
    {
        $query = $this->execute($database, $sql, $parameters);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($query->errorCode() !== '00000' || !$query->closeCursor() || $query->errorCode() !== '00000') {
            throw new RuntimeException('Polling cache database read could not be confirmed.');
        }
        return $rows;
    }
}
