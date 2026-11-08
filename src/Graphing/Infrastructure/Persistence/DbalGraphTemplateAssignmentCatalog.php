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
use Kadupul\Graphing\Application\Port\GraphTemplateAssignmentCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** DBAL implementation of GraphTemplateAssignmentCatalog, reading the local or the main database as the operator database selects. */
final readonly class DbalGraphTemplateAssignmentCatalog implements GraphTemplateAssignmentCatalog
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
    public function findGraphTemplate(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        return OperatorDeviceCatalog::choice($this->catalog->connection($database)->fetchNumeric('SELECT id, name FROM graph_templates WHERE id = ? ORDER BY id LIMIT 1', [$id]));
    }

    #[\Override]
    public function isAssociated(OperatorDatabase $database, int $deviceId, int $graphTemplateId): bool
    {
        return $this->catalog->connection($database)->fetchOne('SELECT host_id FROM host_graph WHERE graph_template_id = ? AND host_id = ?', [$graphTemplateId, $deviceId]) !== false;
    }

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        return $this->catalog->devices($database);
    }

    #[\Override]
    public function graphTemplates(OperatorDatabase $database): array
    {
        return OperatorDeviceCatalog::choices($this->catalog->connection($database)->fetchAllNumeric('SELECT id, name FROM graph_templates ORDER BY id'));
    }
}
