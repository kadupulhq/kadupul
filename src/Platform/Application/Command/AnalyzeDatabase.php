<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\Platform\Application\Port\Clock;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\ReadModel\AnalysisReport;

final readonly class AnalyzeDatabase
{
    public function __construct(
        private MaintenanceTarget $target,
        private DatabaseMaintenance $maintenance,
        private Clock $clock,
    ) {}

    /** @param ?string $operator account to act as; null means the admin_user setting */
    public function __invoke(bool $local, ?string $operator): AnalysisReport
    {
        $target = $this->target->select($local, $operator, MaintenanceRealm::Utilities)->target;
        $start = $this->clock->now();
        $noBinlog = $this->maintenance->binlogEnabled($target);
        $tables = [];
        foreach ($this->maintenance->tables($target) as $table) {
            $tables[] = ['name' => $table, 'ok' => $this->maintenance->analyze($target, $table, $noBinlog)];
        }
        // Whole-second timestamps, as the script's time() - time() reported.
        $seconds = $this->clock->now()->getTimestamp() - $start->getTimestamp();
        if ($tables !== []) {
            $this->maintenance->recordStats($target, 'ANALYSIS STATS: Analyzing Kadupul Tables Complete.  Total time ' . $seconds . ' seconds.');
        }

        return new AnalysisReport($target === DatabaseTarget::Main, $noBinlog, $tables, $seconds);
    }
}
