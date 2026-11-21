<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Application\ReadModel\RulePage;
use Kadupul\Alerting\Application\ReadModel\RuleView;

/**
 * Rules the actor may see: those whose data source the actor can view under
 * the data-source visibility policy, and those whose data source no longer
 * exists, so they can still be deleted. A rule on a data source the actor
 * cannot view is not returned, so its id reads as not found.
 */
interface RuleCatalog
{
    /** Rows per page: the num_rows_table setting, 1 to 5000, or 25. */
    public function rowsPerPage(): int;

    public function page(int $actorId, int $page): RulePage;

    public function find(int $actorId, int $ruleId): ?RuleView;
}
