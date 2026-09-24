<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Application\Command\AnalyzeDatabase;
use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\ReadModel\AnalysisReport;
use Kadupul\Platform\Infrastructure\Doctrine\MainDatabaseNotConfigured;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:database:analyze', description: 'Recalculate index cardinality for every installation table.')]
final readonly class AnalyzeDatabaseCommand
{
    public function __construct(
        private AnalyzeDatabase $analyze,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
        private ClockInterface $clock,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AnalyzeDatabaseInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        try {
            // Only a cli/ shim asks for version or help this way, and it has no
            // way to pass --json, so these are legacy lines only.
            if ($this->presentation->legacy !== LegacyRequest::Run) {
                $lines = [$this->versionLine()];
                if ($this->presentation->legacy === LegacyRequest::Help) {
                    $lines = [...$lines, ...(new AnalyzeDatabaseLegacyArguments())->help()];
                }

                return $this->renderer->render(new CommandResult([], $lines), OutputMode::Legacy, $output);
            }
            // The use case also refuses '', but only as a denial; an empty --as=
            // is a usage error and is reported as one.
            if ($input->as === '') {
                return $this->fail($io, $output, $mode, 'The --as option needs an operator name.', new CommandResult(['status' => 'invalid', 'error' => 'The --as option needs an operator name'], ['ERROR: Invalid Parameter --as='], Command::INVALID));
            }
            $report = ($this->analyze)($input->local, $input->as);
        } catch (InstallationAccessDenied) {
            return $this->fail($io, $output, $mode, 'Unknown or unauthorized operator.', new CommandResult(['status' => 'denied'], ['ERROR: Unknown or unauthorized operator'], Command::FAILURE));
        } catch (MainDatabaseNotConfigured) {
            return $this->databaseFailure($io, $output, $mode, 'Main database is not configured');
        } catch (\Throwable) {
            // Exception text can carry SQL or connection details, so it is never shown.
            return $this->databaseFailure($io, $output, $mode, 'Database analysis failed');
        }

        return $this->report($io, $output, $mode, $report);
    }

    private function versionLine(): string
    {
        return 'Kadupul Analyze Database Utility, Version ' . $this->version->text() . ', Copyright (C) 2004-' . $this->clock->now()->format('Y') . ' The Cacti Group';
    }

    private function databaseFailure(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $message): int
    {
        return $this->fail($io, $output, $mode, $message . '.', new CommandResult(['status' => 'failed', 'error' => $message], ['ERROR: ' . $message], Command::FAILURE));
    }

    private function fail(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $human, CommandResult $result): int
    {
        if ($mode !== OutputMode::Human) {
            return $this->renderer->render($result, $mode, $output);
        }
        $io->getErrorStyle()->error($human);

        return $result->exit;
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AnalysisReport $report): int
    {
        // The original says "Repairing" although it only analyzes; legacy output keeps that.
        $legacy = ['NOTE: Analyzing All Kadupul Database Tables', 'NOTE: Repairing Tables for ' . ($report->main ? 'Main' : 'Local') . ' Database'];
        foreach ($report->tables as $table) {
            $legacy[] = "NOTE: Analyzing Table -> '" . $table['name'] . "'" . ($report->noBinlog ? ' without writing to the binlog' : '') . ($table['ok'] ? ' Successful' : ' Failed');
        }
        if ($mode !== OutputMode::Human) {
            $json = ['status' => 'ok', 'database' => $report->main ? 'main' : 'local', 'binlog_enabled' => $report->noBinlog, 'tables' => $report->tables];

            return $this->renderer->render(new CommandResult($json, $legacy), $mode, $output);
        }
        if ($report->tables !== []) {
            $io->listing(array_map(static fn(array $table): string => $table['name'] . ': ' . ($table['ok'] ? 'analyzed' : 'failed'), $report->tables));
        }
        $failed = count(array_filter($report->tables, static fn(array $table): bool => !$table['ok']));
        $summary = sprintf('Analyzed %d tables', count($report->tables));
        // The original exits 0 when a table fails; the warning says so without changing that.
        if ($failed === 0) {
            $io->success($summary . '.');
        } else {
            $io->warning(sprintf('%s; %d failed.', $summary, $failed));
        }

        return Command::SUCCESS;
    }
}
