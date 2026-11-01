<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\DataQueryAssociationCatalog;
use Kadupul\Graphing\Application\Port\DataQueryAssociator;
use Kadupul\Graphing\Domain\ReindexMethod;

/** Associates a data query with a device and runs it once, as add_data_query.php did; an existing identical association is reported, not rewritten. */
final readonly class AssociateDataQuery
{
    private const string ACTION = 'device.data-query.associate';

    public function __construct(private GraphCommandAccess $access, private DataQueryAssociationCatalog $catalog, private DataQueryAssociator $associator) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param string $deviceId as typed, already known to be numeric
     * @param string $dataQueryId as typed, already known to be numeric
     * @throws GraphManagementDenied
     */
    public function __invoke(bool $local, ?string $operator, string $deviceId, string $dataQueryId, ReindexMethod $method): DataQueryAssociation
    {
        // host.php is the web page that adds a data query to a device: realm 3.
        $scope = $this->access->select(self::ACTION, $local, $operator, true, ManagedArea::Devices);
        $device = $this->catalog->findDevice($scope->database, $deviceId);
        if ($device === null) {
            return new DataQueryAssociation($scope->database, DataQueryAssociation::UNKNOWN_DEVICE);
        }
        $dataQuery = $this->catalog->findDataQuery($scope->database, $dataQueryId);
        if ($dataQuery === null) {
            return new DataQueryAssociation($scope->database, DataQueryAssociation::UNKNOWN_QUERY, $device);
        }
        if ($this->catalog->isAssociated($scope->database, $device->id, $dataQuery->id, $method)) {
            return new DataQueryAssociation($scope->database, DataQueryAssociation::ALREADY, $device, $dataQuery);
        }
        $correlation = $this->access->correlation();
        $status = DataQueryAssociation::FAILED;
        try {
            $this->associator->associate($scope->database, $device->id, $dataQuery->id, $method);
            $status = DataQueryAssociation::ASSOCIATED;
        } catch (\RuntimeException) {
            // Reported as the original's "Failed to add" line; the audit record below says the same.
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'device-data-query', $device->id . '.' . $dataQuery->id, $status === DataQueryAssociation::ASSOCIATED);
        }

        return new DataQueryAssociation($scope->database, $status, $device, $dataQuery);
    }
}
