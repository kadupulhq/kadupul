<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\BrokenGraphCatalog;
use Kadupul\Graphing\Application\Port\GraphRemover;
use Kadupul\Graphing\Application\Port\GraphTitle;

/** Reports, or with $remove removes, the graphs that have an item pointing at a missing data source, as remove_broken_graphs.php did. */
final readonly class RemoveBrokenGraphs
{
    private const string ACTION = 'graph.broken.remove';

    /** Graphs per worker, so a large template is neither one payload nor one process per graph. */
    private const int BATCH = 500;

    public function __construct(private GraphCommandAccess $access, private BrokenGraphCatalog $catalog, private GraphRemover $remover) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $remove false only reports
     * @throws GraphManagementDenied
     */
    public function __invoke(bool $local, ?string $operator, bool $remove): BrokenGraphReport
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, $remove);
        $groups = $this->catalog->find($scope->database);
        if (!$remove || $groups === []) {
            return new BrokenGraphReport($scope->database, $groups, $remove, 0);
        }
        $correlation = $this->access->correlation();
        $removed = 0;
        $total = 0;
        try {
            foreach ($groups as $group) {
                $total += count($group->graphs);
                foreach (array_chunk($group->graphs, self::BATCH) as $batch) {
                    $removed += $this->remover->remove($scope->database, array_map(static fn(GraphTitle $graph): int => $graph->localGraphId, $batch));
                }
            }
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'graphs', (string) $total, $removed === $total);
        }

        return new BrokenGraphReport($scope->database, $groups, true, $removed);
    }
}
