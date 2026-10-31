<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\BrokenGraphCatalog;
use Kadupul\Graphing\Application\Port\BrokenGraphGroup;
use Kadupul\Graphing\Application\Port\GraphRemover;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** A catalog of fixed broken-graph groups and a remover that records each batch it is asked to remove. */
final class InMemoryBrokenGraphs implements BrokenGraphCatalog, GraphRemover
{
    /** @var list<list<int>> */
    public array $batches = [];

    /** @var list<bool> */
    public array $kept = [];

    public int $reads = 0;

    /** @param list<BrokenGraphGroup> $groups @param ?int $finishes graphs finished per call, null for all */
    public function __construct(private readonly array $groups, private readonly ?int $finishes = null, private readonly bool $fails = false) {}

    #[\Override]
    public function find(OperatorDatabase $database): array
    {
        ++$this->reads;

        return $this->groups;
    }

    #[\Override]
    public function remove(OperatorDatabase $database, array $localGraphIds, bool $keepDataSources = false): int
    {
        $this->batches[] = $localGraphIds;
        $this->kept[] = $keepDataSources;
        if ($this->fails) {
            throw new \RuntimeException('Graph removal outcome is unknown.');
        }

        return min(count($localGraphIds), $this->finishes ?? count($localGraphIds));
    }
}
