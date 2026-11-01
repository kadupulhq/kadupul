<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Writes what add_graphs.php wrote, through the legacy code that owns the graph and data source APIs and their plugin hooks. */
interface GraphWriter
{
    /**
     * Associates a data query with a device and runs it once to fill the cache.
     *
     * @phpstan-impure
     * @throws \RuntimeException when the outcome is not known to be a success
     */
    public function associateQuery(OperatorDatabase $database, int $deviceId, int $queryId, int $reindexMethod): void;

    /**
     * Creates the graph and its data sources, sets the title when one is given,
     * and pushes the data sources out to the poller.
     *
     * @phpstan-impure
     * @return ?CreatedGraph null when the whitelist refused the graph
     * @throws \RuntimeException when the outcome is not known to be a success
     */
    public function createGraph(OperatorDatabase $database, GraphDraft $graph): ?CreatedGraph;

    /**
     * @phpstan-impure
     * @throws \RuntimeException when the title was not saved
     */
    public function retitle(OperatorDatabase $database, int $graphId, string $title): void;
}
