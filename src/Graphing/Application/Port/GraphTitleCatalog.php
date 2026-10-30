<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The graphs a bulk tool would act on, read from the database it works on. */
interface GraphTitleCatalog
{
    /**
     * @return list<GraphTitle> ordered by local graph id
     */
    public function select(OperatorDatabase $database, GraphSelection $selection): array;
}
