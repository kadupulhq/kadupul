<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\DataSourceTitle;
use Kadupul\Graphing\Application\Port\DataSourceTitleCatalog;
use Kadupul\Graphing\Application\Port\DataSourceTitleReapplier;
use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** A catalog and reapplier over a fixed data source list, recording each batch it is asked to rename. */
final class InMemoryDataSourceTitles implements DataSourceTitleCatalog, DataSourceTitleReapplier
{
    /** @var list<GraphSelection> */
    public array $selections = [];

    /** @var list<list<int>> */
    public array $batches = [];

    /** @param list<DataSourceTitle> $sources @param ?int $finishes data sources the reapplier finishes per call, null for all */
    public function __construct(private readonly array $sources, private readonly ?int $finishes = null, private readonly bool $fails = false) {}

    #[\Override]
    public function select(OperatorDatabase $database, GraphSelection $selection): array
    {
        $this->selections[] = $selection;

        return $this->sources;
    }

    #[\Override]
    public function reapply(OperatorDatabase $database, array $localDataIds): array
    {
        $this->batches[] = $localDataIds;
        if ($this->fails) {
            throw new \RuntimeException('Data source title outcome is unknown.');
        }

        // The new title is the old one with a suffix, so a test can tell which was printed.
        return array_map(static fn(int $id): string => 'Renamed ' . $id, array_slice($localDataIds, 0, $this->finishes ?? count($localDataIds)));
    }
}
