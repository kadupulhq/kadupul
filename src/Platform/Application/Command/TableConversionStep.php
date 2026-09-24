<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\TableCatalog;
use Kadupul\Platform\Application\Port\TableConversion;
use Kadupul\Platform\Application\ReadModel\ConversionOutcome;
use Kadupul\Platform\Application\ReadModel\TableOutcome;
use Kadupul\Platform\Application\ReadModel\TableResult;
use Kadupul\Platform\Domain\Schema\ConversionOptions;
use Kadupul\Platform\Domain\Schema\TableSkip;

/**
 * The conversion work itself, with no operator and no audit: ConvertTables
 * adds both around it, and the installer, which is its own authority during
 * an install, calls it directly.
 */
final readonly class TableConversionStep
{
    public function __construct(private TableConversion $conversion) {}

    /** Why InnoDB work cannot start on $target, or null when nothing stops it. */
    public function blocker(DatabaseTarget $target, ConversionOptions $options): ?ConversionOutcome
    {
        if (!$options->innodb) {
            return null;
        }
        if (!$this->conversion->innodbEnabled($target)) {
            return ConversionOutcome::InnodbDisabled;
        }

        return $this->conversion->filePerTable($target) ? null : ConversionOutcome::FilePerTableDisabled;
    }

    /**
     * @param TableCatalog $catalog the target's tableStatuses(), read once per run
     * @param bool $apply false builds the statement and runs none
     */
    public function __invoke(DatabaseTarget $target, string $name, TableCatalog $catalog, ConversionOptions $options, bool $apply): TableOutcome
    {
        $status = $catalog->status($name);
        $change = $options->decide($name, $status);
        if ($change instanceof TableSkip) {
            return new TableOutcome($name, $change === TableSkip::TooManyRows ? TableResult::TooLarge : TableResult::Skipped, $status?->rows, null, false);
        }
        // Only an exact key of the target's own catalog reaches DDL. The
        // original sent ALTER TABLE for a name the server did not list, which
        // failed; the failure is reported and logged without sending it.
        if (!$catalog->has($name)) {
            if ($apply) {
                $this->conversion->recordFailure($target, $change->legacyFailure($name));
            }

            return new TableOutcome($name, TableResult::Failed, null, null, $apply);
        }
        $statement = $this->conversion->statement($target, $name, $change);
        if (!$apply) {
            return new TableOutcome($name, TableResult::Planned, $status?->rows, $statement, false);
        }
        $ok = $this->conversion->convert($target, $name, $change);
        if (!$ok) {
            $this->conversion->recordFailure($target, $change->legacyFailure($name));
        }

        return new TableOutcome($name, $ok ? TableResult::Converted : TableResult::Failed, $status?->rows, $statement, true);
    }
}
