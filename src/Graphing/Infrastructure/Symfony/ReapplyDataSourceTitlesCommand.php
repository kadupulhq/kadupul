<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\DataSourceTitleReport;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\ReapplyDataSourceTitles;
use Kadupul\Graphing\Domain\GraphSelection;
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

#[AsCommand(name: 'kadupul:data-source:reapply-titles', description: 'Reapply the naming rules to existing data sources in bulk.')]
final readonly class ReapplyDataSourceTitlesCommand
{
    private const string UTILITY = 'Kadupul Reapply Data Source Names Utility';
    private const string BAD_HOSTS = 'Specify --host-id=all or a comma-separated list of positive device IDs.';

    public function __construct(
        private ReapplyDataSourceTitles $reapply,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] ReapplyDataSourceTitlesInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new ReapplyDataSourceTitlesLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        if ($input->hostId === null && $input->filter === null && !$input->debug && $mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], [...$legacy->noInput(), $this->version->line(self::UTILITY), ...$legacy->help()], Command::FAILURE), $mode, $output);
        }
        $selection = GraphSelection::parse($input->hostId ?? '', $input->filter ?? '');
        if ($selection === null) {
            if ($mode === OutputMode::Legacy) {
                // The original wrote this to stderr, then exited 1 without the help.
                ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('ERROR: ' . self::BAD_HOSTS, OutputInterface::OUTPUT_RAW);

                return Command::FAILURE;
            }

            return $this->renderer->failure($io, $output, $mode, self::BAD_HOSTS, new CommandResult(['status' => 'invalid', 'error' => self::BAD_HOSTS], [], Command::INVALID));
        }
        try {
            $report = ($this->reapply)($selection, $input->local, $input->as, !$input->dryRun);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Data source rename failed');
        }

        return $this->report($io, $output, $mode, $input, $report, $legacy);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, ReapplyDataSourceTitlesInput $input, DataSourceTitleReport $report, ReapplyDataSourceTitlesLegacyArguments $legacy): int
    {
        $exit = $report->succeeded() ? Command::SUCCESS : Command::FAILURE;
        if ($mode === OutputMode::Legacy) {
            [$lines, $newline] = $legacy->run($report->dataSources, $report->renamed, $input->debug);

            return $this->renderer->render(new CommandResult([], $lines, $exit, $newline), $mode, $output);
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => $report->succeeded() ? 'ok' : 'partial', 'database' => $report->database === OperatorDatabase::Main ? 'main' : 'local', 'dry_run' => $report->dryRun,
                'selected' => count($report->dataSources), 'reapplied' => count($report->renamed),
                'data_sources' => array_map(static fn($source): int => $source->localDataId, $report->dataSources)];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        $count = count($report->dataSources);
        if ($report->dryRun) {
            $io->success(sprintf('Would reapply the naming rules to %d data source(s).', $count));

            return $exit;
        }

        return $this->renderer->summary($io, sprintf('Reapplied the naming rules to %d of %d data source(s)', count($report->renamed), $count), $count - count($report->renamed));
    }
}
