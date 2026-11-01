<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\DataQuerySelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The data query index items a reorder would act on, read from the database it works on. */
interface DataQueryIndexCatalog
{
    /**
     * @return list<DataQueryIndexItem> ordered by local data id
     */
    public function select(OperatorDatabase $database, DataQuerySelection $selection): array;
}
