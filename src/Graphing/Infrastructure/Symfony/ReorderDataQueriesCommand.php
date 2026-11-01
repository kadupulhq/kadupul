<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\DataQueryReorderReport;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\MainCollectorOnly;
use Kadupul\Graphing\Application\Command\ReorderDataQueries;
use Kadupul\Graphing\Domain\DataQuerySelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:data-query:reorder', description: 'Reorder the stored index of data query data sources in bulk.')]
final readonly class ReorderDataQueriesCommand
{
    private const string UTILITY = 'Kadupul Reorder Data Query Utility';

    public function __construct(
        private ReorderDataQueries $reorder,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] ReorderDataQueriesInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        // The script refused a remote collector before it read any argument, --help included.
        if ($this->reorder->refusesThisCollector()) {
            return $this->refused($io, $output, $mode);
        }
        $legacy = new ReorderDataQueriesLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        if ($input->hostId === null && $input->qid === null && !$input->debug && $mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], [...$legacy->noInput(), $this->version->line(self::UTILITY), ...$legacy->help()], Command::FAILURE), $mode, $output);
        }
        try {
            $selection = DataQuerySelection::parse($input->hostId ?? 'all', $input->qid);
        } catch (\InvalidArgumentException $invalid) {
            if ($mode === OutputMode::Legacy) {
                // The original wrote this to stderr, then exited 1 without the help.
                ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('ERROR: ' . $invalid->getMessage(), OutputInterface::OUTPUT_RAW);

                return Command::FAILURE;
            }

            return $this->renderer->failure($io, $output, $mode, $invalid->getMessage(), new CommandResult(['status' => 'invalid', 'error' => $invalid->getMessage()], [], Command::INVALID));
        }
        try {
            $report = ($this->reorder)($selection, $input->as, !$input->dryRun);
        } catch (MainCollectorOnly) {
            return $this->refused($io, $output, $mode);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Data query reorder failed');
        }

        return $this->report($io, $output, $mode, $input, $report, $legacy);
    }

    private function refused(SymfonyStyle $io, OutputInterface $output, OutputMode $mode): int
    {
        return $this->renderer->failure($io, $output, $mode, 'The data query reorder runs on the main data collector only.', new CommandResult(
            ['status' => 'failed', 'error' => 'main data collector only'],
            ['FATAL: This utility is designed for the main Data Collector only'],
            Command::FAILURE,
        ));
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, ReorderDataQueriesInput $input, DataQueryReorderReport $report, ReorderDataQueriesLegacyArguments $legacy): int
    {
        $exit = $report->succeeded() ? Command::SUCCESS : Command::FAILURE;
        $stopped = $report->stoppedAt();
        if ($mode === OutputMode::Legacy) {
            [$lines, $newline] = $legacy->run($report->items, $report->indexOn, $report->unresolved, $input->debug);
            $this->renderer->render(new CommandResult([], $lines, $exit, $newline), $mode, $output);
            if ($stopped !== null) {
                ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($legacy->unresolved($stopped), OutputInterface::OUTPUT_RAW);
            }

            return $exit;
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => $report->succeeded() ? 'ok' : 'partial', 'database' => $report->database === OperatorDatabase::Main ? 'main' : 'local', 'dry_run' => $report->dryRun,
                'selected' => count($report->items), 'reordered' => count($report->indexOn)];
            if ($stopped !== null) {
                $json['stopped_at'] = ['host_id' => $stopped->hostId, 'snmp_query_id' => $stopped->snmpQueryId];
            }

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        $count = count($report->items);
        if ($report->dryRun) {
            $io->success(sprintf('Would reorder %d data query index item(s).', $count));

            return $exit;
        }
        if ($stopped !== null) {
            $io->error($legacy->unresolved($stopped));
        }

        return $this->renderer->summary($io, sprintf('Reordered %d of %d data query index item(s)', count($report->indexOn), $count), $count - count($report->indexOn));
    }
}
