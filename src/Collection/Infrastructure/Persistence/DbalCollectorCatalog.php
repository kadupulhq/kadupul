<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Collection\Application\Port\CollectorStore;
use Kadupul\Collection\Domain\Collector;
use Kadupul\Collection\Domain\CollectorId;
use Kadupul\Collection\Domain\CollectorStatus;

/**
 * Reads collector definitions from the poller table via DBAL.
 */
final readonly class DbalCollectorCatalog implements CollectorStore
{
    private const string SELECT_COLUMNS = 'SELECT id, name, hostname, status, disabled, processes, threads, sync_interval, last_update FROM poller';

    public function __construct(private Connection $database) {}

    #[\Override]
    public function findAll(): array
    {
        $rows = $this->database->fetchAllAssociative(self::SELECT_COLUMNS . ' ORDER BY id ASC');
        $collectors = [];
        foreach ($rows as $row) {
            $collector = self::hydrate($row);
            if ($collector !== null) {
                $collectors[] = $collector;
            }
        }

        return $collectors;
    }

    #[\Override]
    public function findById(CollectorId $id): ?Collector
    {
        $row = $this->database->fetchAssociative(
            self::SELECT_COLUMNS . ' WHERE id = ? LIMIT 1',
            [$id->toInt()],
        );

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ?Collector
    {
        $rawId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        if ($rawId < 1 || $rawId > CollectorId::MAX_ID) {
            return null;
        }

        $statusCode = is_numeric($row['status'] ?? null) ? (int) $row['status'] : 0;
        $status = CollectorStatus::tryFrom($statusCode) ?? CollectorStatus::New;

        return new Collector(
            CollectorId::fromInt($rawId),
            is_string($row['name'] ?? null) ? $row['name'] : '',
            is_string($row['hostname'] ?? null) ? $row['hostname'] : '',
            $status,
            ($row['disabled'] ?? '') === 'on',
            is_numeric($row['processes'] ?? null) ? max(1, (int) $row['processes']) : 1,
            is_numeric($row['threads'] ?? null) ? max(1, (int) $row['threads']) : 1,
            is_numeric($row['sync_interval'] ?? null) ? max(0, (int) $row['sync_interval']) : 0,
            is_string($row['last_update'] ?? null) ? $row['last_update'] : '',
        );
    }
}
