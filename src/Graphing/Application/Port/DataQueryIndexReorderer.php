<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Rewrites the stored index of data query items through the legacy data query code. */
interface DataQueryIndexReorderer
{
    /**
     * Works through the items in order and stops at the first one whose sort
     * field cannot be determined, as the script did.
     *
     * @param list<DataQueryIndexItem> $items
     * @throws \RuntimeException when the outcome of the run is unknown
     */
    public function reorder(OperatorDatabase $database, array $items): DataQueryReorderResult;
}
