<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\DataQueryAssociationCatalog;
use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** DBAL implementation of DataQueryAssociationCatalog, reading the local or the main database as the operator database selects. */
final readonly class DbalDataQueryAssociationCatalog implements DataQueryAssociationCatalog
{
    private OperatorDeviceCatalog $catalog;

    public function __construct(Connection $localConnection, Connection $mainConnection)
    {
        $this->catalog = new OperatorDeviceCatalog($localConnection, $mainConnection);
    }

    #[\Override]
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogDevice
    {
        return $this->catalog->findDevice($database, $id);
    }

    #[\Override]
    public function findDataQuery(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        return OperatorDeviceCatalog::choice($this->catalog->connection($database)->fetchNumeric('SELECT id, name FROM snmp_query WHERE id = ? ORDER BY id LIMIT 1', [$id]));
    }

    #[\Override]
    public function isAssociated(OperatorDatabase $database, int $deviceId, int $dataQueryId, ReindexMethod $method): bool
    {
        // The method is compared as a number, whatever way it was typed.
        return $this->catalog->connection($database)->fetchOne('SELECT host_id FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ? AND reindex_method = ?', [$deviceId, $dataQueryId, $method->value]) !== false;
    }

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        return $this->catalog->devices($database);
    }

    #[\Override]
    public function dataQueries(OperatorDatabase $database): array
    {
        return OperatorDeviceCatalog::choices($this->catalog->connection($database)->fetchAllNumeric('SELECT id, name FROM snmp_query ORDER BY id'));
    }
}
