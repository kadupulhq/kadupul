<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\Platform\Application\Port\AuditBaselineStore;
use Kadupul\Platform\Application\Port\AuditCatalog;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\InstallationUpgrade;
use Kadupul\Platform\Application\Port\SchemaAudit;
use Kadupul\Platform\Application\ReadModel\AlterResult;
use Kadupul\Platform\Application\ReadModel\AuditOutcome;
use Kadupul\Platform\Application\ReadModel\AuditReport;
use Kadupul\Platform\Application\ReadModel\BaselineOutcome;
use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\InvalidAuditSchema;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\TableAlter;
use Kadupul\Platform\Domain\Schema\TableAudit;

final readonly class AuditDatabase
{
    private const string ACTION = 'database.audit';
    private const array BASELINE_TABLES = ['table_columns', 'table_indexes'];

    public function __construct(
        private MaintenanceTarget $target,
        private DatabaseMaintenance $maintenance,
        private SchemaAudit $schema,
        private AuditBaselineStore $baseline,
        private InstallationUpgrade $upgrade,
        private SchemaChangeAudit $audit,
    ) {}

    /** audit_database.php refused a remote collector before it read any argument, --help included. */
    public function refusesThisCollector(): bool
    {
        return $this->maintenance->isRemoteCollector();
    }

    /**
     * @param ?AuditMode $mode null when no mode was given, which prints the help after the version check
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $apply false reads the file and the schema and changes nothing, not even the two audit tables
     * @throws RemoteCollectorRefused before any lookup, when this installation is a remote collector
     */
    public function __invoke(?AuditMode $mode, bool $upgrade, ?string $operator, bool $apply): AuditReport
    {
        // Checked here as well as by the command: "local" below is main only
        // on the primary, so this is what keeps the collector's copy unaltered.
        if ($this->maintenance->isRemoteCollector()) {
            $this->audit->denied(self::ACTION, new InstallationAccessDenied(null, DatabaseTarget::Local), !$apply);

            throw new RemoteCollectorRefused();
        }
        // Always local: the script ran only on the primary, where local is main.
        $scope = $this->audit->select($this->target, self::ACTION, true, $operator, $apply);
        $correlation = $this->audit->correlation();
        // Loose on purpose, as audit_database.php:88 compared them.
        $behind = $this->schema->databaseVersion($scope->target) != $this->schema->codeVersion();
        if ($behind && !$upgrade) {
            return new AuditReport(AuditOutcome::UpgradeRequired, $mode, !$apply);
        }
        $upgraded = null;
        if ($behind && $apply) {
            $upgraded = $this->upgrade->run();
            $this->audit->step($correlation, $scope->actor->id, self::ACTION, $scope->target, 'upgrade', $upgraded->completed);
            // The original went on after a failed core upgrade, so --repair
            // sent its ALTERs against a half upgraded schema.
            if (!$upgraded->completed) {
                return new AuditReport(AuditOutcome::UpgradeFailed, $mode, false, $upgraded);
            }
        }
        $run = new AuditRun($scope, $correlation, $apply, $upgraded, $behind && !$apply);

        return match ($mode) {
            null => new AuditReport(AuditOutcome::NoMode, null, !$apply, $upgraded, $run->upgradePlanned),
            AuditMode::Load => $this->load($run),
            default => $this->audit($run, $mode),
        };
    }

    private function audit(AuditRun $run, AuditMode $mode): AuditReport
    {
        [$outcome, $baseline, $line, $uncreated] = $this->loadBaseline($run);
        if ($mode === AuditMode::Create || $outcome === BaselineOutcome::CreateFailed) {
            return $run->report($mode, $outcome, $line, uncreated: $uncreated);
        }
        $catalog = $this->schema->catalog($run->scope->target);
        // Only --report prints findings; the ported loops also decide slightly
        // differently without output (ColumnDrift's "Extra" branch).
        $output = $mode === AuditMode::Report;
        $tables = array_map(static fn(LiveTable $table): TableAudit => TableAudit::of($table, $baseline, $catalog->plugins, $output), $catalog->tables());
        if ($output) {
            return $run->report($mode, $outcome, $line, $tables);
        }
        $alters = [];
        foreach ($tables as $audited) {
            $read = $catalog->table($audited->table);
            $alter = $read === null ? null : $audited->alter($read->status);
            if ($alter !== null) {
                $alters[] = $this->alter($run, $alter, $read, $mode === AuditMode::Repair && $run->apply);
            }
        }

        return $run->report($mode, $outcome, $line, $tables, $alters);
    }

    /** @return array{table: string, legacy: string, result: AlterResult, statement: ?string} */
    private function alter(AuditRun $run, TableAlter $alter, LiveTable $read, bool $send): array
    {
        $statement = $alter->buildable() ? $this->schema->statement($run->scope->target, $alter) : null;
        if (!$send) {
            return ['table' => $alter->table, 'legacy' => $alter->legacy(), 'result' => AlterResult::Planned, 'statement' => $statement];
        }
        // An alter with no typed form is reported failed without a statement;
        // the server refused every such statement the original sent.
        $ok = $statement !== null && $this->schema->alter($run->scope->target, $alter, $read);
        $this->audit->statement($run->correlation, $run->scope->actor->id, self::ACTION, $run->scope->target, $alter->table, $ok);

        return ['table' => $alter->table, 'legacy' => $alter->legacy(), 'result' => $ok ? AlterResult::Altered : AlterResult::Failed, 'statement' => $statement];
    }

    /**
     * create_tables(): the file is read first, so a dry run can report it, then
     * the two tables are reset and, when the file parsed, replaced.
     *
     * @return array{0: BaselineOutcome, 1: AuditBaseline, 2: ?int, 3: ?string}
     */
    private function loadBaseline(AuditRun $run): array
    {
        $line = null;
        $uncreated = null;
        try {
            $baseline = $this->baseline->read();
        } catch (InvalidAuditSchema $invalid) {
            $baseline = false;
            $line = $invalid->lineNumber;
        }
        $outcome = match (true) {
            $baseline === null => BaselineOutcome::FileMissing,
            $baseline === false => BaselineOutcome::Unparsable,
            !$run->apply => BaselineOutcome::Planned,
            default => null,
        };
        if ($run->apply) {
            $uncreated = $this->reset($run);
            if ($uncreated !== null) {
                $outcome = BaselineOutcome::CreateFailed;
            } elseif ($outcome === null) {
                // Only a file that parsed reloads the two tables; a missing or
                // unparsable one leaves them as the reset did, and says so.
                $loaded = $this->baseline->replace($run->scope->target, $baseline);
                $this->auditBaseline($run, $loaded);
                $outcome = $loaded ? BaselineOutcome::Loaded : BaselineOutcome::LoadFailed;
            }
        }
        $usable = in_array($outcome, [BaselineOutcome::Loaded, BaselineOutcome::Planned], true);

        // A table left empty, as a failed load left it, lists no baseline at all.
        return [$outcome, $usable ? $baseline : AuditBaseline::empty(), $line, $uncreated];
    }

    private function load(AuditRun $run): AuditReport
    {
        $uncreated = $run->apply ? $this->reset($run) : null;
        if ($uncreated !== null) {
            return $run->report(AuditMode::Load, BaselineOutcome::CreateFailed, uncreated: $uncreated);
        }
        // Read after the reset, so the two audit tables are listed and
        // imported empty, as SHOW TABLES listed them for the script.
        $catalog = $this->schema->catalog($run->scope->target);
        $path = $this->baseline->dumpPath();
        if (!$run->apply) {
            return $run->report(AuditMode::Load, null, null, [], [], self::planned($catalog), $path);
        }
        $imported = array_map(static fn(LiveTable $table): string => $table->name, $catalog->tables());
        $ok = $this->baseline->import($run->scope->target, $catalog);
        $this->auditBaseline($run, $ok);
        $exported = null;
        if ($path !== null) {
            $exported = $this->baseline->export($run->scope->target);
            $this->audit->step($run->correlation, $run->scope->actor->id, self::ACTION, $run->scope->target, 'audit-schema-export', $exported);
        }

        return $run->report(AuditMode::Load, $ok ? null : BaselineOutcome::LoadFailed, null, [], [], $imported, $path, $exported);
    }

    /**
     * What an applied --load would list. A dry run does not create the two
     * audit tables, so they are added where SHOW TABLES, which sorts names
     * as bytes, would have listed them after the reset.
     *
     * @return list<string>
     */
    private static function planned(AuditCatalog $catalog): array
    {
        $names = array_map(static fn(LiveTable $table): string => $table->name, $catalog->tables());
        foreach (self::BASELINE_TABLES as $table) {
            if (!in_array($table, $names, true)) {
                $at = array_find_key($names, static fn(string $name): bool => strcmp($name, $table) > 0) ?? count($names);
                array_splice($names, $at, 0, [$table]);
            }
        }

        return $names;
    }

    /** create_tables()'s CREATE and TRUNCATE of the two audit tables: one step, whatever the file holds. */
    private function reset(AuditRun $run): ?string
    {
        $uncreated = $this->baseline->reset($run->scope->target);
        $this->audit->step($run->correlation, $run->scope->actor->id, self::ACTION, $run->scope->target, 'audit-schema-reset', $uncreated === null);

        return $uncreated;
    }

    private function auditBaseline(AuditRun $run, bool $ok): void
    {
        foreach (self::BASELINE_TABLES as $table) {
            $this->audit->statement($run->correlation, $run->scope->actor->id, self::ACTION, $run->scope->target, $table, $ok);
        }
    }
}
