<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What add_datasource.php should have read: the device and data template an id names. */
interface DataSourceCreationCatalog
{
    /**
     * @param string $id as typed; compared as a number, so "1e0" is device 1 and "1.5" is none
     * @return ?CatalogChoice the device, named by its hostname
     */
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogChoice;

    /**
     * @param string $id as typed; compared as a number
     */
    public function findDataTemplate(OperatorDatabase $database, string $id): ?CatalogChoice;
}
