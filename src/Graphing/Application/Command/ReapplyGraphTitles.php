<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Application\Port\GraphTitleCatalog;
use Kadupul\Graphing\Application\Port\GraphTitleReapplier;
use Kadupul\Graphing\Domain\GraphSelection;

final readonly class ReapplyGraphTitles
{
    private const string ACTION = 'graph.title.reapply';

    /** Graphs per worker, so a large selection is not one payload or one process per graph. */
    private const int BATCH = 500;

    public function __construct(
        private GraphCommandAccess $access,
        private GraphTitleCatalog $catalog,
        private GraphTitleReapplier $reapplier,
    ) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $apply false reads the same rows and changes none
     * @throws GraphManagementDenied
     */
    public function __invoke(GraphSelection $selection, bool $local, ?string $operator, bool $apply): GraphTitleReport
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, $apply);
        $graphs = $this->catalog->select($scope->database, $selection);
        if (!$apply || $graphs === []) {
            return new GraphTitleReport($scope->database, $graphs, 0, !$apply);
        }
        $correlation = $this->access->correlation();
        $reapplied = 0;
        $ok = false;
        try {
            foreach (array_chunk($graphs, self::BATCH) as $batch) {
                $reapplied += $this->reapplier->reapply($scope->database, array_map(static fn(GraphTitle $graph): int => $graph->localGraphId, $batch));
            }
            $ok = $reapplied === count($graphs);
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'graphs', (string) count($graphs), $ok);
        }

        return new GraphTitleReport($scope->database, $graphs, $reapplied, false);
    }
}
