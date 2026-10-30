<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\GraphItemRepairs;
use Kadupul\Graphing\Application\Port\RepairGraph;
use Kadupul\Graphing\Application\Port\RepairRrdTarget;
use Kadupul\Graphing\Application\Port\WrongItem;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Graph item repairs over fixed rows, recording the lookups and each repoint it is asked for. */
final class InMemoryGraphItemRepairs implements GraphItemRepairs
{
    /** @var list<string> */
    public array $repoints = [];

    public int $targetLookups = 0;

    /**
     * @param list<RepairGraph> $graphs
     * @param array<int, RepairRrdTarget> $targets by device id
     * @param array<int, list<WrongItem>> $wrong by graph id
     */
    public function __construct(private readonly array $graphs, private readonly array $targets, private readonly array $wrong) {}

    #[\Override]
    public function graphs(OperatorDatabase $database, int $graphTemplateId, ?int $hostId): array
    {
        return array_values(array_filter($this->graphs, static fn(RepairGraph $graph): bool => $hostId === null || $graph->hostId === $hostId));
    }

    #[\Override]
    public function rrdTarget(OperatorDatabase $database, int $hostId, int $dataTemplateId): ?RepairRrdTarget
    {
        ++$this->targetLookups;

        return $this->targets[$hostId] ?? null;
    }

    #[\Override]
    public function wrongItems(OperatorDatabase $database, int $graphId, int $graphTemplateId, int $dataTemplateId, int $rrdId): array
    {
        return $this->wrong[$graphId] ?? [];
    }

    #[\Override]
    public function repoint(OperatorDatabase $database, int $graphId, int $graphTemplateId, int $rrdId, array $itemIds): void
    {
        $this->repoints[] = $graphId . '>' . $rrdId . ':' . implode(',', $itemIds);
    }
}
