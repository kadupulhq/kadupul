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
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'kadupul:database:audit', description: 'Compare the schema with docs/audit_schema.sql, and repair it on request.')]
final readonly class AuditDatabaseCommand
{
    private const string UTILITY = 'Kadupul Database Audit Utility';

    public function __construct(
        private AuditDatabase $audit,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
        private Filesystem $filesystem,
        private string $projectDir,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AuditDatabaseInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        if ($input->json && $input->mode() === AuditMode::Load && $input->output === '-') {
            return $this->renderer->failure($io, $output, $mode, 'Use --output=PATH with --load --json so SQL stays separate from the JSON result.', new CommandResult(['status' => 'invalid', 'error' => 'load needs an output path in json mode'], [], Command::INVALID));
        }
        if ($input->upgrade) {
            $warning = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $warning->writeln('DEPRECATION: --upgrade in the audit command is retained for compatibility. Run php cli/upgrade_database.php separately before auditing.');
        }
        $legacy = new AuditDatabaseLegacyArguments();
        // The shim repairs at once, as the script did. Under bin/console a
        // repair changes the schema only with --force or after the operator
        // has seen the plan and said yes.
        $ask = $mode !== OutputMode::Legacy && $input->mode() === AuditMode::Repair && !$input->force && !$input->dryRun;
        $report = $this->run($io, $output, $mode, $legacy, $input, !$input->dryRun && !$ask);
        if (!$report instanceof AuditReport) {
            return $report;
        }
        if ($report->mode === AuditMode::Load && !$report->dryRun && $report->exportContent !== null) {
            if ($input->output === '-') {
                $output->write($report->exportContent, false, OutputInterface::OUTPUT_RAW);
                $warning = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
                $warning->writeln('Wrote the audit schema SQL to stdout.');

                return Command::SUCCESS;
            }
            try {
                $destination = $this->outputDestination($input->output);
                $canonical = realpath($this->projectDir . '/docs/audit_schema.sql') ?: $this->projectDir . '/docs/audit_schema.sql';
                if ($destination === $canonical) {
                    throw new IOException('The canonical audit schema is protected.');
                }
                $this->filesystem->dumpFile($destination, $report->exportContent);
            } catch (IOException) {
                return $this->renderer->failure($io, $output, $mode, 'Could not write the audit schema dump to the requested path.', new CommandResult(
                    ['status' => 'failed', 'error' => 'schema dump write failed'],
                    ['ERROR: Could not write the audit schema dump to the requested path.'],
                    Command::FAILURE,
                ));
            }
        }
        if ($mode === OutputMode::Legacy) {
            return $this->legacy($output, $report, $legacy, $input->alters);
        }
        $exit = $this->report($io, $output, $mode, $report);
        if (!$ask || $mode !== OutputMode::Human || $report->alters === []) {
            return $exit;
        }
        if (!$io->confirm('Run these statements now?', false)) {
            $io->note('Nothing was changed. Add --force to repair without this question.');

            return $exit;
        }
        $applied = $this->run($io, $output, $mode, $legacy, $input, true);

        return $applied instanceof AuditReport ? $this->report($io, $output, $mode, $applied) : $applied;
    }

    /** The use case's report, or the exit code of a run that ended before it. */
    private function run(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AuditDatabaseLegacyArguments $legacy, AuditDatabaseInput $input, bool $apply): AuditReport|int
    {
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
            $report = $auditMode === null && $mode !== OutputMode::Legacy ? null : ($this->audit)($auditMode, $input->upgrade, $input->as, $apply, $input->output);
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

        return $report ?? $this->renderer->failure($io, $output, $mode, 'Choose --report, --repair, --alters, --create or --load.', new CommandResult(['status' => 'invalid', 'error' => 'no mode selected'], [], Command::INVALID));
    }

    private function outputDestination(string $path): string
    {
        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : getcwd() . DIRECTORY_SEPARATOR . $path;
        $resolved = realpath($candidate);
        if ($resolved !== false) {
            return $resolved;
        }
        $parent = realpath(dirname($candidate));

        return ($parent === false ? dirname($candidate) : $parent) . DIRECTORY_SEPARATOR . basename($candidate);
    }

    private function legacy(OutputInterface $output, AuditReport $report, AuditDatabaseLegacyArguments $legacy, bool $alters): int
    {
        // The original passed the upgrade script's stderr through as it ran; here it follows the upgrade.
        $stderr = $report->upgrade?->stderr ?? '';
        if ($stderr !== '') {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->write($stderr, false, OutputInterface::OUTPUT_RAW);
        }
        $lines = $legacy->report($report, $alters, $report->outcome === AuditOutcome::NoMode ? $this->version->line(self::UTILITY) : '');
        // An unusable baseline must not look like a successful audit.
        $baselineFailed = in_array($report->baseline, [BaselineOutcome::FileMissing, BaselineOutcome::Unparsable], true);
        $exit = in_array($report->outcome, [AuditOutcome::UpgradeRequired, AuditOutcome::UpgradeFailed], true) || $baselineFailed
            ? Command::FAILURE
            : Command::SUCCESS;

        return $this->renderer->render(new CommandResult([], $lines, $exit), OutputMode::Legacy, $output);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AuditReport $report): int
    {
        if ($report->outcome === AuditOutcome::UpgradeRequired) {
            return $this->renderer->failure($io, $output, $mode, 'The database is behind the code; run php cli/upgrade_database.php first.', new CommandResult(['status' => 'failed', 'error' => 'upgrade required'], [], Command::FAILURE));
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
                'generated_tables' => $report->generatedTables, 'exported' => $report->exported,
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

    /** --create and --load operate on the parsed baseline and catalog without creating staging tables. */
    private static function summary(AuditReport $report, int $flagged): string
    {
        if (in_array($report->baseline, [BaselineOutcome::FileMissing, BaselineOutcome::Unparsable], true)) {
            return 'Audit stopped because the canonical schema could not be loaded';
        }

        return match ($report->mode) {
            AuditMode::Create => match ($report->baseline) {
                BaselineOutcome::Loaded => 'Validated docs/audit_schema.sql; no database tables were created',
                BaselineOutcome::Planned => 'Read docs/audit_schema.sql; no database tables were created',
                BaselineOutcome::FileMissing => 'docs/audit_schema.sql was not found',
                BaselineOutcome::Unparsable => 'docs/audit_schema.sql line ' . $report->unparsableLine . ' does not parse',
                default => 'The canonical schema could not be read',
            },
            AuditMode::Load => match (true) {
                $report->dryRun => sprintf('Would write a schema dump to %s', $report->dumpPath),
                default => sprintf('Wrote a schema dump to %s', $report->dumpPath),
            },
            default => sprintf('Audited %d tables, %d with problems', count($report->tables), $flagged),
        };
    }
}
