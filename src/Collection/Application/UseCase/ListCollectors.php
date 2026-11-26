<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\UseCase;

use Kadupul\Collection\Application\Port\CollectorStore;
use Kadupul\Collection\Contract\CollectorCatalog;
use Kadupul\Collection\Contract\CollectorSummary;
use Kadupul\Collection\Domain\Collector;
use Kadupul\Collection\Domain\CollectorId;

final readonly class ListCollectors implements CollectorCatalog
{
    public function __construct(private CollectorStore $store) {}

    /**
     * @return list<CollectorSummary>
     */
    public function list(bool $onlyEnabled = false): array
    {
        $summaries = [];
        foreach ($this->store->findAll() as $collector) {
            if ($onlyEnabled && $collector->disabled) {
                continue;
            }
            $summaries[] = self::summarize($collector);
        }

        return $summaries;
    }

    #[\Override]
    public function listAll(): array
    {
        return $this->list(false);
    }

    #[\Override]
    public function findEnabled(): array
    {
        return $this->list(true);
    }

    #[\Override]
    public function findById(int $id): ?CollectorSummary
    {
        try {
            $collectorId = CollectorId::fromInt($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $collector = $this->store->findById($collectorId);

        return $collector === null ? null : self::summarize($collector);
    }

    private static function summarize(Collector $collector): CollectorSummary
    {
        return new CollectorSummary(
            $collector->id->toInt(),
            $collector->name,
            $collector->hostname,
            $collector->status->value,
            $collector->status->label(),
            $collector->disabled,
            $collector->processes,
            $collector->threads,
            $collector->syncInterval,
            $collector->lastUpdate,
        );
    }
}
