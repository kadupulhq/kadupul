<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\TableConversion;
use Kadupul\Platform\Application\ReadModel\ConversionOutcome;
use Kadupul\Platform\Application\ReadModel\ConversionReport;
use Kadupul\Platform\Application\ReadModel\TableResult;
use Kadupul\Platform\Domain\Schema\ConversionOptions;

final readonly class ConvertTables
{
    private const string ACTION = 'database.convert-tables';

    public function __construct(
        private MaintenanceTarget $target,
        private TableConversion $conversion,
        private TableConversionStep $step,
        private SchemaChangeAudit $audit,
    ) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $apply false builds every statement and runs none
     */
    public function __invoke(ConversionOptions $options, bool $local, ?string $operator, bool $apply): ConversionReport
    {
        // A dry run passes the same check: it reads the same schema.
        try {
            $scope = $this->target->select($local, $operator, MaintenanceRealm::Upgrade);
        } catch (InstallationAccessDenied $denied) {
            $this->audit->denied(self::ACTION, $denied, !$apply);

            throw $denied;
        }
        $main = $scope->target === DatabaseTarget::Main;
        // One read of the target's own schema; the step sends DDL only for names it has.
        $catalog = $this->conversion->tableStatuses($scope->target);
        $unknown = array_find($options->skip, static fn(string $name): bool => !$catalog->has($name));
        if ($unknown !== null) {
            return ConversionReport::stopped($main, ConversionOutcome::SkipTableMissing, !$apply, $unknown);
        }
        $blocker = $this->step->blocker($scope->target, $options);
        if ($blocker !== null) {
            return ConversionReport::stopped($main, $blocker, !$apply);
        }
        $correlation = $this->audit->correlation();
        $tables = [];
        // Deciding and applying table by table gives the same result as deciding
        // every table first: the catalog was read once and decide() is pure.
        foreach ($options->table === null ? $this->conversion->baseTables() : [$options->table] as $name) {
            $outcome = ($this->step)($scope->target, $name, $catalog, $options, $apply);
            if ($outcome->attempted) {
                $this->audit->statement($correlation, $scope->actor->id, self::ACTION, $scope->target, $name, $outcome->result === TableResult::Converted);
            }
            $tables[] = $outcome->line();
        }

        return new ConversionReport($main, ConversionOutcome::Completed, null, $tables, !$apply);
    }
}
