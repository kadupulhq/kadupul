<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Associates a data query with a device through the legacy code that runs the query and its plugin hooks. */
interface DataQueryAssociator
{
    /**
     * Replaces the host_snmp_query row, then runs the data query once to fill
     * its cache with run_data_query(), as add_data_query.php did. A row that
     * was written stays when the query then fails, as it did there.
     *
     * @phpstan-impure
     * @throws \RuntimeException when the outcome is unknown, the worker failed, or the query run raised an error message
     */
    public function associate(OperatorDatabase $database, int $deviceId, int $dataQueryId, ReindexMethod $method): void;
}
