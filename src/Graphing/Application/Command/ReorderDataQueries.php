<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\DataQueryIndexCatalog;
use Kadupul\Graphing\Application\Port\DataQueryIndexReorderer;
use Kadupul\Graphing\Domain\DataQuerySelection;

/** Rewrites the stored index of data query data sources in bulk, as reorder_data_query.php did. Main collector only. */
final readonly class ReorderDataQueries
{
    private const string ACTION = 'data-query.reorder';

    public function __construct(
        private GraphCommandAccess $access,
        private DataQueryIndexCatalog $catalog,
        private DataQueryIndexReorderer $reorderer,
    ) {}

    /** reorder_data_query.php refused a remote collector before it read any argument, --help included. */
    public function refusesThisCollector(): bool
    {
        return $this->access->isRemoteCollector();
    }

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $apply false reads the same rows and changes none
     * @throws MainCollectorOnly
     * @throws GraphManagementDenied
     */
    public function __invoke(DataQuerySelection $selection, ?string $operator, bool $apply): DataQueryReorderReport
    {
        if ($this->refusesThisCollector()) {
            throw new MainCollectorOnly();
        }
        // host.php needs realm 3 to reindex a device's data queries.
        $scope = $this->access->select(self::ACTION, false, $operator, $apply, ManagedArea::Devices);
        $items = $this->catalog->select($scope->database, $selection);
        if (!$apply || $items === []) {
            return new DataQueryReorderReport($scope->database, $items, [], false, !$apply);
        }
        $correlation = $this->access->correlation();
        $result = null;
        try {
            $result = $this->reorderer->reorder($scope->database, $items);
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'data-query-indexes', (string) count($items), $result !== null && !$result->unresolved && count($result->indexOn) === count($items));
        }

        return new DataQueryReorderReport($scope->database, $items, $result->indexOn, $result->unresolved, false);
    }
}
