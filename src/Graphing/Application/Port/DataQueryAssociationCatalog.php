<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What add_data_query.php reads: the device and data query an id names, whether they are already associated, and the lists. */
interface DataQueryAssociationCatalog
{
    /**
     * @param string $id as typed; compared as a number, so "1e0" is device 1 and "1.5" is none
     */
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogDevice;

    /**
     * @param string $id as typed; compared as a number
     */
    public function findDataQuery(OperatorDatabase $database, string $id): ?CatalogChoice;

    /** Whether this device has this data query with this very reindex method. */
    public function isAssociated(OperatorDatabase $database, int $deviceId, int $dataQueryId, ReindexMethod $method): bool;

    /** @return list<CatalogDevice> every device, ordered by id */
    public function devices(OperatorDatabase $database): array;

    /** @return list<CatalogChoice> every data query, ordered by id */
    public function dataQueries(OperatorDatabase $database): array;
}
