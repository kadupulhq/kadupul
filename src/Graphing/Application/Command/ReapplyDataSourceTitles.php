<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\DataSourceTitle;
use Kadupul\Graphing\Application\Port\DataSourceTitleCatalog;
use Kadupul\Graphing\Application\Port\DataSourceTitleReapplier;
use Kadupul\Graphing\Domain\GraphSelection;

final readonly class ReapplyDataSourceTitles
{
    private const string ACTION = 'data-source.title.reapply';

    /** Data sources per worker, so a large selection is not one payload or one process per data source. */
    private const int BATCH = 500;

    public function __construct(
        private GraphCommandAccess $access,
        private DataSourceTitleCatalog $catalog,
        private DataSourceTitleReapplier $reapplier,
    ) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $apply false reads the same rows and changes none
     * @throws GraphManagementDenied
     */
    public function __invoke(GraphSelection $selection, bool $local, ?string $operator, bool $apply): DataSourceTitleReport
    {
        // data_sources.php needs the same realm as host.php.
        $scope = $this->access->select(self::ACTION, $local, $operator, $apply, ManagedArea::Devices);
        $dataSources = $this->catalog->select($scope->database, $selection);
        if (!$apply || $dataSources === []) {
            return new DataSourceTitleReport($scope->database, $dataSources, [], !$apply);
        }
        $correlation = $this->access->correlation();
        $renamed = [];
        $ok = false;
        try {
            foreach (array_chunk($dataSources, self::BATCH) as $batch) {
                $renamed = [...$renamed, ...$this->reapplier->reapply($scope->database, array_map(static fn(DataSourceTitle $source): int => $source->localDataId, $batch))];
            }
            $ok = count($renamed) === count($dataSources);
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'data-sources', (string) count($dataSources), $ok);
        }

        return new DataSourceTitleReport($scope->database, $dataSources, $renamed, false);
    }
}
