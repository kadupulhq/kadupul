<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Application\Command\AuditDatabase;
use Kadupul\Platform\Application\Command\RemoteCollectorRefused;
use Kadupul\Platform\Application\ReadModel\AuditOutcome;
use Kadupul\Platform\Application\ReadModel\AuditReport;
use Kadupul\Platform\Application\ReadModel\BaselineOutcome;
use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\TableAudit;
use Kadupul\Platform\Domain\Schema\WidenedColumn;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:database:audit', description: 'Compare the schema with docs/audit_schema.sql, and repair it on request.')]
final readonly class AuditDatabaseCommand
{
    private const string UTILITY = 'Kadupul Database Audit Utility';

    public function __construct(
        private AuditDatabase $audit,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AuditDatabaseInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new AuditDatabaseLegacyArguments();
        try {
            // The script refused a remote collector before it read any argument, --help included.
            if ($this->audit->refusesThisCollector()) {
                throw new RemoteCollectorRefused();
            }
            $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
            if ($early !== null) {
                return $early;
            }
            // A shim with no mode prints the help after the version check, as the
            // script did; under bin/console a missing mode is a usage error.
            $auditMode = $input->mode();
            $report = $auditMode === null && $mode !== OutputMode::Legacy ? null : ($this->audit)($auditMode, $input->upgrade, $input->as, !$input->dryRun);
        } catch (RemoteCollectorRefused) {
            return $this->renderer->failure($io, $output, $mode, 'The audit runs on the main data collector only.', new CommandResult(
                ['status' => 'failed', 'error' => 'main data collector only'],
                ['FATAL: This utility is designed for the main Data Collector only'],
                Command::FAILURE,
            ));
        } catch (\Throwable $error) {
            // An export that timed out lands here too; its message names the dump command.
            return $this->renderer->refused($io, $output, $mode, $error, 'Database audit failed');
        }
        if ($report === null) {
            return $this->renderer->failure($io, $output, $mode, 'Choose --report, --repair, --alters, --create or --load.', new CommandResult(['status' => 'invalid', 'error' => 'no mode selected'], [], Command::INVALID));
        }
        if ($mode === OutputMode::Legacy) {
            return $this->legacy($output, $report, $legacy, $input->alters);
        }

        return $this->report($io, $output, $mode, $report);
    }

    private function legacy(OutputInterface $output, AuditReport $report, AuditDatabaseLegacyArguments $legacy, bool $alters): int
    {
        // The original passed the upgrade script's stderr through as it ran; here it follows the upgrade.
        $stderr = $report->upgrade?->stderr ?? '';
        if ($stderr !== '') {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->write($stderr, false, OutputInterface::OUTPUT_RAW);
        }
        $lines = $legacy->report($report, $alters, $report->outcome === AuditOutcome::NoMode ? $this->version->line(self::UTILITY) : '');
        // Exit 1 only for a missing or failed upgrade; "Failed to create" ended without a newline.
        $exit = in_array($report->outcome, [AuditOutcome::UpgradeRequired, AuditOutcome::UpgradeFailed], true) ? Command::FAILURE : Command::SUCCESS;

        return $this->renderer->render(new CommandResult([], $lines, $exit, $report->baseline !== BaselineOutcome::CreateFailed), OutputMode::Legacy, $output);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AuditReport $report): int
    {
        if ($report->outcome === AuditOutcome::UpgradeRequired) {
            return $this->renderer->failure($io, $output, $mode, 'The database is behind the code; add --upgrade.', new CommandResult(['status' => 'failed', 'error' => 'upgrade required'], [], Command::FAILURE));
        }
        if ($report->outcome === AuditOutcome::UpgradeFailed) {
            return $this->renderer->failure($io, $output, $mode, 'The upgrade failed, so the audit did not run.', new CommandResult(
                ['status' => 'failed', 'database' => 'local', 'dry_run' => false, 'mode' => $report->mode?->value, 'upgrade' => 'failed', 'error' => 'upgrade failed'],
                [],
                Command::FAILURE,
            ));
        }
        $tables = array_map(static fn(TableAudit $table): array => [
            'name' => $table->table, 'status' => $table->status->value, 'errors' => $table->errors, 'warnings' => $table->warnings, 'findings' => $table->findings,
            'widened' => array_map(static fn(WidenedColumn $column): array => ['column' => $column->field, 'type' => $column->type, 'baseline' => $column->baseline], $table->widened),
        ], $report->tables);
        $alters = array_map(static fn(array $alter): array => ['table' => $alter['table'], 'result' => $alter['result']->value]
            + ($alter['statement'] === null ? [] : ['statement' => $alter['statement']]), $report->alters);
        if ($mode === OutputMode::Json) {
            // Always local: the audit runs only on the primary, where local is main.
            return $this->renderer->written($output, false, $report->dryRun, $report->failed(), [
                'mode' => $report->mode?->value,
                'upgrade' => match (true) {
                    $report->upgrade !== null => 'upgraded',
                    $report->upgradePlanned => 'planned',
                    default => 'none',
                },
                'baseline' => $report->baseline?->value, 'tables' => $tables, 'alters' => $alters,
                'imported' => $report->imported, 'exported' => $report->exported,
            ]);
        }
        $flagged = array_values(array_filter($tables, static fn(array $table): bool => $table['errors'] > 0 || $table['warnings'] > 0));
        if ($flagged !== []) {
            $io->table(['Table', 'Errors', 'Warnings'], array_map(static fn(array $table): array => [OutputFormatter::escape($table['name']), $table['errors'], $table['warnings']], $flagged));
        }
        if ($alters !== []) {
            $io->listing(array_map(static fn(array $alter): string => OutputFormatter::escape($alter['table'] . ': ' . $alter['result'] . (isset($alter['statement']) ? ' (' . $alter['statement'] . ')' : '')), $alters));
        }

        return $this->renderer->summary($io, OutputFormatter::escape(self::summary($report, count($flagged))), $report->failed());
    }

    /** --create and --load audit no table, so their summary says what happened to the audit tables instead. */
    private static function summary(AuditReport $report, int $flagged): string
    {
        if ($report->baseline === BaselineOutcome::CreateFailed) {
            return 'Could not create the ' . $report->uncreated . ' table';
        }

        return match ($report->mode) {
            AuditMode::Create => match ($report->baseline) {
                BaselineOutcome::Loaded => 'Reloaded the audit tables from docs/audit_schema.sql',
                BaselineOutcome::Planned => 'Read docs/audit_schema.sql; the audit tables were not reloaded',
                BaselineOutcome::FileMissing => 'docs/audit_schema.sql was not found',
                BaselineOutcome::Unparsable => 'docs/audit_schema.sql line ' . $report->unparsableLine . ' does not parse',
                default => 'The audit tables could not be loaded',
            },
            AuditMode::Load => match (true) {
                $report->baseline === BaselineOutcome::LoadFailed => 'Importing ' . count($report->imported) . ' tables into the audit tables failed',
                $report->dumpPath === null => sprintf($report->dryRun ? 'Would import %d tables; docs/ does not exist, so nothing would be exported' : 'Imported %d tables; docs/ does not exist, so nothing was exported', count($report->imported)),
                $report->dryRun => sprintf('Would import %d tables and export them to %s', count($report->imported), $report->dumpPath),
                default => sprintf('Imported %d tables and exported them to %s', count($report->imported), $report->dumpPath),
            },
            default => sprintf('Audited %d tables, %d with problems', count($report->tables), $flagged),
        };
    }
}
