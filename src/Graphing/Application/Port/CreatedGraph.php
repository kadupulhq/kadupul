<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** What creating a graph produced. */
final readonly class CreatedGraph
{
    /**
     * @param ?int $firstDataSource the data source the graph's first item reads, found before the new ones are listed
     * @param list<int> $dataSourceIds the data sources the creation made
     */
    public function __construct(public int $graphId, public ?int $firstDataSource, public array $dataSourceIds) {}
}
