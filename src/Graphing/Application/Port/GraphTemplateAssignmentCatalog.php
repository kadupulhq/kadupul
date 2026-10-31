<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What add_graph_template.php reads: the device and graph template an id names, and whether they are already associated. */
interface GraphTemplateAssignmentCatalog
{
    /**
     * @param string $id as typed; compared as a number, so "1e0" is device 1 and "1.5" is none
     */
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogDevice;

    /**
     * @param string $id as typed; compared as a number
     */
    public function findGraphTemplate(OperatorDatabase $database, string $id): ?CatalogChoice;

    public function isAssociated(OperatorDatabase $database, int $deviceId, int $graphTemplateId): bool;

    /** @return list<CatalogDevice> every device, ordered by id */
    public function devices(OperatorDatabase $database): array;

    /** @return list<CatalogChoice> every graph template, ordered by id */
    public function graphTemplates(OperatorDatabase $database): array;
}
