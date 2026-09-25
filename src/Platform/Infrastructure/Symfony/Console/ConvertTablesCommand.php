<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Application\Command\ConvertTables;
use Kadupul\Platform\Application\ReadModel\ConversionOutcome;
use Kadupul\Platform\Application\ReadModel\ConversionReport;
use Kadupul\Platform\Application\ReadModel\TableResult;
use Kadupul\Platform\Domain\Schema\ConversionOptions;
use Kadupul\Platform\Domain\Schema\ConversionProblem;
use Kadupul\Platform\Domain\Schema\InvalidConversionOptions;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:database:convert-tables', description: 'Convert installation tables to InnoDB, utf8mb4 or latin1.')]
final readonly class ConvertTablesCommand
{
    private const string UTILITY = 'Kadupul Database Conversion Utility';

    public function __construct(
        private ConvertTables $convert,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] ConvertTablesInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new ConvertTablesLegacyArguments();
        try {
            $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
            if ($early !== null) {
                return $early;
            }
            $options = $input->options();
            $report = ($this->convert)($options, $input->local, $input->as, !$input->dryRun);
        } catch (InvalidConversionOptions $invalid) {
            return $this->invalid($io, $output, $mode, $invalid->problem, $legacy);
        } catch (\Throwable $error) {
            return $this->renderer->refused($io, $output, $mode, $error, 'Table conversion failed');
        }

        return $this->report($io, $output, $mode, $options, $report, $legacy);
    }

    private function invalid(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, ConversionProblem $problem, ConvertTablesLegacyArguments $legacy): int
    {
        [$human, $error] = match ($problem) {
            ConversionProblem::TableAndSkip => ['Use --table or --skip-innodb, not both.', 'table and skip-innodb cannot be combined'],
            ConversionProblem::NoConversion => ['Choose --innodb, --utf8 or --latin1.', 'no conversion selected'],
            ConversionProblem::Size => ['The --size option needs a whole number of rows.', 'size must be a whole number'],
        };
        // The original printed its own two errors, then its help, and exited 0.
        $exit = $mode === OutputMode::Legacy ? Command::SUCCESS : Command::INVALID;
        $lines = $mode === OutputMode::Legacy ? $legacy->problem($problem, $this->version->line(self::UTILITY)) : [];

        return $this->renderer->failure($io, $output, $mode, $human, new CommandResult(['status' => 'invalid', 'error' => $error], $lines, $exit));
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, ConversionOptions $options, ConversionReport $report, ConvertTablesLegacyArguments $legacy): int
    {
        if ($mode === OutputMode::Legacy) {
            $versionLine = $report->outcome === ConversionOutcome::SkipTableMissing ? $this->version->line(self::UTILITY) : '';
            // convert_tables.php exited 0 on every one of these paths, and printed
            // its innodb_file_per_table refusal without a newline.
            $result = new CommandResult([], $legacy->report($report, $options, $versionLine), Command::SUCCESS, $report->outcome !== ConversionOutcome::FilePerTableDisabled);

            return $this->renderer->render($result, $mode, $output);
        }
        $database = $report->main ? 'main' : 'local';
        if ($report->outcome !== ConversionOutcome::Completed) {
            [$human, $error] = match ($report->outcome) {
                ConversionOutcome::SkipTableMissing => ['Skip table ' . $report->missingSkipTable . ' does not exist.', 'Skip table does not exist'],
                ConversionOutcome::InnodbDisabled => ['InnoDB is not enabled.', 'InnoDB is not enabled'],
                default => ['innodb_file_per_table is not enabled.', 'innodb_file_per_table is not enabled'],
            };
            $json = ['status' => 'failed', 'database' => $database, 'error' => $error] + ($report->missingSkipTable === null ? [] : ['table' => $report->missingSkipTable]);

            return $this->renderer->failure($io, $output, $mode, $human, new CommandResult($json, [], Command::FAILURE));
        }
        $tables = array_map(
            static fn(array $table): array => ['name' => $table['name'], 'result' => $table['result']->value, 'rows' => $table['rows']]
                + ($table['statement'] === null ? [] : ['statement' => $table['statement']]),
            $report->tables,
        );
        if ($mode === OutputMode::Json) {
            return $this->renderer->written($output, $report->main, $report->dryRun, $report->failed(), ['tables' => $tables]);
        }
        $io->listing(array_map(
            static fn(array $table): string => OutputFormatter::escape($table['name'] . ': ' . str_replace('_', ' ', $table['result']) . (isset($table['statement']) ? ' (' . $table['statement'] . ')' : '')),
            $tables,
        ));
        $changed = count(array_filter($report->tables, static fn(array $table): bool => in_array($table['result'], [TableResult::Converted, TableResult::Planned], true)));

        return $this->renderer->summary($io, sprintf($report->dryRun ? 'Planned %d of %d tables' : 'Converted %d of %d tables', $changed, count($tables)), $report->failed());
    }
}
