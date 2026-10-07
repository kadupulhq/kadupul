<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\Platform\Application\ReadModel\AlterResult;
use Kadupul\Platform\Application\ReadModel\AuditOutcome;
use Kadupul\Platform\Application\ReadModel\AuditReport;
use Kadupul\Platform\Application\ReadModel\BaselineOutcome;
use Kadupul\Platform\Application\ReadModel\UpgradeOutput;
use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\TableAudit;

/** One AuditDatabase run: who, where, and what the version check did before the mode ran. */
final readonly class AuditRun
{
    public function __construct(
        public MaintenanceScope $scope,
        public string $correlation,
        public bool $apply,
        public ?UpgradeOutput $upgraded,
        public bool $upgradePlanned,
    ) {}

    /**
     * @param list<TableAudit> $tables
     * @param list<array{table: string, legacy: string, result: AlterResult, statement: ?string}> $alters
     * @param list<string> $imported
     */
    public function report(AuditMode $mode, ?BaselineOutcome $baseline, ?int $line = null, array $tables = [], array $alters = [], array $imported = [], ?string $path = null, ?bool $exported = null, ?string $uncreated = null): AuditReport
    {
        return new AuditReport(AuditOutcome::Completed, $mode, !$this->apply, $this->upgraded, $this->upgradePlanned, $baseline, $line, $tables, $alters, $imported, $path, $exported, $uncreated);
    }
}
