<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Application\Port\GraphTitleCatalog;
use Kadupul\Graphing\Application\Port\GraphTitleReapplier;
use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** A catalog and reapplier over a fixed graph list, recording each batch it is asked to rename. */
final class InMemoryGraphTitles implements GraphTitleCatalog, GraphTitleReapplier
{
    /** @var list<GraphSelection> */
    public array $selections = [];

    /** @var list<list<int>> */
    public array $batches = [];

    /** @param list<GraphTitle> $graphs @param ?int $finishes graphs the reapplier finishes per call, null for all */
    public function __construct(private readonly array $graphs, private readonly ?int $finishes = null, private readonly bool $fails = false) {}

    #[\Override]
    public function select(OperatorDatabase $database, GraphSelection $selection): array
    {
        $this->selections[] = $selection;

        return $this->graphs;
    }

    #[\Override]
    public function reapply(OperatorDatabase $database, array $localGraphIds): int
    {
        $this->batches[] = $localGraphIds;
        if ($this->fails) {
            throw new \RuntimeException('Graph title outcome is unknown.');
        }

        return min(count($localGraphIds), $this->finishes ?? count($localGraphIds));
    }
}
