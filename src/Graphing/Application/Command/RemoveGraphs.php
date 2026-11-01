<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GraphRemovalCatalog;
use Kadupul\Graphing\Application\Port\GraphRemover;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Domain\GraphRemovalFilter;

/**
 * Counts, lists or removes the graphs a filter selects, as remove_graphs.php did. Counting or removing
 * needs --all or at least one selector; a refusal is reported, not thrown.
 */
final readonly class RemoveGraphs
{
    private const string ACTION = 'graph.remove';

    /** Graphs per worker, so a large selection is neither one payload nor one process per graph. */
    private const int BATCH = 500;

    public function __construct(private GraphCommandAccess $access, private GraphRemovalCatalog $catalog, private GraphRemover $remover) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @throws GraphManagementDenied
     */
    public function __invoke(bool $local, ?string $operator, GraphRemovalFilter $filter, GraphRemovalMode $mode, bool $keepDataSources): GraphRemovalReport
    {
        $remove = $mode === GraphRemovalMode::Remove;
        $scope = $this->access->select(self::ACTION, $local, $operator, $remove);
        // Counting or removing everything takes --all; only a listing may omit every selector.
        if ($mode !== GraphRemovalMode::Listing && !$filter->all && $filter->matchesEverything()) {
            return new GraphRemovalReport($scope->database, $mode, [], $keepDataSources, 0, true);
        }
        $graphs = $this->catalog->graphs($scope->database, $filter);
        if (!$remove || $graphs === []) {
            return new GraphRemovalReport($scope->database, $mode, $graphs, $keepDataSources, 0);
        }
        $correlation = $this->access->correlation();
        $removed = 0;
        try {
            foreach (array_chunk($graphs, self::BATCH) as $batch) {
                $removed += $this->remover->remove($scope->database, array_map(static fn(GraphTitle $graph): int => $graph->localGraphId, $batch), $keepDataSources);
            }
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'graphs', (string) count($graphs), $removed === count($graphs));
        }

        return new GraphRemovalReport($scope->database, $mode, $graphs, $keepDataSources, $removed);
    }
}
