<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The graphs that lack a data source, grouped by graph template. */
interface BrokenGraphCatalog
{
    /** @return list<BrokenGraphGroup> in graph template id order */
    public function find(OperatorDatabase $database): array;
}
