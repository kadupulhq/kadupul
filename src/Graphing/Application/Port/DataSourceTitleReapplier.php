<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Reapplies the suggested names to data sources through the legacy code, which plugins hook. */
interface DataSourceTitleReapplier
{
    /**
     * @param list<int> $localDataIds
     * @return list<string> the title stored for each data source it finished, in the order given
     * @throws \RuntimeException when the outcome is unknown
     */
    public function reapply(OperatorDatabase $database, array $localDataIds): array;
}
