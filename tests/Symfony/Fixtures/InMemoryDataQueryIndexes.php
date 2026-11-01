<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\DataQueryIndexCatalog;
use Kadupul\Graphing\Application\Port\DataQueryIndexItem;
use Kadupul\Graphing\Application\Port\DataQueryIndexReorderer;
use Kadupul\Graphing\Application\Port\DataQueryReorderResult;
use Kadupul\Graphing\Domain\DataQuerySelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** A catalog and reorderer over a fixed item list, recording what it is asked to reorder. */
final class InMemoryDataQueryIndexes implements DataQueryIndexCatalog, DataQueryIndexReorderer
{
    /** @var list<DataQuerySelection> */
    public array $selections = [];

    /** @var list<list<DataQueryIndexItem>> */
    public array $runs = [];

    /**
     * @param list<DataQueryIndexItem> $items
     * @param ?int $stopsAt position of the item whose sort field cannot be determined, null for none
     */
    public function __construct(private readonly array $items, private readonly ?int $stopsAt = null, private readonly bool $fails = false) {}

    #[\Override]
    public function select(OperatorDatabase $database, DataQuerySelection $selection): array
    {
        $this->selections[] = $selection;

        return $this->items;
    }

    #[\Override]
    public function reorder(OperatorDatabase $database, array $items): DataQueryReorderResult
    {
        $this->runs[] = $items;
        if ($this->fails) {
            throw new \RuntimeException('Data query reorder outcome is unknown.');
        }
        $done = array_slice($items, 0, $this->stopsAt ?? count($items));

        return new DataQueryReorderResult(array_map(static fn(DataQueryIndexItem $item): string => 'ifName' . $item->snmpQueryId, $done), $this->stopsAt !== null);
    }
}
