<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Creates a data source through the legacy code that applies the data template and fills the poller cache. */
interface DataSourceCreator
{
    /**
     * Saves the data_local row, applies the data template, refreshes the title
     * cache and, for $pushOut, pushes the device's fields out, as the body of
     * add_datasource.php did. Nothing is rolled back when a later step fails,
     * as there.
     *
     * @param int $deviceId 0 for a data source on no device
     * @param int $dataTemplateId 0 for a data source with no template
     * @param bool $pushOut whether the original called push_out_host(): its device id was not the text "0"
     * @return int the id of the new data source
     * @phpstan-impure
     * @throws \RuntimeException when the outcome is unknown or the worker failed
     */
    public function create(OperatorDatabase $database, int $deviceId, int $dataTemplateId, bool $pushOut): int;
}
