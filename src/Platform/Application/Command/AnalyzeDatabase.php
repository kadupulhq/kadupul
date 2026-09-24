<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Application\Port\Clock;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\ReadModel\AnalysisReport;

final readonly class AnalyzeDatabase
{
    public function __construct(
        private ConsoleOperator $operator,
        private DatabaseMaintenance $maintenance,
        private Clock $clock,
    ) {}

    /** @param ?string $operator account to act as; null means the admin_user setting */
    public function __invoke(bool $local, ?string $operator): AnalysisReport
    {
        // select() reads '' as "no name" and would fall back to admin_user.
        if ($operator === '') {
            throw new InstallationAccessDenied();
        }
        // analyze_database.php only switched to the main database on a remote
        // collector, and only when --local was not given; every other case,
        // including the primary with --local, stayed on the local connection.
        $target = !$local && $this->maintenance->isRemoteCollector() ? DatabaseTarget::Main : DatabaseTarget::Local;
        $this->operator->select($operator, $target === DatabaseTarget::Main ? OperatorDatabase::Main : OperatorDatabase::Local);
        $actor = $this->operator->actor();
        if ($actor === null || !$this->operator->canAdministerInstallation($actor)) {
            throw new InstallationAccessDenied();
        }
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
