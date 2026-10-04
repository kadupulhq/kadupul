<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Platform\Contract\ReferenceWriteTransactionRunner;
use PDO;
use RuntimeException;

/** Primary-owned cleanup receipts survive a committed collector transfer. */
final class DeviceCollectorCleanup
{
    public function __construct(private readonly ReferenceWriteTransactionRunner $transactions) {}

    // Existing settings replication excludes poller_replicate* names.
    private const PREFIX = 'poller_replicate_device_cleanup_';

    /** @param list<int> $ids
     * @return array<int, array<int, int>> Device => old collector => expected owner.
     */
    public function pending(PDO $connection, array $ids, bool $lock = false): array
    {
        return $this->transactions->run($connection, function () use ($connection, $ids, $lock): array {
            if ($ids === []) {
                return [];
            }
            $clauses = $parameters = [];
            foreach ($ids as $id) {
                $this->identity($id, true);
                $clauses[] = "name LIKE ? ESCAPE '='";
                $parameters[] = str_replace('_', '=_', self::PREFIX . $id . '_') . '%';
            }
            $query = $this->execute($connection, 'SELECT name, value FROM settings WHERE ' . implode(' OR ', $clauses) . ' ORDER BY name' . ($lock ? ' FOR UPDATE' : ''), $parameters);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($query->errorCode() !== '00000') {
                throw new RuntimeException('Collector cleanup receipts unavailable');
            }
            $pending = [];
            foreach ($rows as $row) {
                if (!is_string($row['name'] ?? null) || preg_match('/\A' . self::PREFIX . '([1-9][0-9]*)_([1-9][0-9]*)\z/D', $row['name'], $match) !== 1
                    || !is_string($row['value'] ?? null) || preg_match('/\A[1-9][0-9]*\z/D', $row['value']) !== 1) {
                    throw new RuntimeException('Collector cleanup receipt invalid');
                }
                $id = $this->parseIdentity($match[1], true);
                $previous = $this->parseIdentity($match[2]);
                $target = $this->parseIdentity($row['value']);
                if (!in_array($id, $ids, true) || $previous <= 1 || isset($pending[$id][$previous])) {
                    throw new RuntimeException('Collector cleanup receipt invalid');
                }
                $pending[$id][$previous] = $target;
            }
            ksort($pending, SORT_NUMERIC);
            foreach ($pending as &$owners) {
                ksort($owners, SORT_NUMERIC);
            }
            unset($owners);
            return $pending;
        }, ['settings']);
    }

    /** The caller holds the primary device lock and owns the mutation transaction. */
    public function retain(PDO $connection, array $previousOwners, int $target): array
    {
        $this->identity($target);
        if (!$connection->inTransaction()) {
            throw new RuntimeException('Collector cleanup receipt transaction unavailable');
        }
        return $this->transactions->run($connection, function () use ($connection, $previousOwners, $target): array {
            $ids = array_keys($previousOwners);
            $existing = $this->pending($connection, $ids, true);
            $allExpected = [];
            foreach ($previousOwners as $id => $previous) {
                $this->identity($id, true);
                $this->identity($previous);
                $pending = $existing[$id] ?? [];
                foreach ($pending as $expected) {
                    if ($expected !== $previous) {
                        throw new RuntimeException('Collector cleanup ownership changed');
                    }
                }
                if ($previous !== $target && $previous > 1) {
                    $pending[$previous] = $target;
                }
                foreach ($pending as $owner => $expected) {
                    $name = self::PREFIX . $id . '_' . $owner;
                    if ($owner === $target) {
                        // A returning collector is authoritative again; never purge it.
                        $this->execute($connection, 'DELETE FROM settings WHERE BINARY name = BINARY ? AND BINARY value = BINARY ?', [$name, (string) $expected]);
                    } else {
                        $this->execute($connection, 'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, (string) $target]);
                    }
                }
                $expected = array_fill_keys(array_diff(array_keys($pending), [$target]), $target);
                ksort($expected, SORT_NUMERIC);
                if ($expected !== []) {
                    $allExpected[$id] = $expected;
                }
            }
            ksort($allExpected, SORT_NUMERIC);
            if ($this->pending($connection, $ids, true) !== $allExpected) {
                throw new RuntimeException('Collector cleanup receipt could not be confirmed');
            }
            return $allExpected;
        }, ['settings']);
    }

    /** Acknowledge only after the caller verifies complete remote absence. */
    public function acknowledge(PDO $connection, int $id, int $previous, int $target): void
    {
        if (!$connection->inTransaction() || $previous <= 1 || $previous === $target) {
            throw new RuntimeException('Collector cleanup acknowledgement unavailable');
        }
        $name = self::PREFIX . $this->identity($id, true) . '_' . $this->identity($previous);
        $this->identity($target);
        $query = $this->execute($connection, 'DELETE FROM settings WHERE BINARY name = BINARY ? AND BINARY value = BINARY ?', [$name, (string) $target]);
        if ($query->rowCount() !== 1 || $query->errorCode() !== '00000') {
            throw new RuntimeException('Collector cleanup acknowledgement changed');
        }
        $read = $this->execute($connection, 'SELECT value FROM settings WHERE BINARY name = BINARY ?', [$name]);
        $rows = $read->fetchAll(PDO::FETCH_COLUMN);
        if ($read->errorCode() !== '00000' || $rows !== []) {
            throw new RuntimeException('Collector cleanup acknowledgement could not be confirmed');
        }
    }

    private function execute(PDO $connection, string $sql, array $parameters): \PDOStatement
    {
        $query = $connection->prepare($sql);
        if ($query === false || $connection->errorCode() !== '00000' || !$query->execute($parameters) || $query->errorCode() !== '00000') {
            throw new RuntimeException('Collector cleanup receipt operation unavailable');
        }
        return $query;
    }

    private function identity(int $value, bool $device = false): int
    {
        if ($value < 1 || $value > ($device ? 16777215 : 65535)) {
            throw new RuntimeException('Collector cleanup identity invalid');
        }
        return $value;
    }

    private function parseIdentity(string $value, bool $device = false): int
    {
        if (strlen($value) > ($device ? 8 : 5) || (string) (int) $value !== $value) {
            throw new RuntimeException('Collector cleanup identity invalid');
        }
        return $this->identity((int) $value, $device);
    }
}
